<?php
declare(strict_types=1);
// Genera HTML preview del email de invitacion para cada marca en DB.
// Salida: public/uploads/email_previews/<slug>.html (gitignored).
// Abre los archivos en el navegador para validar branding antes de enviar real.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/mailer.php';

$brands = db_all('SELECT slug, name, logo_url, primary_color, secondary_color, welcome_intro FROM brands ORDER BY name');
if (!$brands) {
    fwrite(STDERR, "No hay marcas en la DB.\n");
    exit(1);
}

$outDir = __DIR__ . '/../public/uploads/email_previews';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "No se pudo crear directorio: {$outDir}\n");
    exit(1);
}

$base = $_SERVER['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: 'http://localhost:8080';
foreach ($brands as $b) {
    $tpl = mail_template_invitation_v2([
        'name' => 'Ana Gomez',
        'companyName' => $b['slug'] === 'fullman' ? 'Coppel' : ($b['slug'] === 'netfy' ? 'Hyatt' : 'Arajet'),
        'loginUrl' => $base . '/',
        'email' => 'ana@empresa.com',
        'tempPassword' => 'Demo%2026!',
        'brandName' => $b['name'],
        'brandLogoUrl' => rtrim($base, '/') . '/' . ltrim($b['logo_url'], '/'),
        'brandPrimary' => $b['primary_color'],
        'brandSecondary' => $b['secondary_color'],
        'brandWelcome' => $b['welcome_intro'],
    ]);
    $path = $outDir . '/' . $b['slug'] . '.html';
    file_put_contents($path, $tpl['html']);
    echo "Preview: /uploads/email_previews/{$b['slug']}.html (subject: {$tpl['subject']})\n";
}
echo "\nAbre cualquiera de ellos en el navegador con el servidor local.\n";
