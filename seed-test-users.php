<?php
/**
 * Seed test users for all role types at Serengeti (SER) camp
 * Run via: curl https://darkblue-goshawk-672880.hostingersite.com/seed-test-users.php
 *
 * All passwords: test1234 | PIN: 1234
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();

$password = 'test1234';
$pin = '1234';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);

// Get SER camp
$campStmt = $pdo->prepare("SELECT id FROM camps WHERE code = 'SER'");
$campStmt->execute();
$serCampId = (int) $campStmt->fetchColumn();

// Get HO camp
$campStmt = $pdo->prepare("SELECT id FROM camps WHERE code = 'HO'");
$campStmt->execute();
$hoCampId = (int) $campStmt->fetchColumn();

if (!$serCampId) die(json_encode(['error' => 'SER camp not found']));

$users = [
    // Camp staff (SER)
    ['username' => 'storekeeper_ser', 'name' => 'John Storekeeper',    'role' => 'camp_storekeeper', 'camp_id' => $serCampId, 'email' => 'storekeeper@test.com'],
    ['username' => 'manager_ser',     'name' => 'Sarah Camp Manager',   'role' => 'camp_manager',     'camp_id' => $serCampId, 'email' => 'campmgr@test.com'],
    ['username' => 'hk_ser',          'name' => 'Mary Housekeeping',    'role' => 'housekeeping',     'camp_id' => $serCampId, 'email' => 'hk@test.com'],

    // Head Office roles
    ['username' => 'stores_mgr',      'name' => 'David Stores Manager', 'role' => 'stores_manager',    'camp_id' => $hoCampId, 'email' => 'storesmgr@test.com'],
    ['username' => 'procurement',     'name' => 'Alice Procurement',    'role' => 'procurement_officer','camp_id' => $hoCampId, 'email' => 'procurement@test.com'],
    ['username' => 'director',        'name' => 'Robert Director',      'role' => 'director',          'camp_id' => $hoCampId, 'email' => 'director@test.com'],
    ['username' => 'admin',           'name' => 'System Admin',         'role' => 'admin',             'camp_id' => $hoCampId, 'email' => 'admin@test.com'],
];

$created = [];
$skipped = [];

foreach ($users as $u) {
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$u['username']]);
    if ($check->fetch()) {
        // Update password to ensure it's correct
        $pdo->prepare("UPDATE users SET password_hash = ?, pin_hash = ? WHERE username = ?")
            ->execute([$passwordHash, $pinHash, $u['username']]);
        $skipped[] = $u['username'] . ' (exists — password reset)';
        continue;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (username, name, password_hash, role, camp_id, is_active, pin_hash, email, created_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, NOW())
    ");
    $stmt->execute([
        $u['username'],
        $u['name'],
        $passwordHash,
        $u['role'],
        $u['camp_id'],
        $pinHash,
        $u['email'],
    ]);

    $created[] = [
        'id' => (int) $pdo->lastInsertId(),
        'username' => $u['username'],
        'name' => $u['name'],
        'role' => $u['role'],
    ];
}

// Also reset passwords on existing chef accounts
$chefAccounts = ['chef_ser', 'chef_lp', 'chef_ngo', 'chef_tar', 'chef_wl'];
foreach ($chefAccounts as $chef) {
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$chef]);
    if ($check->fetch()) {
        // chef password stays chef1234
        $chefHash = password_hash('chef1234', PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ?, pin_hash = ? WHERE username = ?")
            ->execute([$chefHash, $pinHash, $chef]);
    }
}

header('Content-Type: application/json');
echo json_encode([
    'message' => 'Test users ready',
    'created' => $created,
    'skipped' => $skipped,
    'credentials' => [
        'test_users_password' => $password,
        'chef_password' => 'chef1234',
        'pin' => $pin,
    ],
    'test_accounts' => [
        'Chef (kitchen view)' => 'chef_ser / chef1234',
        'Camp Storekeeper' => 'storekeeper_ser / test1234',
        'Camp Manager' => 'manager_ser / test1234',
        'Housekeeping' => 'hk_ser / test1234',
        'Stores Manager (HO)' => 'stores_mgr / test1234',
        'Procurement (HO)' => 'procurement / test1234',
        'Director (HO)' => 'director / test1234',
        'Admin' => 'admin / test1234',
    ],
], JSON_PRETTY_PRINT);
