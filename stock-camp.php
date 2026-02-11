<?php
/**
 * KCL Stores — Camp Stock
 * GET /api/stock-camp.php?camp_id=1 — stock for a specific camp
 */

require_once __DIR__ . '/middleware.php';
$auth = requireAuth();

$pdo = getDB();
$campId = (int) ($_GET['camp_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$group = $_GET['group'] ?? '';
$lowStockOnly = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';

if (!$campId) jsonError('camp_id is required', 400);

// Camp staff can only see their own camp's stock
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager'])
    && $auth['camp_id'] && $campId !== (int) $auth['camp_id']) {
    jsonError('Access denied', 403);
}

$where = ['sb.camp_id = ?'];
$params = [$campId];

if ($search) {
    $where[] = '(i.item_code LIKE ? OR i.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($group) {
    $where[] = 'g.code = ?';
    $params[] = $group;
}

if ($lowStockOnly) {
    $where[] = "sb.stock_status IN ('low', 'critical', 'out')";
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    {$whereClause}
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Data
$stmt = $pdo->prepare("
    SELECT sb.id, sb.item_id, sb.current_qty, sb.current_value, sb.unit_cost,
           sb.par_level, sb.min_level, sb.max_level, sb.safety_stock,
           sb.avg_daily_usage, sb.days_stock_on_hand, sb.stock_status,
           sb.last_count_date, sb.last_receipt_date, sb.last_issue_date,
           sb.days_since_last_movement, sb.updated_at,
           i.item_code, i.name as item_name, i.weighted_avg_cost,
           g.code as group_code, g.name as group_name,
           uom.code as uom_code
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
    {$whereClause}
    ORDER BY i.item_code
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$stock = $stmt->fetchAll();

// Camp info
$campStmt = $pdo->prepare("SELECT code, name FROM camps WHERE id = ?");
$campStmt->execute([$campId]);
$campInfo = $campStmt->fetch();

// Summary
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_items,
        SUM(sb.current_value) as total_value,
        SUM(CASE WHEN sb.stock_status IN ('low', 'critical', 'out') THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN sb.stock_status = 'out' THEN 1 ELSE 0 END) as out_of_stock_count
    FROM stock_balances sb
    WHERE sb.camp_id = ?
");
$summaryStmt->execute([$campId]);
$summary = $summaryStmt->fetch();

jsonResponse([
    'camp' => [
        'id' => $campId,
        'code' => $campInfo['code'] ?? '',
        'name' => $campInfo['name'] ?? '',
    ],
    'summary' => [
        'total_items' => (int) ($summary['total_items'] ?? 0),
        'total_value' => (float) ($summary['total_value'] ?? 0),
        'low_stock_count' => (int) ($summary['low_stock_count'] ?? 0),
        'out_of_stock_count' => (int) ($summary['out_of_stock_count'] ?? 0),
    ],
    'stock' => array_map(function($s) {
        return [
            'id' => (int) $s['id'],
            'item_id' => (int) $s['item_id'],
            'item_code' => $s['item_code'],
            'item_name' => $s['item_name'],
            'group_code' => $s['group_code'],
            'uom' => $s['uom_code'],
            'current_qty' => (float) $s['current_qty'],
            'current_value' => (float) $s['current_value'],
            'unit_cost' => (float) $s['unit_cost'],
            'par_level' => $s['par_level'] ? (float) $s['par_level'] : null,
            'min_level' => $s['min_level'] ? (float) $s['min_level'] : null,
            'max_level' => $s['max_level'] ? (float) $s['max_level'] : null,
            'safety_stock' => $s['safety_stock'] ? (float) $s['safety_stock'] : null,
            'avg_daily_usage' => $s['avg_daily_usage'] ? (float) $s['avg_daily_usage'] : null,
            'days_stock_on_hand' => $s['days_stock_on_hand'] ? (float) $s['days_stock_on_hand'] : null,
            'stock_status' => $s['stock_status'],
            'stock_value' => (float) $s['current_value'],
            'last_count_date' => $s['last_count_date'],
            'last_receipt_date' => $s['last_receipt_date'],
            'last_issue_date' => $s['last_issue_date'],
        ];
    }, $stock),
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => ceil($total / $perPage),
    ],
]);
