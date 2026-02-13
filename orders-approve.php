<?php
/**
 * KCL Stores — Approve/Adjust Order
 * PUT /api/orders-approve.php
 * Body: { order_id, notes?, lines: [{ line_id, action: 'approved'|'adjusted'|'rejected', approved_qty?, note? }] }
 */

require_once __DIR__ . '/middleware.php';
requireMethod('PUT');
$auth = requireAuth();

// Only stores managers and above can approve
if (!in_array($auth['role'], ['stores_manager', 'director', 'admin'])) {
    jsonError('Only Stores Manager can approve orders', 403);
}

$input = getJsonInput();
requireFields($input, ['order_id', 'lines']);

$pdo = getDB();
$orderId = (int) $input['order_id'];

// Get order (parameterized)
$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();

if (!$order) jsonError('Order not found', 404);

if (!in_array($order['status'], ['submitted', 'pending_review', 'queried'])) {
    jsonError('Order cannot be approved in current status: ' . $order['status'], 400);
}

$pdo->beginTransaction();
try {
    $approvedCount = 0;
    $rejectedCount = 0;
    $adjustedCount = 0;

    // Batch-fetch all requested quantities upfront (eliminates N+1)
    $lineIds = array_filter(array_map(function($l) {
        return (!empty($l['line_id']) && !empty($l['action'])) ? (int) $l['line_id'] : null;
    }, $input['lines']));
    $lineIds = array_values(array_unique($lineIds));

    $reqQtyMap = [];
    if (count($lineIds) > 0) {
        $ph = implode(',', array_fill(0, count($lineIds), '?'));
        $rqStmt = $pdo->prepare("SELECT id, requested_qty FROM order_lines WHERE id IN ({$ph}) AND order_id = ?");
        $rqStmt->execute(array_merge($lineIds, [$orderId]));
        foreach ($rqStmt->fetchAll() as $row) {
            $reqQtyMap[(int) $row['id']] = (float) $row['requested_qty'];
        }
    }

    $updateStmt = $pdo->prepare("
        UPDATE order_lines SET stores_action = ?, approved_qty = ?, stores_note = ?, updated_at = NOW()
        WHERE id = ? AND order_id = ?
    ");

    foreach ($input['lines'] as $line) {
        if (empty($line['line_id']) || empty($line['action'])) continue;

        $action = $line['action'];
        $approvedQty = null;
        $note = $line['note'] ?? null;

        if ($action === 'approved') {
            $approvedQty = $reqQtyMap[(int) $line['line_id']] ?? 0;
            $approvedCount++;
        } elseif ($action === 'adjusted') {
            $approvedQty = (float) ($line['approved_qty'] ?? 0);
            $adjustedCount++;
        } elseif ($action === 'rejected') {
            $approvedQty = 0;
            $rejectedCount++;
        }

        $updateStmt->execute([$action, $approvedQty, $note, (int) $line['line_id'], $orderId]);
    }

    // Determine overall status (parameterized)
    $tlStmt = $pdo->prepare("SELECT COUNT(*) FROM order_lines WHERE order_id = ?");
    $tlStmt->execute([$orderId]);
    $totalLines = (int) $tlStmt->fetchColumn();
    $allRejected = $rejectedCount === $totalLines;
    $anyApproved = $approvedCount > 0 || $adjustedCount > 0;

    $newStatus = 'stores_approved';
    if ($allRejected) {
        $newStatus = 'stores_rejected';
    } elseif ($rejectedCount > 0 && $anyApproved) {
        $newStatus = 'stores_partial';
    }

    // Update order header
    $pdo->prepare("
        UPDATE orders SET
            status = ?,
            stores_manager_id = ?,
            stores_reviewed_at = NOW(),
            stores_notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $newStatus,
        $auth['user_id'],
        $input['notes'] ?? null,
        $orderId,
    ]);

    $pdo->commit();

    jsonResponse([
        'message' => 'Order reviewed successfully',
        'status' => $newStatus,
        'approved' => $approvedCount,
        'adjusted' => $adjustedCount,
        'rejected' => $rejectedCount,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Failed to approve order: ' . $e->getMessage(), 500);
}
