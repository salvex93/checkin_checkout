<?php
declare(strict_types=1);

// =====================================================================
// helpers.php — Utilidades compartidas: respuestas JSON, sesion, audit log,
// rate limit y validacion de entradas. Centralizar aqui evita repetir patrones
// y reduce la superficie de error (forgetting input validation).
// =====================================================================

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $data = [], int $status = 200): never {
    json_response(['ok' => true, 'data' => $data], $status);
}

function err(string $code, string $message, int $status = 400, array $extra = []): never {
    json_response(['ok' => false, 'error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    // Limite defensivo: payloads >1MB se rechazan (protege contra DoS).
    if (strlen($raw) > 1_000_000) {
        err('PAYLOAD_TOO_LARGE', 'Cuerpo de solicitud excede el limite permitido.', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        err('INVALID_JSON', 'Cuerpo de solicitud no es JSON valido.', 400);
    }
    return $data;
}

function start_session_secure(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $sessionName = env('SESSION_NAME', 'melius_sid');
    $lifetime = env_int('SESSION_LIFETIME', 28800);
    // En produccion forzamos cookie Secure salvo override explicito a false.
    // En dev local default sigue siendo false para permitir HTTP.
    $secure = env_bool('COOKIE_SECURE', IS_PROD);

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,           // true en HTTPS produccion
        'httponly' => true,            // JavaScript no puede leer la cookie
        // Lax: cookie viaja en navegacion top-level (clic en link de email) pero
        // no en POST cross-site. Strict bloquea incluso el primer GET tras un link
        // externo y rompe el login cuando el usuario llega desde un correo.
        'samesite' => 'Lax'
    ]);
    session_start();
}

function require_login(): array {
    if (empty($_SESSION['user_id'])) {
        err('AUTH_REQUIRED', 'Inicia sesion para continuar.', 401);
    }
    $user = db_one(
        'SELECT id, email, name, role, company_id, timezone,
                work_start_time, work_end_time, work_days_mask,
                is_active, status, must_change_password
           FROM users WHERE id = ?',
        [$_SESSION['user_id']]
    );
    if (!$user || (int)$user['is_active'] !== 1 || ($user['status'] ?? '') !== 'active') {
        session_destroy();
        err('AUTH_REQUIRED', 'Sesion invalida.', 401);
    }
    return $user;
}

/**
 * Gate adicional: bloquea operaciones si el usuario tiene must_change_password
 * pendiente. Usar en endpoints que registran trabajo o estado (records,
 * solicitudes). Solo permite cambio de password y auth/me.
 */
function require_no_pending_password(): array {
    $u = require_login();
    if ((int)($u['must_change_password'] ?? 0) === 1) {
        err('PASSWORD_CHANGE_REQUIRED', 'Cambia tu contrasena temporal antes de continuar.', 403);
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    $role = $u['role'] ?? '';
    if ($role !== 'admin' && $role !== 'super_admin') {
        err('FORBIDDEN', 'Operacion restringida a administradores.', 403);
    }
    return $u;
}

function require_super_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'super_admin') {
        err('FORBIDDEN', 'Operacion restringida a super administradores.', 403);
    }
    return $u;
}

function client_ip(): string {
    // XFF spoofing: cualquier cliente puede enviar X-Forwarded-For. Solo confiamos
    // en el header si REMOTE_ADDR pertenece a un proxy declarado en .env como
    // TRUSTED_PROXIES (CSV de IPs o CIDRs). Sin esta variable definida, ignoramos XFF.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trustedRaw = trim((string)env('TRUSTED_PROXIES', ''));
    if ($trustedRaw === '') return $remote;

    $trusted = array_filter(array_map('trim', explode(',', $trustedRaw)));
    $remoteIsTrustedProxy = false;
    foreach ($trusted as $entry) {
        if (str_contains($entry, '/')) {
            // CIDR
            if (ip_in_cidr($remote, $entry)) { $remoteIsTrustedProxy = true; break; }
        } elseif ($remote === $entry) {
            $remoteIsTrustedProxy = true; break;
        }
    }
    if (!$remoteIsTrustedProxy) return $remote;

    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $remote;
}

