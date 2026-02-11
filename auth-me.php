<?php
/**
 * KCL Stores — Current User
 * GET /api/auth-me.php
 */

require_once __DIR__ . '/middleware.php';

requireMethod('GET');
$payload = requireAuth();

$pdo = getDB();

$stmt = $pdo->prepare('
    SELECT u.id, u.name, u.username, u.role, u.camp_id, u.approval_limit,
           c.code as camp_code, c.name as camp_name
    FROM users u
    LEFT JOIN camps c ON u.camp_id = c.id
    WHERE u.id = ? AND u.is_active = 1
');
$stmt->execute([$payload['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('User not found', 404);
}

jsonResponse([
    'user' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => $user['role'],
        'camp_id' => $user['camp_id'] ? (int) $user['camp_id'] : null,
        'camp_code' => $user['camp_code'],
        'camp_name' => $user['camp_name'],
        'approval_limit' => $user['approval_limit'] ? (float) $user['approval_limit'] : null,
    ],
]);
