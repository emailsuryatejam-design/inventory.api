<?php
/**
 * KCL Stores — Auth Middleware
 * JWT verification and role-based access control
 */

require_once __DIR__ . '/config.php';

// ── Simple JWT Implementation ───────────────────────
// Using HMAC-SHA256 (no external library needed)

function jwtEncode($payload) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time();
    $payloadEncoded = base64url_encode(json_encode($payload));
    $signature = base64url_encode(
        hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
    );
    return "$header.$payloadEncoded.$signature";
}

function jwtDecode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    // Verify signature
    $expectedSig = base64url_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    if (!hash_equals($expectedSig, $signature)) return null;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data) return null;

    // Check expiry
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// ── Auth Check ──────────────────────────────────────

function requireAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError('Authentication required', 401);
    }

    $token = substr($authHeader, 7);
    $payload = jwtDecode($token);

    if (!$payload) {
        jsonError('Invalid or expired token', 401);
    }

    return $payload;
}

function requireRole($allowedRoles) {
    $user = requireAuth();
    if (!in_array($user['role'], $allowedRoles)) {
        jsonError('Insufficient permissions', 403);
    }
    return $user;
}

// Role group helpers
function requireManager() {
    return requireRole(['stores_manager', 'procurement_officer', 'director', 'admin']);
}

function requireCampStaff() {
    return requireRole(['camp_storekeeper', 'chef', 'housekeeping', 'camp_manager',
                        'stores_manager', 'procurement_officer', 'director', 'admin']);
}

function requireAdmin() {
    return requireRole(['admin', 'director']);
}
