<?php
declare(strict_types=1);

// =====================================================================
// mailer.php — Envio de correo via SMTP (Titan Mail / HostGator).
// Lee credenciales de .env. Usa PHPMailer vendido en lib/PHPMailer/ para no
// requerir composer en HostGator. Templates HTML+texto inline para mantener
// el modulo sin dependencias de motor de plantillas.
// =====================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envio generico. Retorna true en exito, false en fallo (sin lanzar).
 * El llamador decide si el fallo debe ser bloqueante o solo logueable.
 */
function mail_send(string $to, string $subject, string $html, string $text): bool {
    $host = env('SMTP_HOST', '');
    $port = env_int('SMTP_PORT', 465);
    $secure = strtolower((string)env('SMTP_SECURE', 'ssl'));
    $user = env('SMTP_USER', '');
    $pass = env('SMTP_PASS', '');
    $from = env('SMTP_FROM', $user ?? '');
    $fromName = env('SMTP_FROM_NAME', 'Melius Clockin');
    $replyTo = env('SMTP_REPLY_TO', SUPPORT_EMAIL);

    if ($host === '' || $user === '' || $pass === '' || $from === '') {
        error_log('[mailer] configuracion SMTP incompleta — email no enviado');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[mailer] destinatario invalido: ' . $to);
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        if ($replyTo) {
            $mail->addReplyTo($replyTo, 'Soporte Melius');
        }
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;

        // Timeout corto: si el servidor SMTP no responde, no bloqueamos la API.
        $mail->Timeout = 10;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[mailer] fallo SMTP a ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[mailer] excepcion al enviar a ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

// =====================================================================
// Templates de correo. HTML minimo accesible + version texto plano.
// =====================================================================

function tpl_layout(string $title, string $bodyHtml): string {
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
<tr><td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">
<div style="font-size:18px;font-weight:600;color:#0f172a;">Melius Clockin</div>
</td></tr>
<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55;">
{$bodyHtml}
</td></tr>
<tr><td style="padding:16px 28px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
Si no esperabas este correo, ignoralo. Para soporte responde a este mensaje.
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Plantilla: password temporal (alta de usuario por invitacion).
 */
function mail_template_temp_password(string $name, string $email, string $tempPassword, string $loginUrl): array {
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $passSafe = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<p>Hola {$nameSafe},</p>
<p>Se creo una cuenta para ti en Melius Clockin. Estas son tus credenciales temporales:</p>
<table cellpadding="8" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-family:Consolas,monospace;font-size:14px;">
<tr><td style="color:#6b7280;">Correo</td><td style="font-weight:600;">{$emailSafe}</td></tr>
<tr><td style="color:#6b7280;">Password temporal</td><td style="font-weight:600;">{$passSafe}</td></tr>
</table>
<p style="margin-top:16px;">Al iniciar sesion por primera vez se te pedira cambiar la contrasena.</p>
<p><a href="{$urlSafe}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">Iniciar sesion</a></p>
<p style="color:#6b7280;font-size:13px;">Por seguridad, no compartas estas credenciales con nadie.</p>
HTML;

    $text = "Hola {$name},\n\n"
        . "Se creo una cuenta para ti en Melius Clockin.\n\n"
        . "Correo: {$email}\n"
        . "Password temporal: {$tempPassword}\n\n"
        . "Al iniciar sesion por primera vez se te pedira cambiar la contrasena.\n"
        . "Inicia sesion en: {$loginUrl}\n";

    return [
        'subject' => 'Tu acceso a Melius Clockin',
        'html' => tpl_layout('Tu acceso a Melius Clockin', $body),
        'text' => $text,
    ];
}

/**
 * Plantilla: enlace de reset de password (flujo forgot-password).
 */
function mail_template_password_reset(string $name, string $resetUrl, int $hours): array {
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $hoursSafe = (int)$hours;

    $body = <<<HTML
<p>Hola {$nameSafe},</p>
<p>Recibimos una solicitud para restablecer tu contrasena en Melius Clockin. Si no fuiste tu, ignora este correo.</p>
<p><a href="{$urlSafe}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">Restablecer contrasena</a></p>
<p style="color:#6b7280;font-size:13px;">El enlace expira en {$hoursSafe} horas y solo puede usarse una vez.</p>
<p style="color:#6b7280;font-size:13px;">Si el boton no funciona, copia y pega en tu navegador:<br>{$urlSafe}</p>
HTML;

    $text = "Hola {$name},\n\n"
        . "Recibimos una solicitud para restablecer tu contrasena en Melius Clockin.\n"
        . "Si no fuiste tu, ignora este correo.\n\n"
        . "Enlace de reset (expira en {$hoursSafe} horas): {$resetUrl}\n";

    return [
        'subject' => 'Restablecer contrasena - Melius Clockin',
        'html' => tpl_layout('Restablecer contrasena', $body),
        'text' => $text,
    ];
}

/**
 * Plantilla: invitacion (placeholder por compatibilidad con #19; #26 ya usa
 * temp_password directamente). Disponible si en el futuro decides aceptar
 * invitaciones donde el usuario elige su propia password al activar.
 */
function mail_template_invitation(string $name, string $inviteUrl, int $hours): array {
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
    $hoursSafe = (int)$hours;

    $body = <<<HTML
<p>Hola {$nameSafe},</p>
<p>Fuiste invitado a Melius Clockin. Activa tu cuenta y define tu contrasena con el siguiente enlace:</p>
<p><a href="{$urlSafe}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">Activar cuenta</a></p>
<p style="color:#6b7280;font-size:13px;">El enlace expira en {$hoursSafe} horas.</p>
HTML;

    $text = "Hola {$name},\n\nFuiste invitado a Melius Clockin.\nActiva tu cuenta en (expira en {$hoursSafe} horas): {$inviteUrl}\n";

    return [
        'subject' => 'Invitacion a Melius Clockin',
        'html' => tpl_layout('Invitacion a Melius Clockin', $body),
        'text' => $text,
    ];
}

/**
 * Plantilla v2: invitacion con hero gradient y copy calido. Para clientes
 * de email (Gmail, Outlook): solo tablas inline + estilos en atributos, sin
 * flex ni grid. Sirve tanto para usuarios nuevos (con password temporal en
 * los datos) como para demo (sin credenciales, solo CTA).
 *
 * @param array $opts {
 *   @var string name        Nombre del destinatario.
 *   @var string companyName Nombre de la empresa que invita.
 *   @var string loginUrl    URL del login.
 *   @var ?string email           Email del destinatario para mostrar credencial.
 *   @var ?string tempPassword    Password temporal si aplica.
 *   @var ?string introOverride   Texto de intro personalizado opcional.
 * }
 */
function mail_template_invitation_v2(array $opts): array {
    $name = (string)($opts['name'] ?? 'Equipo');
    $company = (string)($opts['companyName'] ?? 'Melius Services');
    $loginUrl = (string)($opts['loginUrl'] ?? '/');
    $email = isset($opts['email']) ? (string)$opts['email'] : null;
    $tempPassword = isset($opts['tempPassword']) ? (string)$opts['tempPassword'] : null;
    $intro = isset($opts['introOverride']) ? (string)$opts['introOverride'] : null;

    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $companySafe = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $emailSafe = $email ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : null;
    $passSafe = $tempPassword ? htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8') : null;

    $defaultIntro = "Tu equipo en <strong>{$companySafe}</strong> esta usando Melius Clockin para "
        . "marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.";
    $introHtml = $intro !== null ? htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') : $defaultIntro;

    $credsBlock = '';
    if ($emailSafe !== null && $passSafe !== null) {
        $credsBlock = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;">
  <tr><td style="background:#0f172a;color:#ffffff;padding:18px 20px;border-radius:10px;font-family:Consolas,'SFMono-Regular',Menlo,monospace;font-size:13px;line-height:1.6;">
    <div style="opacity:0.6;text-transform:uppercase;letter-spacing:0.08em;font-size:11px;font-family:Segoe UI,Arial,sans-serif;font-weight:700;">Tus credenciales temporales</div>
    <div style="margin-top:8px;">Correo:&nbsp;&nbsp;<strong>{$emailSafe}</strong></div>
    <div>Password:&nbsp;<strong>{$passSafe}</strong></div>
    <div style="margin-top:10px;color:#94a3b8;font-family:Segoe UI,Arial,sans-serif;font-size:12px;">En tu primer inicio de sesion te pediremos cambiarla.</div>
  </td></tr>
</table>
HTML;
    }

    $hero = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 50%,#06b6d4 100%);background-color:#1e3a8a;border-radius:14px 14px 0 0;">
  <tr><td align="center" style="padding:36px 28px 32px 28px;color:#ffffff;font-family:Segoe UI,-apple-system,Roboto,Arial,sans-serif;">
    <div style="font-size:11px;letter-spacing:0.32em;text-transform:uppercase;opacity:0.85;font-weight:700;">Melius Clockin</div>
    <div style="font-size:26px;font-weight:800;line-height:1.2;margin-top:10px;">Bienvenido a bordo, {$nameSafe}</div>
    <div style="font-size:14px;margin-top:8px;opacity:0.92;">Marca jornada en segundos. Sin Excel. Sin friccion.</div>
  </td></tr>
</table>
HTML;

    $benefits = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:12px 0 4px 0;">
  <tr>
    <td valign="top" width="33%" style="padding:10px 8px;font-family:Segoe UI,Arial,sans-serif;">
      <div style="font-size:13px;font-weight:800;color:#0f172a;">Marcar en 1 toque</div>
      <div style="font-size:12px;color:#475569;margin-top:4px;line-height:1.5;">Entrada y salida desde el navegador. Sin instalaciones.</div>
    </td>
    <td valign="top" width="33%" style="padding:10px 8px;font-family:Segoe UI,Arial,sans-serif;">
      <div style="font-size:13px;font-weight:800;color:#0f172a;">Horas extra controladas</div>
      <div style="font-size:12px;color:#475569;margin-top:4px;line-height:1.5;">Solicitudes registradas y aprobadas, no inventadas.</div>
    </td>
    <td valign="top" width="33%" style="padding:10px 8px;font-family:Segoe UI,Arial,sans-serif;">
      <div style="font-size:13px;font-weight:800;color:#0f172a;">Tu jornada, tu TZ</div>
      <div style="font-size:12px;color:#475569;margin-top:4px;line-height:1.5;">Funciona estes donde estes; auditamos diferencias.</div>
    </td>
  </tr>
</table>
HTML;

    $cta = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:20px auto 8px auto;">
  <tr><td align="center" bgcolor="#2563eb" style="border-radius:10px;">
    <a href="{$urlSafe}" style="display:inline-block;padding:14px 28px;font-family:Segoe UI,Arial,sans-serif;font-size:14px;font-weight:800;color:#ffffff;text-decoration:none;border-radius:10px;background:#2563eb;">
      Entrar a Melius Clockin
    </a>
  </td></tr>
</table>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bienvenido a Melius Clockin</title>
</head>
<body style="margin:0;padding:24px 12px;background:#eef2f7;font-family:Segoe UI,-apple-system,Roboto,Arial,sans-serif;color:#1f2937;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;">
  <tr><td>{$hero}</td></tr>
  <tr><td style="padding:24px 28px 0 28px;font-size:15px;line-height:1.6;color:#1f2937;">
    <p style="margin:0 0 8px 0;">Hola <strong>{$nameSafe}</strong>,</p>
    <p style="margin:0 0 8px 0;">{$introHtml}</p>
  </td></tr>
  <tr><td style="padding:0 28px;">{$credsBlock}</td></tr>
  <tr><td style="padding:0 20px;">{$benefits}</td></tr>
  <tr><td style="padding:0 28px 8px 28px;">{$cta}</td></tr>
  <tr><td style="padding:0 28px 22px 28px;font-size:12px;color:#64748b;line-height:1.6;">
    Si el boton no funciona, copia y pega esta URL en tu navegador:<br>
    <span style="color:#334155;">{$urlSafe}</span>
  </td></tr>
  <tr><td style="padding:14px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#64748b;text-align:center;font-family:Segoe UI,Arial,sans-serif;">
    Enviado por noreply@fullman.tech para {$companySafe} via Melius Clockin.
  </td></tr>
</table>
</body>
</html>
HTML;

    $textParts = [
        "Hola {$name},",
        '',
        $intro !== null ? $intro : "Tu equipo en {$company} esta usando Melius Clockin para marcar jornada.",
        '',
    ];
    if ($email !== null && $tempPassword !== null) {
        $textParts[] = 'Tus credenciales temporales:';
        $textParts[] = "  Correo:   {$email}";
        $textParts[] = "  Password: {$tempPassword}";
        $textParts[] = '';
        $textParts[] = 'En tu primer inicio de sesion te pediremos cambiarla.';
        $textParts[] = '';
    }
    $textParts[] = "Inicia sesion en: {$loginUrl}";

    return [
        'subject' => "Bienvenido a Melius Clockin · {$company}",
        'html' => $html,
        'text' => implode("\n", $textParts),
    ];
}
