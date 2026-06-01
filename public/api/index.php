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
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/records.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/vacations.php';
require_once __DIR__ . '/anti_bot.php';

// Headers de seguridad antes que nada
emit_security_headers();
handle_cors();

// Filtro global anti-scraper: bloquea UAs de scanners, scrapers comerciales y
// herramientas de pentesting antes de tocar sesion o dispatch. OPTIONS exento
// para no romper preflight CORS legitimo.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'OPTIONS') {
    anti_bot_global_ua_filter();
}

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

// Rate-limit global por IP. clockin/clockout tienen anti_bot_min_gap propio
// — excluirlos aqui evita 429 por acumulacion de reintentos del mismo usuario.
$rateLimitExclusions = ['records/clockin', 'records/clockout', 'records/today', 'records/mine'];
if (!in_array($endpoint, $rateLimitExclusions, true)) {
    $rateLimitedPrefixes = ['admin/', 'records/', 'vacations/'];
    foreach ($rateLimitedPrefixes as $prefix) {
        if (str_starts_with($endpoint, $prefix)) {
            rate_limit_ip('api_' . rtrim($prefix, '/'), 120, 60);
            break;
        }
    }
}
// Bloqueo por historial de eventos de seguridad — solo en endpoints admin y auth.
// Excluidos records/* y vacations/* para no bloquear clockin/clockout legitimos.
$ipBlockEndpoints = ['admin/', 'auth/forgot-password', 'auth/reset-password'];
$shouldCheckIpBlock = false;
foreach ($ipBlockEndpoints as $prefix) {
    if (str_starts_with($endpoint, $prefix)) { $shouldCheckIpBlock = true; break; }
}
if ($shouldCheckIpBlock) {
    anti_bot_check_ip_block();
}

