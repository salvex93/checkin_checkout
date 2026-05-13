<?php
declare(strict_types=1);

// =====================================================================
// migrate_tz_hybrid.php — Anade columnas client_timezone y tz_mismatch
// a attendance_records para soportar TZ del navegador con bandera de
// auditoria (tarea #34). Idempotente: detecta columnas existentes antes
// de crearlas. Soporta SQLite y MySQL.
// Ejecucion: php scripts/migrate_tz_hybrid.php
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function table_columns(PDO $pdo, string $driver, string $table): array {
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        return array_map(fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$cols = table_columns($pdo, $driver, 'attendance_records');
$added = [];

if (!in_array('client_timezone', $cols, true)) {
    if ($driver === 'sqlite') {
        $pdo->exec('ALTER TABLE attendance_records ADD COLUMN client_timezone TEXT NULL');
    } else {
        $pdo->exec('ALTER TABLE attendance_records ADD COLUMN client_timezone VARCHAR(64) NULL');
    }
    $added[] = 'client_timezone';
}

if (!in_array('tz_mismatch', $cols, true)) {
    if ($driver === 'sqlite') {
        $pdo->exec('ALTER TABLE attendance_records ADD COLUMN tz_mismatch INTEGER NOT NULL DEFAULT 0');
    } else {
        $pdo->exec('ALTER TABLE attendance_records ADD COLUMN tz_mismatch TINYINT(1) NOT NULL DEFAULT 0');
    }
    $added[] = 'tz_mismatch';
}

echo $added
    ? "Columnas anadidas: " . implode(', ', $added) . "\n"
    : "Schema ya estaba actualizado. Sin cambios.\n";
