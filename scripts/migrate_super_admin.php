<?php
declare(strict_types=1);

// =====================================================================
// scripts/migrate_super_admin.php
// Migracion: agrega rol super_admin, columnas must_change_password /
// password_changed_at, tabla password_reset_tokens, y empresa 'Melius Services'.
// Idempotente: detecta si ya fue aplicada y no rompe.
// Uso: php scripts/migrate_super_admin.php
// =====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("No disponible via HTTP.\n");
}

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "Driver detectado: {$driver}\n";

if ($driver !== 'sqlite') {
    fwrite(STDERR, "Esta migracion solo aplica a SQLite local. Para MySQL, aplica sql/schema.mysql.sql via phpMyAdmin.\n");
    exit(1);
}

$pdo->beginTransaction();

try {
    // --- 1. Verificar estado actual ---
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $colNames = array_column($cols, 'name');
    $needsRoleFix = false;

    $createSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (strpos((string)$createSql, "'super_admin'") === false) {
        $needsRoleFix = true;
    }
    $hasMustChange = in_array('must_change_password', $colNames, true);
    $hasPwdChanged = in_array('password_changed_at', $colNames, true);

    echo "Estado: super_admin en CHECK=" . ($needsRoleFix ? 'NO' : 'SI')
       . ", must_change_password=" . ($hasMustChange ? 'SI' : 'NO')
       . ", password_changed_at=" . ($hasPwdChanged ? 'SI' : 'NO') . "\n";

    // --- 2. Recrear users si falta el CHECK super_admin (SQLite no soporta ALTER CHECK) ---
    if ($needsRoleFix) {
        echo "Recreando tabla users con CHECK actualizado...\n";

        $pdo->exec("CREATE TABLE users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            password_hash TEXT NULL,
            role TEXT NOT NULL DEFAULT 'consultant' CHECK (role IN ('consultant','admin','super_admin')),
            company_id INTEGER NULL,
            timezone TEXT NULL,
            work_start_time TEXT NULL,
            work_end_time TEXT NULL,
            work_days_mask INTEGER NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('pending_confirmation','active','disabled')),
            email_verified_at TEXT NULL,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            password_changed_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        )");

        // Copia respetando columnas que ya existian; las nuevas se llenan con defaults.
        $sourceCols = array_intersect(
            ['id','email','name','password_hash','role','company_id','timezone',
             'work_start_time','work_end_time','work_days_mask','is_active','status',
             'email_verified_at','failed_attempts','locked_until','must_change_password',
             'password_changed_at','created_at'],
            $colNames
        );
        $colList = implode(',', $sourceCols);
        $pdo->exec("INSERT INTO users_new ({$colList}) SELECT {$colList} FROM users");

        $pdo->exec("DROP TABLE users");
        $pdo->exec("ALTER TABLE users_new RENAME TO users");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_company ON users(company_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)");
        echo "  users recreada OK.\n";
    } else {
        // Si el CHECK ya esta bien pero faltan columnas, ALTER ADD COLUMN directo.
        if (!$hasMustChange) {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0");
            echo "  ADD COLUMN must_change_password OK.\n";
        }
        if (!$hasPwdChanged) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at TEXT NULL");
            echo "  ADD COLUMN password_changed_at OK.\n";
        }
    }

    // --- 3. Tabla password_reset_tokens ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        consumed_at TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prtokens_user ON password_reset_tokens(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prtokens_expires ON password_reset_tokens(expires_at)");
    echo "  password_reset_tokens lista OK.\n";

    // --- 4. Seed Melius Services como empresa ---
    $pdo->exec("INSERT OR IGNORE INTO companies (name, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late)
                VALUES ('Melius Services', 'America/Mexico_City', '09:00', '18:00', 31, 15)");
    echo "  Melius Services insertada o ya existia OK.\n";

    $pdo->commit();
    echo "\nMigracion completada con exito.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
