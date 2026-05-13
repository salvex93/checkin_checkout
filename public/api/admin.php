<?php
declare(strict_types=1);

// =====================================================================
// admin.php — Endpoints reservados a rol admin.
// Cada handler valida `require_admin()` que verifica sesion + rol en server.
// El rol jamas se acepta desde el cliente.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

function admin_records(): never {
    require_admin();
    $rows = db_all(
        'SELECT ar.*, u.name as user_name, u.email as user_email, c.name as company_name
         FROM attendance_records ar
         JOIN users u ON u.id = ar.user_id
         LEFT JOIN companies c ON c.id = u.company_id
         ORDER BY ar.work_date DESC, ar.id DESC
         LIMIT 500'
    );
    ok(['records' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'user_email' => $r['user_email'],
            'company_name' => $r['company_name'],
            'work_date' => $r['work_date'],
            'entry_time' => $r['entry_time'],
            'exit_time' => $r['exit_time'],
            'timezone' => $r['timezone'] ?? null,
            'client_timezone' => $r['client_timezone'] ?? null,
            'tz_mismatch' => isset($r['tz_mismatch']) ? (bool)$r['tz_mismatch'] : false,
            'closed_reason' => $r['closed_reason'],
            'overtime_hours' => (float)$r['overtime_hours'],
            'overtime_status' => $r['overtime_status']
        ];
    }, $rows)]);
}

function admin_change_requests(): never {
    require_admin();
    $rows = db_all(
        "SELECT cr.*, u.name as user_name, oc.name as old_company_name, nc.name as new_company_name
         FROM change_requests cr
         JOIN users u ON u.id = cr.user_id
         LEFT JOIN companies oc ON oc.id = cr.old_company_id
         JOIN companies nc ON nc.id = cr.new_company_id
         WHERE cr.status = 'pending'
         ORDER BY cr.requested_at DESC"
    );
    ok(['requests' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'old_company_name' => $r['old_company_name'],
            'new_company_name' => $r['new_company_name'],
            'new_company_id' => (int)$r['new_company_id'],
            'requested_at' => $r['requested_at']
        ];
    }, $rows)]);
}

function admin_overtime_requests(): never {
    require_admin();
    $rows = db_all(
        "SELECT ot.*, u.name as user_name, ar.work_date
         FROM overtime_requests ot
         JOIN users u ON u.id = ot.user_id
         JOIN attendance_records ar ON ar.id = ot.record_id
         WHERE ot.status = 'pending'
         ORDER BY ot.requested_at DESC"
    );
    ok(['requests' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'record_id' => (int)$r['record_id'],
            'date' => $r['work_date'],
            'hours' => (float)$r['hours'],
            'new_hours' => $r['new_hours'] !== null ? (float)$r['new_hours'] : null,
            'request_type' => $r['request_type'] ?? 'new',
            'referenced_request_id' => $r['referenced_request_id'] !== null ? (int)$r['referenced_request_id'] : null,
            'reason' => $r['reason'],
            'requested_at' => $r['requested_at']
        ];
    }, $rows)]);
}

