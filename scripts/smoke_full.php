<?php
declare(strict_types=1);
// =====================================================================
// smoke_full.php — Smoke E2E completo de los endpoints nuevos.
//   1. GET /branding (publico)
//   2. POST /auth/login
//   3. GET /admin/tenant-settings
//   4. PUT /admin/tenant-settings (modifica y revierte)
//   5. GET /admin/billing/plans
//   6. GET /admin/billing/subscription
//   7. GET /admin/companies (verifica branding_* fields)
// Uso: php scripts/smoke_full.php [BASE] [EMAIL] PASSWORD
// Salida: PASS/FAIL por test con codigo de retorno = #fails.
// =====================================================================

$base = $_SERVER['argv'][1] ?? 'http://127.0.0.1:8080';
$email = $_SERVER['argv'][2] ?? 'andrew.arizmendi@meliusservices.com';
$password = $_SERVER['argv'][3] ?? null;

if (!$password) {
    fwrite(STDERR, "Uso: php scripts/smoke_full.php [BASE] [EMAIL] PASSWORD\n");
    exit(2);
}

$cookieFile = tempnam(sys_get_temp_dir(), 'clockin_smoke_');
$fails = 0;
$tests = [];

function curl_call(string $method, string $url, string $cookieFile, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
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

function assert_test(string $name, bool $cond, string $detail = '') use (&$fails, &$tests) {
    $ok = $cond;
    $tests[] = [$name, $ok, $detail];
    if (!$ok) $fails++;
    $icon = $ok ? '[PASS]' : '[FAIL]';
    echo "  {$icon} {$name}" . ($detail ? " — {$detail}" : '') . "\n";
}

echo "=== Smoke full @ {$base} ===\n";

// 1) Branding publico
echo "\n1) GET /api/branding (publico, sin login)\n";
[$s, $r] = curl_call('GET', "{$base}/api/branding", $cookieFile, ['Accept: application/json']);
$d = json_decode($r, true);
assert_test('status 200', $s === 200, "got {$s}");
assert_test('payload.ok true', !empty($d['ok']));
assert_test('branding.product_name presente', isset($d['data']['branding']['product_name']));
assert_test('branding.primary_color hex valido', preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $d['data']['branding']['primary_color'] ?? '') === 1);

// 2) CSRF + Login
echo "\n2) POST /api/auth/login\n";
[$s, $r] = curl_call('GET', "{$base}/api/csrf", $cookieFile, ['Accept: application/json']);
$csrf = json_decode($r, true)['data']['csrf_token'] ?? null;
assert_test('csrf token obtenido', !empty($csrf));

[$s, $r] = curl_call('POST', "{$base}/api/auth/login", $cookieFile, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $csrf,
], json_encode(['email' => $email, 'password' => $password]));
$d = json_decode($r, true);
assert_test('login status 200', $s === 200, "got {$s}: " . substr($r, 0, 200));
$csrf = $d['data']['csrf_token'] ?? $csrf;
$role = $d['data']['user']['role'] ?? null;
echo "   rol detectado: {$role}\n";

if ($s !== 200) {
    echo "\nLogin fallo. Abortando smoke.\n";
    @unlink($cookieFile);
    exit($fails > 0 ? 1 : 0);
}

