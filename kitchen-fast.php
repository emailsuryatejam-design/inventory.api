<?php
/**
 * KCL Stores — Fast Kitchen Cache Endpoint
 *
 * Serves pre-computed plan JSON from file cache.
 * NO MySQL connection — just JWT verify + file read.
 * ~50-100ms instead of ~1300ms.
 *
 * GET ?date=YYYY-MM-DD&meal=lunch|dinner
 * Returns same shape as chef_init: { plan, dishes, recipes }
 * Returns 204 (no cache) if file doesn't exist — frontend falls back to chef_init.
 */

// ── Inline config (no DB needed) ──────────────────────
define('JWT_SECRET', 'kcl-stores-jwt-secret-karibu-2026');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Inline JWT verify (no DB, no middleware include) ──
function base64url_dec($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function verifyJwt() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo '{"error":"Authentication required"}';
        exit;
    }
    $token = substr($authHeader, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        http_response_code(401);
        echo '{"error":"Invalid token"}';
        exit;
    }
    [$header, $payload, $signature] = $parts;
    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    ), '+/', '-_'), '=');
    if (!hash_equals($expected, $signature)) {
        http_response_code(401);
        echo '{"error":"Invalid token"}';
        exit;
    }
    $data = json_decode(base64url_dec($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) {
        http_response_code(401);
        echo '{"error":"Token expired"}';
        exit;
    }
    return $data;
}

$auth = verifyJwt();
$campId = $auth['camp_id'] ?? 0;
$date = $_GET['date'] ?? '';
$meal = $_GET['meal'] ?? '';

if (!$date || !$meal) {
    http_response_code(400);
    echo '{"error":"date and meal required"}';
    exit;
}

// ── Read pre-computed cache file ──
$cacheDir = __DIR__ . '/cache';
$cacheFile = "$cacheDir/plan-{$campId}-{$date}-{$meal}.json";

if (!file_exists($cacheFile)) {
    // No cache — tell frontend to use chef_init instead
    http_response_code(204);
    exit;
}

// Serve cache file directly — no JSON encode/decode overhead
$content = file_get_contents($cacheFile);
if ($content === false) {
    http_response_code(204);
    exit;
}

// Add cache-control headers
header('X-Cache: HIT');
header('X-Cache-File: ' . basename($cacheFile));
echo $content;
