<?php
declare(strict_types=1);

/** Bootstrap: config, autoload-ish requires, error handling, timezone. */
$root = dirname(__DIR__);
require $root . '/backend/src/Core.php';
Config::load($root . '/config/config.php');

date_default_timezone_set((string) Config::get('app.timezone', 'Africa/Kampala'));
mb_internal_encoding('UTF-8');

require $root . '/backend/src/Db.php';
require $root . '/backend/src/Security.php';
require $root . '/backend/src/Auth.php';
require $root . '/backend/src/StockService.php';
require $root . '/backend/src/ShopService.php';
require $root . '/backend/src/SyncService.php';
require $root . '/backend/src/AdminService.php';
require $root . '/backend/src/Analytics.php';
require $root . '/backend/src/Registration.php';
require $root . '/backend/src/Subscription.php';
require $root . '/backend/src/Exporter.php'; // Exporter + Backup

if (Config::get('app.debug')) {
    ini_set('display_errors', '0'); // errors are returned as JSON, never as HTML
    error_reporting(E_ALL);
}

set_exception_handler(function (Throwable $e): void {
    error_log('[smartbook] ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => ['code' => 'FATAL', 'message' => 'Unexpected server error.']]);
});
