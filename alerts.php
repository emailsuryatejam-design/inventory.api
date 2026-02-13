<?php
/**
 * KCL Stores — Stock Alerts & Projections
 * GET /api/alerts.php                — all alerts summary
 * GET /api/alerts.php?type=low_stock — low stock items
 * GET /api/alerts.php?type=out_of_stock — out of stock items
 * GET /api/alerts.php?type=expiring  — items approaching expiry
 * GET /api/alerts.php?type=dead_stock — items with no movement
 * GET /api/alerts.php?type=projections — stock-out projections
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$type = $_GET['type'] ?? 'summary';
$campId = $_GET['camp_id'] ?? '';

// Camp staff can only see their own camp
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
    $campId = $auth['camp_id'];
}

$campFilter = '';
$campParams = [];
if ($campId) {
    $campFilter = 'AND sb.camp_id = ?';
    $campParams = [(int) $campId];
}

switch ($type) {

    // ── Summary — single query for all counts (was 6 separate queries) ──
    case 'summary':
        $summaryStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN sb.stock_status IN ('low', 'critical') THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN sb.stock_status = 'out' THEN 1 ELSE 0 END) as out_count,
                SUM(CASE WHEN sb.stock_status = 'critical' THEN 1 ELSE 0 END) as crit_count,
                SUM(CASE WHEN sb.days_since_last_movement >= 60 AND sb.current_qty > 0 THEN 1 ELSE 0 END) as dead_count,
                SUM(CASE WHEN sb.avg_daily_usage > 0 AND sb.current_qty > 0
                    AND (sb.current_qty / sb.avg_daily_usage) <= 7 THEN 1 ELSE 0 END) as proj7_count,
                SUM(CASE WHEN sb.stock_status = 'excess' THEN 1 ELSE 0 END) as excess_count
            FROM stock_balances sb
            WHERE 1=1 {$campFilter}
        ");
        $summaryStmt->execute($campParams);
        $s = $summaryStmt->fetch();

        $lowCount = (int) $s['low_count'];
        $outCount = (int) $s['out_count'];
        $critCount = (int) $s['crit_count'];
        $deadCount = (int) $s['dead_count'];
        $proj7Count = (int) $s['proj7_count'];
        $excessCount = (int) $s['excess_count'];

        jsonResponse([
            'alerts' => [
                'low_stock' => $lowCount,
                'out_of_stock' => $outCount,
                'critical' => $critCount,
                'dead_stock' => $deadCount,
                'stockout_7days' => $proj7Count,
                'excess_stock' => $excessCount,
                'total_alerts' => $lowCount + $outCount + $deadCount + $proj7Count,
            ],
        ]);
        break;

    // ── Low Stock Items ──
    case 'low_stock':
        $stmt = $pdo->prepare("
            SELECT sb.id, sb.item_id, sb.camp_id, sb.current_qty, sb.current_value,
                   sb.par_level, sb.min_level, sb.safety_stock,
                   sb.avg_daily_usage, sb.days_stock_on_hand, sb.stock_status,
                   sb.last_receipt_date, sb.last_issue_date,
                   i.item_code, i.name as item_name, i.is_critical, i.is_perishable,
                   g.code as group_code,
                   c.code as camp_code, c.name as camp_name,
                   uom.code as uom_code
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            JOIN camps c ON sb.camp_id = c.id
            LEFT JOIN item_groups g ON i.item_group_id = g.id
            LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
            WHERE sb.stock_status IN ('low', 'critical', 'out')
            {$campFilter}
            ORDER BY
                CASE sb.stock_status WHEN 'out' THEN 1 WHEN 'critical' THEN 2 WHEN 'low' THEN 3 END,
                i.is_critical DESC,
                sb.days_stock_on_hand ASC
            LIMIT 200
        ");
        $stmt->execute($campParams);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'type' => 'low_stock',
            'count' => count($rows),
            'items' => array_map(function($r) {
                $daysLeft = null;
                if ($r['avg_daily_usage'] > 0 && $r['current_qty'] > 0) {
                    $daysLeft = round($r['current_qty'] / $r['avg_daily_usage'], 1);
                }
                return [
                    'id' => (int) $r['id'],
                    'item_id' => (int) $r['item_id'],
                    'item_code' => $r['item_code'],
                    'item_name' => $r['item_name'],
                    'group_code' => $r['group_code'],
                    'uom' => $r['uom_code'],
                    'camp_code' => $r['camp_code'],
                    'camp_name' => $r['camp_name'],
                    'current_qty' => (float) $r['current_qty'],
                    'par_level' => $r['par_level'] ? (float) $r['par_level'] : null,
                    'min_level' => $r['min_level'] ? (float) $r['min_level'] : null,
                    'safety_stock' => $r['safety_stock'] ? (float) $r['safety_stock'] : null,
                    'avg_daily_usage' => $r['avg_daily_usage'] ? (float) $r['avg_daily_usage'] : null,
                    'days_left' => $daysLeft,
                    'stock_status' => $r['stock_status'],
                    'is_critical' => (bool) $r['is_critical'],
                    'is_perishable' => (bool) $r['is_perishable'],
                    'last_receipt_date' => $r['last_receipt_date'],
                    'last_issue_date' => $r['last_issue_date'],
                    'reorder_qty' => $r['par_level'] ? max(0, (float) $r['par_level'] - (float) $r['current_qty']) : null,
                ];
            }, $rows),
        ]);
        break;

    // ── Stock-Out Projections ──
    case 'projections':
        $days = (int) ($_GET['days'] ?? 14);
        $days = max(1, min(90, $days));

        $stmt = $pdo->prepare("
            SELECT sb.id, sb.item_id, sb.camp_id, sb.current_qty,
                   sb.par_level, sb.min_level, sb.avg_daily_usage,
                   sb.days_stock_on_hand, sb.stock_status,
                   i.item_code, i.name as item_name, i.is_critical,
                   g.code as group_code,
                   c.code as camp_code, c.name as camp_name,
                   uom.code as uom_code
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            JOIN camps c ON sb.camp_id = c.id
            LEFT JOIN item_groups g ON i.item_group_id = g.id
            LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
            WHERE sb.avg_daily_usage > 0
            AND sb.current_qty > 0
            AND (sb.current_qty / sb.avg_daily_usage) <= ?
            {$campFilter}
            ORDER BY (sb.current_qty / sb.avg_daily_usage) ASC
            LIMIT 200
        ");
        $stmt->execute(array_merge([$days], $campParams));
        $rows = $stmt->fetchAll();

        jsonResponse([
            'type' => 'projections',
            'days_horizon' => $days,
            'count' => count($rows),
            'items' => array_map(function($r) {
                $daysLeft = round((float) $r['current_qty'] / (float) $r['avg_daily_usage'], 1);
                $stockoutDate = date('Y-m-d', strtotime("+{$daysLeft} days"));
                $reorderQty = $r['par_level']
                    ? max(0, (float) $r['par_level'] - (float) $r['current_qty'])
                    : (float) $r['avg_daily_usage'] * 14; // 2 weeks supply

                return [
                    'item_id' => (int) $r['item_id'],
                    'item_code' => $r['item_code'],
                    'item_name' => $r['item_name'],
                    'group_code' => $r['group_code'],
                    'uom' => $r['uom_code'],
                    'camp_code' => $r['camp_code'],
                    'camp_name' => $r['camp_name'],
                    'current_qty' => (float) $r['current_qty'],
                    'avg_daily_usage' => (float) $r['avg_daily_usage'],
                    'days_until_stockout' => $daysLeft,
                    'projected_stockout_date' => $stockoutDate,
                    'is_critical' => (bool) $r['is_critical'],
                    'stock_status' => $r['stock_status'],
                    'suggested_reorder_qty' => round($reorderQty, 1),
                ];
            }, $rows),
        ]);
        break;

    // ── Dead Stock ──
    case 'dead_stock':
        $minDays = (int) ($_GET['min_days'] ?? 60);

        $stmt = $pdo->prepare("
            SELECT sb.id, sb.item_id, sb.camp_id, sb.current_qty, sb.current_value,
                   sb.days_since_last_movement,
                   sb.last_receipt_date, sb.last_issue_date,
                   i.item_code, i.name as item_name,
                   g.code as group_code,
                   c.code as camp_code, c.name as camp_name,
                   uom.code as uom_code
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            JOIN camps c ON sb.camp_id = c.id
            LEFT JOIN item_groups g ON i.item_group_id = g.id
            LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
            WHERE sb.days_since_last_movement >= ?
            AND sb.current_qty > 0
            {$campFilter}
            ORDER BY sb.days_since_last_movement DESC
            LIMIT 200
        ");
        $stmt->execute(array_merge([$minDays], $campParams));
        $rows = $stmt->fetchAll();

        jsonResponse([
            'type' => 'dead_stock',
            'min_days' => $minDays,
            'count' => count($rows),
            'total_value' => array_sum(array_column($rows, 'current_value')),
            'items' => array_map(function($r) {
                return [
                    'item_id' => (int) $r['item_id'],
                    'item_code' => $r['item_code'],
                    'item_name' => $r['item_name'],
                    'group_code' => $r['group_code'],
                    'uom' => $r['uom_code'],
                    'camp_code' => $r['camp_code'],
                    'camp_name' => $r['camp_name'],
                    'current_qty' => (float) $r['current_qty'],
                    'current_value' => (float) $r['current_value'],
                    'days_no_movement' => (int) $r['days_since_last_movement'],
                    'last_receipt_date' => $r['last_receipt_date'],
                    'last_issue_date' => $r['last_issue_date'],
                ];
            }, $rows),
        ]);
        break;

    // ── Excess Stock ──
    case 'excess':
        $stmt = $pdo->prepare("
            SELECT sb.id, sb.item_id, sb.camp_id, sb.current_qty, sb.current_value,
                   sb.max_level, sb.par_level, sb.avg_daily_usage, sb.days_stock_on_hand,
                   i.item_code, i.name as item_name,
                   g.code as group_code,
                   c.code as camp_code, c.name as camp_name,
                   uom.code as uom_code
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            JOIN camps c ON sb.camp_id = c.id
            LEFT JOIN item_groups g ON i.item_group_id = g.id
            LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
            WHERE sb.stock_status = 'excess'
            {$campFilter}
            ORDER BY sb.current_value DESC
            LIMIT 200
        ");
        $stmt->execute($campParams);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'type' => 'excess',
            'count' => count($rows),
            'total_excess_value' => array_sum(array_column($rows, 'current_value')),
            'items' => array_map(function($r) {
                return [
                    'item_id' => (int) $r['item_id'],
                    'item_code' => $r['item_code'],
                    'item_name' => $r['item_name'],
                    'group_code' => $r['group_code'],
                    'uom' => $r['uom_code'],
                    'camp_code' => $r['camp_code'],
                    'camp_name' => $r['camp_name'],
                    'current_qty' => (float) $r['current_qty'],
                    'current_value' => (float) $r['current_value'],
                    'max_level' => $r['max_level'] ? (float) $r['max_level'] : null,
                    'par_level' => $r['par_level'] ? (float) $r['par_level'] : null,
                    'days_stock_on_hand' => $r['days_stock_on_hand'] ? (float) $r['days_stock_on_hand'] : null,
                    'excess_qty' => $r['max_level']
                        ? max(0, (float) $r['current_qty'] - (float) $r['max_level'])
                        : null,
                ];
            }, $rows),
        ]);
        break;

    default:
        jsonError('Unknown alert type. Available: summary, low_stock, projections, dead_stock, excess', 400);
}
