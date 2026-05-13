<?php
declare(strict_types=1);

// =====================================================================
// auth.php — Autenticacion: register, login, logout, me.
// Bcrypt cost 12 + rate limit por cuenta + sesion server-side con regeneracion
// de ID en login (defensa anti session fixation).
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

const PASSWORD_RESET_TTL_HOURS = 72;

function auth_register(array $body): never {
    require_csrf();

    $name = validate_string($body, 'name', 2, 120);
    $email = validate_email($body, 'email');
    $password = validate_string($body, 'password', 10, 200);
    $companyId = validate_int($body, 'company_id', 1, null, false);

    // Validar que la empresa existe (si se proporciono)
    if ($companyId !== null) {
        $company = db_one('SELECT id FROM companies WHERE id = ?', [$companyId]);
        if (!$company) err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
    }

    // Verificar email duplicado SIN revelar al cliente si ya existe (anti enumeracion)
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        // Respuesta deliberadamente identica a la de exito. El usuario que ya existe
        // recibira el mismo mensaje que el nuevo. Auditoria registra el intento.
        audit_log(null, 'register_duplicate_email', ['email' => $email]);
        ok(['message' => 'Registro recibido. Revisa tu email.']);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    db_exec(
        'INSERT INTO users (email, name, password_hash, role, company_id) VALUES (?, ?, ?, ?, ?)',
        [$email, $name, $hash, 'consultant', $companyId]
    );
    $userId = (int)db_last_id();
    audit_log($userId, 'register_success', ['email' => $email]);
    ok(['message' => 'Registro recibido. Revisa tu email.']);
}

function auth_login(array $body): never {
    require_csrf();

    $email = validate_email($body, 'email');
    $password = validate_string($body, 'password', 1, 200);

    // Rate limit por cuenta
    $remaining = account_lock_remaining($email);
    if ($remaining > 0) {
        audit_log(null, 'login_locked', ['email' => $email, 'remaining_sec' => $remaining]);
        err('ACCOUNT_LOCKED', "Cuenta bloqueada temporalmente. Intenta en {$remaining} segundos.", 429, ['retry_after' => $remaining]);
    }

    $user = db_one('SELECT id, email, name, password_hash, role, is_active, status, must_change_password FROM users WHERE email = ?', [$email]);

    // Mensaje generico identico para email inexistente vs password incorrecto
    // (anti enumeracion de usuarios — OWASP A07).
    if (!$user
        || (int)$user['is_active'] !== 1
        || ($user['status'] ?? '') !== 'active'
        || empty($user['password_hash'])
        || !password_verify($password, $user['password_hash'])) {
        if ($user) {
            register_failed_attempt($email);
            audit_log((int)$user['id'], 'login_failed', ['reason' => 'invalid_credentials']);
        } else {
            audit_log(null, 'login_failed', ['reason' => 'unknown_email', 'email' => $email]);
        }
        // Pequeño delay aleatorio para nivelar timing entre los dos caminos
        usleep(random_int(80_000, 200_000));
        err('INVALID_CREDENTIALS', 'Credenciales invalidas.', 401);
    }

    // Login exitoso: regenerar ID para prevenir session fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_at'] = time();

    reset_failed_attempts((int)$user['id']);
    audit_log((int)$user['id'], 'login_success');

    // Rehash si el cost actual difiere (futureproofing)
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, (int)$user['id']]);
    }

    ok([
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'must_change_password' => (int)$user['must_change_password'] === 1
        ],
        'csrf_token' => csrf_token()
    ]);
}

