<?php
declare(strict_types=1);

// vacations.php — Solicitudes de vacaciones que reemplaza al modulo
// de horas extra. Consultor solicita rango de fechas; admin aprueba o rechaza.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

const VACATION_MAX_DAYS_PER_REQUEST = 30;
const VACATION_MAX_FUTURE_MONTHS = 12;

/**
 * Calcula dias calendario inclusivos entre dos fechas YYYY-MM-DD.
 */
function vacation_days_between(string $start, string $end): int {
    $a = new DateTime($start);
    $b = new DateTime($end);
    return (int)$a->diff($b)->days + 1;
}

/**
 * POST vacations/request — consultor solicita vacaciones.
 * Body: { start_date, end_date, reason }
 */
function vacation_request(array $body): never {
    require_csrf();
    $u = require_login();

    $start = validate_string($body, 'start_date', 10, 10);
    $end = validate_string($body, 'end_date', 10, 10);
    $reason = validate_string($body, 'reason', 0, 500, false) ?? '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        err('INVALID_INPUT', 'Formato de fecha invalido (YYYY-MM-DD).', 400);
    }
    if ($start > $end) {
        err('INVALID_RANGE', 'La fecha de inicio debe ser anterior o igual a la fecha de fin.', 400);
    }

    $today = date('Y-m-d');
    if ($start < $today) {
        err('PAST_DATE', 'No puedes solicitar vacaciones en fechas pasadas.', 400);
    }
    $maxFuture = date('Y-m-d', strtotime("+" . VACATION_MAX_FUTURE_MONTHS . " months"));
    if ($end > $maxFuture) {
        err('TOO_FAR', 'La fecha de fin esta demasiado lejana.', 400);
    }
    $days = vacation_days_between($start, $end);
    if ($days > VACATION_MAX_DAYS_PER_REQUEST) {
        err('TOO_LONG', 'La solicitud excede el maximo de ' . VACATION_MAX_DAYS_PER_REQUEST . ' dias.', 400);
    }

    // Bloquear solapamiento con solicitudes pendientes o aprobadas del mismo usuario.
    $overlap = db_one(
        'SELECT id FROM vacation_requests
           WHERE user_id = ?
             AND status IN (\'pending\',\'approved\')
             AND NOT (end_date < ? OR start_date > ?)
           LIMIT 1',
        [(int)$u['id'], $start, $end]
    );
    if ($overlap) {
        err('OVERLAP', 'Ya tienes una solicitud activa que se solapa con esas fechas.', 409);
    }

    db_exec(
        'INSERT INTO vacation_requests (user_id, company_id, start_date, end_date, days_count, reason, status)
              VALUES (?, ?, ?, ?, ?, ?, \'pending\')',
        [
            (int)$u['id'],
            $u['company_id'] ? (int)$u['company_id'] : null,
            $start,
            $end,
            $days,
            $reason !== '' ? $reason : null,
        ]
    );
    $id = (int)db_last_id();
    audit_log((int)$u['id'], 'vacation_requested', [
        'id' => $id, 'start' => $start, 'end' => $end, 'days' => $days,
    ]);
    notify_admins_new_request(
        $u['company_id'] ? (int)$u['company_id'] : null,
        [
            'request_type' => 'vacation',
            'employee_name' => $u['name'] ?? '',
            'company_name' => $u['company_name'] ?? '',
            'detail' => sprintf('%s al %s (%d dias)', $start, $end, $days),
        ]
    );
    ok(['id' => $id, 'days' => $days, 'status' => 'pending']);
}

/**
 * GET vacations/mine — solicitudes propias del consultor (todas).
 */
function vacation_mine(): never {
    $u = require_login();
    $rows = db_all(
        'SELECT id, start_date, end_date, days_count, reason, status,
                decided_at, decision_note, created_at
           FROM vacation_requests
          WHERE user_id = ?
          ORDER BY created_at DESC',
        [(int)$u['id']]
    );
    ok(['requests' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'days' => (int)$r['days_count'],
        'reason' => $r['reason'],
        'status' => $r['status'],
        'decided_at' => $r['decided_at'],
        'decision_note' => $r['decision_note'],
        'created_at' => $r['created_at'],
    ], $rows)]);
}

