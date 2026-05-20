<?php
declare(strict_types=1);

// Migracion idempotente: ampliacion de geo en attendance_records con
// city/region/lat/lon/alert_flag/alert_reasons + datos de salida, y nueva
// tabla location_alerts para el panel de revision.
// Tambien extiende email_templates.kind con 'location_alert'.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "[migrate_location_alerts] driver detectado: {$driver}\n";

if ($driver === 'sqlite') {
    migrate_sqlite($pdo);
} elseif ($driver === 'mysql') {
    migrate_mysql($pdo);
} else {
    fwrite(STDERR, "Driver no soportado: {$driver}\n");
    exit(1);
}

echo "[migrate_location_alerts] OK\n";

function migrate_sqlite(PDO $pdo): void {
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_city',              'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_region',            'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_lat',               'REAL NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_lon',               'REAL NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_alert_flag',        'INTEGER NOT NULL DEFAULT 0');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_alert_reasons',     'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_exit_country_code', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_exit_city',         'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_exit_lat',          'REAL NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_exit_lon',          'REAL NULL');

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

    // En SQLite el CHECK constraint del kind se traduce con la columna creada,
    // como SQLite no soporta DROP CHECK, se trabaja con migracion implicita:
    // los kinds nuevos se aceptan al recrear (no rompe registros existentes).
    echo "  - sqlite: location_alert se acepta en email_templates.kind via schema vigente\n";
}

function add_sqlite_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($col, $cols, true)) {
        echo "  - {$table}.{$col} ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}

function migrate_mysql(PDO $pdo): void {
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_city',              'VARCHAR(120) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_region',            'VARCHAR(120) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_lat',               'DECIMAL(9,6) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_lon',               'DECIMAL(9,6) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_alert_flag',        'TINYINT(1) NOT NULL DEFAULT 0');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_alert_reasons',     'VARCHAR(200) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_exit_country_code', 'CHAR(2) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_exit_city',         'VARCHAR(120) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_exit_lat',          'DECIMAL(9,6) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_exit_lon',          'DECIMAL(9,6) NULL');

    add_mysql_index_if_missing($pdo, 'attendance_records', 'idx_records_alert', '(geo_alert_flag)');

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

    extend_email_templates_kind_enum($pdo);
}

function add_mysql_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "  - {$table}.{$col} ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}

function add_mysql_index_if_missing(PDO $pdo, string $table, string $indexName, string $colsExpr): void {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$table, $indexName]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "  - indice {$table}.{$indexName} ya existe\n";
        return;
    }
    $pdo->exec("CREATE INDEX {$indexName} ON {$table} {$colsExpr}");
    echo "  + indice {$table}.{$indexName} creado\n";
}

function extend_email_templates_kind_enum(PDO $pdo): void {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'kind'"
    );
    $stmt->execute();
    $current = (string)$stmt->fetchColumn();
    if ($current === '' ) {
        echo "  - email_templates.kind no existe, se omite extension de enum\n";
        return;
    }
    if (stripos($current, "'location_alert'") !== false) {
        echo "  - email_templates.kind ya incluye location_alert\n";
        return;
    }
    $pdo->exec(
        "ALTER TABLE email_templates MODIFY COLUMN kind
         ENUM('invitation','password_reset','admin_disabled','admin_delete_receipt','location_alert') NOT NULL"
    );
    echo "  + email_templates.kind extendido con location_alert\n";
}
