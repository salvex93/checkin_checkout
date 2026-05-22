<?php
declare(strict_types=1);

// Migracion idempotente: columnas para PII cifrada en tabla users.
// Agrega email_enc (ciphertext AES-GCM), email_hash (HMAC-SHA256 deterministico
// para login lookup) y full_name_enc. NO toca las columnas existentes
// email/name todavia — el drop se planifica en sprint posterior cuando el
// backfill este 100% confirmado y el codigo no las lea.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "[migrate_pii_encryption] driver: {$driver}\n";

if ($driver === 'sqlite') {
    migrate_sqlite($pdo);
} elseif ($driver === 'mysql') {
    migrate_mysql($pdo);
} else {
    fwrite(STDERR, "Driver no soportado: {$driver}\n");
    exit(1);
}

echo "[migrate_pii_encryption] OK\n";

function migrate_sqlite(PDO $pdo): void {
    add_sqlite_column_if_missing($pdo, 'users', 'email_enc', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'users', 'email_hash', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'users', 'full_name_enc', 'TEXT NULL');
    // Indice unico permite NULL multiples (semantica SQLite). Buscado por login.
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_hash ON users(email_hash)");
}

function migrate_mysql(PDO $pdo): void {
    add_mysql_column_if_missing($pdo, 'users', 'email_enc', 'TEXT NULL');
    add_mysql_column_if_missing($pdo, 'users', 'email_hash', 'VARCHAR(64) NULL');
    add_mysql_column_if_missing($pdo, 'users', 'full_name_enc', 'TEXT NULL');
    // En MySQL, UNIQUE permite multiples NULLs.
    $existsIdx = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email_hash'")->fetchColumn();
    if ((int)$existsIdx === 0) {
        $pdo->exec("CREATE UNIQUE INDEX idx_users_email_hash ON users(email_hash)");
        echo "  + indice idx_users_email_hash creado\n";
    } else {
        echo "  - indice idx_users_email_hash ya existe\n";
    }
}

function add_sqlite_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($col, $cols, true)) { echo "  - {$table}.{$col} ya existe\n"; return; }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}

function add_mysql_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    if ((int)$stmt->fetchColumn() > 0) { echo "  - {$table}.{$col} ya existe\n"; return; }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}
