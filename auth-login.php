<?php
/**
 * KCL Stores — Login (Password)
 * POST /api/auth-login.php
 * Body: { "username": "...", "password": "..." }
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/audit.php';

requireMethod('POST');
$input = getJsonInput();
requireFields($input, ['username', 'password']);

// Rate limit: 5 attempts per IP+username per 5 minutes
$rateLimitKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $input['username'];
checkRateLimit($rateLimitKey);

$pdo = getDB();

// Find user (include tenant_id for trial info)
$stmt = $pdo->prepare('
    SELECT u.*, u.tenant_id, c.code as camp_code, c.name as camp_name, c.type as camp_type
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

// Update last login & clear rate limit on success
$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
    ->execute([$user['id']]);
clearRateLimit($rateLimitKey);
auditLog($pdo, 'login', (int) $user['id'], ['method' => 'password']);

// Generate JWT
$token = jwtEncode([
    'user_id' => (int) $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'camp_id' => $user['camp_id'] ? (int) $user['camp_id'] : null,
]);

// Load tenant trial info
$tenant = null;
$tenantId = $user['tenant_id'] ?? null;
if ($tenantId) {
    $tenantStmt = $pdo->prepare('SELECT id, company_name, status, trial_start, trial_end, plan, max_users, max_camps FROM tenants WHERE id = ?');
    $tenantStmt->execute([$tenantId]);
    $tenantRow = $tenantStmt->fetch();
    if ($tenantRow) {
        $tenant = [
            'id' => (int) $tenantRow['id'],
            'company_name' => $tenantRow['company_name'],
            'status' => $tenantRow['status'],
            'plan' => $tenantRow['plan'],
            'trial_start' => $tenantRow['trial_start'],
            'trial_end' => $tenantRow['trial_end'],
            'max_users' => (int) $tenantRow['max_users'],
            'max_camps' => (int) $tenantRow['max_camps'],
        ];
    }
}

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
    'tenant' => $tenant,
]);
