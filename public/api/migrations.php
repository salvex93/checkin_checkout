<?php
declare(strict_types=1);

// =====================================================================
// migrations.php — Biblioteca de migraciones idempotentes invocables
// desde CLI (scripts/) y desde endpoints admin (cuando el hosting bloquea SSH).
// Cada funcion DEBE ser idempotente y NO destructiva.
// =====================================================================

require_once __DIR__ . '/db.php';

/**
 * Ejecuta la migracion location_alerts. Captura todos los logs y los devuelve
 * como array de strings. No imprime nada (a diferencia de los scripts CLI).
 */
function run_migration_location_alerts(PDO $pdo): array {
    $log = [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $log[] = "driver: {$driver}";

    if ($driver === 'sqlite') {
        $log = array_merge($log, migrate_sqlite_location_alerts($pdo));
    } elseif ($driver === 'mysql') {
        $log = array_merge($log, migrate_mysql_location_alerts($pdo));
    } else {
        $log[] = "ERROR: driver no soportado: {$driver}";
        return $log;
    }
    $log[] = 'OK';
    return $log;
}

function migrate_sqlite_location_alerts(PDO $pdo): array {
    $log = [];
    foreach ([
        ['attendance_records', 'geo_city',              'TEXT NULL'],
        ['attendance_records', 'geo_region',            'TEXT NULL'],
        ['attendance_records', 'geo_lat',               'REAL NULL'],
        ['attendance_records', 'geo_lon',               'REAL NULL'],
        ['attendance_records', 'geo_alert_flag',        'INTEGER NOT NULL DEFAULT 0'],
        ['attendance_records', 'geo_alert_reasons',     'TEXT NULL'],
        ['attendance_records', 'geo_exit_country_code', 'TEXT NULL'],
        ['attendance_records', 'geo_exit_city',         'TEXT NULL'],
        ['attendance_records', 'geo_exit_lat',          'REAL NULL'],
        ['attendance_records', 'geo_exit_lon',          'REAL NULL'],
    ] as $c) {
        $log[] = add_sqlite_column_safe($pdo, $c[0], $c[1], $c[2]);
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_records_alert ON attendance_records(geo_alert_flag)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        attendance_id INTEGER NOT NULL,
        triggered_at TEXT DEFAULT CURRENT_TIMESTAMP,
        reason_codes TEXT NOT NULL,
        prev_country_code TEXT NULL,
        prev_city TEXT NULL,
        prev_lat REAL NULL,
        prev_lon REAL NULL,
        prev_marked_at TEXT NULL,
        curr_country_code TEXT NULL,
        curr_city TEXT NULL,
        curr_lat REAL NULL,
        curr_lon REAL NULL,
        distance_km REAL NULL,
        elapsed_minutes INTEGER NULL,
        implied_speed_kmh REAL NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','reviewed','dismissed')),
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        notes TEXT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_locales_status ON location_alerts(status, triggered_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_locales_user ON location_alerts(user_id)");
    $log[] = 'tabla location_alerts asegurada (sqlite)';
    return $log;
}

function add_sqlite_column_safe(PDO $pdo, string $table, string $col, string $type): string {
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($col, $cols, true)) return "= {$table}.{$col}";
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    return "+ {$table}.{$col}";
}

function migrate_mysql_location_alerts(PDO $pdo): array {
    $log = [];
    foreach ([
        ['attendance_records', 'geo_city',              'VARCHAR(120) NULL'],
        ['attendance_records', 'geo_region',            'VARCHAR(120) NULL'],
        ['attendance_records', 'geo_lat',               'DECIMAL(9,6) NULL'],
        ['attendance_records', 'geo_lon',               'DECIMAL(9,6) NULL'],
        ['attendance_records', 'geo_alert_flag',        'TINYINT(1) NOT NULL DEFAULT 0'],
        ['attendance_records', 'geo_alert_reasons',     'VARCHAR(200) NULL'],
        ['attendance_records', 'geo_exit_country_code', 'CHAR(2) NULL'],
        ['attendance_records', 'geo_exit_city',         'VARCHAR(120) NULL'],
        ['attendance_records', 'geo_exit_lat',          'DECIMAL(9,6) NULL'],
        ['attendance_records', 'geo_exit_lon',          'DECIMAL(9,6) NULL'],
    ] as $c) {
        $log[] = add_mysql_column_safe($pdo, $c[0], $c[1], $c[2]);
    }
    $log[] = add_mysql_index_safe($pdo, 'attendance_records', 'idx_records_alert', '(geo_alert_flag)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        attendance_id INT NOT NULL,
        triggered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reason_codes VARCHAR(200) NOT NULL,
        prev_country_code CHAR(2) NULL,
        prev_city VARCHAR(120) NULL,
        prev_lat DECIMAL(9,6) NULL,
        prev_lon DECIMAL(9,6) NULL,
        prev_marked_at DATETIME NULL,
        curr_country_code CHAR(2) NULL,
        curr_city VARCHAR(120) NULL,
        curr_lat DECIMAL(9,6) NULL,
        curr_lon DECIMAL(9,6) NULL,
        distance_km DECIMAL(10,2) NULL,
        elapsed_minutes INT NULL,
        implied_speed_kmh DECIMAL(10,2) NULL,
        status ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        notes TEXT NULL,
        INDEX idx_locales_status (status, triggered_at),
        INDEX idx_locales_user (user_id),
        CONSTRAINT fk_locales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_locales_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
        CONSTRAINT fk_locales_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = 'tabla location_alerts asegurada (mysql)';
    $log[] = extend_email_templates_kind_enum_safe($pdo);
    return $log;
}

function add_mysql_column_safe(PDO $pdo, string $table, string $col, string $type): string {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    if ((int)$stmt->fetchColumn() > 0) return "= {$table}.{$col}";
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    return "+ {$table}.{$col}";
}

function add_mysql_index_safe(PDO $pdo, string $table, string $indexName, string $colsExpr): string {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$table, $indexName]);
    if ((int)$stmt->fetchColumn() > 0) return "= indice {$table}.{$indexName}";
    $pdo->exec("CREATE INDEX {$indexName} ON {$table} {$colsExpr}");
    return "+ indice {$table}.{$indexName}";
}

/**
 * Migracion idempotente para columnas de PII cifrada en users.
 * Agrega email_enc, email_hash y full_name_enc sin tocar email/name vigentes.
 */
function run_migration_pii_encryption(PDO $pdo): array {
    $log = [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $log[] = "driver: {$driver}";
    if ($driver === 'sqlite') {
        $log[] = add_sqlite_column_safe($pdo, 'users', 'email_enc', 'TEXT NULL');
        $log[] = add_sqlite_column_safe($pdo, 'users', 'email_hash', 'TEXT NULL');
        $log[] = add_sqlite_column_safe($pdo, 'users', 'full_name_enc', 'TEXT NULL');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_hash ON users(email_hash)");
        $log[] = '+ indice unico idx_users_email_hash asegurado';
    } elseif ($driver === 'mysql') {
        $log[] = add_mysql_column_safe($pdo, 'users', 'email_enc', 'TEXT NULL');
        $log[] = add_mysql_column_safe($pdo, 'users', 'email_hash', 'VARCHAR(64) NULL');
        $log[] = add_mysql_column_safe($pdo, 'users', 'full_name_enc', 'TEXT NULL');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email_hash'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("CREATE UNIQUE INDEX idx_users_email_hash ON users(email_hash)");
            $log[] = '+ indice unico idx_users_email_hash';
        } else {
            $log[] = '= indice idx_users_email_hash';
        }
    } else {
        $log[] = "ERROR: driver no soportado";
        return $log;
    }
    $log[] = 'OK';
    return $log;
}

/**
 * Backfill de PII cifrada. Itera usuarios con email_hash NULL y los completa.
 * Lotes de 100 con commit/rollback por lote.
 */
function run_backfill_pii_encryption(PDO $pdo): array {
    require_once __DIR__ . '/crypto.php';
    $log = [];
    try {
        $pdo->query("SELECT email_hash FROM users LIMIT 1");
    } catch (Throwable $e) {
        $log[] = 'ERROR: columna email_hash inexistente; corre primero migration=pii_encryption';
        return $log;
    }
    $total = (int)$pdo->query("SELECT COUNT(*) AS c FROM users WHERE email_hash IS NULL OR email_hash = ''")->fetch(PDO::FETCH_ASSOC)['c'];
    $log[] = "pendientes: {$total}";
    if ($total === 0) { $log[] = 'OK (nada que hacer)'; return $log; }

    $batchSize = 100;
    $processed = 0;
    $errors = 0;
    while (true) {
        $rows = $pdo->query("SELECT id, email, name FROM users WHERE email_hash IS NULL OR email_hash = '' LIMIT {$batchSize}")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE users SET email_enc = ?, email_hash = ?, full_name_enc = ? WHERE id = ?");
            foreach ($rows as $r) {
                $email = (string)($r['email'] ?? '');
                $name  = (string)($r['name'] ?? '');
                if ($email === '') { $errors++; continue; }
                $upd->execute([
                    pii_encrypt($email),
                    pii_hash($email),
                    $name !== '' ? pii_encrypt($name) : null,
                    (int)$r['id'],
                ]);
                $processed++;
            }
            $pdo->commit();
            $log[] = "lote OK: {$processed}/{$total}";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $log[] = 'ERROR en lote: ' . $e->getMessage();
            return $log;
        }
    }
    $log[] = "DONE procesados={$processed} errores={$errors}";
    return $log;
}

