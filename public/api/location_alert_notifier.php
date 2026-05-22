<?php
declare(strict_types=1);

// =====================================================================
// location_alert_notifier.php — Persiste alertas de cambio radical de
// ubicacion en location_alerts y dispara emails al super_admin global
// + admin del tenant del empleado afectado.
// El envio de correo es best-effort: nunca rompe el clock-in si falla.
// =====================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Crea fila en location_alerts y dispara notificacion email.
 * $eval: salida de geo_evaluate_alert() (debe traer flag=true).
 */
function record_location_alert(PDO $pdo, int $userId, int $attendanceId, array $eval): void {
    if (empty($eval['flag'])) return;
    $ctx = $eval['context'] ?? [];
    $reasons = implode(',', $eval['reasons'] ?? []);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO location_alerts (
                user_id, attendance_id, reason_codes,
                prev_country_code, prev_city, prev_lat, prev_lon, prev_marked_at,
                curr_country_code, curr_city, curr_lat, curr_lon,
                distance_km, elapsed_minutes, implied_speed_kmh
             ) VALUES (
                :user_id, :attendance_id, :reason_codes,
                :prev_country_code, :prev_city, :prev_lat, :prev_lon, :prev_marked_at,
                :curr_country_code, :curr_city, :curr_lat, :curr_lon,
                :distance_km, :elapsed_minutes, :implied_speed_kmh
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'attendance_id' => $attendanceId,
            'reason_codes' => $reasons,
            'prev_country_code' => $ctx['prev_country_code'] ?? null,
            'prev_city' => $ctx['prev_city'] ?? null,
            'prev_lat' => $ctx['prev_lat'] ?? null,
            'prev_lon' => $ctx['prev_lon'] ?? null,
            'prev_marked_at' => $ctx['prev_marked_at'] ?? null,
            'curr_country_code' => $ctx['curr_country_code'] ?? null,
            'curr_city' => $ctx['curr_city'] ?? null,
            'curr_lat' => $ctx['curr_lat'] ?? null,
            'curr_lon' => $ctx['curr_lon'] ?? null,
            'distance_km' => $ctx['distance_km'] ?? null,
            'elapsed_minutes' => $ctx['elapsed_minutes'] ?? null,
            'implied_speed_kmh' => $ctx['implied_speed_kmh'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[location_alert] no se pudo insertar en location_alerts: ' . $e->getMessage());
        return;
    }

    try {
        notify_location_alert_recipients($pdo, $userId, $attendanceId, $eval);
    } catch (Throwable $e) {
        error_log('[location_alert] fallo al notificar: ' . $e->getMessage());
    }
}

/**
 * Resuelve destinatarios (super_admin global + admin del tenant del empleado)
 * y envia el correo con la plantilla mail_template_location_alert.
 */
function notify_location_alert_recipients(PDO $pdo, int $userId, int $attendanceId, array $eval): void {
    $employee = db_one(
        'SELECT u.id, u.name, u.email, u.company_id, c.name AS company_name, c.brand_id
           FROM users u
      LEFT JOIN companies c ON c.id = u.company_id
          WHERE u.id = ?',
        [$userId]
    );
    if (!$employee) return;

    $recipients = collect_location_alert_recipients($pdo, $employee);
    if (count($recipients) === 0) return;

    $ctx = $eval['context'] ?? [];
    $reviewUrl = rtrim((string)env('APP_URL', ''), '/') . '/?view=admin&panel=alerts-pending&alert=' . $attendanceId;

    $params = [
        'employee_name' => (string)($employee['name'] ?? ''),
        'employee_email' => (string)($employee['email'] ?? ''),
        'company_name' => (string)($employee['company_name'] ?? '—'),
        'prev_city' => (string)($ctx['prev_city'] ?? '—'),
        'prev_country' => (string)($ctx['prev_country_code'] ?? '—'),
        'curr_city' => (string)($ctx['curr_city'] ?? '—'),
        'curr_country' => (string)($ctx['curr_country_code'] ?? '—'),
        'distance_km' => isset($ctx['distance_km']) ? (string)$ctx['distance_km'] : '—',
        'speed_kmh' => isset($ctx['implied_speed_kmh']) ? (string)$ctx['implied_speed_kmh'] : '—',
        'reasons' => implode(',', $eval['reasons'] ?? []),
        'review_url' => $reviewUrl,
    ];

    $brandId = isset($employee['brand_id']) ? (int)$employee['brand_id'] : null;
    $brandName = brand_name_for($pdo, $brandId);
    $override = email_template_load($brandId, 'location_alert');
    $overrides = ['brandName' => $brandName];
    if (is_array($override)) {
        if (!empty($override['subject']))    $overrides['subjectOverride'] = $override['subject'];
        if (!empty($override['intro_html'])) $overrides['introOverride']   = $override['intro_html'];
        if (!empty($override['cta_label']))  $overrides['ctaOverride']     = $override['cta_label'];
    }
    $tpl = mail_template_location_alert($params, $overrides, resolve_email_brand($brandId));

    foreach ($recipients as $to) {
        mail_send($to, $tpl['subject'], $tpl['html'], $tpl['text']);
    }
}

/**
 * Devuelve lista unica de emails: super_admin global + admin(s) del tenant.
 */
function collect_location_alert_recipients(PDO $pdo, array $employee): array {
    $emails = [];

    // Super admins activos a nivel global.
    $globals = db_all(
        "SELECT email FROM users
          WHERE role = 'super_admin' AND status = 'active' AND email IS NOT NULL AND email <> ''"
    );
    foreach ($globals as $row) {
        $emails[] = strtolower(trim((string)$row['email']));
    }

    // Admins del mismo tenant (misma company_id) del empleado.
    if (!empty($employee['company_id'])) {
        $tenant = db_all(
            "SELECT email FROM users
              WHERE role = 'admin' AND status = 'active' AND company_id = ?
                AND email IS NOT NULL AND email <> ''",
            [(int)$employee['company_id']]
        );
        foreach ($tenant as $row) {
            $emails[] = strtolower(trim((string)$row['email']));
        }
    }

    return array_values(array_unique(array_filter($emails)));
}

function brand_name_for(PDO $pdo, ?int $brandId): string {
    if ($brandId === null) return 'Melius';
    try {
        $stmt = $pdo->prepare('SELECT name FROM brands WHERE id = ?');
        $stmt->execute([$brandId]);
        $name = (string)($stmt->fetchColumn() ?: '');
        return $name !== '' ? $name : 'Melius';
    } catch (Throwable $_) {
        return 'Melius';
    }
}
