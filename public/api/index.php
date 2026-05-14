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
require_once __DIR__ . '/dashboard.php';

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

$body = in_array($method, ['POST', 'PUT', 'DELETE'], true) ? read_json_body() : [];

// Parser de paths con id: admin/companies/123 -> ('admin/companies', 123)
// admin/brands/123/logo -> ('admin/brands', 123, 'logo')
$endpointBase = $endpoint;
$endpointId = null;
$endpointAction = null;
if (preg_match('#^(admin/brands)/(\d+)/(logo)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/companies|admin/users|admin/brands|admin/dashboard/company)/(\d+)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
}

// === Dispatcher ===
try {
    // Rutas con id + accion (subrecurso)
    if ($endpointId !== null && $endpointAction !== null) {
        switch ("{$method} {$endpointBase}/{$endpointAction}") {
            case 'POST admin/brands/logo':
                admin_brands_upload_logo($endpointId);
            default:
                err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
        }
    }
    // Rutas con id (PUT/DELETE sobre recursos especificos)
    if ($endpointId !== null) {
        switch ("{$method} {$endpointBase}") {
            case 'PUT admin/companies':
                admin_companies_update($endpointId, $body);
            case 'DELETE admin/companies':
                admin_companies_delete($endpointId);
            case 'PUT admin/users':
                admin_users_update($endpointId, $body);
            case 'DELETE admin/users':
                admin_users_delete($endpointId, $body);
            case 'PUT admin/brands':
                admin_brands_update($endpointId, $body);
            case 'DELETE admin/brands':
                admin_brands_delete($endpointId);
            case 'GET admin/dashboard/company':
                admin_dashboard_company($endpointId);
            default:
                err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
        }
    }

    switch ("{$method} {$endpoint}") {
        // --- CSRF token ---
        case 'GET csrf':
            ok(['csrf_token' => csrf_token()]);

        // --- Auth ---
        // auth/register deprecado: alta de usuarios ahora va por admin/users/invite (#26).
        case 'POST auth/register':
            err('GONE', 'El registro publico esta deshabilitado. Solicita una invitacion al administrador.', 410);
        case 'POST auth/login':
            auth_login($body);
        case 'POST auth/logout':
            auth_logout();
        case 'GET auth/me':
            auth_me();
        case 'POST auth/change-password':
            auth_change_password($body);
        case 'POST auth/forgot-password':
            auth_forgot_password($body);
        case 'POST auth/reset-password':
            auth_reset_password($body);

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
            records_clockout($body);
        case 'POST records/overtime':
            records_overtime($body);
        case 'POST records/overtime-edit-request':
            records_overtime_edit_request($body);
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

        // --- Admin: empresas y agentes (Fase 2) ---
        case 'GET admin/companies':
            admin_companies_list();
        case 'POST admin/companies':
            admin_companies_create($body);
        case 'GET admin/brands':
            admin_brands_list();
        case 'POST admin/brands':
            admin_brands_create($body);
        case 'GET admin/users':
            admin_users_list();
        case 'POST admin/users/invite':
            admin_users_invite($body);
        case 'POST admin/users/bulk-invite':
            admin_users_bulk_invite($body);
        case 'GET admin/users/template.csv':
            admin_users_template_csv();

        // --- Admin: dashboards y busqueda (Fase 5) ---
        case 'GET admin/dashboard/global':
            admin_dashboard_global();
        case 'GET admin/agents/search':
            admin_agents_search();
        case 'GET admin/records/export':
            admin_records_export();

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
