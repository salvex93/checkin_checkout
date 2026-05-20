<?php
declare(strict_types=1);

// Tests unitarios puros del motor de alertas geo (geo_alerts.php).
// Usa SQLite in-memory para no tocar la BD local.
// Ejecutar: C:\xampp\php\php.exe scripts\test_geo_alerts_unit.php

require_once __DIR__ . '/../public/api/geo_alerts.php';

$passed = 0;
$failed = 0;
$failures = [];

function assert_eq($expected, $actual, string $name): void {
    global $passed, $failed, $failures;
    if ($expected === $actual) {
        $passed++;
        echo "  OK  {$name}\n";
    } else {
        $failed++;
        $failures[] = $name;
        echo "  FAIL {$name}\n";
        echo "       esperado: " . var_export($expected, true) . "\n";
        echo "       obtenido: " . var_export($actual, true) . "\n";
    }
}

function assert_true(bool $cond, string $name): void {
    assert_eq(true, $cond, $name);
}

function setup_memory_db(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE attendance_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        work_date TEXT NOT NULL,
        entry_time TEXT NOT NULL,
        geo_country_code TEXT NULL,
        geo_city TEXT NULL,
        geo_lat REAL NULL,
        geo_lon REAL NULL,
        geo_source TEXT NULL
    )");
    return $pdo;
}

function seed_history(PDO $pdo, int $userId, array $entries): void {
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_records (user_id, work_date, entry_time,
            geo_country_code, geo_city, geo_lat, geo_lon, geo_source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($entries as $e) {
        $stmt->execute([
            $userId, $e['date'], $e['time'],
            $e['country'] ?? null, $e['city'] ?? null,
            $e['lat'] ?? null, $e['lon'] ?? null,
            'ip',
        ]);
    }
}

// === haversine ===
echo "[haversine]\n";
$d = geo_haversine_km(19.4326, -99.1332, 19.4326, -99.1332);
assert_true(abs($d - 0.0) < 0.01, 'haversine_same_point_is_zero');

// CDMX (19.43, -99.13) -> Bogota (4.71, -74.07) ~ 3160 km
$d = geo_haversine_km(19.4326, -99.1332, 4.7110, -74.0721);
assert_true($d > 3100 && $d < 3250, 'haversine_cdmx_to_bogota_3100_3250km');

// CDMX -> Monterrey (~700km)
$d = geo_haversine_km(19.4326, -99.1332, 25.6866, -100.3161);
assert_true($d > 650 && $d < 800, 'haversine_cdmx_to_monterrey_700km');

// === geo_centroid ===
echo "[centroid]\n";
$c = geo_centroid([
    ['geo_lat' => 10.0, 'geo_lon' => 20.0],
    ['geo_lat' => 30.0, 'geo_lon' => 40.0],
]);
assert_eq(['lat' => 20.0, 'lon' => 30.0], $c, 'centroid_two_points_avg');

$c = geo_centroid([['geo_lat' => null, 'geo_lon' => null]]);
assert_eq(null, $c, 'centroid_all_null_returns_null');

// === evaluate sin historial ===
echo "[evaluate_no_history]\n";
$pdo = setup_memory_db();
$newGeo = [
    'source' => 'ip', 'country_code' => 'MX', 'city' => 'CDMX',
    'lat' => 19.43, 'lon' => -99.13,
];
$res = geo_evaluate_alert($pdo, 1, $newGeo, '2026-05-19 09:00:00');
assert_eq(false, $res['flag'], 'no_history_no_alert');
assert_eq([], $res['reasons'], 'no_history_no_reasons');

// === evaluate NEW_COUNTRY ===
echo "[evaluate_new_country]\n";
$pdo = setup_memory_db();
seed_history($pdo, 1, [
    ['date' => '2026-05-15', 'time' => '09:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
    ['date' => '2026-05-16', 'time' => '09:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
]);
$newGeo = [
    'source' => 'ip', 'country_code' => 'CO', 'city' => 'Bogota',
    'lat' => 4.71, 'lon' => -74.07,
];
$res = geo_evaluate_alert($pdo, 1, $newGeo, '2026-05-19 09:00:00');
assert_eq(true, $res['flag'], 'new_country_flag_true');
assert_true(in_array('NEW_COUNTRY', $res['reasons'], true), 'new_country_reason_present');
assert_true(in_array('FAR_FROM_HISTORY', $res['reasons'], true), 'new_country_also_far_from_history');

// === evaluate IMPOSSIBLE_SPEED ===
echo "[evaluate_impossible_speed]\n";
$pdo = setup_memory_db();
// Marca previa hace 1 hora desde CDMX
seed_history($pdo, 1, [
    ['date' => '2026-05-19', 'time' => '08:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
]);
// Nueva marca desde Madrid (~9000km en 1 hora -> imposible)
$newGeo = [
    'source' => 'ip', 'country_code' => 'ES', 'city' => 'Madrid',
    'lat' => 40.4168, 'lon' => -3.7038,
];
$res = geo_evaluate_alert($pdo, 1, $newGeo, '2026-05-19 09:00:00');
assert_eq(true, $res['flag'], 'impossible_speed_flag_true');
assert_true(in_array('IMPOSSIBLE_SPEED', $res['reasons'], true), 'impossible_speed_reason_present');
assert_true($res['context']['implied_speed_kmh'] > 5000, 'impossible_speed_context_above_5000kmh');

// === evaluate FAR_FROM_HISTORY pero NO new country ===
echo "[evaluate_far_only]\n";
$pdo = setup_memory_db();
// Historial concentrado en CDMX (varios puntos)
seed_history($pdo, 1, [
    ['date' => '2026-05-15', 'time' => '09:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
    ['date' => '2026-05-16', 'time' => '09:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
    ['date' => '2026-05-17', 'time' => '09:00', 'country' => 'MX', 'city' => 'CDMX', 'lat' => 19.43, 'lon' => -99.13],
    // Tambien marcado desde Cancun (lejos pero mismo pais)
    ['date' => '2026-05-18', 'time' => '09:00', 'country' => 'MX', 'city' => 'Cancun', 'lat' => 21.1619, 'lon' => -86.8515],
]);
// Marca actual desde Tijuana - mismo pais, lejos del centroide
$newGeo = [
    'source' => 'ip', 'country_code' => 'MX', 'city' => 'Tijuana',
    'lat' => 32.5149, 'lon' => -117.0382,
];
$res = geo_evaluate_alert($pdo, 1, $newGeo, '2026-05-19 09:00:00');
assert_true($res['flag'], 'far_from_history_flag_true');
assert_true(in_array('FAR_FROM_HISTORY', $res['reasons'], true), 'far_from_history_reason_present');
assert_eq(false, in_array('NEW_COUNTRY', $res['reasons'], true), 'far_from_history_no_new_country_when_same');

// === evaluate sin geo no dispara ===
echo "[evaluate_no_geo]\n";
$pdo = setup_memory_db();
seed_history($pdo, 1, [
    ['date' => '2026-05-15', 'time' => '09:00', 'country' => 'MX', 'lat' => 19.43, 'lon' => -99.13],
]);
$res = geo_evaluate_alert($pdo, 1, ['source' => 'none', 'country_code' => null], '2026-05-19 09:00:00');
assert_eq(false, $res['flag'], 'no_geo_source_no_alert');

echo "\n";
echo "RESULTADOS: {$passed} OK, {$failed} FAIL\n";
if ($failed > 0) {
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "Todos los tests pasaron.\n";