// 3) Tenant settings (solo super_admin)
if ($role === 'super_admin') {
    echo "\n3) GET /api/admin/tenant-settings\n";
    [$s, $r] = curl_call('GET', "{$base}/api/admin/tenant-settings", $cookieFile, ['Accept: application/json']);
    $d = json_decode($r, true);
    assert_test('status 200', $s === 200);
    assert_test('tenant.product_name presente', isset($d['data']['tenant']['product_name']));
    $originalName = $d['data']['tenant']['product_name'] ?? 'Melius Clockin';
    $originalPrimary = $d['data']['tenant']['primary_color'] ?? '#07d6da';

    echo "\n4) PUT /api/admin/tenant-settings (modifica)\n";
    [$s, $r] = curl_call('PUT', "{$base}/api/admin/tenant-settings", $cookieFile, [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
    ], json_encode([
        'product_name' => 'Smoke Test Brand',
        'primary_color' => '#ff0000',
        'secondary_color' => '#00ff00',
    ]));
    assert_test('status 200', $s === 200, "got {$s}: " . substr($r, 0, 200));
    $d = json_decode($r, true);
    assert_test('product_name actualizado', ($d['data']['tenant']['product_name'] ?? '') === 'Smoke Test Brand');

    // Validacion hex permisiva no debe pasar (4 chars)
    [$s, $r] = curl_call('PUT', "{$base}/api/admin/tenant-settings", $cookieFile, [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
    ], json_encode([
        'product_name' => 'X',
        'primary_color' => '#abcd',  // invalido: 4 chars
    ]));
    assert_test('hex de 4 chars rechazado', $s === 400, "got {$s}");

    // Revertir
    echo "\n5) PUT revierte a original\n";
    [$s, $r] = curl_call('PUT', "{$base}/api/admin/tenant-settings", $cookieFile, [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
    ], json_encode([
        'product_name' => $originalName,
        'primary_color' => $originalPrimary,
        'secondary_color' => '#9909fe',
    ]));
    assert_test('revert OK', $s === 200);

    // Billing
    echo "\n6) GET /api/admin/billing/plans\n";
    [$s, $r] = curl_call('GET', "{$base}/api/admin/billing/plans", $cookieFile, ['Accept: application/json']);
    $d = json_decode($r, true);
    assert_test('status 200', $s === 200);
    assert_test('al menos 1 plan', !empty($d['data']['plans']));

    echo "\n7) GET /api/admin/billing/subscription\n";
    [$s, $r] = curl_call('GET', "{$base}/api/admin/billing/subscription", $cookieFile, ['Accept: application/json']);
    $d = json_decode($r, true);
    assert_test('status 200', $s === 200);
    assert_test('subscription.plan_code presente', isset($d['data']['subscription']['plan_code']));

    // Connect placeholder retorna 501
    echo "\n8) POST /api/admin/billing/connect (espera 501)\n";
    [$s, $r] = curl_call('POST', "{$base}/api/admin/billing/connect", $cookieFile, [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-CSRF-Token: ' . $csrf,
    ], json_encode(['provider' => 'stripe']));
    assert_test('status 501 NOT_IMPLEMENTED', $s === 501, "got {$s}");
}

// 9) Companies con branding fields
echo "\n9) GET /api/admin/companies (verifica branding_* fields)\n";
[$s, $r] = curl_call('GET', "{$base}/api/admin/companies", $cookieFile, ['Accept: application/json']);
$d = json_decode($r, true);
assert_test('status 200', $s === 200);
if (!empty($d['data']['companies'])) {
    $c = $d['data']['companies'][0];
    assert_test('campo branding_logo_url presente (puede ser null)', array_key_exists('branding_logo_url', $c));
    assert_test('campo branding_primary presente (puede ser null)', array_key_exists('branding_primary', $c));
}

// 10) Dashboard global incluye super_admin en KPIs (regresion del bug original)
echo "\n10) GET /api/admin/dashboard/global (super_admin debe contar en active_users)\n";
[$s, $r] = curl_call('GET', "{$base}/api/admin/dashboard/global", $cookieFile, ['Accept: application/json']);
$d = json_decode($r, true);
$activeUsers = $d['data']['dashboard']['totals']['active_users'] ?? 0;
assert_test('status 200', $s === 200);
assert_test('active_users > 0 (incluye admins)', $activeUsers > 0, "active_users={$activeUsers}");
if ($role === 'super_admin') {
    assert_test('by_company array no vacio', !empty($d['data']['dashboard']['by_company']));
}

@unlink($cookieFile);

echo "\n=== Resumen ===\n";
echo count($tests) . " tests, {$fails} fallos\n";
exit($fails > 0 ? 1 : 0);
