<?php
declare(strict_types=1);

// anti_bot.php — Capa anti-scraping para records/clockin y records/clockout.
// Multiples senales debiles compuestas. Ninguna por si sola bloquea, pero la
// combinacion eleva el costo de automatizar marcajes desde un script.

require_once __DIR__ . '/helpers.php';

const ANTI_BOT_MIN_DELAY_BETWEEN_ACTIONS_S = 60;
const ANTI_BOT_BLACKLIST_UA = [
    'curl', 'wget', 'python-requests', 'python-urllib', 'scrapy', 'libwww-perl',
    'httpclient', 'okhttp', 'go-http-client', 'phantomjs', 'headlesschrome',
    'selenium', 'puppeteer', 'playwright',
];

// UAs adicionales bloqueados a nivel global de API. Incluye crawlers SEO,
// herramientas de scraping comercial y bots de research que no aportan valor
// a un panel privado de control de jornada.
const ANTI_BOT_GLOBAL_BLACKLIST_UA = [
    'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot', 'blexbot',
    'petalbot', 'serpstatbot', 'mauibot', 'dataforseobot', 'megaindex',
    'screaming frog', 'sitebulb', 'http_request', 'apache-httpclient',
    'java/', 'ruby', 'perl/', 'rust-reqwest', 'node-fetch', 'axios/',
    'fasthttp', 'aiohttp', 'urllib3', 'httpx', 'mechanize', 'colly',
    'gobuster', 'dirbuster', 'wpscan', 'nikto', 'sqlmap', 'nmap', 'masscan',
    'zgrab', 'censys', 'shodan', 'expanse', 'paloalto', 'projectdiscovery',
    'nuclei', 'feroxbuster', 'ffuf',
];

/**
 * Bloqueo de UAs a nivel global de API. Se ejecuta antes del dispatcher en
 * index.php. Combina la blacklist propia del modulo (scrapers/headless) con
 * crawlers SEO y herramientas de pentesting que un panel privado nunca debe
 * tolerar. Loguea el bloqueo en error_log con motivo claro para auditoria.
 */
function anti_bot_global_ua_filter(): void {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        anti_bot_log_block('empty', '');
        err('BOT_SUSPECTED', 'Bloqueado: User-Agent ausente.', 403);
    }
    // Bloqueo por UAs claramente automatizados (cualquier modulo).
    foreach (ANTI_BOT_BLACKLIST_UA as $needle) {
        if (strpos($ua, $needle) !== false) {
            anti_bot_log_block('automation_ua', $ua);
            err('BOT_SUSPECTED', "Bloqueado: cliente automatizado ({$needle}) no permitido en una API privada.", 403);
        }
    }
    // Bloqueo por crawlers/scanners/scrapers comerciales.
    foreach (ANTI_BOT_GLOBAL_BLACKLIST_UA as $needle) {
        if (strpos($ua, $needle) !== false) {
            anti_bot_log_block('scanner_ua', $ua);
            err('BOT_SUSPECTED', "Bloqueado: agente externo no autorizado ({$needle}).", 403);
        }
    }
}

/**
 * Log estructurado del bloqueo. Aterriza en error_log del servidor con un
 * prefijo discriminable para tooling externo (grep, logrotate, fail2ban).
 */
function anti_bot_log_block(string $reason, string $ua): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $payload = json_encode([
        'event' => 'anti_bot_block',
        'reason' => $reason,
        'ip' => $ip,
        'ua' => substr($ua, 0, 240),
        'uri' => substr($uri, 0, 200),
        'ts' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    error_log('[anti-bot] ' . $payload);
}

/**
 * Verifica que la peticion provenga del mismo origen (defensa contra CSRF y
 * scripts que no controlan headers). Si Origin/Referer faltan o apuntan a
 * otro host, se rechaza.
 */
function anti_bot_require_same_origin(): void {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return;
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

    $allowed = false;
    foreach ([$origin, $referer] as $cand) {
        if ($cand === '') continue;
        $h = parse_url($cand, PHP_URL_HOST);
        if (!$h) continue;
        if (strtolower($h) === $host) { $allowed = true; break; }
    }
    if (!$allowed) {
        err('FORBIDDEN_ORIGIN', 'La peticion debe venir del mismo origen.', 403);
    }
}

/**
 * Bloquea User-Agents tipicos de scripts. No es exhaustivo: un atacante puede
 * spoofear UA facilmente; aqui solo levantamos la barra contra scripts triviales.
 */
function anti_bot_reject_blacklisted_ua(): void {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        err('BOT_SUSPECTED', 'User-Agent requerido.', 403);
    }
    foreach (ANTI_BOT_BLACKLIST_UA as $needle) {
        if (strpos($ua, $needle) !== false) {
            err('BOT_SUSPECTED', 'User-Agent no permitido.', 403);
        }
    }
}

