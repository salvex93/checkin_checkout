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
            db_exec(
                'UPDATE overtime_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$newStatus, $id]
            );
            // Reflejar en el registro asociado
            db_exec(
                'UPDATE attendance_records SET overtime_status = ? WHERE id = ?',
                [$newStatus, $req['record_id']]
            );
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
