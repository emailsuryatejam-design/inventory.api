<?php
/**
 * One-time: Create kitchen_default_menu table + add is_default column to kitchen_menu_dishes.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-default-menu.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// 1. Create kitchen_default_menu table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_default_menu (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        camp_id INT UNSIGNED DEFAULT NULL,
        day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Mon,1=Tue,2=Wed,3=Thu,4=Fri,5=Sat,6=Sun',
        meal_type ENUM('lunch','dinner') NOT NULL,
        course ENUM('appetizer','soup','salad','main_course','side','dessert','beverage') NOT NULL,
        dish_name VARCHAR(200) NOT NULL,
        description TEXT DEFAULT NULL,
        sort_order INT UNSIGNED DEFAULT 0,
        recipe_id INT UNSIGNED DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_day_meal (day_of_week, meal_type),
        INDEX idx_camp (camp_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'kitchen_default_menu table created ✓';
} catch (Exception $e) {
    $results[] = 'kitchen_default_menu: ' . $e->getMessage();
}

// 2. Add is_default column to kitchen_menu_dishes
try {
    $cols = $pdo->query("SHOW COLUMNS FROM kitchen_menu_dishes LIKE 'is_default'")->fetchAll();
    if (count($cols) > 0) {
        $results[] = 'is_default column already exists — skipped';
    } else {
        $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order");
        $results[] = 'is_default column added to kitchen_menu_dishes ✓';
    }
} catch (Exception $e) {
    $results[] = 'is_default: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
