<?php
/**
 * One-time: Add tracking columns to kitchen_menu_ingredients + create kitchen_weekly_groceries.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-groceries-tracking.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// 1. Add ordered_qty, received_qty, consumed_qty to kitchen_menu_ingredients
$cols = ['ordered_qty', 'received_qty', 'consumed_qty'];
foreach ($cols as $col) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM kitchen_menu_ingredients LIKE '{$col}'")->fetchAll();
        if (count($check) > 0) {
            $results[] = "{$col} column already exists — skipped";
        } else {
            $after = $col === 'ordered_qty' ? 'is_primary' : ($col === 'received_qty' ? 'ordered_qty' : 'received_qty');
            $pdo->exec("ALTER TABLE kitchen_menu_ingredients ADD COLUMN {$col} DECIMAL(10,3) DEFAULT NULL AFTER {$after}");
            $results[] = "{$col} column added to kitchen_menu_ingredients ✓";
        }
    } catch (Exception $e) {
        $results[] = "{$col}: " . $e->getMessage();
    }
}

// 2. Create kitchen_weekly_groceries table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_weekly_groceries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        camp_id INT UNSIGNED NOT NULL,
        week_start DATE NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        projected_qty DECIMAL(10,3) NOT NULL DEFAULT 0,
        ordered_qty DECIMAL(10,3) DEFAULT NULL,
        received_qty DECIMAL(10,3) DEFAULT NULL,
        added_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_camp_week_item (camp_id, week_start, item_id),
        FOREIGN KEY (camp_id) REFERENCES camps(id),
        FOREIGN KEY (item_id) REFERENCES items(id),
        FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = 'kitchen_weekly_groceries table created ✓';
} catch (Exception $e) {
    $results[] = 'kitchen_weekly_groceries: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