/**
 * Purga historico de horas extra. El modulo quedo deprecado en UI; esta
 * migracion limpia las filas pendientes y los marcadores en attendance_records
 * sin eliminar las columnas (asi el codigo que aun lee overtime_hours = 0
 * sigue funcionando).
 * Operacion: DELETE FROM overtime_requests + UPDATE attendance_records
 * SET overtime_hours=0, overtime_status='none', overtime_reason=NULL,
 * closed_reason=NULL where closed_reason='overtime'.
 */
function run_migration_purge_overtime(PDO $pdo): array {
    $log = [];
    try {
        $deleted = $pdo->exec("DELETE FROM overtime_requests");
        $log[] = "overtime_requests filas borradas: " . (int)$deleted;
    } catch (Throwable $e) {
        $log[] = "overtime_requests no existe o no se pudo borrar: " . $e->getMessage();
    }
    try {
        $reset = $pdo->exec("UPDATE attendance_records
                                SET overtime_hours = 0,
                                    overtime_status = 'none',
                                    overtime_reason = NULL
                              WHERE overtime_hours > 0 OR overtime_status <> 'none' OR overtime_reason IS NOT NULL");
        $log[] = "attendance_records reseteados: " . (int)$reset;
    } catch (Throwable $e) {
        $log[] = "no se pudo resetear attendance_records: " . $e->getMessage();
    }
    try {
        $reclassed = $pdo->exec("UPDATE attendance_records
                                    SET closed_reason = NULL
                                  WHERE closed_reason = 'overtime'");
        $log[] = "closed_reason='overtime' limpiados: " . (int)$reclassed;
    } catch (Throwable $e) {
        $log[] = "no se pudo limpiar closed_reason: " . $e->getMessage();
    }
    $log[] = 'OK';
    return $log;
}

function extend_email_templates_kind_enum_safe(PDO $pdo): string {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'kind'"
    );
    $stmt->execute();
    $current = (string)$stmt->fetchColumn();
    if ($current === '') return '- email_templates.kind no existe';
    if (stripos($current, "'location_alert'") !== false) return '= email_templates.kind ya incluye location_alert';
    $pdo->exec(
        "ALTER TABLE email_templates MODIFY COLUMN kind
         ENUM('invitation','password_reset','admin_disabled','admin_delete_receipt','location_alert') NOT NULL"
    );
    return '+ email_templates.kind extendido con location_alert';
}
