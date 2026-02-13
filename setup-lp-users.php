<?php
/**
 * One-time setup: Create Chef + Camp Storekeeper for Lions Paw (LP)
 * Run via: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-lp-users.php
 *
 * Chef      → access to kitchen module only (issue/new for kitchen requisitions)
 * Storekeeper → can see received order list + standard camp storekeeper access
 *
 * DELETE THIS FILE after running in production.
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();

$password = 'chef1234';
$pin = '1234';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);

// Get Lions Paw camp ID
$campStmt = $pdo->prepare("SELECT id, name FROM camps WHERE code = 'LP'");
$campStmt->execute();
$camp = $campStmt->fetch();

if (!$camp) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Camp LP (Lions Paw) not found in database'], JSON_PRETTY_PRINT);
    exit;
}

$campId = (int) $camp['id'];

$users = [
    [
        'username' => 'chef_lp',
        'name'     => 'Chef Lions Paw',
        'role'     => 'chef',
        'phone'    => '+255 754 100 004',
        'email'    => 'chef.lp@karibucamps.com',
    ],
    [
        'username' => 'stores_lp',
        'name'     => 'Stores Lions Paw',
        'role'     => 'camp_storekeeper',
        'phone'    => '+255 754 200 004',
        'email'    => 'stores.lp@karibucamps.com',
    ],
];

$created = [];
$skipped = [];

foreach ($users as $u) {
    // Check if already exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$u['username']]);
    if ($check->fetch()) {
        $skipped[] = $u['username'] . ' (already exists)';
        continue;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (username, name, password_hash, role, camp_id, is_active, pin_hash, phone, email, created_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $u['username'],
        $u['name'],
        $passwordHash,
        $u['role'],
        $campId,
        $pinHash,
        $u['phone'],
        $u['email'],
    ]);

    $created[] = [
        'id'       => (int) $pdo->lastInsertId(),
        'username' => $u['username'],
        'name'     => $u['name'],
        'role'     => $u['role'],
        'camp'     => 'LP',
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'message'     => 'Lions Paw users setup complete',
    'camp'        => $camp['name'] . ' (LP)',
    'camp_id'     => $campId,
    'created'     => $created,
    'skipped'     => $skipped,
    'credentials' => [
        'password' => $password,
        'pin'      => $pin,
    ],
    'access_notes' => [
        'chef_lp'   => 'Role: chef — access to kitchen module only (issue requisitions with recipe system)',
        'stores_lp' => 'Role: camp_storekeeper — can view received orders, manage stock, create issues/orders',
    ],
], JSON_PRETTY_PRINT);
