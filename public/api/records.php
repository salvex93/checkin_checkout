<?php
declare(strict_types=1);

// =====================================================================
// records.php — Logica de jornadas, horas extra y cambio de empresa.
// La regla "olvido vs horas extra" se ejecuta EN SERVIDOR: el cliente no
// decide el cierre, solo declara horas. El servidor:
//   - Si hay registro abierto del dia anterior y la hora actual < 06:00 →
//     respuesta especial DECISION_REQUIRED; el cliente prompt al usuario.
//   - Si >= 06:00 → autocierre silencioso a 18:00 y se crea el nuevo registro.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/geo_alerts.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/anti_bot.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/location_alert_notifier.php';

const OVERTIME_GRACE_HOUR_AM = 6;
const STANDARD_CLOSE_HOUR = 18;
const OVERTIME_MAX_HOURS = 6.0;

/**
 * Bloquea el marcado de jornada si el usuario es admin/super_admin sin company_id.
 * Los consultores ya estan amarrados a su empresa en alta; admins pueden existir
 * sin empresa (super_admin "ghost"), pero para usar el reloj necesitan una.
 */
function require_company_for_clock(array $user): void {
    $role = $user['role'] ?? '';
    if ($role !== 'admin' && $role !== 'super_admin') return;
    if (isset($user['company_id']) && $user['company_id'] !== null) return;
    err(
        'COMPANY_REQUIRED',
        'Asigna una empresa a tu cuenta para marcar jornada.',
        409,
        ['role' => $role]
    );
}

// Tolerancia (en minutos) tras la hora oficial de salida antes de marcar el cierre como tardio.
const LATE_CLOSE_TOLERANCE_MIN = 30;

/**
 * Calcula si el clockout actual es tardio segun horario efectivo del usuario.
 * Devuelve [bool late, int minutes_after_end].
 */
function compute_late_close(array $sched, string $nowHHMM): array {
    $endStr = (string)($sched['work_end_time'] ?? '18:00');
    if (!preg_match('/^\d{2}:\d{2}/', $endStr)) return [false, 0];
    [$eh, $em] = array_map('intval', explode(':', substr($endStr, 0, 5)));
    [$nh, $nm] = array_map('intval', explode(':', substr($nowHHMM, 0, 5)));
    $endMin = $eh * 60 + $em;
    $nowMin = $nh * 60 + $nm;
    $delta = $nowMin - $endMin;
    if ($delta <= LATE_CLOSE_TOLERANCE_MIN) return [false, max(0, $delta)];
    return [true, $delta];
}

/**
 * Notifica via email (best-effort) a los admins de la empresa del usuario cuando
 * un consultor cierra tarde su jornada. Idempotente al nivel de envio: no lleva
 * registro propio porque mail_send ya es no-bloqueante y la frecuencia es baja.
 */
