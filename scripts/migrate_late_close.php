<?php
declare(strict_types=1);

// Migracion: agrega columnas para marcar cierre tardio en attendance_records
// y tabla late_close_log para historial de notificaciones a admins.

require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function column_exists(PDO $pdo, string $driver, string $table, string $col): bool {
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $col]);
        return (int)$stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (strcasecmp($r['name'], $col) === 0) return true;
    }
    return false;
}

if (!column_exists($pdo, $driver, 'attendance_records', 'late_close')) {
    $pdo->exec(
        $driver === 'mysql'
        ? "ALTER TABLE attendance_records ADD COLUMN late_close TINYINT(1) NOT NULL DEFAULT 0"
        : "ALTER TABLE attendance_records ADD COLUMN late_close INTEGER NOT NULL DEFAULT 0"
    );
    echo "+ attendance_records.late_close\n";
}
if (!column_exists($pdo, $driver, 'attendance_records', 'late_minutes')) {
    $pdo->exec(
        $driver === 'mysql'
        ? "ALTER TABLE attendance_records ADD COLUMN late_minutes INT NOT NULL DEFAULT 0"
        : "ALTER TABLE attendance_records ADD COLUMN late_minutes INTEGER NOT NULL DEFAULT 0"
    );
    echo "+ attendance_records.late_minutes\n";
}

echo "OK migrate_late_close (driver={$driver})\n";
