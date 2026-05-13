<?php
declare(strict_types=1);

// =====================================================================
// index.php — Router unico de la API.
// Cada request HTTP entra por aqui, pasa por:
//   1. Headers de seguridad + CORS
//   2. Inicio de sesion HttpOnly Strict
//   3. Carga de body JSON
//   4. Dispatcher por path
// El CSRF se valida dentro de cada handler mutante (POST), no globalmente,
// porque ciertos endpoints (login) requieren obtener el token primero.
// =====================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/records.php';
require_once __DIR__ . '/admin.php';

// Headers de seguridad antes que nada
emit_security_headers();
handle_cors();

// Sesion segura (cookie HttpOnly + SameSite=Strict)
start_session_secure();

// Determinar path del endpoint. Soporta dos modos:
//   - Mod_rewrite/Apache: /api/auth/login (PATH_INFO o REQUEST_URI parseado)
//   - PHP built-in server: /api/index.php/auth/login
$reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo !== '') {
    $endpoint = trim($pathInfo, '/');
} else {
    // Quitar prefijo /api/ del REQUEST_URI
    $endpoint = preg_replace('#^/api/?#', '', $reqUri) ?? '';
    $endpoint = trim($endpoint, '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Limitar tamano del path defensivamente
if (strlen($endpoint) > 80) {
    err('NOT_FOUND', 'Endpoint no existe.', 404);
}

$body = ($method === 'POST') ? read_json_body() : [];

// === Dispatcher ===
try {
    switch ("{$method} {$endpoint}") {
        // --- CSRF token ---
        case 'GET csrf':
            ok(['csrf_token' => csrf_token()]);

        // --- Auth ---
        case 'POST auth/register':
            auth_register($body);
        case 'POST auth/login':
            auth_login($body);
        case 'POST auth/logout':
            auth_logout();
        case 'GET auth/me':
            auth_me();

        // --- Companies ---
        case 'GET companies':
            records_companies();

        // --- Records (usuario) ---
        case 'GET records/today':
            records_today();
        case 'GET records/mine':
            records_mine();
        case 'POST records/clockin':
            records_clockin($body);
        case 'POST records/clockout':
            records_clockout();
        case 'POST records/overtime':
            records_overtime($body);
        case 'POST records/change-company':
            records_change_company($body);

        // --- Admin ---
        case 'GET admin/records':
            admin_records();
        case 'GET admin/change-requests':
            admin_change_requests();
        case 'GET admin/overtime-requests':
            admin_overtime_requests();
        case 'POST admin/decide':
            admin_decide($body);

        default:
            err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
    }
} catch (Throwable $e) {
    // Cualquier excepcion no manejada: log servidor, respuesta generica al cliente.
    // En desarrollo se incluye el mensaje para facilitar debug; en prod jamas.
    error_log('[api] uncaught: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (IS_PROD) {
        err('SERVER_ERROR', 'Error interno.', 500);
    } else {
        err('SERVER_ERROR', $e->getMessage(), 500, ['trace' => $e->getTraceAsString()]);
    }
}
