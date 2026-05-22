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
