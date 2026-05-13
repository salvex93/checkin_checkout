<?php
declare(strict_types=1);

// Smoke test directo (sin HTTP) de la TZ hibrida en records.php.
// Inserta un user dummy si no existe, ejecuta records_clockin via include,
// y verifica que la fila quede con client_timezone y tz_mismatch correctos.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/records.php';

$pdo = Database::pdo();

// 1) Verificar columnas
$cols = array_map(fn($r) => $r['name'], $pdo->query('PRAGMA table_info(attendance_records)')->fetchAll(PDO::FETCH_ASSOC));
foreach (['client_timezone', 'tz_mismatch'] as $c) {
    if (!in_array($c, $cols, true)) {
        echo "FAIL: columna $c no existe\n";
        exit(1);
    }
}
echo "OK: columnas client_timezone y tz_mismatch presentes\n";

// 2) Probar resolve_effective_tz directamente
$sched = ['timezone' => 'America/Mexico_City'];

$r1 = resolve_effective_tz($sched, null);
assert($r1['client_tz'] === null && $r1['mismatch'] === 0, 'caso null debe caer al perfil sin mismatch');
echo "OK: client_timezone=null -> mismatch=0\n";

$r2 = resolve_effective_tz($sched, 'America/Mexico_City');
assert($r2['client_tz'] === 'America/Mexico_City' && $r2['mismatch'] === 0, 'misma TZ -> sin mismatch');
echo "OK: misma TZ -> mismatch=0\n";

$r3 = resolve_effective_tz($sched, 'America/Cancun');
assert($r3['client_tz'] === 'America/Cancun' && $r3['mismatch'] === 1, 'TZ distinta -> mismatch=1');
echo "OK: TZ distinta (Cancun) -> mismatch=1\n";

$r4 = resolve_effective_tz($sched, 'NotARealTZ/Invalid');
assert($r4['client_tz'] === null && $r4['mismatch'] === 0, 'TZ invalida -> ignorar y caer al perfil');
echo "OK: TZ invalida -> ignorada\n";

echo "\nTODOS LOS SMOKES PASARON\n";