function notify_admin_late_close(array $user, int $recordId, string $workDate, string $exitTime, int $lateMinutes): void {
    try {
        $companyId = isset($user['company_id']) && $user['company_id'] !== null ? (int)$user['company_id'] : null;
        if (!$companyId) return;
        $admins = db_all(
            "SELECT email, name FROM users
              WHERE company_id = ? AND role IN ('admin','super_admin')
                AND is_active = 1 AND status = 'active'",
            [$companyId]
        );
        if (!$admins) return;
        $brand = function_exists('resolve_email_brand') ? resolve_email_brand(null, $companyId) : null;
        $subject = 'Cierre tardio: ' . ($user['name'] ?? $user['email'] ?? 'consultor');
        $userNameSafe = htmlspecialchars((string)($user['name'] ?? $user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $body = '<p>El consultor <strong>' . $userNameSafe . '</strong> cerro su jornada con <strong>' . (int)$lateMinutes . ' minutos</strong> de retraso.</p>'
              . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-top:8px;">'
              . '<tr><td style="color:#6b7280;">Fecha</td><td><strong>' . htmlspecialchars($workDate, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
              . '<tr><td style="color:#6b7280;">Hora de cierre</td><td><strong>' . htmlspecialchars($exitTime, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
              . '<tr><td style="color:#6b7280;">Retraso</td><td><strong>' . (int)$lateMinutes . ' min</strong></td></tr>'
              . '</table>'
              . '<p style="color:#6b7280;font-size:12px;margin-top:14px;">Tolerancia configurada: ' . LATE_CLOSE_TOLERANCE_MIN . ' min tras la hora oficial de salida.</p>';
        $html = function_exists('tpl_layout') ? tpl_layout($subject, $body, $brand) : $body;
        $text = "Cierre tardio: {$userNameSafe} cerro con {$lateMinutes} min de retraso el {$workDate} a las {$exitTime}.";
        foreach ($admins as $a) {
            @mail_send((string)$a['email'], $subject, $html, $text);
        }
    } catch (Throwable $e) {
        error_log('[notify_admin_late_close] ' . $e->getMessage());
    }
}

/**
 * Resuelve horario efectivo de un agente: overrides del usuario tienen prioridad,
 * caen a defaults de su empresa. Si el usuario es admin/super_admin sin empresa,
 * cae a un perfil neutro (TZ del servidor, 09-18, L-V, gracia 15).
 */
function effective_schedule(array $user): array {
    $companyId = isset($user['company_id']) && $user['company_id'] !== null ? (int)$user['company_id'] : null;
    $company = $companyId ? db_one(
        'SELECT timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late
           FROM companies WHERE id = ?',
        [$companyId]
    ) : null;

    return [
        'timezone' => $user['timezone'] ?? ($company['timezone'] ?? 'America/Mexico_City'),
        'work_start_time' => $user['work_start_time'] ?? ($company['work_start_time'] ?? '09:00'),
        'work_end_time' => $user['work_end_time'] ?? ($company['work_end_time'] ?? '18:00'),
        'work_days_mask' => isset($user['work_days_mask']) && $user['work_days_mask'] !== null
            ? (int)$user['work_days_mask']
            : (int)($company['work_days_mask'] ?? 31),
        'grace_minutes_late' => (int)($company['grace_minutes_late'] ?? 15),
    ];
}

/**
 * Convierte day-of-week PHP (1=Lun..7=Dom) a bit del work_days_mask
 * (bit 0=Lun..bit 6=Dom) y verifica que el dia este habilitado.
 */
function is_workday(int $dayOfWeekIso, int $mask): bool {
    $bit = $dayOfWeekIso - 1;
    return ($mask & (1 << $bit)) !== 0;
}

/**
 * TZ hibrida: si el cliente envia una TZ valida via Intl.DateTimeFormat,
 * se usa para work_date/entry_time. Se guarda la TZ del perfil tambien y
 * tz_mismatch=1 cuando difieren — bandera de auditoria, no de bloqueo.
 * Si no llega client_timezone (clientes viejos o sin JS) cae al perfil.
 */
function resolve_effective_tz(array $sched, ?string $clientTz): array {
    $profileTz = $sched['timezone'];
    $clientTz = $clientTz !== null ? trim($clientTz) : null;

    if ($clientTz === null || $clientTz === '') {
        return ['tz' => new DateTimeZone($profileTz), 'client_tz' => null, 'mismatch' => 0];
    }

    try {
        $tz = new DateTimeZone($clientTz);
    } catch (Throwable $_) {
        // TZ invalida del cliente: ignorar silenciosamente y caer al perfil.
        return ['tz' => new DateTimeZone($profileTz), 'client_tz' => null, 'mismatch' => 0];
    }
    return [
        'tz' => $tz,
        'client_tz' => $clientTz,
        'mismatch' => $clientTz !== $profileTz ? 1 : 0
    ];
}

function records_companies(): never {
    require_login();
    $companies = db_all('SELECT id, name FROM companies ORDER BY name');
    ok(['companies' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $companies)]);
}

function records_today(): never {
    $u = require_login();
    require_company_for_clock($u);
    $sched = effective_schedule($u);
    $tz = new DateTimeZone($sched['timezone']);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    ok([
        'record' => $rec ? normalize_record($rec) : null,
        'schedule' => $sched,
        'today' => $today
    ]);
}

function records_mine(): never {
    $u = require_login();
    $limit = validate_int(['limit' => $_GET['limit'] ?? 10], 'limit', 1, 100, false) ?? 10;
    $rows = db_all(
        'SELECT * FROM attendance_records WHERE user_id = ? ORDER BY work_date DESC, id DESC LIMIT ' . (int)$limit,
        [$u['id']]
    );
    ok(['records' => array_map('normalize_record', $rows)]);
}

function records_clockin(array $body): never {
    require_csrf();
    $u = require_no_pending_password();
    require_terms_accepted($u);
    require_company_for_clock($u);
    anti_bot_verify((int)$u['id'], $body);
    $sched = effective_schedule($u);
    $clientTzRaw = isset($body['client_timezone']) && is_string($body['client_timezone'])
        ? $body['client_timezone'] : null;
    $tzInfo = resolve_effective_tz($sched, $clientTzRaw);
    $tz = $tzInfo['tz'];
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $currentHour = (int)$now->format('G');

    // Aviso (no bloqueo) si el dia actual no esta marcado como laborable.
    $isWorkday = is_workday((int)$now->format('N'), (int)$sched['work_days_mask']);

    $existing = db_one('SELECT id FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    if ($existing) {
        err('ALREADY_CHECKED_IN', 'Ya registraste tu entrada hoy.', 409);
    }

    $openPrior = db_one(
        'SELECT * FROM attendance_records WHERE user_id = ? AND exit_time IS NULL AND work_date < ? ORDER BY work_date DESC LIMIT 1',
        [$u['id'], $today]
    );

    if ($openPrior) {
        $declareOvertime = isset($body['declare_overtime']) ? (bool)$body['declare_overtime'] : null;
        $overtimeHours = isset($body['overtime_hours']) ? validate_float($body, 'overtime_hours', 0.5, OVERTIME_MAX_HOURS) : null;
        $overtimeReason = isset($body['overtime_reason']) ? validate_string($body, 'overtime_reason', 0, 240, false) : null;

        if ($currentHour < OVERTIME_GRACE_HOUR_AM && $declareOvertime === null) {
            ok([
                'decision_required' => true,
                'prior_record' => normalize_record($openPrior),
                'rule' => [
                    'grace_hour_am' => OVERTIME_GRACE_HOUR_AM,
                    'standard_close_hour' => (int)substr($sched['work_end_time'], 0, 2),
                    'max_overtime_hours' => OVERTIME_MAX_HOURS
                ]
            ]);
        }

        if ($declareOvertime === true) {
            if ($overtimeHours === null) {
                err('INVALID_INPUT', 'Se requieren las horas extra a declarar.', 400, ['field' => 'overtime_hours']);
            }
            close_record_as_overtime((int)$openPrior['id'], (int)$u['id'], $overtimeHours, $overtimeReason ?? '', $tz);
        } else {
            close_record_as_forgotten((int)$openPrior['id'], $sched['work_end_time']);
        }
    }

    $entryTime = $now->format('H:i');
    $startMin = (int)substr($sched['work_start_time'], 0, 2) * 60 + (int)substr($sched['work_start_time'], 3, 2);
    $entryMin = $now->format('G') * 60 + (int)$now->format('i');
    $isLate = $entryMin > ($startMin + (int)$sched['grace_minutes_late']);

    $geo = geo_resolve_current();
    $eval = geo_evaluate_alert(Database::pdo(), (int)$u['id'], $geo, $today . ' ' . $entryTime . ':00');
    $alertFlag = $eval['flag'] ? 1 : 0;
    $alertReasons = $eval['flag'] ? implode(',', $eval['reasons']) : null;
    db_exec(
        'INSERT INTO attendance_records (user_id, work_date, entry_time, timezone, client_timezone, tz_mismatch,
                                          geo_country_code, geo_country_name, geo_city, geo_region, geo_lat, geo_lon,
                                          geo_ip_masked, geo_source, geo_alert_flag, geo_alert_reasons)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $u['id'], $today, $entryTime, $sched['timezone'],
            $tzInfo['client_tz'], $tzInfo['mismatch'],
            $geo['country_code'], $geo['country_name'], $geo['city'], $geo['region'],
            $geo['lat'], $geo['lon'],
            $geo['ip_masked'], $geo['source'],
            $alertFlag, $alertReasons
        ]
    );
    $newId = (int)db_last_id();
    audit_log((int)$u['id'], 'clockin', [
        'date' => $today, 'tz' => $sched['timezone'],
        'client_tz' => $tzInfo['client_tz'], 'tz_mismatch' => (bool)$tzInfo['mismatch'],
        'is_late' => $isLate, 'is_workday' => $isWorkday,
        'geo_country' => $geo['country_code'],
        'geo_city' => $geo['city'],
        'geo_alert' => $alertFlag === 1 ? $alertReasons : null
    ]);
    if ($eval['flag']) {
        record_location_alert(Database::pdo(), (int)$u['id'], $newId, $eval);
    }
    $rec = db_one('SELECT * FROM attendance_records WHERE id = ?', [$newId]);
    ok([
        'record' => normalize_record($rec),
        'warnings' => [
            'is_late' => $isLate,
            'non_workday' => !$isWorkday,
            'tz_mismatch' => (bool)$tzInfo['mismatch'],
        ]
    ]);
}

function records_clockout(array $body = []): never {
    require_csrf();
    $u = require_no_pending_password();
    require_terms_accepted($u);
    require_company_for_clock($u);
    anti_bot_verify((int)$u['id'], $body);
    $sched = effective_schedule($u);
    $clientTzRaw = isset($body['client_timezone']) && is_string($body['client_timezone'])
        ? $body['client_timezone'] : null;
    $tzInfo = resolve_effective_tz($sched, $clientTzRaw);
    $tz = $tzInfo['tz'];
    $nowDt = new DateTimeImmutable('now', $tz);
    $today = $nowDt->format('Y-m-d');
    $now = $nowDt->format('H:i');

    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    if (!$rec) err('NOT_CHECKED_IN', 'Debes marcar entrada primero.', 409);
    if ($rec['exit_time']) err('ALREADY_CHECKED_OUT', 'Ya registraste tu salida hoy.', 409);

    // Calculo de cierre tardio: NO bloquea; solo marca flag + notifica admin si excede tolerancia.
    [$lateClose, $lateMinutes] = compute_late_close($sched, $now);

    // Si el clockout viene desde una TZ distinta a la del clockin (viajaste),
    // se respeta la TZ del navegador para exit_time pero queda marcado.
    $exitMismatch = $tzInfo['mismatch'] || ($rec['tz_mismatch'] ?? 0);
    // Resolvemos geo del clockout SIEMPRE para guardar geo_exit_*.
    // Si el record original no tiene pais de entrada, lo llenamos retroactivo.
    $geo = geo_resolve_current();
    db_exec(
        'UPDATE attendance_records
            SET exit_time = ?, closed_reason = ?, tz_mismatch = ?,
                late_close = ?, late_minutes = ?,
                geo_country_code = COALESCE(geo_country_code, ?),
                geo_country_name = COALESCE(geo_country_name, ?),
                geo_city         = COALESCE(geo_city, ?),
                geo_region       = COALESCE(geo_region, ?),
                geo_lat          = COALESCE(geo_lat, ?),
                geo_lon          = COALESCE(geo_lon, ?),
                geo_ip_masked    = COALESCE(geo_ip_masked, ?),
                geo_source       = COALESCE(geo_source, ?),
                geo_exit_country_code = ?,
                geo_exit_city         = ?,
                geo_exit_lat          = ?,
                geo_exit_lon          = ?
          WHERE id = ?',
        [
            $now, 'normal', $exitMismatch ? 1 : 0,
            $lateClose ? 1 : 0, $lateMinutes,
            $geo['country_code'], $geo['country_name'], $geo['city'], $geo['region'],
            $geo['lat'], $geo['lon'], $geo['ip_masked'], $geo['source'],
            $geo['country_code'], $geo['city'], $geo['lat'], $geo['lon'],
            $rec['id']
        ]
    );

    // Notificacion admin si el cierre fue tardio (no bloquea ni se hace sincrono al usuario).
    if ($lateClose) {
        notify_admin_late_close($u, $rec['id'], $today, $now, $lateMinutes);
    }
    // Evaluacion de alerta en salida: solo si tenemos coords actuales y diferentes al clockin.
    $exitEval = ['flag' => false, 'reasons' => [], 'context' => []];
    if (($geo['source'] ?? null) === 'ip' && !empty($geo['country_code'])) {
        $exitEval = geo_evaluate_alert(Database::pdo(), (int)$u['id'], $geo, $today . ' ' . $now . ':00');
        if ($exitEval['flag']) {
            $existingReasons = $rec['geo_alert_reasons'] ?? null;
            $merged = array_unique(array_filter(array_merge(
                $existingReasons ? explode(',', $existingReasons) : [],
                $exitEval['reasons']
            )));
            db_exec(
                'UPDATE attendance_records SET geo_alert_flag = 1, geo_alert_reasons = ? WHERE id = ?',
                [implode(',', $merged), $rec['id']]
            );
            record_location_alert(Database::pdo(), (int)$u['id'], (int)$rec['id'], $exitEval);
        }
    }
    audit_log((int)$u['id'], 'clockout', [
        'date' => $today, 'tz' => $sched['timezone'],
        'client_tz' => $tzInfo['client_tz'], 'tz_mismatch' => (bool)$tzInfo['mismatch'],
        'geo_exit_country' => $geo['country_code'],
        'geo_exit_city' => $geo['city'],
        'geo_exit_alert' => $exitEval['flag'] ? implode(',', $exitEval['reasons']) : null
    ]);
    $rec = db_one('SELECT * FROM attendance_records WHERE id = ?', [$rec['id']]);
    ok(['record' => normalize_record($rec)]);
}

function records_overtime(array $body): never {
    require_csrf();
    $u = require_no_pending_password();
    require_terms_accepted($u);
    $hours = validate_float($body, 'hours', 0.5, OVERTIME_MAX_HOURS);
    $reason = validate_string($body, 'reason', 0, 240, false) ?? '';

    // Fecha objetivo: hoy por defecto, retroactivo hasta 7 dias, nunca futuro.
    $today = new DateTimeImmutable('today');
    if (isset($body['work_date']) && $body['work_date'] !== '') {
        $rawDate = validate_string($body, 'work_date', 10, 10);
        $target = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
        if (!$target || $target->format('Y-m-d') !== $rawDate) {
            err('INVALID_INPUT', 'Fecha invalida (use YYYY-MM-DD).', 400, ['field' => 'work_date']);
        }
    } else {
        $target = $today;
    }
    if ($target > $today) {
        err('INVALID_INPUT', 'No puedes declarar horas extra para una fecha futura.', 400, ['field' => 'work_date']);
    }
    $minDate = $today->modify('-7 days');
    if ($target < $minDate) {
        err('INVALID_INPUT', 'Solo puedes declarar horas extra de los ultimos 7 dias.', 400, ['field' => 'work_date']);
    }

    $workDate = $target->format('Y-m-d');
    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $workDate]);
    if (!$rec) err('NO_RECORD_FOR_DATE', 'No hay jornada registrada para esa fecha.', 409, ['field' => 'work_date']);

    // Una sola peticion vigente por dia: rechazar si existe pending o approved.
    $existing = db_one(
        "SELECT id, status FROM overtime_requests
          WHERE user_id = ? AND record_id = ? AND status IN ('pending','approved') AND request_type = 'new'
          ORDER BY id DESC LIMIT 1",
        [$u['id'], $rec['id']]
    );
    if ($existing) {
        $msg = $existing['status'] === 'approved'
            ? 'Ya tienes horas extra aprobadas para esa fecha. Solicita una edicion.'
            : 'Ya existe una peticion pendiente para esa fecha.';
        err('OVERTIME_EXISTS', $msg, 409, ['existing_id' => (int)$existing['id'], 'existing_status' => $existing['status']]);
    }

    $geo = geo_resolve_current();
    db_exec(
        "INSERT INTO overtime_requests (user_id, record_id, hours, reason, status, request_type,
                                         geo_country_code, geo_country_name, geo_ip_masked, geo_source)
              VALUES (?, ?, ?, ?, 'pending', 'new', ?, ?, ?, ?)",
        [
            $u['id'], $rec['id'], $hours, $reason,
            $geo['country_code'], $geo['country_name'], $geo['ip_masked'], $geo['source']
        ]
    );
    db_exec(
        'UPDATE attendance_records SET overtime_hours = overtime_hours + ?, overtime_status = ? WHERE id = ?',
        [$hours, 'pending', $rec['id']]
    );
    audit_log((int)$u['id'], 'overtime_request', [
        'hours' => $hours, 'record_id' => (int)$rec['id'], 'work_date' => $workDate,
        'geo_country' => $geo['country_code']
    ]);
    notify_admins_new_request(
        $u['company_id'] ? (int)$u['company_id'] : null,
        [
            'request_type' => 'overtime_new',
            'employee_name' => $u['name'] ?? '',
            'company_name' => $u['company_name'] ?? '',
            'detail' => sprintf('%.1f h del %s', $hours, $workDate),
        ]
    );
    ok(['message' => 'Horas extra enviadas a aprobacion.']);
}

