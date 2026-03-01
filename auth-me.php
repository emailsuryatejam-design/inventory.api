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
    SELECT u.id, u.name, u.username, u.role, u.camp_id, u.approval_limit, u.tenant_id,
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
    'tenant' => $tenant,
]);
