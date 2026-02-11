<?php
/**
 * KCL Stores — One-Time Setup
 * Creates test users with hashed passwords/PINs
 * Run once via browser: /api/setup.php
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();

// Check if users already exist
$count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 1) { // 1 = system_import user
    jsonResponse(['message' => "Setup already done. {$count} users exist.", 'skipped' => true]);
}

// Create test users
$users = [
    // Camp Storekeepers (PIN login)
    ['name' => 'John Storekeeper', 'username' => 'john.ngo', 'pin' => '1234', 'role' => 'camp_storekeeper', 'camp_code' => 'NGO'],
    ['name' => 'Mary Storekeeper', 'username' => 'mary.ser', 'pin' => '1234', 'role' => 'camp_storekeeper', 'camp_code' => 'SER'],
    ['name' => 'Peter Storekeeper', 'username' => 'peter.tar', 'pin' => '1234', 'role' => 'camp_storekeeper', 'camp_code' => 'TAR'],
    ['name' => 'Grace Storekeeper', 'username' => 'grace.lp', 'pin' => '1234', 'role' => 'camp_storekeeper', 'camp_code' => 'LP'],
    ['name' => 'James Storekeeper', 'username' => 'james.wl', 'pin' => '1234', 'role' => 'camp_storekeeper', 'camp_code' => 'WL'],

    // Camp Managers (Password login)
    ['name' => 'Sarah Manager', 'username' => 'sarah.ngo', 'password' => 'karibu2026', 'role' => 'camp_manager', 'camp_code' => 'NGO'],

    // Head Office (Password login)
    ['name' => 'David Stores', 'username' => 'david.stores', 'password' => 'karibu2026', 'role' => 'stores_manager', 'camp_code' => 'HO'],
    ['name' => 'Alice Procurement', 'username' => 'alice.proc', 'password' => 'karibu2026', 'role' => 'procurement_officer', 'camp_code' => 'HO'],
    ['name' => 'Robert Director', 'username' => 'robert.dir', 'password' => 'karibu2026', 'role' => 'director', 'camp_code' => 'HO'],
    ['name' => 'Admin', 'username' => 'admin', 'password' => 'admin2026', 'role' => 'admin', 'camp_code' => 'HO'],
];

$created = 0;
$stmt = $pdo->prepare('
    INSERT INTO users (name, username, pin_hash, password_hash, role, camp_id, approval_limit, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
');

// Get camp IDs
$camps = $pdo->query('SELECT code, id FROM camps')->fetchAll(PDO::FETCH_KEY_PAIR);

// Approval limits by role
$limits = [
    'camp_storekeeper' => 0,
    'camp_manager' => 500000,
    'stores_manager' => 2000000,
    'procurement_officer' => 2000000,
    'director' => 999999999,
    'admin' => 999999999,
];

foreach ($users as $u) {
    $campId = $camps[$u['camp_code']] ?? null;
    $pinHash = isset($u['pin']) ? password_hash($u['pin'], PASSWORD_DEFAULT) : null;
    $passHash = isset($u['password']) ? password_hash($u['password'], PASSWORD_DEFAULT) : null;
    $limit = $limits[$u['role']] ?? 0;

    try {
        $stmt->execute([
            $u['name'],
            $u['username'],
            $pinHash,
            $passHash,
            $u['role'],
            $campId,
            $limit,
        ]);
        $created++;
    } catch (PDOException $e) {
        // Skip if duplicate
        if ($e->getCode() != '23000') throw $e;
    }
}

jsonResponse([
    'message' => "Setup complete. Created {$created} users.",
    'users' => array_map(function($u) {
        return [
            'username' => $u['username'],
            'role' => $u['role'],
            'camp' => $u['camp_code'],
            'login_type' => isset($u['pin']) ? 'PIN: ' . $u['pin'] : 'Password: ' . $u['password'],
        ];
    }, $users),
]);
