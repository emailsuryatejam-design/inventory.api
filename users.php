<?php
/**
 * KCL Stores — User Management
 * GET    /api/users.php       — list users
 * POST   /api/users.php       — create user
 * PUT    /api/users.php?id=X  — update user
 */

require_once __DIR__ . '/middleware.php';

$auth = requireAuth();
$pdo = getDB();

// ── GET — List Users ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = requireRole(['admin', 'director', 'stores_manager']);

    $stmt = $pdo->query("
        SELECT u.id, u.username, u.name, u.role, u.camp_id, u.is_active,
               u.pin_hash, u.approval_limit, u.phone, u.email,
               u.last_login, u.created_at,
               c.code as camp_code, c.name as camp_name
        FROM users u
        LEFT JOIN camps c ON u.camp_id = c.id
        ORDER BY u.name
    ");
    $users = $stmt->fetchAll();

    jsonResponse([
        'users' => array_map(function($u) {
            return [
                'id' => (int) $u['id'],
                'username' => $u['username'],
                'name' => $u['name'],
                'role' => $u['role'],
                'camp_id' => $u['camp_id'] ? (int) $u['camp_id'] : null,
                'camp_code' => $u['camp_code'],
                'camp_name' => $u['camp_name'],
                'is_active' => (bool) $u['is_active'],
                'pin_enabled' => ($u['pin_hash'] !== null && $u['pin_hash'] !== ''),
                'phone' => $u['phone'],
                'email' => $u['email'],
                'approval_limit' => $u['approval_limit'] ? (float) $u['approval_limit'] : null,
                'last_login' => $u['last_login'],
                'created_at' => $u['created_at'],
            ];
        }, $users),
    ]);
    exit;
}

// ── POST — Create User ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAdmin();
    $input = getJsonInput();
    requireFields($input, ['username', 'name', 'role', 'password']);

    // Check unique username
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$input['username']]);
    if ($check->fetch()) {
        jsonError('Username already exists', 400);
    }

    $pinHash = null;
    if (!empty($input['pin'])) {
        $pinHash = password_hash($input['pin'], PASSWORD_DEFAULT);
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (username, name, password_hash, role, camp_id, is_active, pin_hash,
                          phone, email, approval_limit, created_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $input['username'],
        $input['name'],
        password_hash($input['password'], PASSWORD_DEFAULT),
        $input['role'],
        $input['camp_id'] ?? null,
        $pinHash,
        $input['phone'] ?? null,
        $input['email'] ?? null,
        $input['approval_limit'] ?? null,
    ]);

    jsonResponse([
        'message' => 'User created successfully',
        'user' => [
            'id' => (int) $pdo->lastInsertId(),
            'username' => $input['username'],
            'name' => $input['name'],
            'role' => $input['role'],
        ],
    ], 201);
    exit;
}

// ── PUT — Update User ──
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = requireAdmin();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('User ID required', 400);

    $input = getJsonInput();

    // Check user exists
    $existing = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $existing->execute([$id]);
    if (!$existing->fetch()) {
        jsonError('User not found', 404);
    }

    $updates = [];
    $params = [];

    if (isset($input['name'])) {
        $updates[] = 'name = ?';
        $params[] = $input['name'];
    }
    if (isset($input['role'])) {
        $updates[] = 'role = ?';
        $params[] = $input['role'];
    }
    if (isset($input['camp_id'])) {
        $updates[] = 'camp_id = ?';
        $params[] = $input['camp_id'] ?: null;
    }
    if (isset($input['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = $input['is_active'] ? 1 : 0;
    }
    if (isset($input['approval_limit'])) {
        $updates[] = 'approval_limit = ?';
        $params[] = $input['approval_limit'] ?: null;
    }
    if (isset($input['phone'])) {
        $updates[] = 'phone = ?';
        $params[] = $input['phone'] ?: null;
    }
    if (isset($input['email'])) {
        $updates[] = 'email = ?';
        $params[] = $input['email'] ?: null;
    }
    if (!empty($input['password'])) {
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }
    if (isset($input['pin'])) {
        if ($input['pin']) {
            $updates[] = 'pin_hash = ?';
            $params[] = password_hash($input['pin'], PASSWORD_DEFAULT);
        } else {
            $updates[] = 'pin_hash = NULL';
        }
    }

    if (empty($updates)) {
        jsonError('No fields to update', 400);
    }

    $updates[] = 'updated_at = NOW()';
    $params[] = $id;
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);

    jsonResponse(['message' => 'User updated successfully']);
    exit;
}

requireMethod('GET');
