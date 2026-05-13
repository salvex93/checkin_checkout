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

    $user = db_one('SELECT id, email, name, password_hash, role, is_active, status FROM users WHERE email = ?', [$email]);

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
            'role' => $user['role']
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
    ok([
        'user' => [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'company_id' => $u['company_id'] ? (int)$u['company_id'] : null
        ],
        'csrf_token' => csrf_token()
    ]);
}
