<?php
/**
 * KCL Stores — Dispatch Detail
 * GET /api/dispatch-detail.php?id=123
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) jsonError('Dispatch ID required', 400);

// Get dispatch with related info
$stmt = $pdo->prepare("
    SELECT d.*,
           o.order_number,
           c.code as camp_code, c.name as camp_name,
           u.name as dispatched_by_name
    FROM dispatches d
    JOIN orders o ON d.order_id = o.id
    JOIN camps c ON d.camp_id = c.id
    LEFT JOIN users u ON d.dispatched_by = u.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$dispatch = $stmt->fetch();

if (!$dispatch) jsonError('Dispatch not found', 404);

// Camp staff access control
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager'])
    && $auth['camp_id'] && (int) $dispatch['camp_id'] !== (int) $auth['camp_id']) {
    jsonError('Access denied', 403);
}

// Get dispatch lines
$linesStmt = $pdo->prepare("
    SELECT dl.id, dl.dispatch_id, dl.item_id, dl.dispatched_qty, dl.unit_cost, dl.total_value,
           dl.batch_number, dl.expiry_date, dl.source,
           i.item_code, i.name as item_name,
           g.code as group_code,
           uom.code as uom_code
    FROM dispatch_lines dl
    JOIN items i ON dl.item_id = i.id
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
    WHERE dl.dispatch_id = ?
    ORDER BY i.item_code
");
$linesStmt->execute([$id]);
$lines = $linesStmt->fetchAll();

jsonResponse([
    'dispatch' => [
        'id' => (int) $dispatch['id'],
        'dispatch_number' => $dispatch['dispatch_number'],
        'order_id' => (int) $dispatch['order_id'],
        'order_number' => $dispatch['order_number'],
        'camp_id' => (int) $dispatch['camp_id'],
        'camp_code' => $dispatch['camp_code'],
        'camp_name' => $dispatch['camp_name'],
        'status' => $dispatch['status'],
        'total_value' => (float) ($dispatch['total_value'] ?? 0),
        'dispatched_by' => $dispatch['dispatched_by_name'],
        'dispatched_at' => $dispatch['dispatch_date'],
        'vehicle_number' => $dispatch['vehicle_details'],
        'driver_name' => $dispatch['driver_name'],
        'notes' => $dispatch['notes'],
        'created_at' => $dispatch['created_at'],
    ],
    'lines' => array_map(function($l) {
        return [
            'id' => (int) $l['id'],
            'item_id' => (int) $l['item_id'],
            'item_code' => $l['item_code'],
            'item_name' => $l['item_name'],
            'group_code' => $l['group_code'],
            'uom' => $l['uom_code'],
            'dispatched_qty' => (float) $l['dispatched_qty'],
            'unit_cost' => (float) $l['unit_cost'],
            'total_value' => (float) $l['total_value'],
            'batch_number' => $l['batch_number'],
            'expiry_date' => $l['expiry_date'],
            'source' => $l['source'],
        ];
    }, $lines),
]);