/**
 * DELETE vacations/{id} — consultor cancela su solicitud (solo si pending).
 */
function vacation_cancel(int $id): never {
    require_csrf();
    $u = require_login();
    $row = db_one(
        'SELECT id, user_id, status FROM vacation_requests WHERE id = ?',
        [$id]
    );
    if (!$row) err('NOT_FOUND', 'Solicitud no encontrada.', 404);
    if ((int)$row['user_id'] !== (int)$u['id']) err('FORBIDDEN', 'No es tu solicitud.', 403);
    if ($row['status'] !== 'pending') err('INVALID_STATE', 'Solo puedes cancelar solicitudes pendientes.', 409);

    db_exec(
        'UPDATE vacation_requests SET status = \'cancelled\', decided_at = CURRENT_TIMESTAMP WHERE id = ?',
        [$id]
    );
    audit_log((int)$u['id'], 'vacation_cancelled', ['id' => $id]);
    ok(['id' => $id, 'status' => 'cancelled']);
}

/**
 * GET admin/vacations — admin lista solicitudes (con filtro de estado).
 * super_admin: todas. admin: solo de su company_id.
 */
function admin_vacations_list(): never {
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'super_admin'], true)) {
        err('FORBIDDEN', 'Solo admins.', 403);
    }
    $status = $_GET['status'] ?? 'pending';
    if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
        $status = 'pending';
    }
    $where = [];
    $params = [];
    if ($status !== 'all') { $where[] = 'v.status = ?'; $params[] = $status; }
    if ($u['role'] === 'admin' && $u['company_id']) {
        $where[] = 'v.company_id = ?';
        $params[] = (int)$u['company_id'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = db_all(
        "SELECT v.id, v.start_date, v.end_date, v.days_count, v.reason, v.status,
                v.decided_at, v.decision_note, v.created_at,
                u.name AS user_name, u.email AS user_email, c.name AS company_name
           FROM vacation_requests v
           JOIN users u ON u.id = v.user_id
           LEFT JOIN companies c ON c.id = v.company_id
           {$whereSql}
          ORDER BY v.created_at DESC
          LIMIT 200",
        $params
    );
    ok(['requests' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'user_name' => $r['user_name'],
        'user_email' => $r['user_email'],
        'company_name' => $r['company_name'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'days' => (int)$r['days_count'],
        'reason' => $r['reason'],
        'status' => $r['status'],
        'decided_at' => $r['decided_at'],
        'decision_note' => $r['decision_note'],
        'created_at' => $r['created_at'],
    ], $rows)]);
}

/**
 * POST admin/vacations/{id}/decide — aprueba o rechaza.
 * Body: { decision: 'approved'|'rejected', note?: string }
 */
function admin_vacations_decide(int $id, array $body): never {
    require_csrf();
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'super_admin'], true)) {
        err('FORBIDDEN', 'Solo admins.', 403);
    }
    $decision = validate_string($body, 'decision', 1, 20);
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        err('INVALID_INPUT', 'Decision debe ser approved o rejected.', 400);
    }
    $note = validate_string($body, 'note', 0, 500, false) ?? '';

    $row = db_one(
        'SELECT id, user_id, company_id, status FROM vacation_requests WHERE id = ?',
        [$id]
    );
    if (!$row) err('NOT_FOUND', 'Solicitud no encontrada.', 404);
    if ($row['status'] !== 'pending') err('INVALID_STATE', 'La solicitud ya fue decidida.', 409);
    if ($u['role'] === 'admin' && $u['company_id'] && (int)$row['company_id'] !== (int)$u['company_id']) {
        err('FORBIDDEN', 'No puedes decidir sobre solicitudes de otra empresa.', 403);
    }

    db_exec(
        'UPDATE vacation_requests
            SET status = ?, decided_by = ?, decided_at = CURRENT_TIMESTAMP, decision_note = ?
          WHERE id = ?',
        [$decision, (int)$u['id'], $note !== '' ? $note : null, $id]
    );
    audit_log((int)$u['id'], 'vacation_decided', [
        'id' => $id, 'decision' => $decision, 'employee_id' => (int)$row['user_id'],
    ]);
    ok(['id' => $id, 'status' => $decision]);
}
