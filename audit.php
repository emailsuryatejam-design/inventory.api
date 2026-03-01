<?php
/**
 * KCL Stores — Audit Trail Logger
 * Records sensitive operations for compliance
 */

require_once __DIR__ . '/logger.php';

function auditLog(PDO $pdo, string $action, int $userId, array $details = []) {
    try {
        // Try database audit log first
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (action, user_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $action,
            $userId,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Exception $e) {
        // If audit table doesn't exist yet, fall back to file log
        appLog('audit', "$action by user:$userId", $details);
    }
}
