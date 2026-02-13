<?php
/**
 * KCL Stores — Dashboard Stats
 * GET /api/dashboard.php?camp_id=1
 * Optimized: single query for all counts (was 6 separate queries)
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$campId = (int) ($_GET['camp_id'] ?? $auth['camp_id'] ?? 0);
$today = date('Y-m-d');

// ── Single query for all counts ──
$countsSql = "
    SELECT
        (SELECT COUNT(*) FROM orders WHERE status IN ('submitted', 'pending_review')
            " . ($campId ? "AND camp_id = {$campId}" : '') . ") as pending_orders,
        (SELECT COUNT(*) FROM stock_balances WHERE stock_status IN ('low', 'critical', 'out')
            " . ($campId ? "AND camp_id = {$campId}" : '') . ") as low_stock_items,
        (SELECT COUNT(*) FROM dispatches WHERE status IN ('dispatched', 'in_transit')
            " . ($campId ? "AND camp_id = {$campId}" : '') . ") as pending_receipts,
        (SELECT COUNT(*) FROM issue_vouchers WHERE DATE(issue_date) = '{$today}'
            " . ($campId ? "AND camp_id = {$campId}" : '') . ") as issues_today,
        (SELECT COALESCE(SUM(current_value), 0) FROM stock_balances
            " . ($campId ? "WHERE camp_id = {$campId}" : '') . ") as total_stock_value,
        (SELECT COUNT(*) FROM items WHERE is_active = 1) as items_count
";
$counts = $pdo->query($countsSql)->fetch();

$campParams = $campId ? [$campId] : [];

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
    ORDER BY
        CASE sb.stock_status WHEN 'out' THEN 1 WHEN 'critical' THEN 2 WHEN 'low' THEN 3 END,
        sb.current_qty ASC
    LIMIT 10
";
$stmt = $pdo->prepare($lowStockSql);
$stmt->execute($campParams);
$lowStockAlerts = $stmt->fetchAll();

jsonResponse([
    'pending_orders' => (int) $counts['pending_orders'],
    'low_stock_items' => (int) $counts['low_stock_items'],
    'pending_receipts' => (int) $counts['pending_receipts'],
    'issues_today' => (int) $counts['issues_today'],
    'total_stock_value' => (float) $counts['total_stock_value'],
    'items_count' => (int) $counts['items_count'],
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
