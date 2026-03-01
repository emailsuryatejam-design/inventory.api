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

// Validate file size
$maxSize = 10 * 1024 * 1024; // 10MB
if ($file['size'] > $maxSize) {
    jsonError('File too large. Max 10MB', 400);
}

// Validate MIME type server-side (don't trust client-provided type)
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mimeType, $allowed)) {
    jsonError('Invalid file type. Allowed: JPG, PNG, WebP', 400);
}

// Verify it's actually an image
$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    jsonError('File is not a valid image', 400);
}

// Create upload dir
$uploadDir = __DIR__ . '/uploads/dishes';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$ext = $extMap[$mimeType] ?? 'jpg';
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
