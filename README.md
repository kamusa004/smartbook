# SmartBook Solutions

**Multi-tenant, offline-first inventory and sales management system for Ugandan retail shops.**
*Your Business, Under Control.*

Built for the realities of Mile 4, Mbarara: unreliable internet, intermittent power, low-end Android
phones, and shopkeepers who have never used accounting software.

---

## What is actually here

This is a **runnable application**, not a mockup. It has a PHP REST backend on MySQL, a PWA frontend
driven by JavaScript (no build step), a real sync engine, and a Python analytics service.

| Area | Status |
|---|---|
| Multi-tenant registration, Shop IDs, tenant isolation | **Complete and tested** |
| Subscription plans, payments, manual verification, grace periods | **Complete and tested** |
| Super Administrator dashboard (platform level) | **Complete and tested** |
| Shop users, 5 roles, permission matrix | **Complete and tested** |
| Inventory, stock movement ledger, low-stock alerts | **Complete and tested** |
| POS / tap-to-sell, cash, MoMo, Airtel, credit | **Complete and tested** |
| Customer credit ledger with running balance | **Complete and tested** |
| Suppliers, purchase receiving, suggested reorder | **Complete** |
| Expenses, gross and net profit, reports, CSV export | **Complete and tested** |
| Dashboard, charts, best/slow movers | **Complete and tested** |
| Offline-first: Service Worker, IndexedDB queue, delta sync, conflict repair | **Complete and tested** |
| Multi-device sync for one shop | **Complete and tested** |
| Python analytics (forecasting, run-out prediction) | **Working service**, SQL fallback if not installed |
| Barcode scanning via phone camera | **Working** (native `BarcodeDetector`; graceful fallback) |
| MTN MoMo / Airtel Money API integration | **Scaffold** — adapter + config, manual verification by default |
| SMS / WhatsApp reminders | **Working deep links** (WhatsApp/SMS handlers), no paid gateway |
| Luganda / Runyankole translation | **Working dictionary** for UI strings, extendable |

Nothing in the "scaffold" rows pretends to work: there are **no invented API keys, endpoints or
prices**. Payment adapters only activate when you paste in credentials your operator gave you.

---

## Folder structure

```
smartbook/
├── backend/
│   ├── bootstrap.php            Config + class loading + error handling
│   └── src/
│       ├── Core.php             Config, AppException, Response, Input validation
│       ├── Db.php               PDO singleton, prepared statements, UUIDs
│       ├── Security.php         RBAC, tenant gate, subscription state machine, audit
│       ├── Auth.php             Token + session auth, CSRF, login throttling
│       ├── StockService.php     The only code that changes stock (ledger deltas)
│       ├── ShopService.php      Products, sales, customers, suppliers, expenses, reports
│       ├── SyncService.php      Push/pull engine, conflict detection, repair
│       ├── AdminService.php     Super Administrator operations
│       ├── Analytics.php        Python bridge with SQL fallback
│       ├── Registration.php     Shop self-registration
│       ├── Subscription.php     Payment submission + provider adapters
│       └── Exporter.php         CSV export, JSON backup, restore
├── public/api/index.php         REST front controller (all routes)
├── frontend/
│   ├── index.html               Shop app shell
│   ├── admin.html               Super Administrator shell
│   ├── manifest.json            PWA manifest
│   ├── sw.js                    Service Worker (cache-first shell, offline queue)
│   └── assets/
│       ├── css/app.css          Design system (blue/white/green)
│       ├── js/api.js            API client (token + CSRF, offline tolerant)
│       ├── js/db.js             IndexedDB wrapper + write queue
│       ├── js/sync.js           Sync engine, 5 status states
│       ├── js/i18n.js           English / Luganda / Runyankole
│       ├── js/app.js            Router, dashboard, POS
│       ├── js/pages.js          All secondary screens
│       ├── js/admin.js          Platform dashboard
│       └── vendor/              Bootstrap 5.3.3 (vendored so it works offline)
├── database/
│   ├── schema.sql               27 tables, FKs, indexes, tenant keys
│   ├── seed.sql                 Roles, permissions, plans (UGX 10k/15k/20k), super admin
│   └── demo_seed.php            Two sample shops with 30 days of realistic sales
├── python/app.py                Flask analytics service + CLI mode
├── config/config.php            All configuration in one place
├── router.php                   Dev server router (do not use in production)
├── tests/api_test.py            21-group end-to-end test suite
└── docs/                        INSTALL.md, TESTING.md, DEPLOY.md
```

---

## Quick start (5 minutes, on a machine with PHP + MySQL)

```bash
# 1. database
mysql -u root -p -e "CREATE DATABASE smartbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p smartbook < database/schema.sql
mysql -u root -p smartbook < database/seed.sql

# 2. configuration - edit db user/password
cp config/config.php config/config.local.php   # keep your edited copy private

# 3. demo shops (optional but recommended)
php database/demo_seed.php

# 4. run
php -S 127.0.0.1:8080 router.php
```

Then open **http://127.0.0.1:8080/**

| Login | Username | Password |
|---|---|---|
| Sample shop owner (Standard plan) | `grace` | `Demo@2024` |
| Sample shop owner (Basic plan) | `robert` | `Demo@2024` |
| Cashier (restricted role) | `grace_cashier` | `Cashier@2024` |
| Super Administrator | `sysadmin` | `Admin@2026` |

