<?php
/**
 * KCL Stores — Issue Voucher Detail
 * GET /api/issue-detail.php?id=123 — get issue voucher with lines
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) jsonError('Issue voucher ID required', 400);

// Issue voucher header
$stmt = $pdo->prepare("
    SELECT iv.*, c.code as camp_code, c.name as camp_name,
           cc.code as cost_center_code, cc.name as cost_center_name,
           u.name as issued_by_name
    FROM issue_vouchers iv
    JOIN camps c ON iv.camp_id = c.id
    LEFT JOIN cost_centers cc ON iv.cost_center_id = cc.id
    LEFT JOIN users u ON iv.issued_by = u.id
    WHERE iv.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch();

if (!$voucher) jsonError('Issue voucher not found', 404);

// Access control
if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id'] && (int) $voucher['camp_id'] !== (int) $auth['camp_id']) {
    jsonError('Access denied', 403);
}

// Issue lines
$linesStmt = $pdo->prepare("
    SELECT ivl.*, i.item_code, i.name as item_name,
           ig.code as group_code,
           uom.code as uom_code
    FROM issue_voucher_lines ivl
    JOIN items i ON ivl.item_id = i.id
    LEFT JOIN item_groups ig ON i.item_group_id = ig.id
    LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
    WHERE ivl.voucher_id = ?
    ORDER BY ivl.id
");
$linesStmt->execute([$id]);
$lines = $linesStmt->fetchAll();

jsonResponse([
    'voucher' => [
        'id' => (int) $voucher['id'],
        'voucher_number' => $voucher['voucher_number'],
        'camp_code' => $voucher['camp_code'],
        'camp_name' => $voucher['camp_name'],
        'issue_type' => $voucher['issue_type'],
        'cost_center_code' => $voucher['cost_center_code'],
        'cost_center_name' => $voucher['cost_center_name'],
        'issue_date' => $voucher['issue_date'],
        'issued_by' => $voucher['issued_by_name'],
        'received_by_name' => $voucher['received_by_name'],
        'department' => $voucher['department'],
        'room_numbers' => $voucher['room_numbers'],
        'guest_count' => $voucher['guest_count'] ? (int) $voucher['guest_count'] : null,
        'total_value' => (float) $voucher['total_value'],
        'status' => $voucher['status'],
        'notes' => $voucher['notes'],
        'created_at' => $voucher['created_at'],
    ],
    'lines' => array_map(function($l) {
        return [
            'id' => (int) $l['id'],
            'item_id' => (int) $l['item_id'],
            'item_code' => $l['item_code'],
            'item_name' => $l['item_name'],
            'group_code' => $l['group_code'],
            'uom' => $l['uom_code'],
            'quantity' => (float) $l['quantity'],
            'unit_cost' => (float) $l['unit_cost'],
            'total_value' => (float) $l['total_value'],
            'notes' => $l['notes'],
        ];
    }, $lines),
]);
