<?php
/**
 * KCL Stores — API Configuration
 * Database credentials, constants, CORS headers
 */

// ── Database ────────────────────────────────────────
define('DB_HOST', 'auth-db960.hstgr.io');
define('DB_NAME', 'u929828006_KCLStores');
define('DB_USER', 'u929828006_KCLStores');
define('DB_PASS', '6145ury@Teja');

// ── App Constants ───────────────────────────────────
define('APP_NAME', 'KCL Stores');
define('JWT_SECRET', 'kcl-stores-jwt-secret-karibu-2026');
define('JWT_EXPIRY', 8 * 3600); // 8 hours
define('BASE_URL', 'https://darkblue-goshawk-672880.hostingersite.com');

// ── CORS Headers ────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
            ]);
        } catch (PDOException $e) {
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
    jsonResponse(['error' => $message], $status);
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
