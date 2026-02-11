<?php
/**
 * KCL Stores — Login (Password)
 * POST /api/auth-login.php
 * Body: { "username": "...", "password": "..." }
 */

require_once __DIR__ . '/middleware.php';

requireMethod('POST');
$input = getJsonInput();
requireFields($input, ['username', 'password']);

$pdo = getDB();

// Find user
$stmt = $pdo->prepare('
    SELECT u.*, c.code as camp_code, c.name as camp_name, c.type as camp_type
    FROM users u
    LEFT JOIN camps c ON u.camp_id = c.id
    WHERE u.username = ? AND u.is_active = 1
');
$stmt->execute([$input['username']]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('Invalid username or password', 401);
}

// Verify password
if (empty($user['password_hash'])) {
    jsonError('Password login not configured for this user. Use PIN login.', 401);
}

if (!password_verify($input['password'], $user['password_hash'])) {
    jsonError('Invalid username or password', 401);
}

// Update last login
$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
    ->execute([$user['id']]);

// Generate JWT
$token = jwtEncode([
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'camp_id' => $user['camp_id'] ? (int) $user['camp_id'] : null,
]);

// Load all camps for the response
$camps = $pdo->query('SELECT id, code, name, type, is_active FROM camps WHERE is_active = 1 ORDER BY name')
    ->fetchAll();

jsonResponse([
    'token' => $token,
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
    'camps' => array_map(function($c) {
        return [
            'id' => (int) $c['id'],
            'code' => $c['code'],
            'name' => $c['name'],
            'type' => $c['type'],
        ];
    }, $camps),
]);
