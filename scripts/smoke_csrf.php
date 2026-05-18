<?php
declare(strict_types=1);
// Smoke E2E de CSRF: valida headers anti-cache, rechazo sin token,
// rechazo con token invalido, y aceptacion con token correcto.
// Requiere servidor PHP local en 127.0.0.1:8080.

$base = $_SERVER['argv'][1] ?? 'http://127.0.0.1:8080';
$email = $_SERVER['argv'][2] ?? 'andrew.arizmendi@meliusservices.com';
$password = $_SERVER['argv'][3] ?? null;

if (!$password) {
    fwrite(STDERR, "Uso: php scripts/smoke_csrf.php [BASE] [EMAIL] PASSWORD\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function curl_full(string $method, string $url, string $cookieFile, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr((string)$resp, 0, $headerSize);
    $bodyOut = substr((string)$resp, $headerSize);
    curl_close($ch);
    return [$status, $rawHeaders, $bodyOut];
}

function assert_test(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS — {$name}\n";
    } else {
        $failed++;
        echo "  FAIL — {$name}" . ($detail ? " ({$detail})" : '') . "\n";
    }
}

$cookieFile = tempnam(sys_get_temp_dir(), 'csrf_test_');

echo "TEST 1: GET /api/csrf — headers anti-cache + token\n";
[$s, $h, $b] = curl_full('GET', "{$base}/api/csrf", $cookieFile, ['Accept: application/json']);
assert_test('status 200', $s === 200, "got {$s}");
assert_test('Cache-Control: no-store presente', stripos($h, 'cache-control: no-store') !== false);
assert_test('Vary: Cookie presente', stripos($h, 'vary: cookie') !== false);
$csrf = json_decode($b, true)['data']['csrf_token'] ?? null;
assert_test('csrf_token entregado', is_string($csrf) && strlen($csrf) === 64);

echo "\nTEST 2: POST /api/auth/login SIN header CSRF -> 403\n";
$payload = json_encode(['email' => $email, 'password' => $password]);
[$s, $h, $b] = curl_full('POST', "{$base}/api/auth/login", $cookieFile, [
    'Accept: application/json',
    'Content-Type: application/json',
], $payload);
assert_test('status 403', $s === 403, "got {$s}");
$err = json_decode($b, true)['error']['code'] ?? '';
assert_test('error code CSRF_INVALID', $err === 'CSRF_INVALID', "got {$err}");

echo "\nTEST 3: POST /api/auth/login con token CSRF basura -> 403\n";
[$s, $h, $b] = curl_full('POST', "{$base}/api/auth/login", $cookieFile, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-CSRF-Token: ' . str_repeat('a', 64),
], $payload);
assert_test('status 403', $s === 403, "got {$s}");

echo "\nTEST 4: POST /api/auth/login con token CSRF valido -> 200\n";
[$s, $h, $b] = curl_full('POST', "{$base}/api/auth/login", $cookieFile, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $csrf,
], $payload);
assert_test('status 200', $s === 200, "got {$s}");
$loginData = json_decode($b, true)['data'] ?? [];
assert_test('user en respuesta', !empty($loginData['user']['id']));
assert_test('csrf_token renovado en respuesta', !empty($loginData['csrf_token']));

echo "\nTEST 5: GET /api/csrf — tokens distintos para sesiones distintas\n";
$cookieA = tempnam(sys_get_temp_dir(), 'csrfA_');
$cookieB = tempnam(sys_get_temp_dir(), 'csrfB_');
[, , $bA] = curl_full('GET', "{$base}/api/csrf", $cookieA, ['Accept: application/json']);
[, , $bB] = curl_full('GET', "{$base}/api/csrf", $cookieB, ['Accept: application/json']);
$tokA = json_decode($bA, true)['data']['csrf_token'] ?? '';
$tokB = json_decode($bB, true)['data']['csrf_token'] ?? '';
assert_test('cada sesion recibe token unico', $tokA !== $tokB && $tokA !== '' && $tokB !== '');
@unlink($cookieA);
@unlink($cookieB);

@unlink($cookieFile);

echo "\n===========================\n";
echo "Resultados: {$passed} pass / {$failed} fail\n";
exit($failed > 0 ? 1 : 0);
