<?php
declare(strict_types=1);
// =====================================================================
// migrate_rate_limits.php — Tabla rate_limits para throttling.
// Idempotente. SQLite y MySQL.
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function rl_table_exists(PDO $pdo, string $driver, string $table): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

if (!rl_table_exists($pdo, $driver, 'rate_limits')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            key TEXT NOT NULL,
            hit_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX idx_rate_limits_scope_key ON rate_limits(scope, key, hit_at)");
    } else {
        $pdo->exec("CREATE TABLE rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(40) NOT NULL,
            `key` VARCHAR(190) NOT NULL,
            hit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rate_limits_scope_key (scope, `key`, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    echo "tabla rate_limits creada\n";
} else {
    echo "Sin cambios. Tabla rate_limits ya existe.\n";
}