**Change the administrator password immediately** — the hash is in `database/seed.sql`.

---

## How the important parts actually work

### 1. Tenant isolation is structural, not a filter you can forget

`shop_id` is **never** read from the request body or query string. It comes from the resolved
credential (`Auth::context()`), and every single SQL statement filters on it:

```php
$product = Db::one('SELECT ... FROM products WHERE id = ? AND shop_id = ?', [$id, $shopId]);
```

If a shop tries another shop's product ID, it gets a `404 PRODUCT_NOT_FOUND` — not a permission
error, because from its point of view the row does not exist. This is verified in the test suite
(group 15), including an attempted cross-shop sale.

### 2. Stock is a ledger, not a number

`inventory_movements` stores **signed deltas** (`+20 opening`, `-3 sale`, `+10 purchase`).
`products.stock_qty` is only a cache recomputed as `SUM(qty_change)`.

This is the design decision that makes offline multi-device sync work. Two phones that both sold
sugar while offline each push "−2", and the totals add up correctly regardless of arrival order. If
they had pushed absolute quantities, the last one to sync would silently erase the other's sale.

Duplicate detection uses `UNIQUE(shop_id, client_uuid)`; the sync engine reports `duplicate` as a
**success** so the phone clears its queue without ever double-posting.

### 3. Subscription expiry never destroys data

The state machine is `trial → active → grace → expired`. On `expired`:

- **reads still work** — dashboard, products, sales, reports, exports, backup
- **new writes are refused** with HTTP 402 and a plain-language message
- nothing is deleted, ever

Devices offline use `offline_write_until` from the **cached** subscription status, so a shop that
loses internet for a day keeps selling.

### 4. The accuracy trade-off you should know about

Per the brief, the smart recommendations come from Python. If the analytics service is not running
(a real possibility on cheap shared hosting), `Analytics.php` computes the same forecast in SQL and
labels the result `engine: "sql-fallback"` in the UI. The feature degrades; the app does not break.

---

## API surface (42 routes)

```
GET    /api/health                                  Public status
GET    /api/meta/plans                              Public package list
POST   /api/auth/register                           Create shop + owner + trial
POST   /api/auth/login                              Shop user login (token)
POST   /api/auth/admin-login                        Super admin login
GET    /api/auth/me  |  /api/auth/admin-me
POST   /api/auth/logout

GET    /api/admin/overview                          Platform KPIs, revenue series, plan mix
GET    /api/admin/shops  |  /api/admin/shop
POST   /api/admin/shop-status                       Activate / suspend / close
GET|POST /api/admin/plans                           Read / create-update packages
GET    /api/admin/payments
POST   /api/admin/verify-payment                    Verify + extend subscription
GET|POST /api/admin/announcements                   Broadcast to all shops
GET    /api/admin/support  |  /api/admin/logs  |  /api/admin/admins

GET    /api/products  |  /api/products/get  |  /api/products/categories
POST   /api/products  |  /api/products/delete  |  /api/products/categories
POST   /api/stock                                   Signed stock adjustment
GET    /api/sales  |  /api/sales/get
POST   /api/sales                                   Idempotent on client_uuid
POST   /api/sales/cancel                            Return with audit trail
GET    /api/customers  |  /api/customers/ledger
POST   /api/customers  |  /api/customers/pay
GET    /api/suppliers  |  POST /api/suppliers
GET    /api/purchases  |  POST /api/purchases
GET    /api/expenses   |  POST /api/expenses
GET    /api/dashboard
GET    /api/reports  |  /api/reports/export         JSON / CSV
GET    /api/analytics
GET|POST /api/employees
GET|POST /api/sync/pull  |  /api/sync/push  |  /api/sync/conflicts  |  /api/sync/repair
GET|POST /api/subscription
GET    /api/notifications
GET    /api/audit
GET|POST /api/settings
GET|POST /api/backup  |  /api/backup/download
GET|POST /api/support
```

---

## Security measures in place

- **Password hashing**: bcrypt, cost 11 (`password_hash`/`password_verify`).
- **SQL injection**: 100% prepared statements with bound parameters; no string interpolation of
  user input anywhere.
- **CSRF**: HMAC token derived from the bearer token, required on every non-GET request.
- **RBAC**: permission table per role, central middleware, plus an escalation guard that stops a
  non-owner assigning the owner role.
- **Session/token**: 64-hex bearer token, stored only as a SHA-256 hash, 72h expiry, revoked on
  suspension and logout.
- **Login throttling**: 8 attempts per 15 minutes per username.
- **XSS**: all output escaped with a single `esc()` helper before insertion into HTML.
- **Tenant isolation**: as described above, plus the cross-shop restore guard.
- **Audit trail**: logins, sales, cancellations, stock changes, payments, restores.

Uganda's Data Protection and Privacy Act (2019) expects lawful collection, purpose limitation,
security safeguards, and data-subject access. The design supports this through tenant isolation,
the audit log, per-shop backup/export, and the fact that a shop can leave with its data intact.

---

## License / academic use

Produced for the Innovation and Entrepreneurship coursework, Bachelor of Information Technology,
Faculty of Computing and Informatics, Mbarara University of Science and Technology.