function auth_logout(): never {
    require_csrf();
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        audit_log((int)$uid, 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    ok(['message' => 'Sesion finalizada.']);
}

function auth_me(): never {
    $u = require_login();
    $row = db_one('SELECT must_change_password FROM users WHERE id = ?', [$u['id']]);
    ok([
        'user' => [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'company_id' => $u['company_id'] ? (int)$u['company_id'] : null,
            'must_change_password' => $row ? (int)$row['must_change_password'] === 1 : false
        ],
        'csrf_token' => csrf_token()
    ]);
}

/**
 * POST auth/change-password — usuario autenticado cambia su password.
 * Limpia must_change_password tras exito. Audit log.
 */
function auth_change_password(array $body): never {
    require_csrf();
    $u = require_login();

    $current = validate_string($body, 'current_password', 1, 200);
    $new = validate_string($body, 'new_password', 1, 200);
    $confirm = validate_string($body, 'confirm_password', 1, 200);

    if ($new !== $confirm) {
        err('INVALID_INPUT', 'Las contrasenas nuevas no coinciden.', 400, ['field' => 'confirm_password']);
    }
    validate_password_strength($new);

    $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
    if (!$row || !password_verify($current, $row['password_hash'] ?? '')) {
        audit_log((int)$u['id'], 'change_password_invalid_current');
        err('INVALID_CREDENTIALS', 'La contrasena actual es incorrecta.', 401);
    }
    if (password_verify($new, $row['password_hash'] ?? '')) {
        err('INVALID_INPUT', 'La contrasena nueva debe ser distinta a la actual.', 400, ['field' => 'new_password']);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    db_exec(
        'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?',
        [$hash, $u['id']]
    );
    audit_log((int)$u['id'], 'change_password_success');
    ok(['message' => 'Contrasena actualizada.']);
}

/**
 * POST auth/forgot-password — recibe email, genera token de reset, envia link.
 * Anti-enumeracion: respuesta identica exista o no el email. Token 64 hex
 * almacenado hasheado con SHA-256; el cliente recibe el plano por email.
 */
function auth_forgot_password(array $body): never {
    require_csrf();
    $email = validate_email($body, 'email');

    $user = db_one('SELECT id, name, status, is_active FROM users WHERE email = ?', [$email]);
    if ($user && (int)$user['is_active'] === 1 && ($user['status'] ?? '') === 'active') {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_HOURS * 3600);

        db_exec(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, ?, ?)',
            [$user['id'], $hash, $expires, client_ip()]
        );

        $resetUrl = app_base_url() . '/?reset_token=' . urlencode($plain);
        $tpl = mail_template_password_reset($user['name'], $resetUrl, PASSWORD_RESET_TTL_HOURS);
        $sent = mail_send($email, $tpl['subject'], $tpl['html'], $tpl['text']);
        audit_log((int)$user['id'], $sent ? 'forgot_password_sent' : 'forgot_password_mail_failed');
    } else {
        audit_log(null, 'forgot_password_unknown_email', ['email' => $email]);
    }

    // Pequeño delay para nivelar timing entre los dos caminos.
    usleep(random_int(80_000, 200_000));
    ok(['message' => 'Si el correo existe, recibiras instrucciones para restablecer tu contrasena.']);
}

/**
 * POST auth/reset-password — consume token, valida, actualiza hash.
 * Marca consumed_at + ip_address. No requiere sesion.
 */
function auth_reset_password(array $body): never {
    require_csrf();

    $token = validate_string($body, 'token', 64, 64);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        err('INVALID_TOKEN', 'Token invalido.', 400, ['field' => 'token']);
    }
    $new = validate_string($body, 'new_password', 1, 200);
    $confirm = validate_string($body, 'confirm_password', 1, 200);
    if ($new !== $confirm) {
        err('INVALID_INPUT', 'Las contrasenas no coinciden.', 400, ['field' => 'confirm_password']);
    }
    validate_password_strength($new);

    $hash = hash('sha256', $token);
    $row = db_one(
        'SELECT id, user_id, expires_at, consumed_at FROM password_reset_tokens WHERE token_hash = ?',
        [$hash]
    );
    if (!$row) {
        audit_log(null, 'reset_password_invalid_token');
        err('INVALID_TOKEN', 'Token invalido o ya utilizado.', 400);
    }
    if ($row['consumed_at']) {
        err('INVALID_TOKEN', 'Token invalido o ya utilizado.', 400);
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        err('INVALID_TOKEN', 'Token expirado. Solicita uno nuevo.', 400);
    }

    $userId = (int)$row['user_id'];
    $pwdHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);

    Database::pdo()->beginTransaction();
    try {
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP,
                              failed_attempts = 0, locked_until = NULL
                 WHERE id = ?',
            [$pwdHash, $userId]
        );
        db_exec(
            'UPDATE password_reset_tokens SET consumed_at = CURRENT_TIMESTAMP, ip_address = ? WHERE id = ?',
            [client_ip(), $row['id']]
        );
        // Invalidar otros tokens vigentes del mismo usuario.
        db_exec(
            'UPDATE password_reset_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND consumed_at IS NULL',
            [$userId]
        );
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[auth_reset_password] error: ' . $e->getMessage());
        err('SERVER_ERROR', 'No se pudo restablecer la contrasena.', 500);
    }

    audit_log($userId, 'reset_password_success');
    ok(['message' => 'Contrasena restablecida. Inicia sesion.']);
}