// Parser de paths con id: admin/companies/123 -> ('admin/companies', 123)
// admin/brands/123/logo -> ('admin/brands', 123, 'logo')
// admin/companies/123/branding -> ('admin/companies', 123, 'branding')
$endpointBase = $endpoint;
$endpointId = null;
$endpointAction = null;
// Caso especial: admin/email-templates/{brandId}/{kind} — 2 segmentos extra.
$emailTplBrandId = null;
$emailTplKind = null;
if (preg_match('#^admin/email-templates/(\d+)/([a-z_]+)$#', $endpoint, $m)) {
    $endpointBase = 'admin/email-templates';
    $emailTplBrandId = (int)$m[1];
    $emailTplKind = $m[2];
} elseif (preg_match('#^(admin/brands)/(\d+)/(logo)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/companies)/(\d+)/(branding)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/users)/(\d+)/(resend-invite|unblock|send-acta)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/location-alerts)/(\d+)/(review)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/vacations)/(\d+)/(decide)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/security-events)/(\d+)/(review)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
    $endpointAction = $m[3];
} elseif (preg_match('#^(admin/companies|admin/users|admin/brands|admin/dashboard/company|vacations)/(\d+)$#', $endpoint, $m)) {
    $endpointBase = $m[1];
    $endpointId = (int)$m[2];
}

// === Dispatcher ===
try {
    // Rutas de email-templates con dos segmentos (brandId + kind).
    if ($endpointBase === 'admin/email-templates' && $emailTplBrandId !== null && $emailTplKind !== null) {
        switch ($method) {
            case 'GET':
                admin_email_templates_get($emailTplBrandId, $emailTplKind);
                return;
            case 'PUT':
                admin_email_templates_save($emailTplBrandId, $emailTplKind, $body);
                return;
            case 'DELETE':
                admin_email_templates_reset($emailTplBrandId, $emailTplKind);
                return;
            default:
                err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
        }
    }

    // Rutas con id + accion (subrecurso). Cada case usa "return" para evitar
    // fallthrough silencioso si algun handler futuro no llama exit.
    if ($endpointId !== null && $endpointAction !== null) {
        switch ("{$method} {$endpointBase}/{$endpointAction}") {
            case 'POST admin/security-events/review':
                admin_security_events_review($endpointId);
                return;
            case 'POST admin/brands/logo':
                admin_brands_upload_logo($endpointId);
                return;
            case 'PUT admin/companies/branding':
                admin_company_branding_update($endpointId, $body);
                return;
            case 'POST admin/users/resend-invite':
                admin_users_resend_invite($endpointId);
                return;
            case 'POST admin/users/unblock':
                admin_users_unblock($endpointId);
                return;
            case 'POST admin/users/send-acta':
                admin_users_send_acta($endpointId, $body);
                return;
            case 'POST admin/location-alerts/review':
                admin_location_alerts_review($endpointId, $body);
                return;
            case 'POST admin/vacations/decide':
                admin_vacations_decide($endpointId, $body);
                return;
            default:
                err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
        }
    }
    // Rutas con id (PUT/DELETE sobre recursos especificos)
    if ($endpointId !== null) {
        switch ("{$method} {$endpointBase}") {
            case 'PUT admin/companies':
                admin_companies_update($endpointId, $body);
                return;
            case 'DELETE admin/companies':
                admin_companies_delete($endpointId);
                return;
            case 'PUT admin/users':
                admin_users_update($endpointId, $body);
                return;
            case 'DELETE admin/users':
                admin_users_delete($endpointId, $body);
                return;
            case 'PUT admin/brands':
                admin_brands_update($endpointId, $body);
                return;
            case 'DELETE admin/brands':
                admin_brands_delete($endpointId);
                return;
            case 'DELETE vacations':
                vacation_cancel($endpointId);
                return;
            case 'GET admin/dashboard/company':
                admin_dashboard_company($endpointId);
                return;
            default:
                err('NOT_FOUND', "Endpoint {$method} /{$endpoint} no existe.", 404);
        }
    }

    switch ("{$method} {$endpoint}") {
        // --- CAPTCHA matematico (generacion y verificacion) ---
        case 'GET auth/captcha':
            auth_captcha_generate();
        case 'POST auth/captcha/verify':
            auth_captcha_verify($body);

        // --- Reporte de manipulacion DOM desde el frontend ---
        case 'POST anti-bot/dom-report':
            anti_bot_dom_report($body);

        // --- Eventos de seguridad (admin) ---
        case 'GET admin/security-events':
            admin_security_events_list();

        // --- CSRF token ---
        // Headers explicitos para evitar que Cloudflare/cualquier intermediario
        // cachee la respuesta y entregue el mismo token a varios usuarios.
        case 'GET csrf':
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Vary: Cookie');
            ok(['csrf_token' => csrf_token()]);

        // --- Branding (publico, pre-login) ---
        case 'GET branding':
            tenant_public_branding();

        // --- Terminos y Condiciones ---
        case 'GET terms/current':
            terms_current();
        case 'POST terms/accept':
            terms_accept($body);
        case 'GET admin/terms':
            admin_terms_list();
        case 'POST admin/terms':
            admin_terms_create($body);

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
        case 'POST auth/verify-password':
            auth_verify_password($body);
        case 'POST auth/dom-bypass-request':
            auth_dom_bypass_request();
        case 'POST auth/dom-bypass-verify':
            auth_dom_bypass_verify($body);

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

        // --- Vacaciones (reemplaza horas extra como modulo de solicitudes) ---
        case 'POST vacations/request':
            vacation_request($body);
        case 'GET vacations/mine':
            vacation_mine();

        // --- Admin ---
        case 'GET admin/records':
            admin_records();
        case 'GET admin/change-requests':
            admin_change_requests();
        case 'GET admin/overtime-requests':
            admin_overtime_requests();
        case 'POST admin/decide':
            admin_decide($body);
        case 'GET admin/vacations':
            admin_vacations_list();

        // --- Admin: tenant settings (white-label) ---
        case 'GET admin/tenant-settings':
            admin_tenant_get();
        case 'PUT admin/tenant-settings':
            admin_tenant_update($body);
        case 'POST admin/tenant-settings/logo':
            admin_tenant_upload_logo();

        // --- Admin: billing / licenciamiento ---
        case 'GET admin/billing/plans':
            admin_billing_plans();
        case 'GET admin/billing/subscription':
            admin_billing_subscription();
        case 'PUT admin/billing/subscription':
            admin_billing_subscription_update($body);
        case 'POST admin/billing/connect':
            admin_billing_connect($body);

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

        // --- Admin: plantillas de correo (super_admin) ---
        case 'GET admin/email-templates':
            admin_email_templates_list();
        case 'POST admin/email-templates/preview':
            admin_email_templates_preview($body);

        // --- Admin: alertas de ubicacion (cambios radicales) ---
        case 'GET admin/location-alerts':
            admin_location_alerts_list();
        case 'GET admin/location-alerts/pending-count':
            admin_location_alerts_pending_count();

        // --- Admin: ejecucion de migraciones via HTTP (super_admin) ---
        case 'POST admin/migrations/run':
            admin_migrations_run($body);

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
