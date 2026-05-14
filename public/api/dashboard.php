<?php
declare(strict_types=1);

// =====================================================================
// dashboard.php — Endpoints de KPIs y busqueda para el panel admin.
//   GET admin/dashboard/global         — metricas globales (super_admin ve todo,
//                                        admin ve solo su empresa).
//   GET admin/dashboard/company/{id}   — metricas filtradas por empresa.
//   GET admin/agents/search            — buscador paginado de agentes.
// Super_admin se oculta en los listados visibles a admins normales.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Ambito visible para el admin actual. super_admin = todas las empresas;
 * admin = solo la suya. Devuelve company_id o null si ve todo.
 */
function dashboard_scope(array $admin): ?int {
    if (($admin['role'] ?? '') === 'super_admin') return null;
    return $admin['company_id'] !== null ? (int)$admin['company_id'] : null;
}

function admin_dashboard_global(): never {
    $admin = require_admin();
    $scope = dashboard_scope($admin);
    ok(['dashboard' => dashboard_compute($scope, false)]);
}

function admin_dashboard_company(int $companyId): never {
    $admin = require_admin();
    if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
        err('NOT_FOUND', 'Empresa no encontrada.', 404);
    }
    // admin solo puede ver su empresa; super_admin puede ver cualquiera.
    $scope = dashboard_scope($admin);
    if ($scope !== null && $scope !== $companyId) {
        err('FORBIDDEN', 'No tienes acceso a esa empresa.', 403);
    }
    ok(['dashboard' => dashboard_compute($companyId, true)]);
}

/**
 * Computa KPIs sobre attendance_records, filtrando por empresa si aplica.
 * companyFilter=null significa "todas las empresas".
 * Excluye usuarios super_admin de los conteos (rol oculto).
 */
function dashboard_compute(?int $companyFilter, bool $forceCompany): array {
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');

    $whereCo = '';
    $params = [];
    if ($companyFilter !== null) {
        $whereCo = ' AND u.company_id = ?';
        $params[] = $companyFilter;
    }

    // Todos los usuarios activos cuentan, sin importar rol. Super_admin y admin
    // tambien deben reflejarse en KPIs y desgloses si estan asignados a una empresa.
    $hideSuper = '';

    $today_count = db_one(
        "SELECT COUNT(*) c FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
          WHERE ar.work_date = ?{$whereCo}{$hideSuper}",
        array_merge([$today], $params)
    )['c'] ?? 0;

    $week_count = db_one(
        "SELECT COUNT(*) c FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
          WHERE ar.work_date >= ?{$whereCo}{$hideSuper}",
        array_merge([$weekStart], $params)
    )['c'] ?? 0;

    $month_count = db_one(
        "SELECT COUNT(*) c FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
          WHERE ar.work_date >= ?{$whereCo}{$hideSuper}",
        array_merge([$monthStart], $params)
    )['c'] ?? 0;

    $active_users = db_one(
        "SELECT COUNT(*) c FROM users u
          WHERE u.is_active = 1 AND u.status = 'active'{$whereCo}{$hideSuper}",
        $params
    )['c'] ?? 0;

    // Retrasos del mes: late = closed_reason='normal' AND entry_time > work_start + grace.
    // Calculamos en aplicacion porque mezclar tiempos y mascaras en SQL portable es fragil.
    $lateRows = db_all(
        "SELECT ar.entry_time, COALESCE(u.work_start_time, c.work_start_time) AS start_t,
                COALESCE(u.work_days_mask, c.work_days_mask) AS days_mask,
                c.grace_minutes_late, ar.work_date
           FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
           LEFT JOIN companies c ON c.id = u.company_id
          WHERE ar.work_date >= ?{$whereCo}{$hideSuper}",
        array_merge([$monthStart], $params)
    );
    $late_count = 0;
    foreach ($lateRows as $r) {
        $startT = $r['start_t'] ?? '09:00';
        $grace = (int)($r['grace_minutes_late'] ?? 0);
        $entryMin = time_hhmm_to_minutes((string)$r['entry_time']);
        $startMin = time_hhmm_to_minutes((string)$startT) + $grace;
        if ($entryMin !== null && $startMin !== null && $entryMin > $startMin) {
            $late_count++;
        }
    }

    // Ausencias del mes: dias laborables (segun mask) sin record por agente.
    // Aproximacion: total dias laborables * activos - registros del periodo.
    $workDays = count_business_days($monthStart, $today);
    $absences = max(0, ($workDays * (int)$active_users) - (int)$month_count);

    // Horas extra agregadas por status.
    $ot = db_one(
        "SELECT
            SUM(CASE WHEN ar.overtime_status = 'pending' THEN ar.overtime_hours ELSE 0 END) AS pending_h,
            SUM(CASE WHEN ar.overtime_status = 'approved' THEN ar.overtime_hours ELSE 0 END) AS approved_h
           FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
          WHERE ar.work_date >= ?{$whereCo}{$hideSuper}",
        array_merge([$monthStart], $params)
    );

    $by_company = [];
    if (!$forceCompany && $companyFilter === null) {
        $rows = db_all(
            "SELECT c.id, c.name,
                    SUM(CASE WHEN u.is_active = 1 AND u.status = 'active' THEN 1 ELSE 0 END) AS active_users
               FROM companies c
               LEFT JOIN users u ON u.company_id = c.id
              GROUP BY c.id, c.name
              ORDER BY c.name"
        );
        foreach ($rows as $r) {
            $by_company[] = [
                'company_id' => (int)$r['id'],
                'company_name' => $r['name'],
                'active_users' => (int)$r['active_users']
            ];
        }
    }

    return [
        'period' => [
            'today' => $today,
            'week_start' => $weekStart,
            'month_start' => $monthStart,
        ],
        'totals' => [
            'records_today' => (int)$today_count,
            'records_week' => (int)$week_count,
            'records_month' => (int)$month_count,
            'active_users' => (int)$active_users,
            'late_month' => (int)$late_count,
            'absences_month' => (int)$absences,
            'overtime_pending_hours' => (float)($ot['pending_h'] ?? 0),
            'overtime_approved_hours' => (float)($ot['approved_h'] ?? 0),
        ],
        'by_company' => $by_company,
    ];
}

