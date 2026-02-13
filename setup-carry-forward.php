<?php
/**
 * One-time: Add 'carried_forward' to source ENUM on kitchen_menu_ingredients.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/setup-carry-forward.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run migration']);
    exit;
}

$pdo = getDB();
$results = [];

// 1. Add 'carried_forward' to source ENUM on kitchen_menu_ingredients
try {
    $pdo->exec("
        ALTER TABLE kitchen_menu_ingredients
        MODIFY COLUMN source ENUM('ai_suggested','manual','modified','recipe','carried_forward') NOT NULL DEFAULT 'manual'
    ");
    $results[] = "source ENUM updated with 'carried_forward' ✓";
} catch (Exception $e) {
    $results[] = 'source ENUM: ' . $e->getMessage();
}

jsonResponse(['results' => $results]);
