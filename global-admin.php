<?php
/**
 * WebSquare — Global Admin API
 * Manages tenants, subscriptions, and system-level operations
 *
 * Routes (via ?action= parameter):
 *   POST   ?action=login        — Global admin login
 *   GET    ?action=dashboard     — Stats overview
 *   GET    ?action=tenants       — List all tenants
 *   GET    ?action=tenant&id=X   — Get tenant details
 *   POST   ?action=update        — Update tenant status/plan
 *   POST   ?action=extend-trial  — Extend trial period
 *   POST   ?action=suspend       — Suspend a tenant
 *   POST   ?action=activate      — Activate a tenant
 *   POST   ?action=change-password — Change global admin password
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit.php';

$action = $_GET['action'] ?? '';
$pdo = getDB();

// ── Global Admin Auth ──────────────────────────────
function requireGlobalAdmin() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError('Authentication required', 401);
    }

    $token = substr($authHeader, 7);

    // Decode JWT manually (same as middleware but check for global_admin flag)
    $parts = explode('.', $token);
    if (count($parts) !== 3) jsonError('Invalid token', 401);

    [$header, $payload, $signature] = $parts;
    $expectedSig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    ), '+/', '-_'), '=');

    if (!hash_equals($expectedSig, $signature)) jsonError('Invalid token', 401);

    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!$data || !isset($data['global_admin'])) jsonError('Not a global admin token', 403);
    if (isset($data['exp']) && $data['exp'] < time()) jsonError('Token expired', 401);

    return $data;
}

function globalJwtEncode($payload) {
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload['exp'] = time() + (12 * 3600); // 12 hours
    $payload['iat'] = time();
    $payload['global_admin'] = true;
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
    ), '+/', '-_'), '=');
    return "$header.$payloadEncoded.$signature";
}

// ══════════════════════════════════════════════════════
// ROUTES
// ══════════════════════════════════════════════════════

switch ($action) {

// ── LOGIN ────────────────────────────────────────────
case 'login':
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['username', 'password']);

    $rateLimitKey = 'gadmin:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    checkRateLimit($rateLimitKey, 5, 900);

    $stmt = $pdo->prepare('SELECT * FROM global_admins WHERE username = ? AND is_active = 1');
    $stmt->execute([$input['username']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($input['password'], $admin['password_hash'])) {
        jsonError('Invalid credentials', 401);
    }

    $pdo->prepare('UPDATE global_admins SET last_login = NOW() WHERE id = ?')
        ->execute([$admin['id']]);
    clearRateLimit($rateLimitKey);

    $token = globalJwtEncode([
        'admin_id' => (int)$admin['id'],
        'username' => $admin['username'],
        'name' => $admin['name'],
    ]);

    jsonResponse([
        'token' => $token,
        'admin' => [
            'id' => (int)$admin['id'],
            'name' => $admin['name'],
            'username' => $admin['username'],
        ],
    ]);
    break;

// ── DASHBOARD ───────────────────────────────────────
case 'dashboard':
    $ga = requireGlobalAdmin();

    $stats = [];

    // Total tenants
    $stats['total_tenants'] = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();

    // By status
    $statusCounts = $pdo->query("SELECT status, COUNT(*) as cnt FROM tenants GROUP BY status")->fetchAll();
    $stats['by_status'] = [];
    foreach ($statusCounts as $row) {
        $stats['by_status'][$row['status']] = (int)$row['cnt'];
    }

    // Trials expiring in 7 days
    $stats['trials_expiring_soon'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM tenants WHERE status = 'trial' AND trial_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn();

    // Expired trials not yet suspended
    $stats['expired_trials'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM tenants WHERE status = 'trial' AND trial_end < CURDATE()"
    )->fetchColumn();

    // Total users across all tenants
    $stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE tenant_id IS NOT NULL")->fetchColumn();

    // Recent registrations (last 30 days)
    $stats['recent_registrations'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetchColumn();

    // Latest 5 tenants
    $latest = $pdo->query("
        SELECT t.*,
               (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as user_count,
               (SELECT COUNT(*) FROM camps WHERE tenant_id = t.id) as camp_count
        FROM tenants t
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll();

    $stats['latest_tenants'] = array_map(function($t) {
        return [
            'id' => (int)$t['id'],
            'company_name' => $t['company_name'],
            'email' => $t['email'],
            'status' => $t['status'],
            'plan' => $t['plan'],
            'trial_end' => $t['trial_end'],
            'user_count' => (int)$t['user_count'],
            'camp_count' => (int)$t['camp_count'],
            'created_at' => $t['created_at'],
        ];
    }, $latest);

    jsonResponse($stats);
    break;

// ── LIST TENANTS ────────────────────────────────────
case 'tenants':
    $ga = requireGlobalAdmin();

    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($status && in_array($status, ['trial', 'active', 'suspended', 'expired'])) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }

    if ($search) {
        $where[] = '(t.company_name LIKE ? OR t.email LIKE ? OR t.slug LIKE ?)';
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tenants t $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT t.*,
               (SELECT COUNT(*) FROM users WHERE tenant_id = t.id) as user_count,
               (SELECT COUNT(*) FROM camps WHERE tenant_id = t.id) as camp_count
        FROM tenants t
        $whereClause
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $tenants = $stmt->fetchAll();

    jsonResponse([
        'tenants' => array_map(function($t) {
            $daysLeft = null;
            if ($t['status'] === 'trial') {
                $daysLeft = max(0, (int)((strtotime($t['trial_end']) - time()) / 86400));
            }
            return [
                'id' => (int)$t['id'],
                'company_name' => $t['company_name'],
                'slug' => $t['slug'],
                'email' => $t['email'],
                'phone' => $t['phone'],
                'country' => $t['country'],
                'industry' => $t['industry'],
                'status' => $t['status'],
                'plan' => $t['plan'],
                'trial_start' => $t['trial_start'],
                'trial_end' => $t['trial_end'],
                'days_left' => $daysLeft,
                'max_users' => (int)$t['max_users'],
                'max_camps' => (int)$t['max_camps'],
                'user_count' => (int)$t['user_count'],
                'camp_count' => (int)$t['camp_count'],
                'created_at' => $t['created_at'],
            ];
        }, $tenants),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => ceil($total / $perPage),
        ],
    ]);
    break;

// ── GET SINGLE TENANT ────────────────────────────────
case 'tenant':
    $ga = requireGlobalAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing tenant ID', 400);

    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if (!$tenant) jsonError('Tenant not found', 404);

    // Get users
    $usersStmt = $pdo->prepare("
        SELECT u.id, u.name, u.username, u.role, u.is_active, u.last_login, u.created_at, c.code as camp_code, c.name as camp_name
        FROM users u
        LEFT JOIN camps c ON u.camp_id = c.id
        WHERE u.tenant_id = ?
        ORDER BY u.created_at DESC
    ");
    $usersStmt->execute([$id]);
    $users = $usersStmt->fetchAll();

    // Get camps
    $campsStmt = $pdo->prepare("SELECT * FROM camps WHERE tenant_id = ? ORDER BY name");
    $campsStmt->execute([$id]);
    $camps = $campsStmt->fetchAll();

    $daysLeft = null;
    if ($tenant['status'] === 'trial') {
        $daysLeft = max(0, (int)((strtotime($tenant['trial_end']) - time()) / 86400));
    }

    jsonResponse([
        'tenant' => [
            'id' => (int)$tenant['id'],
            'company_name' => $tenant['company_name'],
            'slug' => $tenant['slug'],
            'email' => $tenant['email'],
            'phone' => $tenant['phone'],
            'country' => $tenant['country'],
            'industry' => $tenant['industry'],
            'status' => $tenant['status'],
            'plan' => $tenant['plan'],
            'trial_start' => $tenant['trial_start'],
            'trial_end' => $tenant['trial_end'],
            'days_left' => $daysLeft,
            'max_users' => (int)$tenant['max_users'],
            'max_camps' => (int)$tenant['max_camps'],
            'modules' => json_decode($tenant['modules'] ?? '[]'),
            'notes' => $tenant['notes'],
            'created_at' => $tenant['created_at'],
            'updated_at' => $tenant['updated_at'],
        ],
        'users' => array_map(function($u) {
            return [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'username' => $u['username'],
                'role' => $u['role'],
                'is_active' => (bool)$u['is_active'],
                'last_login' => $u['last_login'],
                'camp_code' => $u['camp_code'],
                'camp_name' => $u['camp_name'],
                'created_at' => $u['created_at'],
            ];
        }, $users),
        'camps' => array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'code' => $c['code'],
                'name' => $c['name'],
                'type' => $c['type'],
                'is_active' => (bool)$c['is_active'],
            ];
        }, $camps),
    ]);
    break;

// ── UPDATE TENANT ────────────────────────────────────
case 'update':
    $ga = requireGlobalAdmin();
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['id']);

    $id = (int)$input['id'];
    $updates = [];
    $params = [];

    $allowed = ['max_users', 'max_camps', 'plan', 'notes'];
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
        }
    }

    if (isset($input['modules']) && is_array($input['modules'])) {
        $updates[] = "modules = ?";
        $params[] = json_encode($input['modules']);
    }

    if (empty($updates)) jsonError('Nothing to update', 400);

    $params[] = $id;
    $sql = "UPDATE tenants SET " . implode(', ', $updates) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);

    jsonResponse(['success' => true, 'message' => 'Tenant updated']);
    break;

// ── EXTEND TRIAL ─────────────────────────────────────
case 'extend-trial':
    $ga = requireGlobalAdmin();
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['id', 'days']);

    $id = (int)$input['id'];
    $days = max(1, min(365, (int)$input['days']));

    $stmt = $pdo->prepare("
        UPDATE tenants
        SET trial_end = DATE_ADD(trial_end, INTERVAL ? DAY),
            status = 'trial'
        WHERE id = ?
    ");
    $stmt->execute([$days, $id]);

    jsonResponse(['success' => true, 'message' => "Trial extended by {$days} days"]);
    break;

// ── SUSPEND ──────────────────────────────────────────
case 'suspend':
    $ga = requireGlobalAdmin();
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['id']);

    $id = (int)$input['id'];
    $reason = trim($input['reason'] ?? '');

    $pdo->prepare("UPDATE tenants SET status = 'suspended', notes = CONCAT(IFNULL(notes,''), '\nSuspended: $reason') WHERE id = ?")
        ->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Tenant suspended']);
    break;

// ── ACTIVATE ─────────────────────────────────────────
case 'activate':
    $ga = requireGlobalAdmin();
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['id']);

    $id = (int)$input['id'];
    $plan = $input['plan'] ?? 'active';

    $pdo->prepare("UPDATE tenants SET status = 'active', plan = ? WHERE id = ?")
        ->execute([$plan, $id]);

    jsonResponse(['success' => true, 'message' => 'Tenant activated']);
    break;

// ── CHANGE PASSWORD ─────────────────────────────────
case 'change-password':
    $ga = requireGlobalAdmin();
    requireMethod('POST');
    $input = getJsonInput();
    requireFields($input, ['current_password', 'new_password']);

    if (strlen($input['new_password']) < 8) {
        jsonError('New password must be at least 8 characters', 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM global_admins WHERE id = ?');
    $stmt->execute([$ga['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($input['current_password'], $admin['password_hash'])) {
        jsonError('Current password is incorrect', 401);
    }

    $newHash = password_hash($input['new_password'], PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE global_admins SET password_hash = ? WHERE id = ?')
        ->execute([$newHash, $ga['admin_id']]);

    jsonResponse(['success' => true, 'message' => 'Password changed']);
    break;

// ── DEFAULT ──────────────────────────────────────────
default:
    jsonError('Unknown action. Valid actions: login, dashboard, tenants, tenant, update, extend-trial, suspend, activate, change-password', 400);
}
