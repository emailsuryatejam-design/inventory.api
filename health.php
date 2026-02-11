<?php
/**
 * KCL Stores — Health Check
 * GET /api/health.php
 */

require_once __DIR__ . '/config.php';

$health = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'service' => 'kcl-stores-api',
];

try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    $health['database'] = 'connected';

    // Get some stats
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM items');
    $health['items_count'] = (int) $stmt->fetch()['count'];

    $stmt = $pdo->query('SELECT COUNT(*) as count FROM camps');
    $health['camps_count'] = (int) $stmt->fetch()['count'];

    $stmt = $pdo->query('SELECT COUNT(*) as count FROM stock_balances');
    $health['stock_balances'] = (int) $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sap_import_date'");
    $row = $stmt->fetch();
    $health['sap_import_date'] = $row ? $row['setting_value'] : 'not imported';

} catch (Exception $e) {
    $health['database'] = 'error';
    $health['db_error'] = $e->getMessage();
    $health['status'] = 'degraded';
}

jsonResponse($health);