/**
 * Honeypot: el frontend envia el campo `hp_field` vacio. Un bot que rellena
 * todos los campos del payload lo dejara con contenido y lo descartamos.
 */
function anti_bot_reject_honeypot(array $body): void {
    if (isset($body['hp_field']) && $body['hp_field'] !== '' && $body['hp_field'] !== null) {
        err('BOT_SUSPECTED', 'Entrada invalida.', 403);
    }
}

/**
 * El frontend marca `human_interaction = true` cuando el usuario tuvo
 * eventos mousemove/touchstart/keydown previos en la sesion del navegador.
 * Un script que solo hace POST no incluira ese flag.
 */
function anti_bot_require_human_signal(array $body): void {
    if (empty($body['human_interaction'])) {
        err('BOT_SUSPECTED', 'Interaccion humana no detectada.', 403);
    }
}

/**
 * Limita la frecuencia entre clockin y clockout del mismo usuario para
 * impedir automatizaciones que disparen ambos endpoints en menos de N segundos.
 */
function anti_bot_min_gap_between_actions(int $userId): void {
    $row = db_one(
        'SELECT entry_time, exit_time, work_date FROM attendance_records
          WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    if (!$row) return;
    // Si el ultimo evento es del mismo dia (entry o exit), exige una ventana minima.
    // Usamos updated/created si existieran; aqui caemos a comparar hora actual contra entry_time.
    // No bloquea por hora absoluta; bloquea solo si el servidor recibe llamadas demasiado seguidas.
    $now = time();
    $last = isset($_SESSION['_last_clock_action_ts']) ? (int)$_SESSION['_last_clock_action_ts'] : 0;
    if ($last > 0 && ($now - $last) < ANTI_BOT_MIN_DELAY_BETWEEN_ACTIONS_S) {
        err('RATE_LIMITED', 'Espera unos segundos antes de marcar de nuevo.', 429);
    }
    $_SESSION['_last_clock_action_ts'] = $now;
}

/**
 * Verificacion compuesta para usar en records/clockin y records/clockout.
 * Aborta con err() si alguna capa rechaza la peticion.
 */
function anti_bot_verify(int $userId, array $body): void {
    anti_bot_require_same_origin();
    anti_bot_reject_blacklisted_ua();
    anti_bot_reject_honeypot($body);
    anti_bot_require_human_signal($body);
    anti_bot_min_gap_between_actions($userId);
}

// =====================================================================
// Persistencia de eventos de seguridad en DB + bloqueo por IP
// =====================================================================

const ANTI_BOT_IP_BLOCK_THRESHOLD = 5;   // hits en ventana
const ANTI_BOT_IP_BLOCK_WINDOW_S  = 600; // 10 minutos
const ANTI_BOT_IP_BLOCK_DURATION_S = 3600; // 1 hora bloqueada

/**
 * Persiste un evento de seguridad en la tabla security_events y notifica
 * a admins activos si el tipo es scraping, dom_manipulation o ip_blocked.
 */
function anti_bot_record_event(string $eventType, string $detail, ?int $userId = null): void {
    try {
        $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        $ua  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $uri = substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 255);
        db_exec(
            "INSERT INTO security_events (event_type, ip, user_agent, uri, user_id, detail)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$eventType, $ip, $ua ?: null, $uri ?: null, $userId, $detail]
        );
        if (in_array($eventType, ['scraping', 'dom_manipulation', 'ip_blocked'], true)) {
            anti_bot_notify_admins($eventType, $ip, $detail, $userId);
        }
    } catch (Throwable $e) {
        error_log('[anti-bot] record_event failed: ' . $e->getMessage());
    }
}

/**
 * Verifica si la IP esta bloqueada (demasiados eventos en la ventana).
 * Si supera el umbral registra ip_blocked y rechaza la peticion.
 */
