<?php
declare(strict_types=1);

// =====================================================================
// scripts/send_invitation.php
// CLI para enviar un email de demostracion del template "invitation v2"
// a una direccion arbitraria. No crea cuenta, no toca DB; solo SMTP.
// Sirve como smoke test real del transporte y para revisar el render
// del template en clientes de correo de produccion.
// Uso:
//   php scripts/send_invitation.php <to_email> [<nombre>] [<empresa>]
// Ejemplo:
//   php scripts/send_invitation.php andrew.arizmendi@meliusservices.com "Andrew" "Melius Services"
// =====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("No disponible via HTTP.\n");
}

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/mailer.php';

$to = isset($argv[1]) ? strtolower(trim($argv[1])) : '';
$name = isset($argv[2]) ? trim($argv[2]) : 'Equipo';
$company = isset($argv[3]) ? trim($argv[3]) : 'Melius Services';

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/send_invitation.php <to_email> [<nombre>] [<empresa>]\n");
    fwrite(STDERR, "Email destino invalido o ausente.\n");
    exit(1);
}

$loginUrl = (env('APP_BASE_URL', '') !== '')
    ? rtrim((string)env('APP_BASE_URL'), '/') . '/'
    : 'http://127.0.0.1:8080/';

$tpl = mail_template_invitation_v2([
    'name' => $name,
    'companyName' => $company,
    'loginUrl' => $loginUrl,
    'introOverride' => 'Esto es una demostracion del template de invitacion v2. Sin credenciales, sin cuenta creada. Solo para validar el render en tu cliente de correo.',
]);

echo "Enviando demo a {$to} (nombre={$name}, empresa={$company})...\n";
$ok = mail_send($to, $tpl['subject'], $tpl['html'], $tpl['text']);

if (!$ok) {
    fwrite(STDERR, "ERROR: el envio fallo. Revisa logs PHP y configuracion SMTP en .env.\n");
    exit(2);
}

echo "OK: email enviado.\n";
echo "Asunto: {$tpl['subject']}\n";
echo "From:   " . env('SMTP_FROM', '(no definido)') . "\n";
echo "Host:   " . env('SMTP_HOST', '(no definido)') . ":" . env('SMTP_PORT', '465') . "\n";
