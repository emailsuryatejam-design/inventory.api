<?php
/**
 * KCL Stores — Query on Order (send message)
 * POST /api/orders-query.php
 * Body: { order_id, message, order_line_id? }
 */

require_once __DIR__ . '/middleware.php';
requireMethod('POST');
$auth = requireAuth();

$input = getJsonInput();
requireFields($input, ['order_id', 'message']);

$pdo = getDB();
$orderId = (int) $input['order_id'];

$order = $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch();
if (!$order) jsonError('Order not found', 404);

// Insert query message
$pdo->prepare("
    INSERT INTO order_queries (order_id, order_line_id, sender_id, message, is_read, created_at)
    VALUES (?, ?, ?, ?, 0, NOW())
")->execute([
    $orderId,
    $input['order_line_id'] ?? null,
    $auth['user_id'],
    $input['message'],
]);

// If stores manager is querying, change status
if (in_array($auth['role'], ['stores_manager', 'director', 'admin'])
    && in_array($order['status'], ['submitted', 'pending_review'])) {
    $pdo->prepare("UPDATE orders SET status = 'queried', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
}

jsonResponse([
    'message' => 'Query sent',
    'query_id' => (int) $pdo->lastInsertId(),
]);
