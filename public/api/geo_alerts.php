<?php
declare(strict_types=1);

// =====================================================================
// geo_alerts.php — Motor de evaluacion de cambios radicales de ubicacion.
// Reglas (OR): NEW_COUNTRY (no visto en 90d) | IMPOSSIBLE_SPEED (>800km/h
// respecto al ultimo registro con coords) | FAR_FROM_HISTORY (>500km
// del centroide del historial).
// =====================================================================

const GEO_ALERT_HISTORY_DAYS = 90;
const GEO_ALERT_MAX_SPEED_KMH = 800.0;
const GEO_ALERT_MAX_DISTANCE_KM = 500.0;

/**
 * Distancia haversine en kilometros entre dos puntos.
 */
function geo_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth = 6371.0;
    $toRad = static fn(float $d): float => $d * M_PI / 180.0;
    $dLat = $toRad($lat2 - $lat1);
    $dLon = $toRad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $earth * asin(min(1.0, sqrt($a)));
}

/**
 * Carga las marcas de jornada con geo del usuario en la ventana de retencion.
 * Devuelve filas con id, work_date, entry_time, geo_country_code, geo_city, geo_lat, geo_lon.
 */
function geo_load_user_history(PDO $pdo, int $userId, int $days = GEO_ALERT_HISTORY_DAYS): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $cutoff = $driver === 'sqlite'
        ? "date('now', '-{$days} days')"
        : "DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";

    $sql = "SELECT id, work_date, entry_time, geo_country_code, geo_city, geo_lat, geo_lon
              FROM attendance_records
             WHERE user_id = :uid
               AND geo_source = 'ip'
               AND work_date >= {$cutoff}
          ORDER BY work_date DESC, entry_time DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Calcula centroide simple (promedio aritmetico) de coordenadas validas.
 * Para >100 puntos en escalas globales se preferiria centroide geografico,
 * pero a este volumen el error introducido es < 50km en el peor caso.
 */
function geo_centroid(array $rows): ?array {
    $lats = []; $lons = [];
    foreach ($rows as $r) {
        if (is_numeric($r['geo_lat'] ?? null) && is_numeric($r['geo_lon'] ?? null)) {
            $lats[] = (float)$r['geo_lat'];
            $lons[] = (float)$r['geo_lon'];
        }
    }
    if (count($lats) === 0) return null;
    return ['lat' => array_sum($lats) / count($lats), 'lon' => array_sum($lons) / count($lons)];
}

/**
 * Evalua si la nueva geo dispara alerta contra el historial.
 * $newGeo: payload de geo_resolve() (debe traer country_code y opcionalmente lat/lon, city).
 * $referenceTime: 'YYYY-MM-DD HH:MM:SS' del marcaje actual (para velocidad implicita).
 * Retorna: ['flag' => bool, 'reasons' => string[], 'context' => array]
 */
function geo_evaluate_alert(PDO $pdo, int $userId, array $newGeo, string $referenceTime): array {
    $context = [
        'prev_country_code' => null, 'prev_city' => null,
        'prev_lat' => null, 'prev_lon' => null, 'prev_marked_at' => null,
        'curr_country_code' => $newGeo['country_code'] ?? null,
        'curr_city' => $newGeo['city'] ?? null,
        'curr_lat' => $newGeo['lat'] ?? null,
        'curr_lon' => $newGeo['lon'] ?? null,
        'distance_km' => null, 'elapsed_minutes' => null, 'implied_speed_kmh' => null,
    ];

    if (($newGeo['source'] ?? null) !== 'ip' || empty($newGeo['country_code'])) {
        return ['flag' => false, 'reasons' => [], 'context' => $context];
    }

    $history = geo_load_user_history($pdo, $userId);
    if (count($history) === 0) {
        // Sin historial no hay con que comparar. No es alerta.
        return ['flag' => false, 'reasons' => [], 'context' => $context];
    }

    $reasons = [];

    // 1) NEW_COUNTRY — el codigo no aparece en los ultimos N dias.
    $seenCountries = [];
    foreach ($history as $h) {
        $c = $h['geo_country_code'] ?? null;
        if (is_string($c) && $c !== '') $seenCountries[$c] = true;
    }
    if (!isset($seenCountries[$newGeo['country_code']])) {
        $reasons[] = 'NEW_COUNTRY';
    }

    // 2) IMPOSSIBLE_SPEED — respecto al ultimo registro con coords (mas reciente).
    $lastWithCoords = null;
    foreach ($history as $h) {
        if (is_numeric($h['geo_lat'] ?? null) && is_numeric($h['geo_lon'] ?? null)) {
            $lastWithCoords = $h;
            break;
        }
    }

    if ($lastWithCoords !== null && is_numeric($newGeo['lat'] ?? null) && is_numeric($newGeo['lon'] ?? null)) {
        $prevAt = trim(($lastWithCoords['work_date'] ?? '') . ' ' . ($lastWithCoords['entry_time'] ?? ''));
        $tsPrev = strtotime($prevAt);
        $tsCurr = strtotime($referenceTime);
        if ($tsPrev !== false && $tsCurr !== false && $tsCurr > $tsPrev) {
            $distKm = geo_haversine_km(
                (float)$lastWithCoords['geo_lat'], (float)$lastWithCoords['geo_lon'],
                (float)$newGeo['lat'], (float)$newGeo['lon']
            );
            $minutes = (int) round(($tsCurr - $tsPrev) / 60);
            $hours = max($minutes / 60.0, 1 / 60.0); // evita div/0 con 1 min minimo
            $speed = $distKm / $hours;
            $context['prev_country_code'] = $lastWithCoords['geo_country_code'] ?? null;
            $context['prev_city'] = $lastWithCoords['geo_city'] ?? null;
            $context['prev_lat'] = (float)$lastWithCoords['geo_lat'];
            $context['prev_lon'] = (float)$lastWithCoords['geo_lon'];
            $context['prev_marked_at'] = $prevAt;
            $context['distance_km'] = round($distKm, 2);
            $context['elapsed_minutes'] = $minutes;
            $context['implied_speed_kmh'] = round($speed, 2);
            if ($distKm > 50 && $speed > GEO_ALERT_MAX_SPEED_KMH) {
                $reasons[] = 'IMPOSSIBLE_SPEED';
            }
        }
    }

    // 3) FAR_FROM_HISTORY — > N km del centroide.
    if (is_numeric($newGeo['lat'] ?? null) && is_numeric($newGeo['lon'] ?? null)) {
        $centroid = geo_centroid($history);
        if ($centroid !== null) {
            $distToCentroid = geo_haversine_km(
                $centroid['lat'], $centroid['lon'],
                (float)$newGeo['lat'], (float)$newGeo['lon']
            );
            if ($distToCentroid > GEO_ALERT_MAX_DISTANCE_KM) {
                $reasons[] = 'FAR_FROM_HISTORY';
            }
        }
    }

    return [
        'flag' => count($reasons) > 0,
        'reasons' => array_values(array_unique($reasons)),
        'context' => $context,
    ];
}