function records_change_company(array $body): never {
    require_csrf();
    $u = require_no_pending_password();
    $newCompanyId = validate_int($body, 'new_company_id', 1);

    $company = db_one('SELECT id, name, brand_id FROM companies WHERE id = ?', [$newCompanyId]);
    if (!$company) err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'new_company_id']);
    if ((int)$u['company_id'] === $newCompanyId) {
        err('INVALID_INPUT', 'Ya perteneces a esa empresa.', 400, ['field' => 'new_company_id']);
    }

    // IDOR fix: el consultor solo puede solicitar cambio a empresas DE LA MISMA
    // marca paraguas que su empresa actual. Si su empresa no tiene marca, solo
    // puede pedir a empresas sin marca tambien. Esto refleja el modelo real:
    // un consultor de Melius no salta a Fullman sin proceso administrativo.
    $currentCompany = $u['company_id'] !== null
        ? db_one('SELECT brand_id FROM companies WHERE id = ?', [$u['company_id']])
        : null;
    $currentBrand = $currentCompany['brand_id'] ?? null;
    $targetBrand = $company['brand_id'] ?? null;
    if ($currentBrand !== $targetBrand) {
        err('FORBIDDEN', 'Solo puedes solicitar cambio entre empresas de la misma marca. Contacta a un administrador para cambios entre marcas.', 403, ['field' => 'new_company_id']);
    }

    db_exec(
        'INSERT INTO change_requests (user_id, old_company_id, new_company_id, status) VALUES (?, ?, ?, ?)',
        [$u['id'], $u['company_id'], $newCompanyId, 'pending']
    );
    audit_log((int)$u['id'], 'change_company_request', ['new_company_id' => $newCompanyId]);
    notify_admins_new_request(
        $u['company_id'] ? (int)$u['company_id'] : null,
        [
            'request_type' => 'change_company',
            'employee_name' => $u['name'] ?? '',
            'company_name' => $u['company_name'] ?? '',
            'detail' => 'Quiere cambiar a ' . ($company['name'] ?? "ID {$newCompanyId}"),
        ]
    );
    ok(['message' => 'Solicitud enviada a aprobacion.']);
}

