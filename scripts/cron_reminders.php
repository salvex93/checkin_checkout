<?php
declare(strict_types=1);

// cron_reminders.php — Envia recordatorios por email 15 min antes del horario
// de entrada y 15 min antes del horario de salida de cada consultor activo.
// Idempotente: tabla reminder_log con UNIQUE (user_id, type, sent_date).
//
// Ejecutar via cron cPanel cada 5 minutos:
//   */5 * * * * /usr/bin/php /home/{cuenta}/scripts/cron_reminders.php >> /home/{cuenta}/cron_reminders.log 2>&1

require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/mailer.php';

const REMINDER_LEAD_MIN = 15;
const REMINDER_WINDOW_MIN = 5;

function reminder_already_sent(int $userId, string $type, string $date): bool {
    $row = db_one(
        'SELECT id FROM reminder_log WHERE user_id = ? AND reminder_type = ? AND sent_date = ?',
        [$userId, $type, $date]
    );
    return $row !== null && $row !== false;
}

function reminder_log_sent(int $userId, string $type, string $date): void {
    try {
        db_exec(
            'INSERT INTO reminder_log (user_id, reminder_type, sent_date) VALUES (?, ?, ?)',
            [$userId, $type, $date]
        );
    } catch (Throwable $e) {
        // Carrera muy improbable; UNIQUE evita doble envio.
    }
}

function user_already_checked_in_today(int $userId, string $date): bool {
    $row = db_one(
        'SELECT id FROM attendance_records WHERE user_id = ? AND work_date = ? LIMIT 1',
        [$userId, $date]
    );
    return $row !== null && $row !== false;
}

function user_already_checked_out_today(int $userId, string $date): bool {
    $row = db_one(
        'SELECT id FROM attendance_records WHERE user_id = ? AND work_date = ? AND exit_time IS NOT NULL LIMIT 1',
        [$userId, $date]
    );
    return $row !== null && $row !== false;
}

function send_clockin_reminder(array $user, ?array $brand, string $startTime): void {
    $brandName = (string)($brand['name'] ?? 'Melius');
    $subject = "Recordatorio: tu jornada inicia a las {$startTime}";
    $nameSafe = htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $timeSafe = htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8');
    $body = "<p>Hola <strong>{$nameSafe}</strong>,</p>"
          . "<p>Tu jornada en <strong>{$brandName} Clockin</strong> inicia a las <strong>{$timeSafe}</strong>. Recuerda marcar tu entrada apenas comiences.</p>"
          . "<p style=\"color:#6b7280;font-size:13px;\">Este recordatorio se envia 15 minutos antes de tu horario de entrada.</p>";
    $html = tpl_layout($subject, $body, $brand);
    $text = "Hola {$user['name']},\n\nTu jornada inicia a las {$startTime}. Recuerda marcar tu entrada.";
    @mail_send((string)$user['email'], $subject, $html, $text);
}

function send_clockout_reminder(array $user, ?array $brand, string $endTime): void {
    $brandName = (string)($brand['name'] ?? 'Melius');
    $subject = "Recordatorio: tu jornada termina a las {$endTime}";
    $nameSafe = htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $timeSafe = htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8');
    $body = "<p>Hola <strong>{$nameSafe}</strong>,</p>"
          . "<p>Tu jornada termina a las <strong>{$timeSafe}</strong>. No olvides marcar tu salida al finalizar.</p>"
          . "<p style=\"color:#6b7280;font-size:13px;\">Este recordatorio se envia 15 minutos antes del cierre.</p>";
    $html = tpl_layout($subject, $body, $brand);
    $text = "Hola {$user['name']},\n\nTu jornada termina a las {$endTime}. No olvides marcar tu salida.";
    @mail_send((string)$user['email'], $subject, $html, $text);
}

// === Main ===

$users = db_all(
    "SELECT u.id, u.name, u.email, u.timezone, u.work_start_time, u.work_end_time,
            c.id AS company_id, c.timezone AS c_tz, c.work_start_time AS c_start,
            c.work_end_time AS c_end, c.brand_id
       FROM users u
       LEFT JOIN companies c ON c.id = u.company_id
      WHERE u.is_active = 1 AND u.status = 'active' AND u.role = 'consultant'"
);

$processed = 0;
$sentClockin = 0;
$sentClockout = 0;

foreach ($users as $user) {
    $tzName = $user['timezone'] ?? $user['c_tz'] ?? 'America/Mexico_City';
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $_) { continue; }
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $nowMin = (int)$now->format('H') * 60 + (int)$now->format('i');

    $start = (string)($user['work_start_time'] ?? $user['c_start'] ?? '09:00');
    $end = (string)($user['work_end_time'] ?? $user['c_end'] ?? '18:00');

    foreach ([['clockin', $start, true], ['clockout', $end, false]] as [$type, $hhmm, $isClockin]) {
        if (!preg_match('/^\d{2}:\d{2}/', $hhmm)) continue;
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        $eventMin = $h * 60 + $m;
        $diff = $eventMin - $nowMin;
        // Ventana: -REMINDER_LEAD_MIN ± REMINDER_WINDOW_MIN
        if ($diff < REMINDER_LEAD_MIN - REMINDER_WINDOW_MIN) continue;
        if ($diff > REMINDER_LEAD_MIN + REMINDER_WINDOW_MIN) continue;

        if (reminder_already_sent((int)$user['id'], $type, $today)) continue;
        if ($isClockin && user_already_checked_in_today((int)$user['id'], $today)) continue;
        if (!$isClockin && user_already_checked_out_today((int)$user['id'], $today)) continue;

        $brand = $user['brand_id'] ? resolve_email_brand((int)$user['brand_id']) : null;
        if ($isClockin) {
            send_clockin_reminder($user, $brand, $hhmm);
            $sentClockin++;
        } else {
            send_clockout_reminder($user, $brand, $hhmm);
            $sentClockout++;
        }
        reminder_log_sent((int)$user['id'], $type, $today);
    }
    $processed++;
}

echo date('c') . " procesados={$processed} clockin={$sentClockin} clockout={$sentClockout}\n";
