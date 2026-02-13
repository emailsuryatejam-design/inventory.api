<?php
/**
 * One-time: Add portions column to kitchen_menu_dishes table.
 * Run via: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-dish-portions.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';
$pdo = getDB();

$results = [];

try {
    // Check if column already exists
    $cols = $pdo->query("SHOW COLUMNS FROM kitchen_menu_dishes LIKE 'portions'")->fetchAll();
    if (count($cols) > 0) {
        $results[] = 'portions column already exists';
    } else {
        $pdo->exec("ALTER TABLE kitchen_menu_dishes ADD COLUMN portions INT UNSIGNED NOT NULL DEFAULT 20 AFTER dish_name");
        $results[] = 'portions column added to kitchen_menu_dishes';
    }
} catch (Exception $e) {
    $results[] = 'ERROR: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