/**
 * POST records/overtime-edit-request — agente solicita modificar el monto
 * de una solicitud aprobada. Mientras la edicion esta pending, el valor
 * approved original sigue contando en reportes. Una sola edicion vigente
 * por overtime original.
 */
function records_overtime_edit_request(array $body): never {
    require_csrf();
    $u = require_no_pending_password();
    require_terms_accepted($u);

    $refId = validate_int($body, 'overtime_request_id', 1);
    $newHours = validate_float($body, 'new_hours', 0.5, OVERTIME_MAX_HOURS);
    $reason = validate_string($body, 'reason', 0, 240, false) ?? '';

    $original = db_one(
        "SELECT * FROM overtime_requests
          WHERE id = ? AND user_id = ? AND status = 'approved' AND request_type = 'new'",
        [$refId, $u['id']]
    );
    if (!$original) {
        err('NOT_FOUND', 'No existe una solicitud aprobada con ese id para tu usuario.', 404, ['field' => 'overtime_request_id']);
    }
    if (abs((float)$original['hours'] - $newHours) < 0.01) {
        err('INVALID_INPUT', 'El nuevo monto debe diferir del actual.', 400, ['field' => 'new_hours']);
    }

    // No permitir doble edicion pending sobre la misma solicitud.
    $pending = db_one(
        "SELECT id FROM overtime_requests
          WHERE referenced_request_id = ? AND status = 'pending' AND request_type = 'edit'",
        [$refId]
    );
    if ($pending) {
        err('OVERTIME_EDIT_EXISTS', 'Ya tienes una edicion pendiente para esa solicitud.', 409, ['existing_id' => (int)$pending['id']]);
    }

    $geo = geo_resolve_current();
    db_exec(
        "INSERT INTO overtime_requests (user_id, record_id, hours, new_hours, reason, status, request_type, referenced_request_id,
                                         geo_country_code, geo_country_name, geo_ip_masked, geo_source)
              VALUES (?, ?, ?, ?, ?, 'pending', 'edit', ?, ?, ?, ?, ?)",
        [
            $u['id'], $original['record_id'], $original['hours'], $newHours, $reason, $refId,
            $geo['country_code'], $geo['country_name'], $geo['ip_masked'], $geo['source']
        ]
    );
    audit_log((int)$u['id'], 'overtime_edit_request', [
        'reference_id' => $refId, 'old_hours' => (float)$original['hours'], 'new_hours' => $newHours
    ]);
    notify_admins_new_request(
        $u['company_id'] ? (int)$u['company_id'] : null,
        [
            'request_type' => 'overtime_edit',
            'employee_name' => $u['name'] ?? '',
            'company_name' => $u['company_name'] ?? '',
            'detail' => sprintf('%.1f h -> %.1f h (solicitud #%d)', (float)$original['hours'], $newHours, $refId),
        ]
    );
    ok(['message' => 'Edicion enviada a aprobacion.']);
}

