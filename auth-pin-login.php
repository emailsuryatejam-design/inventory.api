<?php
/**
 * KCL Stores — PIN Login (Camp Staff)
 * POST /api/auth-pin-login.php
 * Body: { "username": "...", "pin": "1234" }
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/audit.php';

requireMethod('POST');
$input = getJsonInput();
requireFields($input, ['username', 'pin']);

// Rate limit: 5 attempts per IP+username per 5 minutes
$rateLimitKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':pin:' . $input['username'];
checkRateLimit($rateLimitKey);

// Validate PIN format (4 digits)
if (!preg_match('/^\d{4}$/', $input['pin'])) {
    jsonError('PIN must be 4 digits', 400);
}

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
    jsonError('Invalid username or PIN', 401);
}

// Verify PIN
if (empty($user['pin_hash'])) {
    jsonError('PIN login not configured for this user. Use password login.', 401);
}

if (!password_verify($input['pin'], $user['pin_hash'])) {
    jsonError('Invalid username or PIN', 401);
}

// Update last login & clear rate limit on success
$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
    ->execute([$user['id']]);
clearRateLimit($rateLimitKey);
auditLog($pdo, 'login', (int) $user['id'], ['method' => 'pin']);

// Generate JWT
$token = jwtEncode([
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'camp_id' => $user['camp_id'] ? (int) $user['camp_id'] : null,
]);

// Load camps
$camps = $pdo->query('SELECT id, code, name, type FROM camps WHERE is_active = 1 ORDER BY id')
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
