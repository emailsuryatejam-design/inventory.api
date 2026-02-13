<?php
/**
 * One-time: Create kitchen_recipes + kitchen_recipe_ingredients tables.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-kitchen-recipes.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// 1. kitchen_recipes
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_recipes (
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
        FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_kitchen_recipes_category (category),
        INDEX idx_kitchen_recipes_camp (camp_id),
        INDEX idx_kitchen_recipes_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'kitchen_recipes table created ✓';
} catch (Exception $e) {
    $results[] = 'kitchen_recipes: ' . $e->getMessage();
}

// 2. kitchen_recipe_ingredients
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_recipe_ingredients (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        qty_per_serving DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
        is_primary TINYINT(1) DEFAULT 0,
        notes VARCHAR(200) DEFAULT NULL,
        FOREIGN KEY (recipe_id) REFERENCES kitchen_recipes(id) ON DELETE CASCADE,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
        INDEX idx_kri_recipe (recipe_id),
        INDEX idx_kri_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'kitchen_recipe_ingredients table created ✓';
} catch (Exception $e) {
    $results[] = 'kitchen_recipe_ingredients: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