function admin_decide(array $body): never {
    require_csrf();
    $admin = require_admin();
    $type = validate_string($body, 'type', 1, 20);     // 'change' o 'overtime'
    $id = validate_int($body, 'id', 1);
    $decision = validate_string($body, 'decision', 1, 20); // 'approve' o 'reject'

    if (!in_array($type, ['change', 'overtime'], true)) {
        err('INVALID_INPUT', 'Tipo de solicitud invalido.', 400, ['field' => 'type']);
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        err('INVALID_INPUT', 'Decision invalida.', 400, ['field' => 'decision']);
    }
    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';

    Database::pdo()->beginTransaction();
    try {
        if ($type === 'change') {
            $req = db_one('SELECT * FROM change_requests WHERE id = ? AND status = ?', [$id, 'pending']);
            if (!$req) { Database::pdo()->rollBack(); err('NOT_FOUND', 'Solicitud no encontrada o ya procesada.', 404); }
            if ($decision === 'approve') {
                db_exec('UPDATE users SET company_id = ? WHERE id = ?', [$req['new_company_id'], $req['user_id']]);
            }
            db_exec(
                'UPDATE change_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$newStatus, $id]
            );
        } else { // overtime
            $req = db_one('SELECT * FROM overtime_requests WHERE id = ? AND status = ?', [$id, 'pending']);
            if (!$req) { Database::pdo()->rollBack(); err('NOT_FOUND', 'Solicitud no encontrada o ya procesada.', 404); }

            $isEdit = ($req['request_type'] ?? 'new') === 'edit';

            db_exec(
                'UPDATE overtime_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$newStatus, $id]
            );

            if ($isEdit) {
                // Edicion: solo modifica al original si aprobamos. Si rechazamos,
                // el original queda intacto y nada cambia en attendance_records.
                if ($decision === 'approve' && $req['referenced_request_id'] !== null && $req['new_hours'] !== null) {
                    $original = db_one(
                        'SELECT id, record_id, hours FROM overtime_requests WHERE id = ?',
                        [$req['referenced_request_id']]
                    );
                    if ($original) {
                        $oldHours = (float)$original['hours'];
                        $newHours = (float)$req['new_hours'];
                        $delta = $newHours - $oldHours;
                        db_exec(
                            'UPDATE overtime_requests SET hours = ? WHERE id = ?',
                            [$newHours, $original['id']]
                        );
                        db_exec(
                            'UPDATE attendance_records SET overtime_hours = overtime_hours + ? WHERE id = ?',
                            [$delta, $original['record_id']]
                        );
                    }
                }
            } else {
                // Solicitud nueva: reflejar status en el registro asociado.
                db_exec(
                    'UPDATE attendance_records SET overtime_status = ? WHERE id = ?',
                    [$newStatus, $req['record_id']]
                );
            }
        }
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[admin_decide] error: ' . $e->getMessage());
        err('SERVER_ERROR', 'No se pudo procesar la decision.', 500);
    }

    audit_log((int)$admin['id'], "admin_{$type}_{$decision}", ['target_id' => $id]);
    ok(['message' => 'Decision aplicada.']);
}

// =====================================================================
// Fase 2 — Administracion de empresas y agentes.
// =====================================================================

function admin_companies_list(): never {
    require_admin();
    $rows = db_all(
        'SELECT id, name, timezone, work_start_time, work_end_time,
                work_days_mask, grace_minutes_late, is_configured, created_at,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1) AS active_users
           FROM companies c
          ORDER BY name ASC'
    );
    ok(['companies' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'timezone' => $r['timezone'],
        'work_start_time' => substr($r['work_start_time'], 0, 5),
        'work_end_time' => substr($r['work_end_time'], 0, 5),
        'work_days_mask' => (int)$r['work_days_mask'],
        'grace_minutes_late' => (int)$r['grace_minutes_late'],
        'is_configured' => (int)$r['is_configured'] === 1,
        'active_users' => (int)$r['active_users'],
        'created_at' => $r['created_at'],
    ], $rows)]);
}

function admin_companies_create(array $body): never {
    require_csrf();
    $admin = require_admin();
    $name = validate_string($body, 'name', 1, 100);
    $tz = validate_timezone($body, 'timezone');
    $start = validate_time_hhmm($body, 'work_start_time');
    $end = validate_time_hhmm($body, 'work_end_time');
    $mask = validate_days_mask($body, 'work_days_mask');
    $grace = validate_int($body, 'grace_minutes_late', 0, 60);

    if ($start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }
    if (db_one('SELECT id FROM companies WHERE name = ?', [$name])) {
        err('CONFLICT', 'Ya existe una empresa con ese nombre.', 409, ['field' => 'name']);
    }

    db_exec(
        'INSERT INTO companies (name, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late, is_configured)
              VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$name, $tz, $start, $end, $mask, $grace]
    );
    $id = (int)db_last_id();
    audit_log((int)$admin['id'], 'admin_company_create', ['company_id' => $id, 'name' => $name]);
    ok(['id' => $id, 'message' => 'Empresa creada.'], 201);
}