function anti_bot_check_ip_block(): void {
    $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') return;
    try {
        $cutoff = (new DateTimeImmutable('-' . ANTI_BOT_IP_BLOCK_WINDOW_S . ' seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        // Solo contar eventos de alto riesgo para el bloqueo — excluir dom_manipulation
        // y bot_blocked para no bloquear al usuario legitimo que tiene DevTools abierto.
        $count = (int)(db_one(
            "SELECT COUNT(*) AS c FROM security_events
              WHERE ip = ? AND created_at >= ?
                AND event_type IN ('scraping','brute_force','ip_blocked')",
            [$ip, $cutoff]
        )['c'] ?? 0);
        if ($count >= ANTI_BOT_IP_BLOCK_THRESHOLD) {
            anti_bot_record_event('ip_blocked', "IP bloqueada por {$count} eventos de alto riesgo en " . ANTI_BOT_IP_BLOCK_WINDOW_S . "s");
            err('IP_BLOCKED', 'Demasiadas solicitudes sospechosas. Intenta mas tarde.', 429);
        }
    } catch (Throwable $e) {
        error_log('[anti-bot] check_ip_block failed: ' . $e->getMessage());
    }
}

/**
 * Notifica a admins activos sobre un evento de seguridad. Fire-and-forget.
 */
function anti_bot_notify_admins(string $eventType, string $ip, string $detail, ?int $userId): void {
    try {
        $typeLabels = [
            'scraping'        => 'Scraping detectado',
            'dom_manipulation'=> 'Manipulacion del DOM',
            'ip_blocked'      => 'IP bloqueada',
        ];
        $label = $typeLabels[$eventType] ?? $eventType;
        $admins = db_all(
            "SELECT u.email, u.name FROM users u
              WHERE u.role IN ('admin','super_admin') AND u.is_active = 1 AND u.status = 'active'
              LIMIT 10"
        );
        foreach ($admins as $admin) {
            $admin = user_decrypt_pii($admin);
            if (empty($admin['email'])) continue;
            $subject = "[Seguridad] {$label} — Melius Clockin";
            $body    = "<p>Se detecto un evento de seguridad en la aplicacion.</p>"
                     . "<table cellpadding='0' cellspacing='0' style='width:100%;border-collapse:collapse;'>"
                     . "<tr><td style='padding:6px 0;color:#475569;'>Tipo</td><td style='padding:6px 0;font-weight:700;'>{$label}</td></tr>"
                     . "<tr><td style='padding:6px 0;color:#475569;'>IP</td><td style='padding:6px 0;font-family:monospace;'>{$ip}</td></tr>"
                     . "<tr><td style='padding:6px 0;color:#475569;'>Detalle</td><td style='padding:6px 0;'>" . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . "</td></tr>"
                     . "</table>"
                     . "<p style='margin-top:16px;'><a href='" . app_base_url() . "/?tab=security' style='display:inline-block;padding:10px 20px;background:linear-gradient(135deg,#07d6da,#9909fe);color:#fff;font-weight:700;text-decoration:none;border-radius:8px;'>Ver en panel</a></p>";
            $text    = "{$label}\nIP: {$ip}\nDetalle: {$detail}\n";
            @mail_send($admin['email'], $subject, tpl_layout($label, $body, null), $text);
        }
    } catch (Throwable $e) {
        error_log('[anti-bot] notify_admins failed: ' . $e->getMessage());
    }
}

/**
 * Endpoint POST anti-bot/dom-report — el frontend reporta manipulacion del DOM.
 * Captura evidencia forense: tipo de intento, fingerprint del navegador,
 * accion intentada y si la tuvo exito o fue bloqueada.
 * No requiere sesion activa (el atacante puede no estar logueado).
 */
function anti_bot_dom_report(array $body): never {
    $detail          = substr((string)($body['detail']           ?? 'sin detalle'), 0, 500);
    $actionAttempted = substr((string)($body['action_attempted'] ?? ''),            0, 100);
    $succeeded       = !empty($body['succeeded']) ? 1 : 0;
    $fingerprint     = substr((string)($body['fingerprint']      ?? ''),            0, 512);
    $userId          = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Enriquecer el detalle con evidencia forense estructurada
    $evidence = json_encode([
        'action_attempted' => $actionAttempted ?: null,
        'succeeded'        => (bool)$succeeded,
        'fingerprint'      => $fingerprint ?: null,
        'session_user_id'  => $userId,
        'timestamp_ms'     => $body['timestamp_ms'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $fullDetail = $detail . ' | evidence=' . $evidence;

    anti_bot_record_event('dom_manipulation', substr($fullDetail, 0, 1000), $userId);
    ok(['received' => true]);
}

// =====================================================================
// Helpers testables — wrappers sin exit() para PHPUnit
// =====================================================================

/**
 * Versión testable de anti_bot_dom_report: no hace ok() (que hace exit).
 * Sólo persiste el evento. Úsala en tests unitarios.
 */
function anti_bot_dom_report_testable(array $body): void {
    $detail          = substr((string)($body['detail']           ?? 'sin detalle'), 0, 500);
    $actionAttempted = substr((string)($body['action_attempted'] ?? ''),            0, 100);
    $succeeded       = !empty($body['succeeded']) ? 1 : 0;
    $fingerprint     = substr((string)($body['fingerprint']      ?? ''),            0, 512);
    $userId          = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $evidence = json_encode([
        'action_attempted' => $actionAttempted ?: null,
        'succeeded'        => (bool)$succeeded,
        'fingerprint'      => $fingerprint ?: null,
        'session_user_id'  => $userId,
        'timestamp_ms'     => $body['timestamp_ms'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $fullDetail = $detail . ' | evidence=' . $evidence;
    anti_bot_record_event('dom_manipulation', substr($fullDetail, 0, 1000), $userId);
}

/**
 * Versión testable de admin_security_events_list: retorna array en vez de ok().
 */
function security_events_for_admin(int $adminId, string $type = 'all', string $reviewed = 'false'): array {
    $where  = [];
    $params = [];
    $allowed = ['scraping', 'dom_manipulation', 'brute_force', 'bot_blocked', 'ip_blocked', 'all'];
    if (in_array($type, $allowed, true) && $type !== 'all') {
        $where[]  = 'event_type = ?';
        $params[] = $type;
    }
    if ($reviewed === 'false') {
        $where[] = 'reviewed = 0';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return db_all(
        "SELECT s.id, s.event_type, s.ip, s.user_agent, s.uri, s.detail,
                s.reviewed, s.created_at,
                u.name AS user_name, u.email AS user_email
           FROM security_events s
           LEFT JOIN users u ON u.id = s.user_id
           {$whereSql}
          ORDER BY s.created_at DESC
          LIMIT 200",
        $params
    );
}

/**
 * GET admin/security-events — lista eventos de seguridad para el panel admin.
 * Filtra por tipo y estado de revision.
 */
function admin_security_events_list(): never {
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'super_admin'], true)) {
        err('FORBIDDEN', 'Solo admins.', 403);
    }
    $type     = $_GET['type'] ?? 'all';
    $reviewed = $_GET['reviewed'] ?? 'false';
    $where    = [];
    $params   = [];
    $allowed  = ['scraping', 'dom_manipulation', 'brute_force', 'bot_blocked', 'ip_blocked', 'all'];
    if (in_array($type, $allowed, true) && $type !== 'all') {
        $where[]  = 'event_type = ?';
        $params[] = $type;
    }
    if ($reviewed === 'false') {
        $where[]  = 'reviewed = 0';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = db_all(
        "SELECT s.id, s.event_type, s.ip, s.user_agent, s.uri, s.detail,
                s.reviewed, s.created_at,
                u.name AS user_name, u.email AS user_email
           FROM security_events s
           LEFT JOIN users u ON u.id = s.user_id
           {$whereSql}
          ORDER BY s.created_at DESC
          LIMIT 200",
        $params
    );
    $unreviewed = (int)(db_one("SELECT COUNT(*) AS c FROM security_events WHERE reviewed = 0")['c'] ?? 0);
    ok(['events' => $rows, 'unreviewed_count' => $unreviewed]);
}

/**
 * POST admin/security-events/{id}/review — marca un evento como revisado.
 */
function admin_security_events_review(int $id): never {
    require_csrf();
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'super_admin'], true)) {
        err('FORBIDDEN', 'Solo admins.', 403);
    }
    db_exec("UPDATE security_events SET reviewed = 1 WHERE id = ?", [$id]);
    ok(['id' => $id, 'reviewed' => true]);
}
