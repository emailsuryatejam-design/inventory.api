<?php
/**
 * One-time: Add is_primary column to kitchen_menu_ingredients.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-ingredient-primary.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// Add is_primary column to kitchen_menu_ingredients
try {
    // Check if column already exists
    $cols = $pdo->query("SHOW COLUMNS FROM kitchen_menu_ingredients LIKE 'is_primary'")->fetchAll();
    if (count($cols) > 0) {
        $results[] = 'is_primary column already exists — skipped';
    } else {
        $pdo->exec("ALTER TABLE kitchen_menu_ingredients ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER uom");
        $results[] = 'is_primary column added to kitchen_menu_ingredients ✓';
    }
} catch (Exception $e) {
    $results[] = 'Error: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
