<?php
declare(strict_types=1);

/**
 * SmartBook API front controller.
 *
 * Routes:  /api/<resource>/<action>
 * Every response is JSON: {ok:true,data:...} or {ok:false,error:{code,message}}
 *
 * Security order on every request:
 *   1. route lookup          4. RBAC permission
 *   2. auth resolve          5. CSRF (mutating verbs)
 *   3. tenant from token     6. subscription write gate
 */

require __DIR__ . '/../../backend/bootstrap.php';

/* Cross-origin support: the same app shell may be hosted on static hosting
   (GitHub Pages / Firebase Hosting) and pointed at this server. The API is
   authorised by bearer tokens in headers (no cross-site cookies), so echoing
   the caller's own origin is safe. */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = preg_replace('#^/api#', '', $path);
$seg    = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
$res    = $seg[0] ?? 'health';
$act    = $seg[1] ?? 'index';
$q      = $_GET;
$in     = Input::body();

try {
    /* ---------------------------------------------------------------- */
    /* Public                                                           */
    /* ---------------------------------------------------------------- */
    if ($res === 'health') {
        $dbOk = true;
        try {
            Db::val('SELECT 1');
        } catch (Throwable $e) {
            $dbOk = false;
        }
        Response::ok([
            'app'      => Config::get('app.name'),
            'tagline'  => Config::get('app.tagline'),
            'version'  => '1.0.0',
            'database' => $dbOk ? 'connected' : 'unavailable',
            'time'     => Db::now(),
        ]);
        exit;
    }

    if ($res === 'meta' && $act === 'plans') {
        Response::ok(Db::all('SELECT id, code, name, price_ugx, max_users, features FROM subscription_plans WHERE is_active = 1 ORDER BY price_ugx'));
        exit;
    }

    if ($res === 'locations' && $act === 'districts') {
        Response::ok(['Mbarara', 'Bushenyi', 'Isingiro', 'Ntungamo', 'Sheema', 'Kampala', 'Masaka', 'Fort Portal', 'Gulu', 'Mbale']);
        exit;
    }

    /* Shop self-registration is disabled: only the platform administrator
       creates shop accounts (POST /api/admin/create-shop, admin.html). */
    if ($res === 'auth' && $act === 'register') {
        Response::fail('Only the administrator can create shop accounts.', 403, 'FORBIDDEN');
        exit;
    }
    if ($res === 'auth' && $act === 'login') {
        Response::ok(Auth::loginShop(Input::need($in, 'username', 60), (string) ($in['password'] ?? ''), Input::str($in, 'device_id', '', 64) ?: null));
        exit;
    }
    if ($res === 'auth' && $act === 'admin-login') {
        Response::ok(Auth::loginAdmin(Input::need($in, 'username', 160), (string) ($in['password'] ?? '')));
        exit;
    }
    if ($res === 'auth' && $act === 'me') {
        $c = Auth::requireShop();
        Response::ok([
            'user' => ['id' => $c['user_id'], 'name' => $c['name'], 'role' => $c['role'], 'role_name' => $c['role_name']],
            'shop' => Shop::profile($c['shop_id']),
            'permissions' => $c['permissions'],
        ]);
        exit;
    }
    if ($res === 'auth' && $act === 'admin-me') {
        $c = Auth::requireAdmin();
        Response::ok(['admin' => ['id' => $c['admin_id'], 'name' => $c['name']]]);
        exit;
    }
    if ($res === 'auth' && $act === 'logout') {
        $t = Auth::bearer();
        if ($t) {
            Auth::revoke($t);
        }
        Auth::startSession();
        $_SESSION = [];
        @session_destroy();
        Response::ok(['logged_out' => true]);
        exit;
    }

    /* ---------------------------------------------------------------- */
    /* SUPER ADMIN                                                      */
    /* ---------------------------------------------------------------- */
    if ($res === 'admin') {
        $c = Auth::requireAdmin();
        $adminId = (int) $c['admin_id'];
        if ($method !== 'GET') {
            Auth::verifyCsrf();
        }
        switch ($act) {
            case 'overview':      Response::ok(AdminService::overview()); break;
            case 'shops':         Response::ok(AdminService::shops($q)); break;
            case 'shop':
                Response::ok(AdminService::shopDetail(Input::int($q, 'id')));
                break;
            case 'shop-status':
                Response::ok(AdminService::setShopStatus(Input::int($in, 'id'), Input::str($in, 'status'), $adminId));
                break;
            case 'plans':
                Response::ok($method === 'POST' ? AdminService::savePlan($in, $adminId) : AdminService::plans());
                break;
            case 'payments':      Response::ok(AdminService::payments($q)); break;
            case 'verify-payment':
                Response::ok(AdminService::verifyPayment(Input::int($in, 'payment_id'), $adminId, Input::bool($in, 'approve', true), Input::str($in, 'note', '', 255)));
                break;
            case 'announcements':
                Response::ok($method === 'POST' ? AdminService::announcement($in, $adminId) : AdminService::announcements());
                break;
            case 'create-shop':
                Response::ok(AdminService::createShop($in, $adminId), [], 201);
                break;
            case 'support':       Response::ok(AdminService::support($q)); break;
            case 'logs':          Response::ok(AdminService::logs(Input::int($q, 'limit', 200))); break;
            case 'admins':
                Response::ok($method === 'POST' ? AdminService::createAdmin($in, $adminId) : AdminService::admins());
                break;
            default:
                throw new AppException('Unknown administrator endpoint.', 404, 'NO_ROUTE');
        }
        exit;
    }

    /* ---------------------------------------------------------------- */
    /* SHOP (tenant resolved from credential)                            */
    /* ---------------------------------------------------------------- */
    $c = Auth::requireShop();
    $shopId = (int) $c['shop_id'];
    $userId = (int) $c['user_id'];
    $device = Input::str($in, 'device_id', '', 64) ?: null;

    if ($method !== 'GET') {
        Auth::verifyCsrf();
    }

    // Reads are always allowed (records stay visible after expiry).
    $writeGate = !in_array($act, ['index', 'list', 'dashboard', 'report', 'export', 'ledger', 'analytics', 'conflicts'], true);

    switch ($res) {
        case 'products':
            if ($method === 'GET') {
                Auth::requirePermission('products.view');
                if ($act === 'categories') {
                    Response::ok(ShopService::categories($shopId));
                } elseif ($act === 'get') {
                    Response::ok(ShopService::getProduct($shopId, Input::int($q, 'id')));
                } else {
                    Response::ok(ShopService::listProducts($shopId, $q));
                }
            }
            Auth::requirePermission('products.manage');
            Shop::assertCanWrite($c);
            if ($act === 'delete') {
                Response::ok(ShopService::deleteProduct($shopId, Input::int($in, 'id')));
            } elseif ($act === 'categories') {
                Response::ok(ShopService::saveCategory($shopId, $in));
            } else {
                Response::ok(ShopService::saveProduct($shopId, $in, empty($in['id'])));
            }
            break;

        case 'stock':
            if ($method === 'GET') {
                Auth::requirePermission('products.view');
                Response::ok(ShopService::getProduct($shopId, Input::int($q, 'product_id'))['movements'] ?? []);
            }
            Auth::requirePermission('stock.adjust');
            Shop::assertCanWrite($c);
            $m = StockService::adjust($shopId, $in, $userId);
            Response::ok(['movement' => $m, 'product' => ShopService::getProduct($shopId, (int) $m['product_id'])]);
            break;

        case 'sales':
            if ($method === 'GET') {
                Auth::requirePermission('sales.view');
                Response::ok($act === 'get'
                    ? ShopService::getSale($shopId, Input::int($q, 'id'))
                    : ShopService::listSales($shopId, $q));
            }
            if ($act === 'cancel') {
                Auth::requirePermission('sales.cancel');
                Shop::assertCanWrite($c);
                Response::ok(ShopService::cancelSale($shopId, Input::int($in, 'id'), $userId, Input::str($in, 'reason', 'Cancelled by authorised user', 255)));
            }
            Auth::requirePermission('sales.create');
            Shop::assertCanWrite($c);
            Response::ok(ShopService::createSale($shopId, $in, $userId, $device), [], 201);

        case 'customers':
            if ($method === 'GET') {
                Auth::requirePermission('customers.view');
                if ($act === 'ledger') {
                    Response::ok(ShopService::customerLedger($shopId, Input::int($q, 'id')));
                }
                Response::ok(ShopService::listCustomers($shopId, $q));
            }
            Auth::requirePermission('customers.manage');
            Shop::assertCanWrite($c);
            if ($act === 'pay') {
                Response::ok(CreditService::pay($shopId, Input::int($in, 'customer_id'), Input::num($in, 'amount'), $userId,
                    Input::str($in, 'note', 'Credit payment', 200), null, $in['client_uuid'] ?? null));
            }
            Response::ok(ShopService::saveCustomer($shopId, $in));

        case 'suppliers':
            if ($method === 'GET') {
                Auth::requirePermission('suppliers.view');
                Response::ok(ShopService::listSuppliers($shopId));
            }
            Auth::requirePermission('suppliers.manage');
            Shop::assertCanWrite($c);
            Response::ok(ShopService::saveSupplier($shopId, $in));

        case 'purchases':
            if ($method === 'GET') {
                Auth::requirePermission('suppliers.view');
                Response::ok(ShopService::listPurchases($shopId));
            }
            Auth::requirePermission('suppliers.manage');
            Shop::assertCanWrite($c);
            Response::ok(ShopService::receivePurchase($shopId, $in, $userId), [], 201);

        case 'expenses':
            if ($method === 'GET') {
                Auth::requirePermission('expenses.view');
                Response::ok(ShopService::listExpenses($shopId, $q));
            }
            Auth::requirePermission('expenses.manage');
            Shop::assertCanWrite($c);
            Response::ok(ShopService::saveExpense($shopId, $in, $userId), [], 201);

        case 'dashboard':
            Auth::requirePermission('dashboard.view');
            Response::ok(ShopService::dashboard($shopId));
            break;

        case 'reports':
            Auth::requirePermission('reports.view');
            if ($act === 'export') {
                Exporter::csv(ShopService::report($shopId, Input::str($q, 'period', 'month'), $q['from'] ?? null, $q['to'] ?? null));
                exit;
            }
            Response::ok(ShopService::report($shopId, Input::str($q, 'period', 'month'), $q['from'] ?? null, $q['to'] ?? null));
            break;

        case 'analytics':
            Auth::requirePermission('analytics.view');
            Response::ok(Analytics::insights($shopId));
            break;

        case 'employees':
            if ($method === 'GET') {
                Auth::requirePermission('employees.manage');
                Response::ok(ShopService::listEmployees($shopId));
            }
            Auth::requirePermission('employees.manage');
            Shop::assertCanWrite($c);
            Response::ok(ShopService::saveEmployee($shopId, $in, $c));
            break;

        case 'sync':
            Auth::requirePermission('sync.use');
            // Sync push is written data; pull is a read.
            if ($act === 'pull' || $act === 'conflicts') {
                Response::ok($act === 'pull'
                    ? SyncService::pull($shopId, $q['since'] ?? null)
                    : ['conflicts' => SyncService::conflicts($shopId)]);
            }
            Shop::assertCanWrite($c);
            if ($act === 'repair') {
                Response::ok(SyncService::repair($shopId));
            }
            Response::ok(SyncService::push($shopId, $in, $device));
            break;

        case 'subscription':
            Auth::requirePermission('subscription.manage');
            if ($method === 'GET') {
                Response::ok([
                    'shop'     => Shop::profile($shopId),
                    'plans'    => Db::all('SELECT id, code, name, price_ugx, max_users, features FROM subscription_plans WHERE is_active = 1 ORDER BY price_ugx'),
                    'payments' => Db::all('SELECT id, amount_ugx, method, reference, status, period_months, created_at, notes FROM payments WHERE shop_id = ? ORDER BY created_at DESC LIMIT 50', [$shopId]),
                    'history'  => Db::all('SELECT s.*, p.name AS plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON p.id = s.plan_id WHERE s.shop_id = ? ORDER BY s.id DESC LIMIT 24', [$shopId]),
                ]);
            }
            Response::ok(Subscription::submitPayment($shopId, $in, $userId), [], 201);

        case 'notifications':
            if ($method === 'GET') {
                Response::ok(Db::all('SELECT * FROM notifications WHERE shop_id = ? ORDER BY created_at DESC LIMIT 100', [$shopId]));
            }
            Db::exec('UPDATE notifications SET is_read = 1 WHERE shop_id = ?', [$shopId]);
            Response::ok(['read' => true]);

        case 'audit':
            Auth::requirePermission('audit.view');
            Response::ok(ShopService::auditLogs($shopId, Input::int($q, 'limit', 200)));
            break;

        case 'settings':
            Auth::requirePermission('settings.manage');
            if ($method === 'GET') {
                Response::ok(Shop::profile($shopId));
            }
            Shop::assertCanWrite($c);
            Response::ok(Shop::update($shopId, $in));
            break;

        case 'backup':
            Auth::requirePermission('settings.manage');
            if ($method === 'GET') {
                $data = Exporter::jsonBackup($shopId, $act === 'download');
                if ($act === 'download') {
                    exit; // jsonBackup already streamed the file
                }
                Response::ok($data);
            }
            Response::ok(Backup::restore($shopId, $in, $userId));
            break;

        case 'support':
            if ($method === 'GET') {
                Response::ok(Db::all('SELECT id, subject, message, status, response, created_at FROM support_requests WHERE shop_id = ? ORDER BY created_at DESC', [$shopId]));
            }
            Db::exec('INSERT INTO support_requests (shop_id, subject, message) VALUES (?,?,?)',
                [$shopId, Input::need($in, 'subject', 160), Input::need($in, 'message', 2000)]);
            Response::ok(['id' => Db::id()], [], 201);

        default:
            throw new AppException('Unknown endpoint: ' . $res . '/' . $act, 404, 'NO_ROUTE');
    }
} catch (AppException $e) {
    Response::fail($e->getMessage(), $e->status, $e->errorCode, $e->meta);
} catch (Throwable $e) {
    error_log('[smartbook] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::fail(
        Config::get('app.debug') ? $e->getMessage() : 'Something went wrong on the server. Please try again.',
        500,
        'SERVER_ERROR'
    );
}
