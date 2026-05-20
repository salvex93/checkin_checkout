<?php
declare(strict_types=1);

// =====================================================================
// mailer.php — Envio de correo con dos drivers seleccionables por env:
//   MAIL_DRIVER=resend  -> API HTTP de Resend (recomendado en produccion)
//   MAIL_DRIVER=smtp    -> PHPMailer + servidor SMTP (default; util en local)
// Los templates HTML+texto son los mismos para ambos drivers.
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
 * Selecciona driver por env MAIL_DRIVER. Fallback a smtp si no esta definido.
 */
function mail_send(string $to, string $subject, string $html, string $text): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[mailer] destinatario invalido: ' . $to);
        return false;
    }
    $driver = strtolower((string)env('MAIL_DRIVER', 'smtp'));
    return $driver === 'resend'
        ? mail_send_resend($to, $subject, $html, $text)
        : mail_send_smtp($to, $subject, $html, $text);
}

/**
 * Driver Resend (HTTPS:443). Documentacion: https://resend.com/docs/api-reference/emails/send-email
 * Requiere RESEND_API_KEY en .env y dominio verificado en resend.com/domains.
 */
function mail_send_resend(string $to, string $subject, string $html, string $text): bool {
    $apiKey = (string)env('RESEND_API_KEY', '');
    $from = (string)env('MAIL_FROM', env('SMTP_FROM', ''));
    $fromName = (string)env('MAIL_FROM_NAME', env('SMTP_FROM_NAME', 'Melius Clockin'));
    $replyTo = (string)env('MAIL_REPLY_TO', env('SMTP_REPLY_TO', SUPPORT_EMAIL));

    if ($apiKey === '' || $from === '') {
        error_log('[mailer] Resend mal configurado — falta RESEND_API_KEY o MAIL_FROM');
        return false;
    }

    $payload = [
        'from' => $fromName !== '' ? "{$fromName} <{$from}>" : $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
    if ($replyTo !== '') $payload['reply_to'] = $replyTo;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '') {
        error_log('[mailer] Resend curl error: ' . $err);
        return false;
    }
    if ($code >= 200 && $code < 300) {
        return true;
    }
    error_log('[mailer] Resend HTTP ' . $code . ' a ' . $to . ': ' . substr((string)$body, 0, 500));
    return false;
}

/**
 * Driver SMTP via PHPMailer. Usado en desarrollo local (MailHog/MailCatcher)
 * o en hosting que permita SMTP saliente.
 */
function mail_send_smtp(string $to, string $subject, string $html, string $text): bool {
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
        if ((string)env('SMTP_INSECURE_TLS', '0') === '1') {
            $mail->SMTPOptions = [
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
            ];
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
// Loader de overrides editables (email_templates). Si no existe override
// para (brand_id, kind), retorna null y el caller usa el copy hardcoded.
// =====================================================================

/**
 * Carga override desde DB. Retorna null si no hay fila o falla la consulta
 * (defensivo: nunca rompe el envio aunque la tabla no exista todavia).
 */
function email_template_load(?int $brandId, string $kind): ?array {
    if ($brandId === null) return null;
    $allowed = ['invitation', 'password_reset', 'admin_disabled', 'admin_delete_receipt', 'location_alert'];
    if (!in_array($kind, $allowed, true)) return null;
    try {
        return db_one(
            'SELECT subject, intro_html, cta_label FROM email_templates WHERE brand_id = ? AND kind = ?',
            [$brandId, $kind]
        ) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Reemplaza placeholders {{key}} por valores. Cuando $forHtml=true, escapa
 * TODO el template (incluyendo cualquier HTML literal del usuario) antes
 * de inyectar valores, que se escapan a su vez. En modo texto plano usa
 * los valores crudos. Esto previene XSS desde overrides de DB.
 */
function email_template_render(string $tpl, array $vars, bool $forHtml = true): string {
    if ($forHtml) {
        $escaped = htmlspecialchars($tpl, ENT_QUOTES, 'UTF-8');
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1];
            if (!array_key_exists($key, $vars)) return $m[0];
            return htmlspecialchars((string)$vars[$key], ENT_QUOTES, 'UTF-8');
        }, $escaped);
    }
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars) {
        $key = $m[1];
        if (!array_key_exists($key, $vars)) return $m[0];
        return (string)$vars[$key];
    }, $tpl);
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
 * Acepta overrides opcionales (subjectOverride, introOverride, ctaOverride,
 * brandName) cuando se invoca como mail_template_password_reset_ex.
 */