function admin_companies_update(int $id, array $body): never {
    require_csrf();
    $admin = require_admin();
    $existing = db_one('SELECT id, name FROM companies WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Empresa no encontrada.', 404);

    $name = validate_string($body, 'name', 1, 100);
    $tz = validate_timezone($body, 'timezone');
    $start = validate_time_hhmm($body, 'work_start_time');
    $end = validate_time_hhmm($body, 'work_end_time');
    $mask = validate_days_mask($body, 'work_days_mask');
    $grace = validate_int($body, 'grace_minutes_late', 0, 60);

    if ($start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }
    $dup = db_one('SELECT id FROM companies WHERE name = ? AND id <> ?', [$name, $id]);
    if ($dup) err('CONFLICT', 'Ya existe otra empresa con ese nombre.', 409, ['field' => 'name']);

    db_exec(
        'UPDATE companies SET name = ?, timezone = ?, work_start_time = ?, work_end_time = ?,
                              work_days_mask = ?, grace_minutes_late = ?, is_configured = 1
                 WHERE id = ?',
        [$name, $tz, $start, $end, $mask, $grace, $id]
    );
    audit_log((int)$admin['id'], 'admin_company_update', ['company_id' => $id]);
    ok(['message' => 'Empresa actualizada.']);
}

function admin_companies_delete(int $id): never {
    require_csrf();
    $admin = require_admin();
    $existing = db_one('SELECT id, name FROM companies WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Empresa no encontrada.', 404);

    $active = db_one('SELECT COUNT(*) AS c FROM users WHERE company_id = ? AND is_active = 1', [$id]);
    if ((int)$active['c'] > 0) {
        err('CONFLICT', 'No se puede eliminar: la empresa tiene agentes activos asignados.', 409, ['active_users' => (int)$active['c']]);
    }
    db_exec('DELETE FROM companies WHERE id = ?', [$id]);
    audit_log((int)$admin['id'], 'admin_company_delete', ['company_id' => $id, 'name' => $existing['name']]);
    ok(['message' => 'Empresa eliminada.']);
}

function admin_users_list(): never {
    require_admin();
    $rows = db_all(
        "SELECT u.id, u.email, u.name, u.role, u.company_id, u.is_active, u.status,
                u.timezone, u.work_start_time, u.work_end_time, u.work_days_mask,
                u.created_at, c.name AS company_name
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
          ORDER BY u.created_at DESC"
    );
    ok(['users' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'email' => $r['email'],
        'name' => $r['name'],
        'role' => $r['role'],
        'company_id' => $r['company_id'] !== null ? (int)$r['company_id'] : null,
        'company_name' => $r['company_name'],
        'is_active' => (int)$r['is_active'] === 1,
        'status' => $r['status'],
        'timezone' => $r['timezone'],
        'work_start_time' => $r['work_start_time'] !== null ? substr($r['work_start_time'], 0, 5) : null,
        'work_end_time' => $r['work_end_time'] !== null ? substr($r['work_end_time'], 0, 5) : null,
        'work_days_mask' => $r['work_days_mask'] !== null ? (int)$r['work_days_mask'] : null,
        'created_at' => $r['created_at'],
    ], $rows)]);
}

function admin_users_update(int $id, array $body): never {
    require_csrf();
    $admin = require_admin();
    $user = db_one('SELECT id, role FROM users WHERE id = ?', [$id]);
    if (!$user) err('NOT_FOUND', 'Agente no encontrado.', 404);

    $companyId = null;
    if (array_key_exists('company_id', $body) && $body['company_id'] !== null && $body['company_id'] !== '') {
        $companyId = validate_int($body, 'company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
            err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
        }
    }

    $status = validate_string($body, 'status', 1, 30);
    if (!in_array($status, ['pending_confirmation', 'active', 'disabled'], true)) {
        err('INVALID_INPUT', 'Status invalido.', 400, ['field' => 'status']);
    }
    if ($user['role'] === 'admin' && $status === 'disabled' && (int)$admin['id'] === $id) {
        err('CONFLICT', 'No puedes desactivarte a ti mismo.', 409);
    }

    $tz = isset($body['timezone']) && $body['timezone'] !== '' ? validate_timezone($body, 'timezone') : null;
    $start = isset($body['work_start_time']) && $body['work_start_time'] !== '' ? validate_time_hhmm($body, 'work_start_time') : null;
    $end = isset($body['work_end_time']) && $body['work_end_time'] !== '' ? validate_time_hhmm($body, 'work_end_time') : null;
    $mask = isset($body['work_days_mask']) && $body['work_days_mask'] !== '' ? validate_days_mask($body, 'work_days_mask') : null;

    if ($start !== null && $end !== null && $start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }

    $isActive = $status === 'active' ? 1 : 0;
    db_exec(
        'UPDATE users SET company_id = ?, status = ?, is_active = ?,
                          timezone = ?, work_start_time = ?, work_end_time = ?, work_days_mask = ?
                 WHERE id = ?',
        [$companyId, $status, $isActive, $tz, $start, $end, $mask, $id]
    );
    audit_log((int)$admin['id'], 'admin_user_update', [
        'user_id' => $id, 'company_id' => $companyId, 'status' => $status
    ]);
    ok(['message' => 'Agente actualizado.']);
}

