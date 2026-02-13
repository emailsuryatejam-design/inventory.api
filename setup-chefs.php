<?php
/**
 * One-time setup script to create chef users for each camp.
 * Run via: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-chefs.php
 * Or locally: php api/setup-chefs.php
 *
 * DELETE THIS FILE after running in production.
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();

$password = 'chef1234';
$pin = '1234';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);

$chefs = [
    ['username' => 'chef_ngo', 'name' => 'Joseph Massawe',   'camp_code' => 'NGO', 'phone' => '+255 754 100 001', 'email' => 'joseph.chef@karibucamps.com'],
    ['username' => 'chef_ser', 'name' => 'Grace Mwakasege',  'camp_code' => 'SER', 'phone' => '+255 754 100 002', 'email' => 'grace.chef@karibucamps.com'],
    ['username' => 'chef_tar', 'name' => 'Emmanuel Kimaro',  'camp_code' => 'TAR', 'phone' => '+255 754 100 003', 'email' => 'emmanuel.chef@karibucamps.com'],
    ['username' => 'chef_lp',  'name' => 'Fatma Hassan',     'camp_code' => 'LP',  'phone' => '+255 754 100 004', 'email' => 'fatma.chef@karibucamps.com'],
    ['username' => 'chef_wl',  'name' => 'Daniel Mushi',     'camp_code' => 'WL',  'phone' => '+255 754 100 005', 'email' => 'daniel.chef@karibucamps.com'],
];

$created = [];
$skipped = [];

foreach ($chefs as $chef) {
    // Check if already exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$chef['username']]);
    if ($check->fetch()) {
        $skipped[] = $chef['username'];
        continue;
    }

    // Get camp ID
    $campStmt = $pdo->prepare("SELECT id FROM camps WHERE code = ?");
    $campStmt->execute([$chef['camp_code']]);
    $campId = $campStmt->fetchColumn();

    if (!$campId) {
        $skipped[] = $chef['username'] . " (camp {$chef['camp_code']} not found)";
        continue;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (username, name, password_hash, role, camp_id, is_active, pin_hash, phone, email, created_at)
        VALUES (?, ?, ?, 'chef', ?, 1, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $chef['username'],
        $chef['name'],
        $passwordHash,
        $campId,
        $pinHash,
        $chef['phone'],
        $chef['email'],
    ]);

    $created[] = [
        'id' => (int) $pdo->lastInsertId(),
        'username' => $chef['username'],
        'name' => $chef['name'],
        'camp' => $chef['camp_code'],
    ];
}

// Also run the kitchen recipe migration tables
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_recipes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description VARCHAR(500) DEFAULT NULL,
            category ENUM('breakfast','lunch','dinner','snack','dessert','sauce','soup','salad','bread','other') DEFAULT 'other',
            cuisine VARCHAR(50) DEFAULT NULL,
            serves INT UNSIGNED DEFAULT 1,
            prep_time_minutes INT UNSIGNED DEFAULT NULL,
            cook_time_minutes INT UNSIGNED DEFAULT NULL,
            difficulty ENUM('easy','medium','hard') DEFAULT 'medium',
            instructions TEXT DEFAULT NULL,
            camp_id INT UNSIGNED DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_kr_category (category),
            INDEX idx_kr_camp (camp_id),
            INDEX idx_kr_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_recipe_ingredients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            qty_per_serving DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            is_primary TINYINT(1) DEFAULT 0,
            notes VARCHAR(200) DEFAULT NULL,
            INDEX idx_kri_recipe (recipe_id),
            INDEX idx_kri_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_preferences (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            preference_type ENUM('frequent_item','item_pair','substitution','recipe_favorite','category_affinity','time_pattern') NOT NULL,
            item_id INT UNSIGNED DEFAULT NULL,
            related_item_id INT UNSIGNED DEFAULT NULL,
            recipe_id INT UNSIGNED DEFAULT NULL,
            group_code VARCHAR(2) DEFAULT NULL,
            score DECIMAL(8,4) DEFAULT 1.0000,
            occurrence_count INT UNSIGNED DEFAULT 1,
            last_occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            context_data JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_up_user (user_id),
            INDEX idx_up_type (preference_type),
            INDEX idx_up_user_type (user_id, preference_type),
            INDEX idx_up_item (item_id),
            INDEX idx_up_score (score DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_pattern_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            session_id VARCHAR(36) NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            qty DECIMAL(12,3) DEFAULT 1,
            source ENUM('order','issue','bar','pos') NOT NULL,
            reference_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_opl_user (user_id),
            INDEX idx_opl_session (session_id),
            INDEX idx_opl_item (item_id),
            INDEX idx_opl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $tablesCreated = true;
} catch (Exception $e) {
    $tablesCreated = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode([
    'message' => 'Chef setup complete',
    'created' => $created,
    'skipped' => $skipped,
    'tables_created' => $tablesCreated,
    'credentials' => [
        'password' => $password,
        'pin' => $pin,
    ],
], JSON_PRETTY_PRINT);