/**
 * Rate limit ligero por (scope, key). Cuenta hits en los ultimos $windowSec
 * segundos. Si supera $maxHits, responde 429 con detalle. Sino registra el hit
 * y deja continuar. Limpieza ocasional (1% probabilidad) de filas viejas.
 *
 * Ejemplos:
 *   rate_limit_or_block('login', $email, 5, 900);          // 5 intentos / 15min
 *   rate_limit_or_block('forgot', $email, 3, 900);          // 3 forgot / 15min
 *   rate_limit_or_block('bulk_invite', (string)$adminId, 5, 3600); // 5/hora
 */
function rate_limit_or_block(string $scope, string $key, int $maxHits, int $windowSec): void {
    if ($key === '') return;
    try {
        $driver = Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $col = $driver === 'mysql' ? '`key`' : 'key';
        $cutoff = (new DateTimeImmutable("-{$windowSec} seconds"))->format('Y-m-d H:i:s');

        $row = db_one(
            "SELECT COUNT(*) AS c FROM rate_limits WHERE scope = ? AND {$col} = ? AND hit_at >= ?",
            [$scope, $key, $cutoff]
        );
        $hits = (int)($row['c'] ?? 0);
        if ($hits >= $maxHits) {
            err('RATE_LIMITED', "Demasiados intentos. Espera unos minutos antes de reintentar.", 429, [
                'scope' => $scope,
                'retry_after_seconds' => $windowSec,
            ]);
        }
        db_exec("INSERT INTO rate_limits (scope, {$col}) VALUES (?, ?)", [$scope, $key]);

        // Limpieza oportunista 1% del tiempo para no inflar la tabla.
        if (random_int(1, 100) === 1) {
            $deleteCutoff = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
            db_exec('DELETE FROM rate_limits WHERE hit_at < ?', [$deleteCutoff]);
        }
    } catch (Throwable $e) {
        // Si la tabla no existe (migracion no aplicada) NO bloqueamos al usuario.
        // Solo logueamos para debug. Es preferible servir sin throttle a romper login.
        error_log('[rate_limit] ' . $e->getMessage());
    }
}

// Match IPv4 contra CIDR. Si la entrada no parsea, devuelve false defensivamente.
function ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$subnet, $maskBits] = explode('/', $cidr, 2);
    $maskBits = (int)$maskBits;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    if ($maskBits < 0 || $maskBits > 32) return false;
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($maskBits === 0) return true;
    $mask = -1 << (32 - $maskBits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Throttle por IP del cliente. Atajo sobre rate_limit_or_block.
 * Si no hay IP detectable (proxy raro o CLI), no bloquea.
 */
function rate_limit_ip(string $scope, int $maxHits, int $windowSec): void {
    $ip = client_ip();
    if ($ip === '' || $ip === '0.0.0.0') return;
    rate_limit_or_block($scope, $ip, $maxHits, $windowSec);
}

/**
 * Enmascara PII en metadata de audit_log antes de persistir.
 * Reemplaza emails completos por hash corto + dominio para conservar
 * la utilidad de busqueda sin almacenar el local-part.
 */
function sanitize_pii_for_audit(array $metadata): array {
    $maskEmail = static function (string $email): string {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) return '***@***';
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $hash = substr(hash('sha256', strtolower($local . '@' . $domain)), 0, 8);
        return $hash . '@' . $domain;
    };
    $walker = static function (&$value) use (&$walker, $maskEmail) {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $value = $maskEmail($value);
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                $walker($v);
            }
        }
    };
    foreach ($metadata as $k => &$v) {
        $walker($v);
    }
    return $metadata;
}

function audit_log(?int $userId, string $event, array $metadata = []): void {
    try {
        $clean = $metadata ? sanitize_pii_for_audit($metadata) : [];
        db_exec(
            'INSERT INTO audit_log (user_id, event, ip, user_agent, metadata) VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $event,
                client_ip(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null
            ]
        );
    } catch (Throwable $e) {
        // El logging nunca debe romper el flujo del usuario.
        error_log('[audit] fallo al registrar evento: ' . $e->getMessage());
    }
}