// === Helpers internos ===

function close_record_as_forgotten(int $recordId, string $workEndTime = '18:00'): void {
    $exitTime = substr($workEndTime, 0, 5);
    db_exec(
        'UPDATE attendance_records SET exit_time = ?, closed_reason = ? WHERE id = ?',
        [$exitTime, 'forgotten', $recordId]
    );
    audit_log(null, 'autoclose_forgotten', ['record_id' => $recordId, 'exit_time' => $exitTime]);
}

function close_record_as_overtime(int $recordId, int $userId, float $hours, string $reason, ?DateTimeZone $tz = null): void {
    $exitTime = (new DateTimeImmutable('now', $tz))->format('H:i');
    db_exec(
        'UPDATE attendance_records SET exit_time = ?, closed_reason = ?, overtime_hours = ?, overtime_status = ? WHERE id = ?',
        [$exitTime, 'overtime', $hours, 'pending', $recordId]
    );
    db_exec(
        "INSERT INTO overtime_requests (user_id, record_id, hours, reason, status, request_type)
              VALUES (?, ?, ?, ?, 'pending', 'new')",
        [$userId, $recordId, $hours, $reason]
    );
    audit_log($userId, 'autoclose_overtime', ['record_id' => $recordId, 'hours' => $hours]);
}

