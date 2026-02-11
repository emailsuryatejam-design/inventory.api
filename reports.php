<?php
/**
 * KCL Stores — Reports
 * GET /api/reports.php?type=stock_summary
 * GET /api/reports.php?type=movement_history
 * GET /api/reports.php?type=order_summary
 * GET /api/reports.php?type=consumption
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$type = $_GET['type'] ?? '';
$campId = $_GET['camp_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

if (!$type) jsonError('Report type is required', 400);

// Camp staff can only see their own camp
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
    $campId = $auth['camp_id'];
}

switch ($type) {

    // ── Stock Summary Report ──
    case 'stock_summary':
        $where = [];
        $params = [];
        if ($campId) {
            $where[] = 'sb.camp_id = ?';
            $params[] = (int) $campId;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT c.code as camp_code, c.name as camp_name,
                   COUNT(*) as item_count,
                   SUM(sb.current_qty * COALESCE(i.weighted_avg_cost, 0)) as total_value,
                   SUM(CASE WHEN sb.current_qty <= COALESCE(sb.reorder_level, 5) THEN 1 ELSE 0 END) as low_stock_items
            FROM stock_balances sb
            JOIN camps c ON sb.camp_id = c.id
            JOIN items i ON sb.item_id = i.id
            {$whereClause}
            GROUP BY c.id, c.code, c.name
            ORDER BY c.code
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'report_type' => 'stock_summary',
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'data' => $rows,
        ]);
        break;

    // ── Stock Movement History ──
    case 'movement_history':
        $where = ["sm.created_at BETWEEN ? AND ?"];
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
        if ($campId) {
            $where[] = 'sm.camp_id = ?';
            $params[] = (int) $campId;
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT sm.id, sm.movement_type, sm.quantity, sm.reference_type, sm.reference_id,
                   sm.created_at,
                   i.item_code, i.name as item_name,
                   c.code as camp_code,
                   u.name as created_by_name
            FROM stock_movements sm
            JOIN items i ON sm.item_id = i.id
            JOIN camps c ON sm.camp_id = c.id
            LEFT JOIN users u ON sm.created_by = u.id
            {$whereClause}
            ORDER BY sm.created_at DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'report_type' => 'movement_history',
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'data' => $rows,
        ]);
        break;

    // ── Order Summary Report ──
    case 'order_summary':
        $where = ["o.created_at BETWEEN ? AND ?"];
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
        if ($campId) {
            $where[] = 'o.camp_id = ?';
            $params[] = (int) $campId;
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT c.code as camp_code, c.name as camp_name,
                   COUNT(*) as total_orders,
                   SUM(CASE WHEN o.status = 'received' THEN 1 ELSE 0 END) as completed_orders,
                   SUM(CASE WHEN o.status = 'stores_rejected' THEN 1 ELSE 0 END) as rejected_orders,
                   SUM(o.total_value) as total_value
            FROM orders o
            JOIN camps c ON o.camp_id = c.id
            {$whereClause}
            GROUP BY c.id, c.code, c.name
            ORDER BY c.code
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'report_type' => 'order_summary',
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'data' => $rows,
        ]);
        break;

    // ── Consumption Report ──
    case 'consumption':
        $where = ["iv.issue_date BETWEEN ? AND ?"];
        $params = [$dateFrom, $dateTo];
        if ($campId) {
            $where[] = 'iv.camp_id = ?';
            $params[] = (int) $campId;
        }
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT i.item_code, i.name as item_name,
                   g.code as group_code,
                   c.code as camp_code,
                   iv.issue_type,
                   SUM(il.quantity) as total_qty,
                   SUM(il.quantity * il.unit_cost) as total_value
            FROM issue_lines il
            JOIN issue_vouchers iv ON il.issue_voucher_id = iv.id
            JOIN items i ON il.item_id = i.id
            JOIN camps c ON iv.camp_id = c.id
            LEFT JOIN item_groups g ON i.item_group_id = g.id
            {$whereClause}
            GROUP BY i.id, i.item_code, i.name, g.code, c.code, iv.issue_type
            ORDER BY total_value DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        jsonResponse([
            'report_type' => 'consumption',
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'data' => $rows,
        ]);
        break;

    default:
        jsonError('Unknown report type. Available: stock_summary, movement_history, order_summary, consumption', 400);
}