/**
 * Rate limit por cuenta basado en failed_attempts y locked_until.
 * Implementacion server-side persistente (sobrevive reinicios).
 * Retorna segundos restantes de bloqueo o 0 si no esta bloqueada.
 */
function account_lock_remaining(string $email): int {
    $row = function_exists('db_user_by_email')
        ? db_user_by_email($email, 'locked_until')
        : db_one('SELECT locked_until FROM users WHERE email = ?', [$email]);
    if (!$row || !$row['locked_until']) return 0;
    $until = strtotime((string)$row['locked_until']);
    if ($until === false) return 0;
    $rem = $until - time();
    return max(0, $rem);
}

function register_failed_attempt(string $email): void {
    $max = env_int('AUTH_MAX_ATTEMPTS', 5);
    $minutes = env_int('AUTH_LOCK_MINUTES', 15);
    $row = function_exists('db_user_by_email')
        ? db_user_by_email($email, 'id, failed_attempts')
        : db_one('SELECT id, failed_attempts FROM users WHERE email = ?', [$email]);
    if (!$row) return; // No revelamos si el email existe
    $next = (int)$row['failed_attempts'] + 1;
    if ($next >= $max) {
        $lockUntil = date('Y-m-d H:i:s', time() + $minutes * 60);
        db_exec('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$next, $lockUntil, $row['id']]);
    } else {
        db_exec('UPDATE users SET failed_attempts = ? WHERE id = ?', [$next, $row['id']]);
    }
}

function reset_failed_attempts(int $userId): void {
    db_exec('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$userId]);
}

/**
 * Validador de entradas: tipo + reglas. Falla con err() si la regla no se cumple.
 * Centraliza la validacion para que ningun endpoint la olvide.
 */
function validate_string(array $input, string $key, int $min = 1, int $max = 255, bool $required = true): ?string {
    $val = $input[$key] ?? null;
    if ($val === null || $val === '') {
        if ($required) err('INVALID_INPUT', "Campo requerido: {$key}", 400, ['field' => $key]);
        return null;
    }
    if (!is_string($val)) err('INVALID_INPUT', "Campo invalido: {$key}", 400, ['field' => $key]);
    $val = trim($val);
    $len = mb_strlen($val);
    if ($len < $min || $len > $max) {
        err('INVALID_INPUT', "Longitud invalida en {$key}", 400, ['field' => $key, 'min' => $min, 'max' => $max]);
    }
    return $val;
}

function validate_email(array $input, string $key, bool $required = true): ?string {
    $val = validate_string($input, $key, 3, 190, $required);
    if ($val === null) return null;
    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
        err('INVALID_INPUT', "Email invalido en {$key}", 400, ['field' => $key]);
    }
    return strtolower($val);
}

function validate_int(array $input, string $key, ?int $min = null, ?int $max = null, bool $required = true): ?int {
    $val = $input[$key] ?? null;
    if ($val === null || $val === '') {
        if ($required) err('INVALID_INPUT', "Campo requerido: {$key}", 400, ['field' => $key]);
        return null;
    }
    $n = filter_var($val, FILTER_VALIDATE_INT);
    if ($n === false) err('INVALID_INPUT', "Numero invalido en {$key}", 400, ['field' => $key]);
    if ($min !== null && $n < $min) err('INVALID_INPUT', "Valor minimo en {$key} es {$min}", 400, ['field' => $key]);
    if ($max !== null && $n > $max) err('INVALID_INPUT', "Valor maximo en {$key} es {$max}", 400, ['field' => $key]);
    return $n;
}

function validate_float(array $input, string $key, ?float $min = null, ?float $max = null, bool $required = true): ?float {
    $val = $input[$key] ?? null;
    if ($val === null || $val === '') {
        if ($required) err('INVALID_INPUT', "Campo requerido: {$key}", 400, ['field' => $key]);
        return null;
    }
    $n = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($n === false) err('INVALID_INPUT', "Numero invalido en {$key}", 400, ['field' => $key]);
    if ($min !== null && $n < $min) err('INVALID_INPUT', "Valor minimo en {$key} es {$min}", 400, ['field' => $key]);
    if ($max !== null && $n > $max) err('INVALID_INPUT', "Valor maximo en {$key} es {$max}", 400, ['field' => $key]);
    return (float)$n;
}

/**
 * Valida zona horaria IANA usando DateTimeZone. Solo acepta identificadores reconocidos
 * por PHP (p.ej. "America/Mexico_City"). Rechaza abreviaciones ambiguas como "EST".
 */
function validate_timezone(array $input, string $key, bool $required = true): ?string {
    $val = validate_string($input, $key, 1, 64, $required);
    if ($val === null) return null;
    try {
        new DateTimeZone($val);
    } catch (Throwable $e) {
        err('INVALID_INPUT', "Zona horaria invalida en {$key}", 400, ['field' => $key]);
    }
    return $val;
}

/**
 * Valida string en formato "HH:MM" (24h). Hora 00-23, minutos 00-59.
 */
function validate_time_hhmm(array $input, string $key, bool $required = true): ?string {
    $val = validate_string($input, $key, 5, 5, $required);
    if ($val === null) return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $val)) {
        err('INVALID_INPUT', "Formato de hora invalido en {$key} (use HH:MM)", 400, ['field' => $key]);
    }
    return $val;
}

