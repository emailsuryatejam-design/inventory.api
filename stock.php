<?php
/**
 * KCL Stores — Stock Overview (All Camps or Single Camp)
 * GET /api/stock.php?camp_id=1&page=1&per_page=25&search=&status=&group=
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();

// Params
$campId = (int) ($_GET['camp_id'] ?? $auth['camp_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$groupId = $_GET['group'] ?? '';

// Build query
$where = ['sb.current_qty > 0 OR sb.par_level > 0'];
$params = [];

if ($campId) {
    $where[] = 'sb.camp_id = ?';
    $params[] = $campId;
}

if ($search) {
    $where[] = '(i.item_code LIKE ? OR i.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($status && in_array($status, ['excess', 'ok', 'low', 'critical', 'out'])) {
    $where[] = 'sb.stock_status = ?';
    $params[] = $status;
}

if ($groupId) {
    $where[] = 'i.item_group_id = ?';
    $params[] = (int) $groupId;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    {$whereClause}
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Data
$sql = "
    SELECT
        sb.id, sb.camp_id, sb.item_id,
        sb.current_qty, sb.current_value, sb.unit_cost,
        sb.par_level, sb.min_level, sb.max_level, sb.safety_stock,
        sb.avg_daily_usage, sb.days_stock_on_hand, sb.stock_status,
        sb.last_receipt_date, sb.last_issue_date, sb.days_since_last_movement,
        i.item_code, i.name as item_name, i.abc_class, i.is_critical, i.is_perishable,
        i.storage_type, i.shelf_life_days,
        g.code as group_code, g.name as group_name,
        u.code as uom_code,
        c.code as camp_code, c.name as camp_name
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    JOIN camps c ON sb.camp_id = c.id
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
    {$whereClause}
    ORDER BY
        FIELD(sb.stock_status, 'out', 'critical', 'low', 'ok', 'excess'),
        i.item_code ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Summary stats
$summaryParams = $campId ? [$campId] : [];
$campFilter = $campId ? 'WHERE sb.camp_id = ?' : '';

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_items,
        SUM(sb.current_value) as total_value,
        SUM(CASE WHEN sb.stock_status = 'critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN sb.stock_status = 'low' THEN 1 ELSE 0 END) as low_count,
        SUM(CASE WHEN sb.stock_status = 'out' THEN 1 ELSE 0 END) as out_count,
        SUM(CASE WHEN sb.stock_status = 'ok' THEN 1 ELSE 0 END) as ok_count,
        SUM(CASE WHEN sb.stock_status = 'excess' THEN 1 ELSE 0 END) as excess_count
    FROM stock_balances sb
    {$campFilter}
");
$summaryStmt->execute($summaryParams);
$summary = $summaryStmt->fetch();

// Load camps and groups for filters
$camps = $pdo->query('SELECT id, code, name, type FROM camps WHERE is_active = 1 ORDER BY name')->fetchAll();
$groups = $pdo->query('SELECT id, code, name FROM item_groups ORDER BY name')->fetchAll();

jsonResponse([
    'stock' => array_map(function($r) {
        return [
            'id' => (int) $r['id'],
            'camp_id' => (int) $r['camp_id'],
            'camp_code' => $r['camp_code'],
            'camp_name' => $r['camp_name'],
            'item_id' => (int) $r['item_id'],
            'item_code' => $r['item_code'],
            'item_name' => $r['item_name'],
            'group_code' => $r['group_code'],
            'group_name' => $r['group_name'],
            'uom' => $r['uom_code'],
            'abc_class' => $r['abc_class'],
            'is_critical' => (bool) $r['is_critical'],
            'is_perishable' => (bool) $r['is_perishable'],
            'storage_type' => $r['storage_type'],
            'current_qty' => (float) $r['current_qty'],
            'current_value' => (float) $r['current_value'],
            'unit_cost' => (float) $r['unit_cost'],
            'par_level' => $r['par_level'] ? (float) $r['par_level'] : null,
            'min_level' => $r['min_level'] ? (float) $r['min_level'] : null,
            'max_level' => $r['max_level'] ? (float) $r['max_level'] : null,
            'safety_stock' => $r['safety_stock'] ? (float) $r['safety_stock'] : null,
            'avg_daily_usage' => $r['avg_daily_usage'] ? (float) $r['avg_daily_usage'] : null,
            'days_stock_on_hand' => $r['days_stock_on_hand'] ? (float) $r['days_stock_on_hand'] : null,
            'stock_status' => $r['stock_status'],
            'last_receipt_date' => $r['last_receipt_date'],
            'last_issue_date' => $r['last_issue_date'],
            'days_since_last_movement' => $r['days_since_last_movement'] ? (int) $r['days_since_last_movement'] : null,
        ];
    }, $rows),
    'summary' => [
        'total_items' => (int) ($summary['total_items'] ?? 0),
        'total_value' => (float) ($summary['total_value'] ?? 0),
        'critical_count' => (int) ($summary['critical_count'] ?? 0),
        'low_count' => (int) ($summary['low_count'] ?? 0),
        'out_count' => (int) ($summary['out_count'] ?? 0),
        'ok_count' => (int) ($summary['ok_count'] ?? 0),
        'excess_count' => (int) ($summary['excess_count'] ?? 0),
    ],
    'camps' => array_map(function($c) {
        return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type']];
    }, $camps),
    'groups' => array_map(function($g) {
        return ['id' => (int) $g['id'], 'code' => $g['code'], 'name' => $g['name']];
    }, $groups),
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => ceil($total / $perPage),
    ],
]);
