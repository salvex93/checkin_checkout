<?php
declare(strict_types=1);

// =====================================================================
// geo.php — Resolucion de geolocalizacion por IP (modo minimo).
// Solo registra pais y enmascara la IP (privacidad: ultimos octetos en cero).
// NO usa GPS, NO requiere consentimiento expreso de ubicacion precisa.
// Fuente: ip-api.com (free tier, 45 req/min, sin API key).
// Si la consulta falla o el resultado es invalido, degrada a null sin romper.
// =====================================================================

const GEO_API_URL = 'http://ip-api.com/json/';
const GEO_HTTP_TIMEOUT_SEC = 1.5;
const GEO_LOCAL_RANGES = [
    '127.0.0.1', '::1',
    '10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.',
    '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.',
];

/**
 * Enmascara IP: IPv4 -> a.b.c.0; IPv6 -> primeros 4 grupos + ::.
 * Cumple "data minimization" de GDPR y LFPDPPP.
 * Devuelve null si la entrada no es una IP valida.
 */
function geo_mask_ip(?string $ip): ?string {
    if (!is_string($ip) || $ip === '') return null;
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $expanded = inet_ntop(inet_pton($ip) ?: '');
        if (!$expanded) return null;
        $groups = explode(':', $expanded);
        $first4 = array_slice($groups, 0, 4);
        return implode(':', $first4) . '::';
    }
    return null;
}

/**
 * Valida codigo ISO-3166-1 alpha-2: dos letras mayusculas.
 */
function geo_validate_country_code(?string $code): ?string {
    if (!is_string($code)) return null;
    $code = strtoupper(trim($code));
    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
}

/**
 * Detecta direcciones locales/privadas donde la geolocalizacion via IP no aplica.
 */
function geo_is_local_ip(string $ip): bool {
    if ($ip === '' ) return true;
    foreach (GEO_LOCAL_RANGES as $prefix) {
        if ($ip === $prefix) return true;
        if (str_starts_with($ip, $prefix)) return true;
    }
    return false;
}

/**
 * Resuelve pais por IP usando ip-api.com.
 * Retorna ['country_code' => 'MX', 'country_name' => 'Mexico', 'ip_masked' => '1.2.3.0', 'source' => 'ip']
 * o un payload vacio con source='none' si no se pudo resolver / es IP local.
 * NUNCA lanza excepciones.
 */
function geo_resolve(string $ip): array {
    $masked = geo_mask_ip($ip);
    $empty = [
        'country_code' => null,
        'country_name' => null,
        'ip_masked' => $masked,
        'source' => 'none',
    ];

    if (geo_is_local_ip($ip)) return $empty;

    $response = geo_http_get(GEO_API_URL . urlencode($ip) . '?fields=status,country,countryCode,message');
    if ($response === null) return $empty;

    $parsed = json_decode($response, true);
    if (!is_array($parsed)) return $empty;
    if (($parsed['status'] ?? '') !== 'success') return $empty;

    $code = geo_validate_country_code($parsed['countryCode'] ?? null);
    $name = isset($parsed['country']) && is_string($parsed['country'])
        ? substr(trim($parsed['country']), 0, 80) : null;
    if ($code === null) return $empty;

    return [
        'country_code' => $code,
        'country_name' => $name,
        'ip_masked' => $masked,
        'source' => 'ip',
    ];
}

/**
 * HTTP GET ligero con timeout estricto. Devuelve el body o null ante fallo.
 * Aislado para que los tests unitarios puedan mockearlo definiendo la funcion antes.
 */
if (!function_exists('geo_http_get')) {
    function geo_http_get(string $url): ?string {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => GEO_HTTP_TIMEOUT_SEC,
                'ignore_errors' => true,
                'header' => "User-Agent: MeliusClockin/1.0\r\n",
            ],
        ]);
        try {
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        } catch (Throwable $_) {
            return null;
        }
    }
}

/**
 * Helper de alto nivel para usar en records.php: toma la IP del cliente actual
 * (via client_ip() de helpers.php) y devuelve el payload listo para persistir.
 */
function geo_resolve_current(): array {
    $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    return geo_resolve($ip);
}