/**
 * Valida bitmask de dias laborales. Bit 0 = Lunes, bit 6 = Domingo.
 * Rango 1..127 (al menos un dia, todos los dias = 127).
 */
function validate_days_mask(array $input, string $key, bool $required = true): ?int {
    return validate_int($input, $key, 1, 127, $required);
}

/**
 * Genera password temporal con alfabeto sin caracteres confusos (0/O, 1/I/l).
 * Por seguridad usa random_int (CSPRNG) y garantiza al menos un caracter
 * de cada clase requerida por la politica de fuerza.
 */
function password_temp_generate(int $length = 14): string {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digit = '23456789';
    $symbol = '!@#$%&*?-_';
    $all = $upper . $lower . $digit . $symbol;

    $pick = function (string $alphabet): string {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    };
    $chars = [$pick($upper), $pick($lower), $pick($digit), $pick($symbol)];
    for ($i = count($chars); $i < $length; $i++) {
        $chars[] = $pick($all);
    }
    // Shuffle CSPRNG-safe: Fisher-Yates con random_int
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/**
 * Valida fuerza de una password: longitud minima, mayuscula, numero y simbolo.
 */
function validate_password_strength(string $password): void {
    if (mb_strlen($password) < 10) {
        err('WEAK_PASSWORD', 'La contrasena debe tener al menos 10 caracteres.', 400, ['field' => 'password']);
    }
    if (!preg_match('/[A-Z]/', $password)) {
        err('WEAK_PASSWORD', 'La contrasena debe incluir al menos una mayuscula.', 400, ['field' => 'password']);
    }
    if (!preg_match('/[0-9]/', $password)) {
        err('WEAK_PASSWORD', 'La contrasena debe incluir al menos un numero.', 400, ['field' => 'password']);
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        err('WEAK_PASSWORD', 'La contrasena debe incluir al menos un simbolo.', 400, ['field' => 'password']);
    }
}

/**
 * URL base publica de la aplicacion. Permite override por env APP_BASE_URL;
 * en su ausencia se construye desde el host actual. Usado para armar enlaces
 * de invitacion/reset en correos.
 */
function app_base_url(): string {
    $override = env('APP_BASE_URL', '');
    if ($override !== '') return rtrim($override, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}";
}

/**
 * Convierte un path relativo (/assets/...) a URL absoluta. Si ya viene absoluta
 * (http:// o https://) la devuelve tal cual. Usado para incrustar imagenes en
 * correos, donde los clientes no resuelven paths relativos.
 */
function absolute_asset_url(string $path): string {
    if (preg_match('#^https?://#i', $path)) return $path;
    $base = app_base_url();
    return $base . '/' . ltrim($path, '/');
}
