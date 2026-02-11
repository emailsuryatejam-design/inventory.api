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

// Get order
$order = $pdo->prepare("SELECT * FROM orders WHERE id = ?")->execute([$orderId]);
$order = $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch();

if (!$order) jsonError('Order not found', 404);

if (!in_array($order['status'], ['submitted', 'pending_review', 'queried'])) {
    jsonError('Order cannot be approved in current status: ' . $order['status'], 400);
}

$pdo->beginTransaction();
try {
    $approvedCount = 0;
    $rejectedCount = 0;
    $adjustedCount = 0;

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
            // Get the requested qty
            $reqQty = $pdo->query("SELECT requested_qty FROM order_lines WHERE id = {$line['line_id']}")->fetchColumn();
            $approvedQty = (float) $reqQty;
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

    // Determine overall status
    $totalLines = (int) $pdo->query("SELECT COUNT(*) FROM order_lines WHERE order_id = {$orderId}")->fetchColumn();
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
