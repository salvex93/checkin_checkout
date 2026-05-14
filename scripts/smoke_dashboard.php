<?php
declare(strict_types=1);
// Smoke E2E del dashboard: GET csrf -> POST login -> GET admin/dashboard/global.
// Usa cookie jar en memoria. Requiere servidor PHP local en 127.0.0.1:8080.

$base = $_SERVER['argv'][1] ?? 'http://127.0.0.1:8080';
$email = $_SERVER['argv'][2] ?? 'andrew.arizmendi@meliusservices.com';
$password = $_SERVER['argv'][3] ?? null;

if (!$password) {
    fwrite(STDERR, "Uso: php scripts/smoke_dashboard.php [BASE] [EMAIL] PASSWORD\n");
    exit(2);
}

$cookieFile = tempnam(sys_get_temp_dir(), 'clockin_cookies_');

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
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $resp];
}

echo "1) GET /api/csrf\n";
[$s, $r] = curl_call('GET', "{$base}/api/csrf", $cookieFile, ['Accept: application/json']);
echo "   -> {$s} {$r}\n";
$csrf = json_decode($r, true)['data']['csrf_token'] ?? null;
if (!$csrf) { echo "FAIL: no csrf\n"; exit(1); }

echo "2) POST /api/auth/login\n";
$payload = json_encode(['email' => $email, 'password' => $password]);
[$s, $r] = curl_call('POST', "{$base}/api/auth/login", $cookieFile, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $csrf,
], $payload);
echo "   -> {$s}\n";
echo "   body: " . substr($r, 0, 300) . "\n";
if ($s !== 200) { echo "FAIL login\n"; exit(1); }

echo "3) GET /api/admin/dashboard/global\n";
[$s, $r] = curl_call('GET', "{$base}/api/admin/dashboard/global", $cookieFile, ['Accept: application/json']);
echo "   -> {$s}\n";
echo "   body: " . substr($r, 0, 600) . "\n";

@unlink($cookieFile);
