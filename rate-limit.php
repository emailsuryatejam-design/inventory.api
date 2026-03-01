<?php
/**
 * KCL Stores — File-based Rate Limiter
 * No Redis needed — works on Hostinger shared hosting
 */

function checkRateLimit($key, $maxAttempts = 5, $windowSeconds = 300) {
    $dir = __DIR__ . '/cache/rate-limits';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $file = $dir . '/' . md5($key) . '.json';
    $now = time();

    $data = ['attempts' => [], 'blocked_until' => 0];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
    }

    // Check if currently blocked
    if ($now < ($data['blocked_until'] ?? 0)) {
        $wait = ($data['blocked_until'] ?? 0) - $now;
        jsonError("Too many attempts. Please wait {$wait} seconds.", 429);
    }

    // Clear expired attempts
    $data['attempts'] = array_values(array_filter(
        $data['attempts'] ?? [],
        fn($t) => ($now - $t) < $windowSeconds
    ));

    // Check if limit reached
    if (count($data['attempts']) >= $maxAttempts) {
        $data['blocked_until'] = $now + $windowSeconds;
        file_put_contents($file, json_encode($data), LOCK_EX);
        jsonError('Too many login attempts. Please wait 5 minutes.', 429);
    }

    // Record this attempt
    $data['attempts'][] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearRateLimit($key) {
    $dir = __DIR__ . '/cache/rate-limits';
    $file = $dir . '/' . md5($key) . '.json';
    if (file_exists($file)) unlink($file);
}
