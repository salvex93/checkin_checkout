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
require_once __DIR__ . '/terms.php';

const PASSWORD_RESET_TTL_HOURS = 72;

function auth_login(array $body): never {
    require_csrf();

    $email = validate_email($body, 'email');
    $password = validate_string($body, 'password', 1, 200);

    // Rate limit por IP: 15 intentos / 15 min. Anti-credential-stuffing.
    rate_limit_ip('login_ip', 15, 900);

    // Rate limit por cuenta
    $remaining = account_lock_remaining($email);
    if ($remaining > 0) {
        audit_log(null, 'login_locked', ['email' => $email, 'remaining_sec' => $remaining]);
        err('ACCOUNT_LOCKED', "Cuenta bloqueada temporalmente. Intenta en {$remaining} segundos.", 429, ['retry_after' => $remaining]);
    }

    // Lookup por email_hash si la migracion PII corrio; fallback a email plaintext.
    $userBase = db_user_by_email($email, 'id');
    $user = null;
    if ($userBase) {
        $piiCols = pii_columns_select('u');
        $user = db_one(
            "SELECT u.id, u.email, u.name, {$piiCols} u.password_hash, u.role, u.is_active, u.status,
                    u.must_change_password, u.company_id,
                    c.name AS company_name,
                    b.id AS brand_id, b.slug AS brand_slug, b.name AS brand_name,
                    b.logo_url AS brand_logo_url,
                    b.primary_color AS brand_primary_color,
                    b.secondary_color AS brand_secondary_color
               FROM users u
               LEFT JOIN companies c ON c.id = u.company_id
               LEFT JOIN brands b ON b.id = c.brand_id
              WHERE u.id = ?",
            [(int)$userBase['id']]
        );
        if ($user) $user = user_decrypt_pii($user);
    }

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

    $termsPending = !user_has_accepted_active_terms((int)$user['id']);
    $activeTerms = terms_active_version();
    ok([
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'company_id' => $user['company_id'] ? (int)$user['company_id'] : null,
            'company_name' => $user['company_name'] ?? null,
            'brand_id' => $user['brand_id'] ? (int)$user['brand_id'] : null,
            'brand_slug' => $user['brand_slug'] ?? null,
            'brand_name' => $user['brand_name'] ?? null,
            'brand_logo_url' => $user['brand_logo_url'] ?? null,
            'brand_primary_color' => $user['brand_primary_color'] ?? null,
            'brand_secondary_color' => $user['brand_secondary_color'] ?? null,
            'must_change_password' => (int)$user['must_change_password'] === 1,
            'terms_pending' => $termsPending,
            'terms_version' => $activeTerms['version'] ?? null,
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
    $row = db_one(
        'SELECT u.must_change_password,
                c.name AS company_name,
                b.id AS brand_id, b.slug AS brand_slug, b.name AS brand_name,
                b.logo_url AS brand_logo_url,
                b.primary_color AS brand_primary_color,
                b.secondary_color AS brand_secondary_color
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
           LEFT JOIN brands b ON b.id = c.brand_id
          WHERE u.id = ?',
        [$u['id']]
    );
    $termsPending = !user_has_accepted_active_terms((int)$u['id']);
    $activeTerms = terms_active_version();
    ok([
        'user' => [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'company_id' => $u['company_id'] ? (int)$u['company_id'] : null,
            'company_name' => $row['company_name'] ?? null,
            'brand_id' => $row && $row['brand_id'] !== null ? (int)$row['brand_id'] : null,
            'brand_slug' => $row['brand_slug'] ?? null,
            'brand_name' => $row['brand_name'] ?? null,
            'brand_logo_url' => $row['brand_logo_url'] ?? null,
            'brand_primary_color' => $row['brand_primary_color'] ?? null,
            'brand_secondary_color' => $row['brand_secondary_color'] ?? null,
            'must_change_password' => $row ? (int)$row['must_change_password'] === 1 : false,
            'terms_pending' => $termsPending,
            'terms_version' => $activeTerms['version'] ?? null,
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

    $row = db_one('SELECT password_hash, role, company_id FROM users WHERE id = ?', [$u['id']]);
    if (!$row || !password_verify($current, $row['password_hash'] ?? '')) {
        audit_log((int)$u['id'], 'change_password_invalid_current');
        err('INVALID_CREDENTIALS', 'La contrasena actual es incorrecta.', 401);
    }
    if (password_verify($new, $row['password_hash'] ?? '')) {
        err('INVALID_INPUT', 'La contrasena nueva debe ser distinta a la actual.', 400, ['field' => 'new_password']);
    }

    // Admin sin empresa: en el primer login debe elegir su empresa.
    // Reglas:
    //   - Solo para role=admin (super_admin sigue sin empresa por diseño).
    //   - Solo si todavia NO tiene company_id asignado.
    //   - Si el target ya tiene company_id, ignoramos el body['company_id'].
    //   - La empresa elegida debe existir.
    $companyToAssign = null;
    if ($row['role'] === 'admin' && empty($row['company_id'])) {
        if (!isset($body['company_id']) || $body['company_id'] === '' || $body['company_id'] === null) {
            err('INVALID_INPUT', 'Debes elegir la empresa a la que perteneces.', 400, ['field' => 'company_id']);
        }
        $cid = validate_int($body, 'company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$cid])) {
            err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
        }
        $companyToAssign = $cid;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    if ($companyToAssign !== null) {
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0,
                              password_changed_at = CURRENT_TIMESTAMP, company_id = ?
                    WHERE id = ?',
            [$hash, $companyToAssign, $u['id']]
        );
        audit_log((int)$u['id'], 'change_password_success_with_company', ['company_id' => $companyToAssign]);
    } else {
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0,
                              password_changed_at = CURRENT_TIMESTAMP
                    WHERE id = ?',
            [$hash, $u['id']]
        );
        audit_log((int)$u['id'], 'change_password_success');
    }
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

    // Rate limit: 3 forgot-password por email en 15 minutos.
    // Tambien throttle por IP para evitar email-amplification.
    rate_limit_or_block('forgot_email', $email, 3, 900);
    rate_limit_or_block('forgot_ip', client_ip(), 10, 900);

    $userBase = db_user_by_email($email, 'id');
    $user = null;
    if ($userBase) {
        $piiCols = pii_columns_select('u');
        $user = db_one(
            "SELECT u.id, u.name, {$piiCols} u.status, u.is_active, b.id AS brand_id, b.name AS brand_name
               FROM users u
               LEFT JOIN companies c ON c.id = u.company_id
               LEFT JOIN brands b ON b.id = c.brand_id
              WHERE u.id = ?",
            [(int)$userBase['id']]
        );
        if ($user) $user = user_decrypt_pii($user);
    }
    if ($user && (int)$user['is_active'] === 1 && ($user['status'] ?? '') === 'active') {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_HOURS * 3600);

        db_exec(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, ?, ?)',
            [$user['id'], $hash, $expires, client_ip()]
        );

        $resetUrl = app_base_url() . '/?reset_token=' . urlencode($plain);
        $brandId = isset($user['brand_id']) ? (int)$user['brand_id'] : null;
        $override = email_template_load($brandId, 'password_reset');
        $tpl = mail_template_password_reset($user['name'], $resetUrl, PASSWORD_RESET_TTL_HOURS, [
            'brandName' => $user['brand_name'] ?? 'Melius',
            'subjectOverride' => $override['subject'] ?? null,
            'introOverride' => $override['intro_html'] ?? null,
            'ctaOverride' => $override['cta_label'] ?? null,
        ], resolve_email_brand($brandId));
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

    // Rate limit por IP: defensa contra fuerza bruta sobre tokens robados.
    rate_limit_ip('reset_ip', 20, 900);

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

// =====================================================================
// CAPTCHA matematico server-side (suma/resta de 1 a 9, sin dependencias externas).
// El challenge se genera en sesion PHP. El frontend lo muestra como pregunta
// y envia la respuesta. Un bot que no mantenga cookies de sesion no puede resolver.
// =====================================================================

/**
 * GET auth/captcha — genera un challenge nuevo y lo guarda en sesion.
 * Devuelve solo la pregunta visible; la respuesta correcta nunca sale al cliente.
 */
function auth_captcha_generate(): never {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $ops = ['+', '-'];
    $op  = $ops[random_int(0, 1)];
    $answer = $op === '+' ? $a + $b : $a - $b;
    $_SESSION['_captcha_answer'] = $answer;
    $_SESSION['_captcha_ts']     = time();
    ok(['question' => "{$a} {$op} {$b} = ?", 'expires_in' => 300]);
}

/**
 * POST auth/captcha/verify — verifica la respuesta del usuario.
 * El challenge expira en 5 minutos. Un intento fallido invalida el challenge.
 */
function auth_captcha_verify(array $body): never {
    $answer = (int)($body['answer'] ?? PHP_INT_MIN);
    $stored = $_SESSION['_captcha_answer'] ?? null;
    $ts     = (int)($_SESSION['_captcha_ts'] ?? 0);

    // Limpiar challenge siempre (un intento = consume el challenge)
    unset($_SESSION['_captcha_answer'], $_SESSION['_captcha_ts']);

    if ($stored === null || (time() - $ts) > 300) {
        err('CAPTCHA_EXPIRED', 'El codigo ha expirado. Recarga la pagina.', 400);
    }
    if ($answer !== (int)$stored) {
        err('CAPTCHA_INVALID', 'Respuesta incorrecta. Intenta de nuevo.', 400);
    }
    // Marcar sesion como captcha verificado para este flujo de login
    $_SESSION['_captcha_verified_at'] = time();
    ok(['verified' => true]);
}

/**
 * POST auth/dom-bypass-request — genera y envia OTP de 6 digitos al correo
 * del super_admin para cerrar la alerta de DOM hardening.
 * Requiere sesion activa de super_admin.
 * OTP expira en 5 minutos. Rate limit: 3 intentos / 10 min.
 */
function auth_dom_bypass_request(): never {
    $u = require_login();
    if ($u['role'] !== 'super_admin') {
        err('FORBIDDEN', 'Solo super administradores pueden usar este endpoint.', 403);
    }
    rate_limit_or_block('dom_bypass_request', (string)$u['id'], 3, 600);

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
    $_SESSION['_dom_bypass_otp_hash'] = $hash;
    $_SESSION['_dom_bypass_otp_ts']   = time();

    $email = (string)($u['email'] ?? '');
    $name  = (string)($u['name']  ?? 'Admin');

    $subject = 'Codigo de verificacion — Melius Clockin';
    $body = "<p>Hola <strong>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</strong>,</p>"
          . "<p>Tu codigo de verificacion para continuar en el panel es:</p>"
          . "<p style='font-size:36px;font-weight:900;letter-spacing:0.15em;font-family:monospace;color:#0f172a;background:#f1f5f9;padding:16px 32px;border-radius:12px;display:inline-block;margin:16px 0;'>{$otp}</p>"
          . "<p style='color:#64748b;font-size:13px;'>Este codigo caduca en <strong>5 minutos</strong> y solo puede usarse una vez.<br>Si no solicitaste este codigo, ignora este correo.</p>";
    $text = "Hola {$name},\n\nTu codigo de verificacion es: {$otp}\n\nCaduca en 5 minutos.\n";

    $sent = mail_send($email, $subject, tpl_layout($subject, $body, null), $text);
    if (!$sent) {
        unset($_SESSION['_dom_bypass_otp_hash'], $_SESSION['_dom_bypass_otp_ts']);
        err('MAIL_FAIL', 'No se pudo enviar el codigo. Revisa la configuracion SMTP.', 500);
    }
    ok(['sent' => true, 'email_hint' => substr($email, 0, 3) . '***@' . explode('@', $email)[1]]);
}

/**
 * POST auth/dom-bypass-verify — verifica el OTP enviado al correo.
 * Un intento consume el OTP (single-use). Expira en 5 minutos.
 */
function auth_dom_bypass_verify(array $body): never {
    $u = require_login();
    if ($u['role'] !== 'super_admin') {
        err('FORBIDDEN', 'Solo super administradores.', 403);
    }
    $otp  = trim((string)($body['otp'] ?? ''));
    $hash = $_SESSION['_dom_bypass_otp_hash'] ?? null;
    $ts   = (int)($_SESSION['_dom_bypass_otp_ts'] ?? 0);

    // Consumir siempre (single-use)
    unset($_SESSION['_dom_bypass_otp_hash'], $_SESSION['_dom_bypass_otp_ts']);

    if ($hash === null || (time() - $ts) > 300) {
        ok(['ok' => false, 'reason' => 'expired']);
    }
    if (!password_verify($otp, $hash)) {
        ok(['ok' => false, 'reason' => 'invalid']);
    }
    ok(['ok' => true]);
}
