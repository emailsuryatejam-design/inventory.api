<?php
/**
 * KCL Stores — Reject Order (full order)
 * PUT /api/orders-reject.php
 * Body: { order_id, reason }
 */

require_once __DIR__ . '/middleware.php';
requireMethod('PUT');
$auth = requireAuth();

if (!in_array($auth['role'], ['stores_manager', 'director', 'admin'])) {
    jsonError('Only Stores Manager can reject orders', 403);
}

$input = getJsonInput();
requireFields($input, ['order_id', 'reason']);

$pdo = getDB();
$orderId = (int) $input['order_id'];

$order = $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch();
if (!$order) jsonError('Order not found', 404);

if (!in_array($order['status'], ['submitted', 'pending_review', 'queried'])) {
    jsonError('Order cannot be rejected in current status: ' . $order['status'], 400);
}

$pdo->beginTransaction();
try {
    // Reject all lines
    $pdo->prepare("
        UPDATE order_lines SET stores_action = 'rejected', approved_qty = 0, stores_note = ?, updated_at = NOW()
        WHERE order_id = ?
    ")->execute([$input['reason'], $orderId]);

    // Update order
    $pdo->prepare("
        UPDATE orders SET
            status = 'stores_rejected',
            stores_manager_id = ?,
            stores_reviewed_at = NOW(),
            stores_notes = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$auth['user_id'], $input['reason'], $orderId]);

    $pdo->commit();

    jsonResponse(['message' => 'Order rejected', 'status' => 'stores_rejected']);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonError('Failed to reject order: ' . $e->getMessage(), 500);
}
