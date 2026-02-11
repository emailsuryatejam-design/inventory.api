<?php
/**
 * KCL Stores — Dashboard Stats
 * GET /api/dashboard.php?camp_id=1
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$campId = (int) ($_GET['camp_id'] ?? $auth['camp_id'] ?? 0);

$campFilter = $campId ? 'AND camp_id = ?' : '';
$campParams = $campId ? [$campId] : [];

// Pending orders (submitted, pending_review)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE status IN ('submitted', 'pending_review') {$campFilter}
");
$stmt->execute($campParams);
$pendingOrders = (int) $stmt->fetchColumn();

// Low stock items
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM stock_balances
    WHERE stock_status IN ('low', 'critical', 'out')
    " . ($campId ? 'AND camp_id = ?' : '') . "
");
$stmt->execute($campParams);
$lowStockItems = (int) $stmt->fetchColumn();

// Pending receipts (dispatched but not received)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM dispatches
    WHERE status IN ('dispatched', 'in_transit')
    " . ($campId ? 'AND camp_id = ?' : '') . "
");
$stmt->execute($campParams);
$pendingReceipts = (int) $stmt->fetchColumn();

// Issues today
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM issue_vouchers
    WHERE DATE(issue_date) = ?
    " . ($campId ? 'AND camp_id = ?' : '') . "
");
$issueParams = [$today];
if ($campId) $issueParams[] = $campId;
$stmt->execute($issueParams);
$issuesToday = (int) $stmt->fetchColumn();

// Total stock value
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(current_value), 0) FROM stock_balances
    " . ($campId ? 'WHERE camp_id = ?' : '') . "
");
$stmt->execute($campParams);
$totalStockValue = (float) $stmt->fetchColumn();

// Items count
$itemsCount = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE is_active = 1')->fetchColumn();

// Recent orders (last 5)
$recentOrdersSql = "
    SELECT o.id, o.order_number, o.status, o.total_value,
           c.code as camp_code, c.name as camp_name,
           o.created_at, u.name as created_by_name
    FROM orders o
    JOIN camps c ON o.camp_id = c.id
    LEFT JOIN users u ON o.created_by = u.id
    " . ($campId ? 'WHERE o.camp_id = ?' : '') . "
    ORDER BY o.created_at DESC
    LIMIT 5
";
$stmt = $pdo->prepare($recentOrdersSql);
$stmt->execute($campParams);
$recentOrders = $stmt->fetchAll();

// Low stock alerts (top 10 most critical)
$lowStockSql = "
    SELECT sb.item_id, i.item_code, i.name as item_name,
           sb.current_qty, sb.par_level, sb.min_level, sb.stock_status,
           u.code as uom,
           c.code as camp_code, c.name as camp_name
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    JOIN camps c ON sb.camp_id = c.id
    LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
    WHERE sb.stock_status IN ('low', 'critical', 'out')
    " . ($campId ? 'AND sb.camp_id = ?' : '') . "
    ORDER BY FIELD(sb.stock_status, 'out', 'critical', 'low')
    LIMIT 10
";
$stmt = $pdo->prepare($lowStockSql);
$stmt->execute($campParams);
$lowStockAlerts = $stmt->fetchAll();

jsonResponse([
    'pending_orders' => $pendingOrders,
    'low_stock_items' => $lowStockItems,
    'pending_receipts' => $pendingReceipts,
    'issues_today' => $issuesToday,
    'total_stock_value' => $totalStockValue,
    'items_count' => $itemsCount,
    'recent_orders' => array_map(function($o) {
        return [
            'id' => (int) $o['id'],
            'order_number' => $o['order_number'],
            'status' => $o['status'],
            'total_value' => (float) $o['total_value'],
            'camp_code' => $o['camp_code'],
            'camp_name' => $o['camp_name'],
            'created_by' => $o['created_by_name'],
            'created_at' => $o['created_at'],
        ];
    }, $recentOrders),
    'low_stock_alerts' => array_map(function($a) {
        return [
            'item_id' => (int) $a['item_id'],
            'item_code' => $a['item_code'],
            'item_name' => $a['item_name'],
            'current_qty' => (float) $a['current_qty'],
            'par_level' => $a['par_level'] ? (float) $a['par_level'] : null,
            'min_level' => $a['min_level'] ? (float) $a['min_level'] : null,
            'stock_status' => $a['stock_status'],
            'uom' => $a['uom'],
            'camp_code' => $a['camp_code'],
            'camp_name' => $a['camp_name'],
        ];
    }, $lowStockAlerts),
]);