function normalize_record(array $r): array {
    return [
        'id' => (int)$r['id'],
        'user_id' => (int)$r['user_id'],
        'work_date' => $r['work_date'],
        'entry_time' => $r['entry_time'],
        'exit_time' => $r['exit_time'],
        'timezone' => $r['timezone'] ?? null,
        'client_timezone' => $r['client_timezone'] ?? null,
        'tz_mismatch' => isset($r['tz_mismatch']) ? (bool)$r['tz_mismatch'] : false,
        'closed_reason' => $r['closed_reason'],
        'late_close' => isset($r['late_close']) ? (bool)$r['late_close'] : false,
        'late_minutes' => isset($r['late_minutes']) ? (int)$r['late_minutes'] : 0,
        'overtime_hours' => (float)$r['overtime_hours'],
        'overtime_status' => $r['overtime_status'],
        'geo_country_code' => $r['geo_country_code'] ?? null,
        'geo_country_name' => $r['geo_country_name'] ?? null,
        'geo_city' => $r['geo_city'] ?? null,
        'geo_region' => $r['geo_region'] ?? null,
        'geo_source' => $r['geo_source'] ?? null,
        'geo_alert_flag' => isset($r['geo_alert_flag']) ? (bool)$r['geo_alert_flag'] : false,
        'geo_alert_reasons' => $r['geo_alert_reasons'] ?? null,
        'geo_exit_country_code' => $r['geo_exit_country_code'] ?? null,
        'geo_exit_city' => $r['geo_exit_city'] ?? null,
    ];
}
