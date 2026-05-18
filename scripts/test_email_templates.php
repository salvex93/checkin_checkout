<?php
declare(strict_types=1);

// =====================================================================
// test_email_templates.php — Tests unitarios + integracion del sistema
// de plantillas editables. Ejecucion: php scripts/test_email_templates.php
// Sale con codigo 0 si todo pasa, 1 si hay fallos.
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/mailer.php';

$pass = 0;
$fail = 0;
$failures = [];

function assert_eq($expected, $actual, string $msg): void {
    global $pass, $fail, $failures;
    if ($expected === $actual) {
        $pass++;
        echo "  OK   {$msg}\n";
    } else {
        $fail++;
        $failures[] = $msg;
        $expectedStr = is_scalar($expected) ? (string)$expected : json_encode($expected);
        $actualStr = is_scalar($actual) ? (string)$actual : json_encode($actual);
        echo "  FAIL {$msg}\n";
        echo "       expected: " . substr($expectedStr, 0, 120) . "\n";
        echo "       actual:   " . substr($actualStr, 0, 120) . "\n";
    }
}

function assert_contains(string $haystack, string $needle, string $msg): void {
    global $pass, $fail, $failures;
    if (str_contains($haystack, $needle)) {
        $pass++;
        echo "  OK   {$msg}\n";
    } else {
        $fail++;
        $failures[] = $msg;
        echo "  FAIL {$msg}\n";
        echo "       no contiene: '{$needle}'\n";
    }
}

function assert_not_contains(string $haystack, string $needle, string $msg): void {
    global $pass, $fail, $failures;
    if (!str_contains($haystack, $needle)) {
        $pass++;
        echo "  OK   {$msg}\n";
    } else {
        $fail++;
        $failures[] = $msg;
        echo "  FAIL {$msg}\n";
        echo "       contiene (no deberia): '{$needle}'\n";
    }
}

// ----- UNIT: email_template_render -----
echo "\n[UNIT] email_template_render\n";

assert_eq(
    'Hola Ana',
    email_template_render('Hola {{name}}', ['name' => 'Ana'], false),
    'render texto plano sustituye placeholder'
);

assert_eq(
    'Hola &lt;Ana&gt;',
    email_template_render('Hola {{name}}', ['name' => '<Ana>'], true),
    'render HTML escapa caracteres peligrosos'
);

assert_eq(
    'Hola {{unknown}}',
    email_template_render('Hola {{unknown}}', ['name' => 'Ana'], false),
    'placeholder desconocido se deja literal'
);

assert_eq(
    'A: 1 / B: 2',
    email_template_render('A: {{a}} / B: {{b}}', ['a' => '1', 'b' => '2'], false),
    'multiples placeholders en una cadena'
);

// Protege contra inyeccion HTML en intro
$xss = email_template_render('Hola {{name}}', ['name' => '<script>alert(1)</script>'], true);
assert_not_contains($xss, '<script>', 'render HTML neutraliza tags script');

// ----- UNIT: email_template_load -----
echo "\n[UNIT] email_template_load\n";

$brand = db_one('SELECT id FROM brands ORDER BY id LIMIT 1');
if (!$brand) {
    echo "  SKIP no hay marcas en DB para probar email_template_load\n";
} else {
    $brandId = (int)$brand['id'];
    $row = email_template_load($brandId, 'invitation');
    assert_eq(true, is_array($row), 'load retorna array para (brand, invitation) seed');
    assert_eq(true, isset($row['subject']), 'load incluye campo subject');
    assert_eq(true, isset($row['intro_html']), 'load incluye campo intro_html');

    assert_eq(null, email_template_load($brandId, 'kind_invalido'), 'load con kind invalido retorna null');
    assert_eq(null, email_template_load(null, 'invitation'), 'load con brand_id null retorna null');
    assert_eq(null, email_template_load(999999, 'invitation'), 'load con brand inexistente retorna null');
}

// ----- UNIT: mail_template_invitation_v2 con overrides -----
echo "\n[UNIT] mail_template_invitation_v2 con overrides\n";

$tpl = mail_template_invitation_v2([
    'name' => 'Ana Gomez',
    'companyName' => 'Coppel',
    'loginUrl' => 'https://example.com/',
    'email' => 'ana@empresa.com',
    'tempPassword' => 'Demo123!',
    'brandName' => 'Fullman',
    'brandPrimary' => '#65422a',
    'brandSecondary' => '#f2e484',
    'subjectOverride' => 'Bienvenida {{name}} a {{brand_name}}',
    'introOverride' => 'Hola desde {{company}} con {{brand_name}}',
    'ctaOverride' => 'Acceder a {{brand_name}}',
]);

assert_eq('Bienvenida Ana Gomez a Fullman', $tpl['subject'], 'subject override aplica placeholders');
assert_contains($tpl['html'], 'Hola desde Coppel con Fullman', 'intro override aparece en HTML');
assert_contains($tpl['html'], 'Acceder a Fullman', 'cta override aparece en HTML');
assert_contains($tpl['html'], 'ana@empresa.com', 'credenciales aparecen en HTML');
assert_contains($tpl['html'], 'Demo123!', 'password temporal aparece en HTML');
assert_contains($tpl['text'], 'Demo123!', 'password aparece en version texto');

// Sin overrides usa defaults
$tpl2 = mail_template_invitation_v2([
    'name' => 'Ana',
    'companyName' => 'Empresa X',
    'loginUrl' => 'https://example.com/',
    'brandName' => 'Melius',
]);
assert_contains($tpl2['subject'], 'Bienvenido a Melius', 'subject default cuando no hay override');
assert_contains($tpl2['html'], 'Entrar a Melius Clockin', 'cta default cuando no hay override');