function time_hhmm_to_minutes(string $hhmm): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) return null;
    return ((int)$m[1]) * 60 + ((int)$m[2]);
}

function count_business_days(string $fromDate, string $toDate): int {
    try {
        $from = new DateTimeImmutable($fromDate);
        $to = new DateTimeImmutable($toDate);
    } catch (Throwable $e) {
        return 0;
    }
    if ($from > $to) return 0;
    $count = 0;
    $cursor = $from;
    while ($cursor <= $to) {
        $dow = (int)$cursor->format('N'); // 1=Lunes, 7=Domingo
        if ($dow >= 1 && $dow <= 5) $count++;
        $cursor = $cursor->modify('+1 day');
    }
    return $count;
}

/**
 * GET admin/agents/search?q=&company_id=&status=&limit=&offset=
 * Busca por nombre o email, paginacion offset/limit. Oculta super_admin a
 * admins normales. super_admin ve todo.
 */
function admin_agents_search(): never {
    $admin = require_admin();
    $scope = dashboard_scope($admin);
    $isSuper = ($admin['role'] ?? '') === 'super_admin';

    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $companyId = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $companyId = validate_int(['v' => $_GET['company_id']], 'v', 1);
    }
    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    if ($status !== '' && !in_array($status, ['pending_confirmation', 'active', 'disabled'], true)) {
        err('INVALID_INPUT', 'status invalido.', 400, ['field' => 'status']);
    }
    $limit = validate_int(['v' => $_GET['limit'] ?? 20], 'v', 1, 100, false) ?? 20;
    $offset = validate_int(['v' => $_GET['offset'] ?? 0], 'v', 0, 100000, false) ?? 0;

    // Ambito: admin normal limita a su empresa; super_admin sin restriccion.
    if ($scope !== null) {
        $companyId = $companyId ?? $scope;
        if ($companyId !== $scope) {
            err('FORBIDDEN', 'No tienes acceso a esa empresa.', 403);
        }
    }

    $where = ['1=1'];
    $params = [];
    if (!$isSuper) {
        $where[] = "u.role <> 'super_admin'";
    }
    if ($q !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($companyId !== null) {
        $where[] = 'u.company_id = ?';
        $params[] = $companyId;
    }
    if ($status !== '') {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }
    $whereSql = implode(' AND ', $where);

    $total = db_one(
        "SELECT COUNT(*) c FROM users u WHERE {$whereSql}",
        $params
    )['c'] ?? 0;

    $rows = db_all(
        "SELECT u.id, u.email, u.name, u.role, u.status, u.is_active,
                u.company_id, c.name AS company_name, u.created_at
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
          WHERE {$whereSql}
          ORDER BY u.created_at DESC
          LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
        $params
    );

    ok([
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset,
        'agents' => array_map(fn($r) => [
            'id' => (int)$r['id'],
            'email' => $r['email'],
            'name' => $r['name'],
            'role' => $r['role'],
            'status' => $r['status'],
            'is_active' => (int)$r['is_active'] === 1,
            'company_id' => $r['company_id'] !== null ? (int)$r['company_id'] : null,
            'company_name' => $r['company_name'],
            'created_at' => $r['created_at'],
        ], $rows),
    ]);
}