/**
 * POST admin/users/invite — crea cuenta con password temporal y envia email.
 * Reemplaza el flujo publico de auth/register. super_admin puede crear admin
 * o consultant; admin solo consultant. Anti-enumeracion: respuesta identica
 * si el email ya existe (no crea ni envia).
 */
function admin_users_invite(array $body): never {
    require_csrf();
    $admin = require_admin();

    $email = validate_email($body, 'email');
    $name = validate_string($body, 'name', 2, 120);
    $role = validate_string($body, 'role', 1, 20);
    if (!in_array($role, ['consultant', 'admin'], true)) {
        err('INVALID_INPUT', 'Rol invalido. Permitidos: consultant, admin.', 400, ['field' => 'role']);
    }
    if ($role === 'admin' && ($admin['role'] ?? '') !== 'super_admin') {
        err('FORBIDDEN', 'Solo super_admin puede crear administradores.', 403);
    }

    $companyId = null;
    if (array_key_exists('company_id', $body) && $body['company_id'] !== null && $body['company_id'] !== '') {
        $companyId = validate_int($body, 'company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
            err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
        }
    }
    // Consultor obligatoriamente vinculado a una empresa.
    if ($role === 'consultant' && $companyId === null) {
        err('INVALID_INPUT', 'Los agentes requieren empresa asignada.', 400, ['field' => 'company_id']);
    }
    // Admin tambien marca jornada como empleado: requiere empresa al darlo de alta.
    // super_admin queda fuera de este flujo (no se crea via admin_users_invite).
    if ($role === 'admin' && $companyId === null) {
        err('INVALID_INPUT', 'Los administradores requieren empresa asignada para poder marcar jornada.', 400, ['field' => 'company_id']);
    }

    // Anti-enumeracion: si el email ya existe no creamos pero respondemos OK.
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        audit_log((int)$admin['id'], 'admin_invite_duplicate', ['email' => $email]);
        ok(['message' => 'Invitacion enviada.']);
    }

    $tempPassword = password_temp_generate(14);
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    Database::pdo()->beginTransaction();
    try {
        db_exec(
            'INSERT INTO users (email, name, password_hash, role, company_id, status, is_active, must_change_password)
                  VALUES (?, ?, ?, ?, ?, ?, 1, 1)',
            [$email, $name, $hash, $role, $companyId, 'active']
        );
        $userId = (int)db_last_id();

        // Envio fuera de la transaccion: si falla SMTP no queremos romper el
        // commit; lo hacemos justo despues. La transaccion aqui solo abarca el
        // insert para que un fallo de BD revierta antes de mandar correo.
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[admin_users_invite] insert fallo: ' . $e->getMessage());
        err('SERVER_ERROR', 'No se pudo crear el usuario.', 500);
    }

    $loginUrl = app_base_url() . '/';
    $tpl = mail_template_temp_password($name, $email, $tempPassword, $loginUrl);
    $sent = mail_send($email, $tpl['subject'], $tpl['html'], $tpl['text']);

    if (!$sent) {
        // El usuario fue creado pero el correo no salio. Revertir creacion para
        // no dejar cuenta zombi sin credenciales conocidas por el destinatario.
        db_exec('DELETE FROM users WHERE id = ?', [$userId]);
        audit_log((int)$admin['id'], 'admin_invite_mail_failed', ['email' => $email]);
        err('MAIL_FAILED', 'No se pudo enviar el correo. Verifica configuracion SMTP.', 502);
    }

    audit_log((int)$admin['id'], 'admin_invite_created', [
        'user_id' => $userId, 'email' => $email, 'role' => $role, 'company_id' => $companyId
    ]);
    ok(['message' => 'Invitacion enviada.', 'user_id' => $userId], 201);
}