// XSS en override
$tplXss = mail_template_invitation_v2([
    'name' => 'Ana',
    'companyName' => 'Empresa X',
    'loginUrl' => 'https://example.com/',
    'brandName' => 'Melius',
    'introOverride' => '<script>alert(1)</script>',
]);
assert_not_contains($tplXss['html'], '<script>alert(1)</script>', 'intro override neutraliza script tags');

// ----- UNIT: mail_template_password_reset con overrides -----
echo "\n[UNIT] mail_template_password_reset con overrides\n";

$tplPr = mail_template_password_reset('Ana', 'https://x/reset', 2, [
    'brandName' => 'Netfy',
    'subjectOverride' => 'Reset de {{brand_name}}',
    'introOverride' => 'Solicitaste reset desde {{brand_name}}',
    'ctaOverride' => 'Restablecer en {{brand_name}}',
]);
assert_eq('Reset de Netfy', $tplPr['subject'], 'password_reset subject override');
assert_contains($tplPr['html'], 'Solicitaste reset desde Netfy', 'password_reset intro override');
assert_contains($tplPr['html'], 'Restablecer en Netfy', 'password_reset cta override');

// ----- UNIT: mail_template_admin_disabled con overrides -----
echo "\n[UNIT] mail_template_admin_disabled con overrides\n";

$tplAd = mail_template_admin_disabled('Pedro', 'Acme', 'Andrew', [
    'brandName' => 'Melius',
    'subjectOverride' => 'Tu cuenta en {{brand_name}} fue desactivada',
    'introOverride' => 'Tu cuenta en {{company}} fue desactivada por {{actor_name}}.',
]);
assert_contains($tplAd['subject'], 'Melius', 'admin_disabled subject override');
assert_contains($tplAd['html'], 'fue desactivada por Andrew', 'admin_disabled intro override aplica actor_name');

// ----- UNIT: mail_template_admin_delete_receipt con overrides -----
echo "\n[UNIT] mail_template_admin_delete_receipt con overrides\n";

$tplRc = mail_template_admin_delete_receipt('Andrew', 'Pedro', 'pedro@x.com', 'Acme', [
    'brandName' => 'Melius',
    'subjectOverride' => 'Desactivaste a {{target_email}}',
    'introOverride' => 'Confirmamos {{target_name}} desactivado en {{company}}.',
]);
assert_contains($tplRc['subject'], 'pedro@x.com', 'admin_delete_receipt subject override aplica target_email');
assert_contains($tplRc['html'], 'Pedro desactivado en Acme', 'admin_delete_receipt intro override');

// ----- INTEGRACION: ciclo CRUD via DB directo (sin HTTP) -----
echo "\n[INT] Ciclo CRUD email_templates en DB\n";

$brand2 = db_one('SELECT id FROM brands ORDER BY id LIMIT 1');
if ($brand2) {
    $bid = (int)$brand2['id'];

    // Backup del actual
    $original = email_template_load($bid, 'invitation');

    // UPDATE
    db_exec(
        'UPDATE email_templates SET subject = ?, intro_html = ?, cta_label = ?
         WHERE brand_id = ? AND kind = ?',
        ['TEST SUBJECT', 'TEST INTRO {{brand_name}}', 'TEST CTA', $bid, 'invitation']
    );
    $afterUpdate = email_template_load($bid, 'invitation');
    assert_eq('TEST SUBJECT', $afterUpdate['subject'], 'update persiste subject');
    assert_eq('TEST INTRO {{brand_name}}', $afterUpdate['intro_html'], 'update persiste intro');
    assert_eq('TEST CTA', $afterUpdate['cta_label'], 'update persiste cta_label');

    // El render real usa el override
    $brandFull = db_one('SELECT id, name, primary_color, secondary_color FROM brands WHERE id = ?', [$bid]);
    $tplDb = mail_template_invitation_v2([
        'name' => 'Ana',
        'companyName' => 'X',
        'loginUrl' => 'https://x/',
        'brandName' => $brandFull['name'],
        'brandPrimary' => $brandFull['primary_color'],
        'brandSecondary' => $brandFull['secondary_color'],
        'subjectOverride' => $afterUpdate['subject'],
        'introOverride' => $afterUpdate['intro_html'],
        'ctaOverride' => $afterUpdate['cta_label'],
    ]);
    assert_eq('TEST SUBJECT', $tplDb['subject'], 'pipeline DB->template entrega subject de DB');
    assert_contains($tplDb['html'], 'TEST INTRO ' . $brandFull['name'], 'pipeline DB->template aplica placeholder con brand_name de DB');
    assert_contains($tplDb['html'], 'TEST CTA', 'pipeline DB->template entrega cta de DB');

    // Restore original
    if ($original) {
        db_exec(
            'UPDATE email_templates SET subject = ?, intro_html = ?, cta_label = ?
             WHERE brand_id = ? AND kind = ?',
            [$original['subject'], $original['intro_html'], $original['cta_label'], $bid, 'invitation']
        );
        echo "  OK   restore plantilla original tras test\n";
        $pass++;
    }
}

// ----- INTEGRACION: cardinality check -----
echo "\n[INT] Estado de DB\n";
$count = (int)Database::pdo()->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();
$brandsCount = (int)Database::pdo()->query('SELECT COUNT(*) FROM brands')->fetchColumn();
$expected = $brandsCount * 4;
assert_eq($expected, $count, "email_templates tiene {$expected} filas (brands x 4 kinds)");

// ----- Resumen -----
echo "\n";
echo str_repeat('=', 60) . "\n";
echo "RESULTADO: {$pass} OK / {$fail} FAIL\n";
if ($fail > 0) {
    echo "FALLOS:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
