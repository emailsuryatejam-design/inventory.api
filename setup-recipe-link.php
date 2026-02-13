<?php
/**
 * One-time migration: Link menu dishes to recipes + presentation scoring
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-recipe-link.php
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// 1. Add recipe_id to kitchen_menu_dishes
try {
    $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN recipe_id INT UNSIGNED DEFAULT NULL AFTER dish_name");
    $results[] = 'Added recipe_id to kitchen_menu_dishes ✓';
} catch (Exception $e) {
    $results[] = 'recipe_id: ' . $e->getMessage();
}

// 2. Add presentation columns
try {
    $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN presentation_score TINYINT UNSIGNED DEFAULT NULL AFTER portions");
    $results[] = 'Added presentation_score ✓';
} catch (Exception $e) {
    $results[] = 'presentation_score: ' . $e->getMessage();
}

try {
    $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN presentation_feedback TEXT DEFAULT NULL AFTER presentation_score");
    $results[] = 'Added presentation_feedback ✓';
} catch (Exception $e) {
    $results[] = 'presentation_feedback: ' . $e->getMessage();
}

try {
    $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN presentation_photo VARCHAR(500) DEFAULT NULL AFTER presentation_feedback");
    $results[] = 'Added presentation_photo ✓';
} catch (Exception $e) {
    $results[] = 'presentation_photo: ' . $e->getMessage();
}

// 3. Add 'recipe' to source ENUM in kitchen_menu_ingredients
try {
    $pdo->exec("ALTER TABLE kitchen_menu_ingredients MODIFY COLUMN source ENUM('ai_suggested','manual','modified','recipe') NOT NULL DEFAULT 'manual'");
    $results[] = 'Added recipe to source ENUM ✓';
} catch (Exception $e) {
    $results[] = 'source ENUM: ' . $e->getMessage();
}

// 4. Add load_recipe + rate_presentation to audit action ENUM
try {
    $pdo->exec("ALTER TABLE kitchen_menu_audit_log MODIFY COLUMN action ENUM(
        'create_plan','update_portions','change_meal',
        'add_dish','remove_dish',
        'ai_suggest','add_ingredient','remove_ingredient','modify_qty',
        'confirm_plan','reopen_plan',
        'load_recipe','rate_presentation'
    ) NOT NULL");
    $results[] = 'Added load_recipe + rate_presentation to audit ENUM ✓';
} catch (Exception $e) {
    $results[] = 'audit ENUM: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
