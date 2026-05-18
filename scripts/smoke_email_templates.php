<?php
declare(strict_types=1);
// Smoke E2E HTTP del CRUD de email_templates.
// Requiere servidor PHP local en 127.0.0.1:8080 y credenciales de super_admin.
// Uso: php scripts/smoke_email_templates.php [BASE] [EMAIL] PASSWORD

$base = $_SERVER['argv'][1] ?? 'http://127.0.0.1:8080';
$email = $_SERVER['argv'][2] ?? 'andrew.arizmendi@meliusservices.com';
$password = $_SERVER['argv'][3] ?? null;

if (!$password) {
    fwrite(STDERR, "Uso: php scripts/smoke_email_templates.php [BASE] [EMAIL] PASSWORD\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$failures = [];

function http_req(string $method, string $url, string $cookieFile, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $resp];
}

function check(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  PASS — {$name}\n"; }
    else { $failed++; $failures[] = $name; echo "  FAIL — {$name}" . ($detail ? " ({$detail})" : '') . "\n"; }
}

$cookieFile = tempnam(sys_get_temp_dir(), 'etpl_');

echo "SETUP: obtener CSRF + login\n";
[$s, $b] = http_req('GET', "{$base}/api/csrf", $cookieFile);
check('CSRF 200', $s === 200);
$csrf = json_decode((string)$b, true)['data']['csrf_token'] ?? null;
check('csrf_token valido', is_string($csrf) && strlen($csrf) === 64);

[$s, $b] = http_req('POST', "{$base}/api/auth/login", $cookieFile,
    ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"],
    json_encode(['email' => $email, 'password' => $password])
);
check('login 200', $s === 200, "got {$s}: " . substr((string)$b, 0, 200));

// Refresh CSRF tras login (sesion nueva)
[$s, $b] = http_req('GET', "{$base}/api/csrf", $cookieFile);
$csrf = json_decode((string)$b, true)['data']['csrf_token'] ?? null;

echo "\nTEST 1: GET /admin/email-templates lista las plantillas\n";
[$s, $b] = http_req('GET', "{$base}/api/admin/email-templates", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
check('GET list 200', $s === 200, "got {$s}: " . substr((string)$b, 0, 200));
$data = json_decode((string)$b, true);
$rows = $data['data']['templates'] ?? [];
check('lista no vacia', count($rows) > 0);
check('cada fila tiene brand+kind+subject', !empty($rows[0]['brand_name']) && !empty($rows[0]['kind']) && !empty($rows[0]['subject']));

// Captura brand_id de melius para los siguientes tests
$meliusRow = null;
foreach ($rows as $r) {
    if (($r['brand_slug'] ?? '') === 'melius' && $r['kind'] === 'invitation') {
        $meliusRow = $r;
        break;
    }
}
check('encontro fila melius/invitation', $meliusRow !== null);
$brandId = $meliusRow['brand_id'] ?? null;

echo "\nTEST 2: GET /admin/email-templates/{brand}/{kind} retorna detalle\n";
[$s, $b] = http_req('GET', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
check('GET detail 200', $s === 200, "got {$s}");
$tpl = json_decode((string)$b, true)['data']['template'] ?? null;
check('tiene subject', !empty($tpl['subject']));
check('tiene intro_html', !empty($tpl['intro_html']));

echo "\nTEST 3: GET con kind invalido devuelve 400\n";
[$s, $b] = http_req('GET', "{$base}/api/admin/email-templates/{$brandId}/kind_x", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
check('GET kind invalido 400', $s === 400, "got {$s}");

echo "\nTEST 4: POST /admin/email-templates/preview renderiza\n";
[$s, $b] = http_req('POST', "{$base}/api/admin/email-templates/preview", $cookieFile,
    ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"],
    json_encode([
        'brand_id' => $brandId,
        'kind' => 'invitation',
        'subject' => 'Demo {{brand_name}}',
        'intro_html' => 'Intro de prueba para {{company}}',
        'cta_label' => 'Entrar',
    ])
);
check('preview 200', $s === 200, "got {$s}: " . substr((string)$b, 0, 200));
$preview = json_decode((string)$b, true)['data'] ?? [];
check('preview tiene html', !empty($preview['html']));
check('preview tiene subject', !empty($preview['subject']));
check('preview aplica placeholder en subject', str_contains((string)$preview['subject'], 'Melius'));
check('preview aplica intro custom', str_contains((string)$preview['html'], 'Intro de prueba'));

echo "\nTEST 5: PUT /admin/email-templates persiste cambio\n";
$nuevoSubject = 'TEST SMOKE ' . time();
[$s, $b] = http_req('PUT', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile,
    ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"],
    json_encode([
        'subject' => $nuevoSubject,
        'intro_html' => 'Intro custom desde smoke',
        'cta_label' => 'CTA custom',
    ])
);
check('PUT 200', $s === 200, "got {$s}: " . substr((string)$b, 0, 200));

[$s, $b] = http_req('GET', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
$after = json_decode((string)$b, true)['data']['template'] ?? [];
check('persistio subject', ($after['subject'] ?? '') === $nuevoSubject);
check('persistio intro_html', ($after['intro_html'] ?? '') === 'Intro custom desde smoke');
check('persistio cta_label', ($after['cta_label'] ?? '') === 'CTA custom');

echo "\nTEST 6: DELETE elimina override; GET retorna 404 luego\n";
[$s, $b] = http_req('DELETE', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
check('DELETE 200', $s === 200, "got {$s}");

[$s, $b] = http_req('GET', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile, ["X-CSRF-Token: {$csrf}"]);
check('GET tras DELETE retorna 404', $s === 404, "got {$s}");

echo "\nTEST 7: PUT sin CSRF rechazado\n";
[$s, $b] = http_req('PUT', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile,
    ['Content-Type: application/json'],
    json_encode(['subject' => 'NoCSRF', 'intro_html' => 'x'])
);
check('PUT sin CSRF 403', $s === 403, "got {$s}");

// Restore (re-crear via PUT con CSRF para que el seed siga consistente)
[$s, $b] = http_req('PUT', "{$base}/api/admin/email-templates/{$brandId}/invitation", $cookieFile,
    ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"],
    json_encode([
        'subject' => 'Bienvenido a {{brand_name}} Clockin · {{company}}',
        'intro_html' => 'Tu equipo en {{company}} esta usando {{brand_name}} Clockin para marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.',
        'cta_label' => 'Entrar a {{brand_name}} Clockin',
    ])
);
check('restore seed via PUT 200', $s === 200, "got {$s}");

echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULTADO HTTP: {$passed} PASS / {$failed} FAIL\n";
if ($failed > 0) {
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
