<?php
require_once __DIR__ . '/config.php';
$pdo = getDB();

$tables = ['stock_balances', 'stock_movements', 'dispatch_notes', 'dispatch_lines', 'items', 'item_suppliers', 'suppliers', 'users'];
$result = [];

foreach ($tables as $table) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
        $result[$table] = $cols;
    } catch (Exception $e) {
        $result[$table] = "ERROR: " . $e->getMessage();
    }
}

jsonResponse($result);
