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

const OVERTIME_GRACE_HOUR_AM = 6;
const STANDARD_CLOSE_HOUR = 18;
const OVERTIME_MAX_HOURS = 6.0;

function records_companies(): never {
    require_login();
    $companies = db_all('SELECT id, name FROM companies ORDER BY name');
    ok(['companies' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $companies)]);
}

function records_today(): never {
    $u = require_login();
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    ok(['record' => $rec ? normalize_record($rec) : null]);
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
    $u = require_login();
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    $currentHour = (int)$now->format('G');

    // Idempotencia: si ya hay registro de hoy con entry, error
    $existing = db_one('SELECT id FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    if ($existing) {
        err('ALREADY_CHECKED_IN', 'Ya registraste tu entrada hoy.', 409);
    }

    // Buscar registro abierto de dia anterior
    $openPrior = db_one(
        'SELECT * FROM attendance_records WHERE user_id = ? AND exit_time IS NULL AND work_date < ? ORDER BY work_date DESC LIMIT 1',
        [$u['id'], $today]
    );

    if ($openPrior) {
        $declareOvertime = isset($body['declare_overtime']) ? (bool)$body['declare_overtime'] : null;
        $overtimeHours = isset($body['overtime_hours']) ? validate_float($body, 'overtime_hours', 0.5, OVERTIME_MAX_HOURS) : null;
        $overtimeReason = isset($body['overtime_reason']) ? validate_string($body, 'overtime_reason', 0, 240, false) : null;

        if ($currentHour < OVERTIME_GRACE_HOUR_AM && $declareOvertime === null) {
            // Decision pendiente: el cliente debe preguntar al usuario
            ok([
                'decision_required' => true,
                'prior_record' => normalize_record($openPrior),
                'rule' => [
                    'grace_hour_am' => OVERTIME_GRACE_HOUR_AM,
                    'standard_close_hour' => STANDARD_CLOSE_HOUR,
                    'max_overtime_hours' => OVERTIME_MAX_HOURS
                ]
            ]);
        }

        if ($declareOvertime === true) {
            if ($overtimeHours === null) {
                err('INVALID_INPUT', 'Se requieren las horas extra a declarar.', 400, ['field' => 'overtime_hours']);
            }
            close_record_as_overtime((int)$openPrior['id'], (int)$u['id'], $overtimeHours, $overtimeReason ?? '');
        } else {
            close_record_as_forgotten((int)$openPrior['id']);
        }
    }

    // Crear el registro de hoy
    db_exec(
        'INSERT INTO attendance_records (user_id, work_date, entry_time) VALUES (?, ?, ?)',
        [$u['id'], $today, $now->format('H:i')]
    );
    $newId = (int)db_last_id();
    audit_log((int)$u['id'], 'clockin', ['date' => $today]);
    $rec = db_one('SELECT * FROM attendance_records WHERE id = ?', [$newId]);
    ok(['record' => normalize_record($rec)]);
}

function records_clockout(): never {
    require_csrf();
    $u = require_login();
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $now = (new DateTimeImmutable('now'))->format('H:i');

    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    if (!$rec) err('NOT_CHECKED_IN', 'Debes marcar entrada primero.', 409);
    if ($rec['exit_time']) err('ALREADY_CHECKED_OUT', 'Ya registraste tu salida hoy.', 409);

    db_exec(
        'UPDATE attendance_records SET exit_time = ?, closed_reason = ? WHERE id = ?',
        [$now, 'normal', $rec['id']]
    );
    audit_log((int)$u['id'], 'clockout', ['date' => $today]);
    $rec = db_one('SELECT * FROM attendance_records WHERE id = ?', [$rec['id']]);
    ok(['record' => normalize_record($rec)]);
}

function records_overtime(array $body): never {
    require_csrf();
    $u = require_login();
    $hours = validate_float($body, 'hours', 0.5, OVERTIME_MAX_HOURS);
    $reason = validate_string($body, 'reason', 0, 240, false) ?? '';

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $rec = db_one('SELECT * FROM attendance_records WHERE user_id = ? AND work_date = ?', [$u['id'], $today]);
    if (!$rec) err('NOT_CHECKED_IN', 'Necesitas un registro del dia para declarar horas extra.', 409);

    db_exec(
        'INSERT INTO overtime_requests (user_id, record_id, hours, reason, status) VALUES (?, ?, ?, ?, ?)',
        [$u['id'], $rec['id'], $hours, $reason, 'pending']
    );
    db_exec(
        'UPDATE attendance_records SET overtime_hours = overtime_hours + ?, overtime_status = ? WHERE id = ?',
        [$hours, 'pending', $rec['id']]
    );
    audit_log((int)$u['id'], 'overtime_request', ['hours' => $hours, 'record_id' => $rec['id']]);
    ok(['message' => 'Horas extra enviadas a aprobacion.']);
}

function records_change_company(array $body): never {
    require_csrf();
    $u = require_login();
    $newCompanyId = validate_int($body, 'new_company_id', 1);

    $company = db_one('SELECT id, name FROM companies WHERE id = ?', [$newCompanyId]);
    if (!$company) err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'new_company_id']);
    if ((int)$u['company_id'] === $newCompanyId) {
        err('INVALID_INPUT', 'Ya perteneces a esa empresa.', 400, ['field' => 'new_company_id']);
    }

    db_exec(
        'INSERT INTO change_requests (user_id, old_company_id, new_company_id, status) VALUES (?, ?, ?, ?)',
        [$u['id'], $u['company_id'], $newCompanyId, 'pending']
    );
    audit_log((int)$u['id'], 'change_company_request', ['new_company_id' => $newCompanyId]);
    ok(['message' => 'Solicitud enviada a aprobacion.']);
}

// === Helpers internos ===

function close_record_as_forgotten(int $recordId): void {
    $exitTime = sprintf('%02d:00', STANDARD_CLOSE_HOUR);
    db_exec(
        'UPDATE attendance_records SET exit_time = ?, closed_reason = ? WHERE id = ?',
        [$exitTime, 'forgotten', $recordId]
    );
    audit_log(null, 'autoclose_forgotten', ['record_id' => $recordId, 'exit_time' => $exitTime]);
}

function close_record_as_overtime(int $recordId, int $userId, float $hours, string $reason): void {
    $exitTime = (new DateTimeImmutable('now'))->format('H:i');
    db_exec(
        'UPDATE attendance_records SET exit_time = ?, closed_reason = ?, overtime_hours = ?, overtime_status = ? WHERE id = ?',
        [$exitTime, 'overtime', $hours, 'pending', $recordId]
    );
    db_exec(
        'INSERT INTO overtime_requests (user_id, record_id, hours, reason, status) VALUES (?, ?, ?, ?, ?)',
        [$userId, $recordId, $hours, $reason, 'pending']
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
        'closed_reason' => $r['closed_reason'],
        'overtime_hours' => (float)$r['overtime_hours'],
        'overtime_status' => $r['overtime_status']
    ];
}
