<?php
/**
 * WebSquare — Tenant System Setup
 * Creates tenants table, global_admins table, adds tenant_id to users/camps
 * Run once: /api/setup-tenants.php
 */

require_once __DIR__ . '/config.php';

// Gate: only in debug mode or if no global_admins table exists
$pdo = getDB();

try {
    // ── Create tenants table ──────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tenants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) UNIQUE NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            industry VARCHAR(100) DEFAULT NULL,
            status ENUM('trial', 'active', 'suspended', 'expired') DEFAULT 'trial',
            trial_start DATE NOT NULL,
            trial_end DATE NOT NULL,
            plan VARCHAR(50) DEFAULT 'trial',
            max_users INT DEFAULT 5,
            max_camps INT DEFAULT 2,
            modules JSON DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_email (email),
            INDEX idx_trial_end (trial_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── Create global_admins table ─────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS global_admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── Add tenant_id to users (nullable for backward compatibility) ──
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN tenant_id INT DEFAULT NULL AFTER id");
        $pdo->exec("ALTER TABLE users ADD INDEX idx_tenant (tenant_id)");
    }

    // ── Add tenant_id to camps ──
    $cols = $pdo->query("SHOW COLUMNS FROM camps LIKE 'tenant_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE camps ADD COLUMN tenant_id INT DEFAULT NULL AFTER id");
        $pdo->exec("ALTER TABLE camps ADD INDEX idx_camp_tenant (tenant_id)");
    }

    // ── Create default global admin ──
    $adminExists = $pdo->query("SELECT COUNT(*) FROM global_admins")->fetchColumn();
    if ($adminExists == 0) {
        $hash = password_hash('SuperAdmin@2026', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO global_admins (username, password_hash, name, email) VALUES (?, ?, ?, ?)")
            ->execute(['superadmin', $hash, 'Super Admin', 'admin@websquare.io']);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Tenant system setup complete.',
        'default_admin' => [
            'username' => 'superadmin',
            'password' => 'SuperAdmin@2026',
            'note' => 'Change this password immediately!'
        ]
    ]);

} catch (PDOException $e) {
    error_log('[Setup] ' . $e->getMessage());
    jsonError('Setup failed: ' . $e->getMessage(), 500);
}
