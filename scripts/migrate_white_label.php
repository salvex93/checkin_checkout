<?php
declare(strict_types=1);

// =====================================================================
// migrate_white_label.php — Tabla tenant_settings (single-row) y columnas
// de override por empresa (branding_logo_url, branding_primary, branding_secondary).
// Idempotente. Soporta SQLite y MySQL.
// Ejecucion: php scripts/migrate_white_label.php
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function tw_table_exists(PDO $pdo, string $driver, string $table): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function tw_table_columns(PDO $pdo, string $driver, string $table): array {
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        return array_map(fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$report = [];

// 1) Tabla tenant_settings (single-row, id=1 fijo)
if (!tw_table_exists($pdo, $driver, 'tenant_settings')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE tenant_settings (
            id INTEGER PRIMARY KEY,
            product_name TEXT NOT NULL DEFAULT 'Melius Clockin',
            logo_url TEXT NULL,
            primary_color TEXT NOT NULL DEFAULT '#07d6da',
            secondary_color TEXT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE tenant_settings (
            id INT PRIMARY KEY,
            product_name VARCHAR(120) NOT NULL DEFAULT 'Melius Clockin',
            logo_url VARCHAR(255) NULL,
            primary_color VARCHAR(9) NOT NULL DEFAULT '#07d6da',
            secondary_color VARCHAR(9) NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $report[] = 'tabla tenant_settings creada';
}

// 2) Seed fila inicial id=1 si no existe
$existing = $pdo->query('SELECT id FROM tenant_settings WHERE id = 1')->fetchColumn();
if (!$existing) {
    $pdo->exec("INSERT INTO tenant_settings (id, product_name, primary_color, secondary_color)
                VALUES (1, 'Melius Clockin', '#07d6da', '#9909fe')");
    $report[] = 'fila tenant_settings id=1 seedeada con defaults Melius';
}

// 3) Columnas override en companies: branding_logo_url, branding_primary, branding_secondary
if (tw_table_exists($pdo, $driver, 'companies')) {
    $cols = tw_table_columns($pdo, $driver, 'companies');
    if (!in_array('branding_logo_url', $cols, true)) {
        $pdo->exec('ALTER TABLE companies ADD COLUMN branding_logo_url ' . ($driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(255) NULL'));
        $report[] = 'columna companies.branding_logo_url creada';
    }
    if (!in_array('branding_primary', $cols, true)) {
        $pdo->exec('ALTER TABLE companies ADD COLUMN branding_primary ' . ($driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(9) NULL'));
        $report[] = 'columna companies.branding_primary creada';
    }
    if (!in_array('branding_secondary', $cols, true)) {
        $pdo->exec('ALTER TABLE companies ADD COLUMN branding_secondary ' . ($driver === 'sqlite' ? 'TEXT NULL' : 'VARCHAR(9) NULL'));
        $report[] = 'columna companies.branding_secondary creada';
    }
}

if (empty($report)) {
    echo "Sin cambios. Migracion white-label ya aplicada.\n";
} else {
    echo "Cambios aplicados:\n";
    foreach ($report as $r) echo " - {$r}\n";
}