/**
 * GET admin/records/export?period=week|month|year&company_id=&user_id=&from=&to=
 * CSV streaming UTF-8 con BOM. Filtros aplicables; ambito por rol.
 */
function admin_records_export(): never {
    $admin = require_admin();
    $scope = dashboard_scope($admin);
    $isSuper = ($admin['role'] ?? '') === 'super_admin';

    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    $companyId = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $companyId = validate_int(['v' => $_GET['company_id']], 'v', 1);
    }
    $userId = null;
    if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
        $userId = validate_int(['v' => $_GET['user_id']], 'v', 1);
    }

    [$from, $to] = export_resolve_range($period, $_GET['from'] ?? '', $_GET['to'] ?? '');

    if ($scope !== null) {
        if ($companyId !== null && $companyId !== $scope) {
            err('FORBIDDEN', 'No tienes acceso a esa empresa.', 403);
        }
        $companyId = $scope;
    }

    $where = ['ar.work_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    if (!$isSuper) {
        $where[] = "u.role <> 'super_admin'";
    }
    if ($companyId !== null) {
        $where[] = 'u.company_id = ?';
        $params[] = $companyId;
    }
    if ($userId !== null) {
        $where[] = 'u.id = ?';
        $params[] = $userId;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = Database::pdo()->prepare(
        "SELECT ar.work_date, u.name AS user_name, u.email AS user_email,
                c.name AS company_name, ar.entry_time, ar.exit_time,
                ar.closed_reason, ar.overtime_hours, ar.overtime_status
           FROM attendance_records ar
           JOIN users u ON u.id = ar.user_id
           LEFT JOIN companies c ON c.id = u.company_id
          WHERE {$whereSql}
          ORDER BY ar.work_date DESC, u.name ASC"
    );
    $stmt->execute($params);

    $filename = sprintf('records_%s_%s.csv', $from, $to);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para que Excel reconozca acentos correctamente.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'fecha', 'agente', 'email', 'empresa',
        'clockin', 'clockout', 'horas_trabajadas',
        'status', 'horas_extra', 'overtime_status'
    ]);

    $rowCount = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $worked = compute_worked_hours((string)$r['entry_time'], (string)$r['exit_time']);
        fputcsv($out, [
            $r['work_date'],
            $r['user_name'],
            $r['user_email'],
            $r['company_name'] ?? '',
            $r['entry_time'],
            $r['exit_time'] ?? '',
            $worked,
            $r['closed_reason'] ?? '',
            number_format((float)$r['overtime_hours'], 2, '.', ''),
            $r['overtime_status'] ?? 'none',
        ]);
        $rowCount++;
    }
    fclose($out);

    audit_log((int)$admin['id'], 'admin_records_export', [
        'period' => $period, 'company_id' => $companyId, 'user_id' => $userId,
        'from' => $from, 'to' => $to, 'rows' => $rowCount
    ]);
    exit;
}

function export_resolve_range(string $period, string $from, string $to): array {
    $today = new DateTimeImmutable('today');
    $fmt = 'Y-m-d';

    if ($period === 'week') {
        return [(new DateTimeImmutable('monday this week'))->format($fmt), $today->format($fmt)];
    }
    if ($period === 'month') {
        return [(new DateTimeImmutable('first day of this month'))->format($fmt), $today->format($fmt)];
    }
    if ($period === 'year') {
        return [(new DateTimeImmutable('first day of january this year'))->format($fmt), $today->format($fmt)];
    }
    // Rango libre: validar formato YYYY-MM-DD
    $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && DateTimeImmutable::createFromFormat($fmt, $from);
    $toOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && DateTimeImmutable::createFromFormat($fmt, $to);
    if (!$fromOk || !$toOk) {
        err('INVALID_INPUT', 'Especifica period o un rango from/to en YYYY-MM-DD.', 400);
    }
    if ($from > $to) {
        err('INVALID_INPUT', 'El rango es invalido (from > to).', 400);
    }
    return [$from, $to];
}

function compute_worked_hours(string $entry, ?string $exit): string {
    if (!$exit) return '';
    $a = time_hhmm_to_minutes($entry);
    $b = time_hhmm_to_minutes($exit);
    if ($a === null || $b === null || $b < $a) return '';
    return number_format(($b - $a) / 60.0, 2, '.', '');
}
