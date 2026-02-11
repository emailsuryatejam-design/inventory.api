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
    $where[] = 'sb.current_qty <= COALESCE(sb.reorder_level, sb.par_level * 0.3, 5)';
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
    SELECT sb.id, sb.item_id, sb.current_qty, sb.par_level, sb.reorder_level,
           sb.avg_daily_usage, sb.last_count_date, sb.updated_at,
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
$camp = $pdo->prepare("SELECT code, name FROM camps WHERE id = ?")->execute([$campId]);
$camp = $pdo->prepare("SELECT code, name FROM camps WHERE id = ?");
$camp->execute([$campId]);
$campInfo = $camp->fetch();

// Summary
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_items,
        SUM(sb.current_qty * COALESCE(i.weighted_avg_cost, 0)) as total_value,
        SUM(CASE WHEN sb.current_qty <= COALESCE(sb.reorder_level, sb.par_level * 0.3, 5) THEN 1 ELSE 0 END) as low_stock_count
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    WHERE sb.camp_id = ? AND sb.current_qty > 0
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
    ],
    'stock' => array_map(function($s) {
        $qty = (float) $s['current_qty'];
        $parLevel = $s['par_level'] ? (float) $s['par_level'] : null;
        $stockLevel = 'normal';
        if ($parLevel && $qty <= $parLevel * 0.3) $stockLevel = 'critical';
        elseif ($parLevel && $qty <= $parLevel * 0.6) $stockLevel = 'low';

        return [
            'id' => (int) $s['id'],
            'item_id' => (int) $s['item_id'],
            'item_code' => $s['item_code'],
            'item_name' => $s['item_name'],
            'group_code' => $s['group_code'],
            'uom' => $s['uom_code'],
            'current_qty' => $qty,
            'par_level' => $parLevel,
            'reorder_level' => $s['reorder_level'] ? (float) $s['reorder_level'] : null,
            'avg_daily_usage' => $s['avg_daily_usage'] ? (float) $s['avg_daily_usage'] : null,
            'stock_value' => $qty * (float) ($s['weighted_avg_cost'] ?? 0),
            'stock_level' => $stockLevel,
            'last_count_date' => $s['last_count_date'],
        ];
    }, $stock),
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => ceil($total / $perPage),
    ],
]);
