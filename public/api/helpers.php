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
    $secure = env_bool('COOKIE_SECURE', false);

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,           // true en HTTPS produccion
        'httponly' => true,            // JavaScript no puede leer la cookie
        'samesite' => 'Strict'         // bloquea envio cross-site
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
                is_active, status
           FROM users WHERE id = ?',
        [$_SESSION['user_id']]
    );
    if (!$user || (int)$user['is_active'] !== 1 || ($user['status'] ?? '') !== 'active') {
        session_destroy();
        err('AUTH_REQUIRED', 'Sesion invalida.', 401);
    }
    return $user;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        err('FORBIDDEN', 'Operacion restringida a administradores.', 403);
    }
    return $u;
}

function client_ip(): string {
    // Proxies de HostGator pueden setear X-Forwarded-For. En produccion conviene
    // restringir cuales proxies son de confianza. Aqui aceptamos el primero del header
    // o caemos a REMOTE_ADDR.
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function audit_log(?int $userId, string $event, array $metadata = []): void {
    try {
        db_exec(
            'INSERT INTO audit_log (user_id, event, ip, user_agent, metadata) VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $event,
                client_ip(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
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
    $row = db_one('SELECT locked_until FROM users WHERE email = ?', [$email]);
    if (!$row || !$row['locked_until']) return 0;
    $until = strtotime((string)$row['locked_until']);
    if ($until === false) return 0;
    $rem = $until - time();
    return max(0, $rem);
}

function register_failed_attempt(string $email): void {
    $max = env_int('AUTH_MAX_ATTEMPTS', 5);
    $minutes = env_int('AUTH_LOCK_MINUTES', 15);
    $row = db_one('SELECT id, failed_attempts FROM users WHERE email = ?', [$email]);
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
