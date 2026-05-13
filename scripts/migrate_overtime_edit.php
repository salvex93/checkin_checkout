<?php
declare(strict_types=1);

// =====================================================================
// migrate_overtime_edit.php — Anade columnas request_type, referenced_request_id
// y new_hours a overtime_requests. Idempotente: detecta columnas existentes
// antes de intentar crearlas. Soporta SQLite y MySQL.
// Ejecucion: php scripts/migrate_overtime_edit.php
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

$cols = table_columns($pdo, $driver, 'overtime_requests');
$added = [];

if (!in_array('request_type', $cols, true)) {
    if ($driver === 'sqlite') {
        $pdo->exec("ALTER TABLE overtime_requests ADD COLUMN request_type TEXT NOT NULL DEFAULT 'new' CHECK (request_type IN ('new','edit'))");
    } else {
        $pdo->exec("ALTER TABLE overtime_requests ADD COLUMN request_type ENUM('new','edit') NOT NULL DEFAULT 'new'");
    }
    $added[] = 'request_type';
}

if (!in_array('referenced_request_id', $cols, true)) {
    $pdo->exec('ALTER TABLE overtime_requests ADD COLUMN referenced_request_id INTEGER NULL');
    $added[] = 'referenced_request_id';
}

if (!in_array('new_hours', $cols, true)) {
    if ($driver === 'sqlite') {
        $pdo->exec('ALTER TABLE overtime_requests ADD COLUMN new_hours REAL NULL');
    } else {
        $pdo->exec('ALTER TABLE overtime_requests ADD COLUMN new_hours DECIMAL(3,1) NULL');
    }
    $added[] = 'new_hours';
}

try {
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otreq_type ON overtime_requests(request_type)');
} catch (Throwable $e) {
    // MySQL antiguo no soporta IF NOT EXISTS en indices.
}

echo $added
    ? "Columnas anadidas: " . implode(', ', $added) . "\n"
    : "Schema ya estaba actualizado. Sin cambios.\n";
