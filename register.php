<?php
/**
 * WebSquare — Public Registration
 * POST /api/register.php
 * Creates a new tenant with 30-day free trial + first admin user
 *
 * Body: {
 *   company_name, email, password, name, phone?, country?, industry?
 * }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit.php';

requireMethod('POST');
$input = getJsonInput();
requireFields($input, ['company_name', 'email', 'password', 'name']);

// Validate email
$email = filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL);
if (!$email) {
    jsonError('Invalid email address', 400);
}

// Validate password strength
$password = $input['password'];
if (strlen($password) < 8) {
    jsonError('Password must be at least 8 characters', 400);
}

// Rate limit registration: 3 attempts per IP per 15 minutes
$rateLimitKey = 'register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
checkRateLimit($rateLimitKey, 3, 900);

// Sanitize all inputs — strip tags and limit lengths
$companyName = mb_substr(strip_tags(trim($input['company_name'])), 0, 200);
$name = mb_substr(strip_tags(trim($input['name'])), 0, 100);
$phone = mb_substr(preg_replace('/[^\d+\-\s()]/', '', trim($input['phone'] ?? '')), 0, 30);
$country = mb_substr(strip_tags(trim($input['country'] ?? '')), 0, 100);
$industry = mb_substr(strip_tags(trim($input['industry'] ?? '')), 0, 100);

if (strlen($companyName) < 2) {
    jsonError('Company name is too short', 400);
}
if (strlen($name) < 2) {
    jsonError('Name is too short', 400);
}

// Generate slug from company name
$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $companyName));
$slug = trim($slug, '-');
if (strlen($slug) < 3) {
    jsonError('Company name is too short', 400);
}

$pdo = getDB();

// Check if email already registered
$stmt = $pdo->prepare('SELECT id FROM tenants WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    jsonError('An account with this email already exists. Please login instead.', 409);
}

// Check if slug is taken, append random suffix if needed
$stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
$stmt->execute([$slug]);
if ($stmt->fetch()) {
    $slug .= '-' . substr(uniqid(), -4);
}

$pdo->beginTransaction();

try {
    // ── Create tenant ──────────────────────────────────
    $trialStart = date('Y-m-d');
    $trialEnd = date('Y-m-d', strtotime('+30 days'));
    $defaultModules = json_encode(['stores', 'kitchen', 'bar', 'admin']);

    $stmt = $pdo->prepare("
        INSERT INTO tenants (company_name, slug, email, phone, country, industry, status, trial_start, trial_end, plan, max_users, max_camps, modules)
        VALUES (?, ?, ?, ?, ?, ?, 'trial', ?, ?, 'trial', 5, 2, ?)
    ");
    $stmt->execute([$companyName, $slug, $email, $phone, $country, $industry, $trialStart, $trialEnd, $defaultModules]);
    $tenantId = (int)$pdo->lastInsertId();

    // ── Create default camp (Head Office) ──────────────
    $stmt = $pdo->prepare("
        INSERT INTO camps (tenant_id, code, name, type, is_active)
        VALUES (?, 'HO', ?, 'head_office', 1)
    ");
    $stmt->execute([$tenantId, $companyName . ' - Head Office']);
    $campId = (int)$pdo->lastInsertId();

    // ── Create admin user ──────────────────────────────
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));

    // Ensure username is unique
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $username .= rand(100, 999);
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (tenant_id, name, username, password_hash, role, camp_id, approval_limit, is_active)
        VALUES (?, ?, ?, ?, 'admin', ?, 999999999, 1)
    ");
    $stmt->execute([$tenantId, $name, $username, $passwordHash, $campId]);
    $userId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // Generate JWT so user is auto-logged-in
    require_once __DIR__ . '/middleware.php';
    $token = jwtEncode([
        'user_id' => $userId,
        'username' => $username,
        'role' => 'admin',
        'camp_id' => $campId,
        'tenant_id' => $tenantId,
    ]);

    // Load camps for the response
    $camps = $pdo->prepare('SELECT id, code, name, type, is_active FROM camps WHERE tenant_id = ? AND is_active = 1 ORDER BY name');
    $camps->execute([$tenantId]);
    $campsList = $camps->fetchAll();

    clearRateLimit($rateLimitKey);

    jsonResponse([
        'success' => true,
        'message' => 'Registration successful! Your 30-day free trial has started.',
        'token' => $token,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'username' => $username,
            'role' => 'admin',
            'camp_id' => $campId,
            'camp_code' => 'HO',
            'camp_name' => $companyName . ' - Head Office',
        ],
        'camps' => array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'code' => $c['code'],
                'name' => $c['name'],
                'type' => $c['type'],
            ];
        }, $campsList),
        'tenant' => [
            'id' => $tenantId,
            'company_name' => $companyName,
            'slug' => $slug,
            'trial_end' => $trialEnd,
        ],
        'modules' => ['stores', 'kitchen', 'bar', 'admin'],
    ], 201);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[Register] ' . $e->getMessage());
    jsonError('Registration failed. Please try again.', 500);
}