function mail_template_password_reset(string $name, string $resetUrl, int $hours, array $overrides = []): array {
    $brandName = (string)($overrides['brandName'] ?? 'Melius');
    $subjectOverride = $overrides['subjectOverride'] ?? null;
    $introOverride = $overrides['introOverride'] ?? null;
    $ctaOverride = $overrides['ctaOverride'] ?? null;

    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
    $hoursSafe = (int)$hours;

    $vars = ['name' => $name, 'brand_name' => $brandName, 'reset_url' => $resetUrl, 'hours' => (string)$hoursSafe];
    $introHtml = $introOverride !== null && trim((string)$introOverride) !== ''
        ? nl2br(email_template_render((string)$introOverride, $vars, true))
        : "Recibimos una solicitud para restablecer tu contrasena en {$brandNameSafe} Clockin. Si no fuiste tu, ignora este correo.";
    $ctaLabel = $ctaOverride !== null && trim((string)$ctaOverride) !== ''
        ? email_template_render((string)$ctaOverride, $vars, true)
        : 'Restablecer contrasena';

    $body = <<<HTML
<p>Hola {$nameSafe},</p>
<p>{$introHtml}</p>
<p><a href="{$urlSafe}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">{$ctaLabel}</a></p>
<p style="color:#6b7280;font-size:13px;">El enlace expira en {$hoursSafe} horas y solo puede usarse una vez.</p>
<p style="color:#6b7280;font-size:13px;">Si el boton no funciona, copia y pega en tu navegador:<br>{$urlSafe}</p>
HTML;

    $textIntro = $introOverride !== null && trim((string)$introOverride) !== ''
        ? email_template_render((string)$introOverride, $vars, false)
        : "Recibimos una solicitud para restablecer tu contrasena en {$brandName} Clockin.\nSi no fuiste tu, ignora este correo.";
    $text = "Hola {$name},\n\n{$textIntro}\n\nEnlace de reset (expira en {$hoursSafe} horas): {$resetUrl}\n";

    $subject = $subjectOverride !== null && trim((string)$subjectOverride) !== ''
        ? email_template_render((string)$subjectOverride, $vars, false)
        : "Restablecer contrasena - {$brandName} Clockin";

    return [
        'subject' => $subject,
        'html' => tpl_layout($subject, $body),
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
 * Personalizable por marca: logo, colores primario/secundario y welcome_intro.
 * Si los datos de marca no vienen, usa defaults Melius (cyan+violet).
 *
 * @param array $opts {
 *   @var string name             Nombre del destinatario.
 *   @var string companyName      Nombre de la empresa que invita.
 *   @var string loginUrl         URL del login.
 *   @var ?string email           Email del destinatario.
 *   @var ?string tempPassword    Password temporal si aplica.
 *   @var ?string introOverride   Texto de intro personalizado opcional.
 *   @var ?string brandName       Nombre de la marca paraguas (Melius, Fullman, Netfy).
 *   @var ?string brandLogoUrl    URL ABSOLUTA del logo de la marca.
 *   @var ?string brandPrimary    Color hex primario.
 *   @var ?string brandSecondary  Color hex secundario.
 *   @var ?string brandWelcome    welcome_intro de la marca (HTML escapado).
 * }
 */
function mail_template_invitation_v2(array $opts): array {
    $name = (string)($opts['name'] ?? 'Equipo');
    $company = (string)($opts['companyName'] ?? 'Melius Services');
    $loginUrl = (string)($opts['loginUrl'] ?? '/');
    $email = isset($opts['email']) ? (string)$opts['email'] : null;
    $tempPassword = isset($opts['tempPassword']) ? (string)$opts['tempPassword'] : null;
    $intro = isset($opts['introOverride']) ? (string)$opts['introOverride'] : null;
    $subjectOverride = isset($opts['subjectOverride']) ? (string)$opts['subjectOverride'] : null;
    $ctaOverride = isset($opts['ctaOverride']) ? (string)$opts['ctaOverride'] : null;

    // Defaults Melius si la marca no viene.
    $brandName = (string)($opts['brandName'] ?? 'Melius');
    $brandLogoUrl = isset($opts['brandLogoUrl']) ? (string)$opts['brandLogoUrl'] : null;
    $brandPrimary = (string)($opts['brandPrimary'] ?? '#07d6da');
    $brandSecondary = (string)($opts['brandSecondary'] ?? '#9909fe');
    $brandWelcome = isset($opts['brandWelcome']) ? (string)$opts['brandWelcome'] : null;

    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $companySafe = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
    $brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
    $urlSafe = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $emailSafe = $email ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : null;
    $passSafe = $tempPassword ? htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8') : null;
    $primarySafe = preg_match('/^#[0-9a-fA-F]{3,6}$/', $brandPrimary) ? $brandPrimary : '#07d6da';
    $secondarySafe = preg_match('/^#[0-9a-fA-F]{3,6}$/', $brandSecondary) ? $brandSecondary : '#9909fe';
    $senderSafe = htmlspecialchars((string)env('SMTP_FROM', 'noreply@meliusservices.com'), ENT_QUOTES, 'UTF-8');

    // Prioridad de intro:
    //   1) introOverride explicito (tests/demos)
    //   2) welcome_intro de la marca
    //   3) fallback automatico con nombre de empresa y marca
    $introVars = [
        'name' => $name,
        'company' => $company,
        'brand_name' => $brandName,
        'email' => $email ?? '',
    ];
    if ($intro !== null) {
        $introHtml = nl2br(email_template_render($intro, $introVars, true));
    } elseif ($brandWelcome !== null && trim($brandWelcome) !== '') {
        // welcome_intro guardado en DB ya viene como texto plano. Lo escapamos para
        // evitar inyeccion HTML/JS, y permitimos solo saltos de linea como <br>.
        $introHtml = nl2br(htmlspecialchars($brandWelcome, ENT_QUOTES, 'UTF-8'));
    } else {
        $introHtml = "Tu equipo en <strong>{$companySafe}</strong> esta usando <strong>{$brandNameSafe} Clockin</strong> para "
            . "marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.";
    }

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

    // Hero: gradiente con colores de marca. Logo arriba si la marca lo tiene.
    $logoBlock = '';
    if ($brandLogoUrl !== null && $brandLogoUrl !== '') {
        $logoSafe = htmlspecialchars($brandLogoUrl, ENT_QUOTES, 'UTF-8');
        $logoBlock = '<div style="margin-bottom:14px;"><img src="' . $logoSafe . '" alt="' . $brandNameSafe . '" width="64" height="64" style="display:inline-block;width:64px;height:64px;border-radius:14px;background:#ffffff;padding:6px;border:0;outline:0;"></div>';
    }
    $hero = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:linear-gradient(135deg,{$primarySafe} 0%,{$secondarySafe} 100%);background-color:{$primarySafe};border-radius:14px 14px 0 0;">
  <tr><td align="center" style="padding:32px 28px 30px 28px;color:#ffffff;font-family:Segoe UI,-apple-system,Roboto,Arial,sans-serif;">
    {$logoBlock}
    <div style="font-size:11px;letter-spacing:0.32em;text-transform:uppercase;opacity:0.92;font-weight:700;">{$brandNameSafe} Clockin</div>
    <div style="font-size:26px;font-weight:800;line-height:1.2;margin-top:10px;">Bienvenido a bordo, {$nameSafe}</div>
    <div style="font-size:14px;margin-top:8px;opacity:0.94;">Marca jornada en segundos. Sin Excel. Sin friccion.</div>
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

    // CTA con color de marca. Label personalizable por override.
    $ctaVars = ['brand_name' => $brandName, 'company' => $company, 'name' => $name];
    $ctaLabel = $ctaOverride !== null && trim($ctaOverride) !== ''
        ? email_template_render($ctaOverride, $ctaVars, true)
        : "Entrar a {$brandNameSafe} Clockin";
    $cta = <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:20px auto 8px auto;">
  <tr><td align="center" bgcolor="{$primarySafe}" style="border-radius:10px;">
    <a href="{$urlSafe}" style="display:inline-block;padding:14px 28px;font-family:Segoe UI,Arial,sans-serif;font-size:14px;font-weight:800;color:#ffffff;text-decoration:none;border-radius:10px;background:{$primarySafe};">
      {$ctaLabel}
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
<title>Bienvenido a {$brandNameSafe} Clockin</title>
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
    Enviado por {$senderSafe} para {$companySafe} via {$brandNameSafe} Clockin.
  </td></tr>
</table>
</body>
</html>
HTML;

    $textIntro = $intro !== null
        ? email_template_render($intro, $introVars, false)
        : ($brandWelcome !== null && trim($brandWelcome) !== ''
            ? $brandWelcome
            : "Tu equipo en {$company} esta usando {$brandName} Clockin para marcar jornada.");
    $textParts = [
        "Hola {$name},",
        '',
        $textIntro,
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

    $subjectVars = ['brand_name' => $brandName, 'company' => $company, 'name' => $name];
    $subject = $subjectOverride !== null && trim($subjectOverride) !== ''
        ? email_template_render($subjectOverride, $subjectVars, false)
        : "Bienvenido a {$brandName} Clockin · {$company}";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => implode("\n", $textParts),
    ];
}

/**
 * Aviso al admin afectado tras desactivacion (soft delete) por otro admin.
 * No contiene credenciales. Si el admin considera que es un error, contacta soporte.
 */
function mail_template_admin_disabled(string $name, string $companyName, string $actorName, array $overrides = []): array {
    $brandName = (string)($overrides['brandName'] ?? 'Melius');
    $subjectOverride = $overrides['subjectOverride'] ?? null;
    $introOverride = $overrides['introOverride'] ?? null;

    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $companySafe = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $actorSafe = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');
    $brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
    $supportSafe = htmlspecialchars(SUPPORT_EMAIL, ENT_QUOTES, 'UTF-8');

    $vars = ['name' => $name, 'company' => $companyName, 'actor_name' => $actorName, 'brand_name' => $brandName];

    $introHtml = $introOverride !== null && trim((string)$introOverride) !== ''
        ? nl2br(email_template_render((string)$introOverride, $vars, true))
        : "Tu cuenta de administrador en <strong>{$brandNameSafe} Clockin</strong> ({$companySafe}) fue desactivada por <strong>{$actorSafe}</strong>. A partir de este momento no podras iniciar sesion. Tus registros historicos se conservan.";

    $body = <<<HTML
<p style="margin:0 0 12px 0;">Hola <strong>{$nameSafe}</strong>,</p>
<p style="margin:0 0 12px 0;">{$introHtml}</p>
<p style="margin:0 0 12px 0;">Si crees que es un error, responde este correo o escribe a <a href="mailto:{$supportSafe}" style="color:#2563eb;text-decoration:none;">{$supportSafe}</a>.</p>
HTML;

    $textIntro = $introOverride !== null && trim((string)$introOverride) !== ''
        ? email_template_render((string)$introOverride, $vars, false)
        : "Tu cuenta de administrador en {$brandName} Clockin ({$companyName}) fue desactivada por {$actorName}.\nA partir de este momento no podras iniciar sesion. Tus registros historicos se conservan.";
    $text = "Hola {$name},\n\n{$textIntro}\n\nSi crees que es un error, responde este correo o escribe a " . SUPPORT_EMAIL . ".";

    $subject = $subjectOverride !== null && trim((string)$subjectOverride) !== ''
        ? email_template_render((string)$subjectOverride, $vars, false)
        : "Tu cuenta de administrador fue desactivada · {$brandName} Clockin";

    return [
        'subject' => $subject,
        'html' => tpl_layout('Cuenta desactivada', $body),
        'text' => $text,
    ];
}

/**
 * Recibo al admin que ejecuto la desactivacion. Confirmacion + traza para el actor.
 */
function mail_template_admin_delete_receipt(string $actorName, string $targetName, string $targetEmail, string $companyName, array $overrides = []): array {
    $brandName = (string)($overrides['brandName'] ?? 'Melius');
    $subjectOverride = $overrides['subjectOverride'] ?? null;
    $introOverride = $overrides['introOverride'] ?? null;

    $actorSafe = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');
    $targetNameSafe = htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8');
    $targetEmailSafe = htmlspecialchars($targetEmail, ENT_QUOTES, 'UTF-8');
    $companySafe = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $whenSafe = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');

    $vars = [
        'actor_name' => $actorName,
        'target_name' => $targetName,
        'target_email' => $targetEmail,
        'company' => $companyName,
        'brand_name' => $brandName,
    ];
    $introHtml = $introOverride !== null && trim((string)$introOverride) !== ''
        ? nl2br(email_template_render((string)$introOverride, $vars, true))
        : 'Confirmamos que desactivaste la siguiente cuenta de administrador:';

    $body = <<<HTML
<p style="margin:0 0 12px 0;">Hola <strong>{$actorSafe}</strong>,</p>
<p style="margin:0 0 12px 0;">{$introHtml}</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:8px 0 16px 0;border-collapse:collapse;">
  <tr><td style="padding:6px 0;font-size:13px;color:#475569;width:130px;">Nombre</td><td style="padding:6px 0;font-size:13px;color:#0f172a;"><strong>{$targetNameSafe}</strong></td></tr>
  <tr><td style="padding:6px 0;font-size:13px;color:#475569;">Correo</td><td style="padding:6px 0;font-size:13px;color:#0f172a;">{$targetEmailSafe}</td></tr>
  <tr><td style="padding:6px 0;font-size:13px;color:#475569;">Empresa</td><td style="padding:6px 0;font-size:13px;color:#0f172a;">{$companySafe}</td></tr>
  <tr><td style="padding:6px 0;font-size:13px;color:#475569;">Fecha</td><td style="padding:6px 0;font-size:13px;color:#0f172a;">{$whenSafe}</td></tr>
</table>
<p style="margin:0 0 12px 0;font-size:13px;color:#475569;">La desactivacion es reversible desde el panel admin (cambiar status a active). Los registros historicos del usuario se conservan.</p>
HTML;

    $textIntro = $introOverride !== null && trim((string)$introOverride) !== ''
        ? email_template_render((string)$introOverride, $vars, false)
        : 'Confirmamos que desactivaste la siguiente cuenta de administrador:';
    $text = "Hola {$actorName},\n\n{$textIntro}\n\n"
        . "  Nombre:  {$targetName}\n"
        . "  Correo:  {$targetEmail}\n"
        . "  Empresa: {$companyName}\n"
        . "  Fecha:   {$whenSafe}\n\n"
        . "La desactivacion es reversible desde el panel admin (status active). Los registros se conservan.";

    $subject = $subjectOverride !== null && trim((string)$subjectOverride) !== ''
        ? email_template_render((string)$subjectOverride, $vars, false)
        : "Confirmacion: desactivaste a {$targetEmail} · {$brandName} Clockin";

    return [
        'subject' => $subject,
        'html' => tpl_layout('Recibo de desactivacion', $body),
        'text' => $text,
    ];
}


/**
 * Plantilla: alerta de cambio radical de ubicacion (geo IP).
 * Notifica a super_admin global y admin del tenant cuando un clock-in/out
 * dispara una alerta del motor geo_alerts.
 * Acepta overrides via email_templates con kind='location_alert'.
 */
function mail_template_location_alert(array $params, array $overrides = []): array {
    $brandName  = (string)($overrides['brandName'] ?? 'Melius');
    $subjectOverride = $overrides['subjectOverride'] ?? null;
    $introOverride   = $overrides['introOverride'] ?? null;
    $ctaOverride     = $overrides['ctaOverride'] ?? null;

    $employeeName = (string)($params['employee_name'] ?? '');
    $employeeEmail = (string)($params['employee_email'] ?? '');
    $companyName = (string)($params['company_name'] ?? '');
    $prevCity = (string)($params['prev_city'] ?? '—');
    $prevCountry = (string)($params['prev_country'] ?? '—');
    $currCity = (string)($params['curr_city'] ?? '—');
    $currCountry = (string)($params['curr_country'] ?? '—');
    $distanceKm = (string)($params['distance_km'] ?? '—');
    $speedKmh = (string)($params['speed_kmh'] ?? '—');
    $reasons = (string)($params['reasons'] ?? '');
    $reviewUrl = (string)($params['review_url'] ?? '');

    $nameSafe = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8');
    $companySafe = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');
    $prevCitySafe = htmlspecialchars($prevCity, ENT_QUOTES, 'UTF-8');
    $prevCountrySafe = htmlspecialchars($prevCountry, ENT_QUOTES, 'UTF-8');
    $currCitySafe = htmlspecialchars($currCity, ENT_QUOTES, 'UTF-8');
    $currCountrySafe = htmlspecialchars($currCountry, ENT_QUOTES, 'UTF-8');
    $distanceSafe = htmlspecialchars($distanceKm, ENT_QUOTES, 'UTF-8');
    $speedSafe = htmlspecialchars($speedKmh, ENT_QUOTES, 'UTF-8');
    $reasonsSafe = htmlspecialchars($reasons, ENT_QUOTES, 'UTF-8');
    $reviewUrlSafe = htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8');
    $brandSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');

    $vars = [
        'employee_name' => $employeeName,
        'employee_email' => $employeeEmail,
        'company' => $companyName,
        'prev_city' => $prevCity,
        'prev_country' => $prevCountry,
        'curr_city' => $currCity,
        'curr_country' => $currCountry,
        'distance_km' => $distanceKm,
        'speed_kmh' => $speedKmh,
        'reasons' => $reasons,
        'brand_name' => $brandName,
        'review_url' => $reviewUrl,
    ];

    $introHtml = $introOverride !== null && trim((string)$introOverride) !== ''
        ? nl2br(email_template_render((string)$introOverride, $vars, true))
        : "Detectamos un cambio radical de ubicacion en el marcaje de <strong>{$nameSafe}</strong> ({$emailSafe}) en <strong>{$companySafe}</strong>.";

    $ctaLabel = $ctaOverride !== null && trim((string)$ctaOverride) !== ''
        ? email_template_render((string)$ctaOverride, $vars, true)
        : 'Revisar alerta en panel';

    $body = <<<HTML
<p style="margin:0 0 12px 0;">{$introHtml}</p>
<table cellpadding="8" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;width:100%;">
<tr><td style="color:#991b1b;font-weight:600;width:40%;">Empleado</td><td>{$nameSafe} &lt;{$emailSafe}&gt;</td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Empresa</td><td>{$companySafe}</td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Razones</td><td><code>{$reasonsSafe}</code></td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Ubicacion previa</td><td>{$prevCitySafe}, {$prevCountrySafe}</td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Ubicacion actual</td><td>{$currCitySafe}, {$currCountrySafe}</td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Distancia</td><td>{$distanceSafe} km</td></tr>
<tr><td style="color:#991b1b;font-weight:600;">Velocidad implicita</td><td>{$speedSafe} km/h</td></tr>
</table>
<p style="margin:16px 0 0 0;"><a href="{$reviewUrlSafe}" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">{$ctaLabel}</a></p>
<p style="margin:12px 0 0 0;color:#6b7280;font-size:12px;">La jornada no fue bloqueada. Marca como revisada o descartada desde el panel.</p>
HTML;

    $textIntro = $introOverride !== null && trim((string)$introOverride) !== ''
        ? email_template_render((string)$introOverride, $vars, false)
        : "Detectamos un cambio radical de ubicacion en el marcaje de {$employeeName} <{$employeeEmail}> en {$companyName}.";
    $text = "{$textIntro}\n\n"
          . "Razones: {$reasons}\n"
          . "Previa: {$prevCity}, {$prevCountry}\n"
          . "Actual: {$currCity}, {$currCountry}\n"
          . "Distancia: {$distanceKm} km\n"
          . "Velocidad: {$speedKmh} km/h\n\n"
          . "Revisar en: {$reviewUrl}\n";

    $subject = $subjectOverride !== null && trim((string)$subjectOverride) !== ''
        ? email_template_render((string)$subjectOverride, $vars, false)
        : "Alerta de ubicacion: {$employeeName} ({$companyName}) - {$brandName} Clockin";

    return [
        'subject' => $subject,
        'html' => tpl_layout('Alerta de ubicacion', $body),
        'text' => $text,
    ];
}
