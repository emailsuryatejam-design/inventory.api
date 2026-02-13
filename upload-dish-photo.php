<?php
/**
 * KCL Stores — Dish Photo Upload
 * Accepts multipart image upload, saves to uploads/dishes/
 */

require_once __DIR__ . '/middleware.php';

$auth = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

if (empty($_FILES['photo'])) {
    jsonError('No photo uploaded', 400);
}

$file = $_FILES['photo'];

// Validate
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
if (!in_array($file['type'], $allowed)) {
    jsonError('Invalid file type. Allowed: JPG, PNG, WebP', 400);
}

$maxSize = 10 * 1024 * 1024; // 10MB
if ($file['size'] > $maxSize) {
    jsonError('File too large. Max 10MB', 400);
}

// Create upload dir
$uploadDir = __DIR__ . '/uploads/dishes';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = 'dish_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filepath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    jsonError('Failed to save file', 500);
}

// Return relative URL path
$url = '/uploads/dishes/' . $filename;

jsonResponse([
    'url' => $url,
    'filename' => $filename,
]);
