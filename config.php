<?php
/**
 * KCL Stores — API Configuration
 * Database credentials, constants, CORS headers
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/logger.php';

// ── Database ────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));

// ── App Constants ───────────────────────────────────
define('APP_NAME', 'WebSquare');
define('JWT_SECRET', env('JWT_SECRET', 'change-me-in-env-file'));
define('JWT_EXPIRY', 8 * 3600); // 8 hours
define('BASE_URL', env('APP_URL', ''));

// ── Abort if JWT_SECRET not configured ──────────────
if (JWT_SECRET === 'change-me-in-env-file' && env('DEBUG') !== 'true') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfigured']);
    exit;
}

// ── CORS Headers ────────────────────────────────────
// Same-origin (websquare.pro/api) needs no CORS headers.
// Only allow explicit origins listed in .env for dev / mobile app.
$allowedOrigins = array_filter(array_map('trim', explode(',', env('ALLOWED_ORIGINS', ''))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin && !empty($allowedOrigins) && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
// No wildcard CORS — not even in debug mode

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Database Connection ─────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (PDOException $e) {
            appLog('error', 'Database connection failed', ['msg' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }
    }
    return $pdo;
}

// ── Helper Functions ────────────────────────────────

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message, $status = 400) {
    jsonResponse(['error' => true, 'message' => $message, 'code' => $status], $status);
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
    }
    return $input;
}

function requireMethod($method) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        jsonError('Method not allowed', 405);
    }
}

function requireFields($data, $fields) {
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            jsonError("Missing required field: {$field}", 400);
        }
    }
}
