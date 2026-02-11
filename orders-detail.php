<?php
/**
 * KCL Stores — Order Detail
 * GET /api/orders-detail.php?id=123
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) jsonError('Order ID required', 400);

// Get order
$stmt = $pdo->prepare("
    SELECT o.*,
           c.code as camp_code, c.name as camp_name,
           u.name as created_by_name,
           sm.name as stores_manager_name,
           po.name as procurement_officer_name
    FROM orders o
    JOIN camps c ON o.camp_id = c.id
    LEFT JOIN users u ON o.created_by = u.id
    LEFT JOIN users sm ON o.stores_manager_id = sm.id
    LEFT JOIN users po ON o.procurement_officer_id = po.id
    WHERE o.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) jsonError('Order not found', 404);

// Camp staff can only see their own camp's orders
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager'])
    && $auth['camp_id'] && (int) $order['camp_id'] !== (int) $auth['camp_id']) {
    jsonError('Access denied', 403);
}

// Get order lines with item details
$linesStmt = $pdo->prepare("
    SELECT ol.*,
           i.item_code, i.name as item_name,
           g.code as group_code, g.name as group_name,
           u.code as uom_code
    FROM order_lines ol
    JOIN items i ON ol.item_id = i.id
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
    WHERE ol.order_id = ?
    ORDER BY i.item_code
");
$linesStmt->execute([$id]);
$lines = $linesStmt->fetchAll();

// Get queries/messages
$queriesStmt = $pdo->prepare("
    SELECT oq.*, u.name as sender_name, u.role as sender_role
    FROM order_queries oq
    LEFT JOIN users u ON oq.sender_id = u.id
    WHERE oq.order_id = ?
    ORDER BY oq.created_at ASC
");
$queriesStmt->execute([$id]);
$queries = $queriesStmt->fetchAll();

jsonResponse([
    'order' => [
        'id' => (int) $order['id'],
        'order_number' => $order['order_number'],
        'camp_id' => (int) $order['camp_id'],
        'camp_code' => $order['camp_code'],
        'camp_name' => $order['camp_name'],
        'status' => $order['status'],
        'total_items' => (int) $order['total_items'],
        'total_value' => (float) $order['total_value'],
        'flagged_items' => (int) $order['flagged_items'],
        'notes' => $order['notes'],
        'created_by' => $order['created_by_name'],
        'stores_manager' => $order['stores_manager_name'],
        'stores_reviewed_at' => $order['stores_reviewed_at'],
        'stores_notes' => $order['stores_notes'],
        'procurement_officer' => $order['procurement_officer_name'],
        'procurement_processed_at' => $order['procurement_processed_at'],
        'submitted_at' => $order['submitted_at'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
    ],
    'lines' => array_map(function($l) {
        return [
            'id' => (int) $l['id'],
            'item_id' => (int) $l['item_id'],
            'item_code' => $l['item_code'],
            'item_name' => $l['item_name'],
            'group_code' => $l['group_code'],
            'uom' => $l['uom_code'],
            'requested_qty' => (float) $l['requested_qty'],
            'approved_qty' => $l['approved_qty'] !== null ? (float) $l['approved_qty'] : null,
            'camp_stock' => (float) $l['camp_stock_at_order'],
            'ho_stock' => (float) $l['ho_stock_at_order'],
            'par_level' => $l['par_level'] ? (float) $l['par_level'] : null,
            'avg_daily_usage' => $l['avg_daily_usage'] ? (float) $l['avg_daily_usage'] : null,
            'unit_cost' => (float) $l['estimated_unit_cost'],
            'line_value' => (float) $l['estimated_line_value'],
            'validation_status' => $l['validation_status'],
            'validation_note' => $l['validation_note'],
            'stores_action' => $l['stores_action'],
            'stores_note' => $l['stores_note'],
        ];
    }, $lines),
    'queries' => array_map(function($q) {
        return [
            'id' => (int) $q['id'],
            'sender' => $q['sender_name'],
            'sender_role' => $q['sender_role'],
            'message' => $q['message'],
            'is_read' => (bool) $q['is_read'],
            'created_at' => $q['created_at'],
            'line_id' => $q['order_line_id'] ? (int) $q['order_line_id'] : null,
        ];
    }, $queries),
]);
