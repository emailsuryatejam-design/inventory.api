<?php
/**
 * One-time setup: Create kitchen_menu_* tables for menu planning.
 * Run via: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-menu-tables.php
 *
 * DELETE THIS FILE after running in production.
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();
$results = [];

// ── Table 1: kitchen_menu_plans ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_menu_plans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            camp_id INT UNSIGNED NOT NULL,
            plan_date DATE NOT NULL,
            meal_type ENUM('lunch','dinner') NOT NULL,
            portions INT UNSIGNED NOT NULL DEFAULT 20,
            status ENUM('draft','confirmed','issued') NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NOT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_camp_date_meal (camp_id, plan_date, meal_type),
            INDEX idx_kmp_date (plan_date),
            INDEX idx_kmp_camp (camp_id),
            INDEX idx_kmp_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'kitchen_menu_plans: OK';
} catch (Exception $e) {
    $results[] = 'kitchen_menu_plans: ERROR - ' . $e->getMessage();
}

// ── Table 2: kitchen_menu_dishes ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_menu_dishes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            menu_plan_id INT UNSIGNED NOT NULL,
            course ENUM('appetizer','soup','salad','main_course','side','dessert','beverage') NOT NULL,
            dish_name VARCHAR(200) NOT NULL,
            sort_order INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kmd_plan (menu_plan_id),
            INDEX idx_kmd_course (course),
            FOREIGN KEY (menu_plan_id) REFERENCES kitchen_menu_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'kitchen_menu_dishes: OK';
} catch (Exception $e) {
    $results[] = 'kitchen_menu_dishes: ERROR - ' . $e->getMessage();
}

// ── Table 3: kitchen_menu_ingredients ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_menu_ingredients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dish_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            suggested_qty DECIMAL(12,3) DEFAULT NULL,
            final_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            uom VARCHAR(10) DEFAULT NULL,
            source ENUM('ai_suggested','manual','modified') NOT NULL DEFAULT 'ai_suggested',
            is_removed TINYINT(1) NOT NULL DEFAULT 0,
            removed_reason VARCHAR(200) DEFAULT NULL,
            ai_reason VARCHAR(300) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_kmi_dish (dish_id),
            INDEX idx_kmi_item (item_id),
            FOREIGN KEY (dish_id) REFERENCES kitchen_menu_dishes(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'kitchen_menu_ingredients: OK';
} catch (Exception $e) {
    $results[] = 'kitchen_menu_ingredients: ERROR - ' . $e->getMessage();
}

// ── Table 4: kitchen_menu_audit_log ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kitchen_menu_audit_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            menu_plan_id INT UNSIGNED NOT NULL,
            dish_id INT UNSIGNED DEFAULT NULL,
            ingredient_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            action ENUM(
                'create_plan','update_portions','change_meal',
                'add_dish','remove_dish',
                'ai_suggest','add_ingredient','remove_ingredient','modify_qty',
                'confirm_plan','reopen_plan'
            ) NOT NULL,
            old_value JSON DEFAULT NULL,
            new_value JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kmal_plan (menu_plan_id),
            INDEX idx_kmal_user (user_id),
            INDEX idx_kmal_action (action),
            INDEX idx_kmal_created (created_at),
            FOREIGN KEY (menu_plan_id) REFERENCES kitchen_menu_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'kitchen_menu_audit_log: OK';
} catch (Exception $e) {
    $results[] = 'kitchen_menu_audit_log: ERROR - ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode([
    'message' => 'Kitchen menu tables setup complete',
    'tables' => $results,
], JSON_PRETTY_PRINT);
