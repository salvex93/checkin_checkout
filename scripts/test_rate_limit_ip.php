<?php
declare(strict_types=1);

// Test smoke del rate-limit por IP en /api/auth/login.
// Requiere servidor PHP local en localhost:8000.
//   C:\xampp\php\php.exe -S localhost:8000 -t public scripts/router.php
// Ejecutar:
//   C:\xampp\php\php.exe scripts/test_rate_limit_ip.php

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$base = $_SERVER['argv'][1] ?? 'http://localhost:8000';
$cookieFile = tempnam(sys_get_temp_dir(), 'cj_');

function http_post(string $url, string $cookieFile, string $body, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 RateLimitTest',
        ], $headers),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$resp, true) ?: []];
}

function http_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 RateLimitTest',
        ],
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$resp, true) ?: []];
}

$passed = 0;
$failed = 0;
function assert_t(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS — {$name}\n";
    } else {
        $failed++;
        echo "  FAIL — {$name}" . ($detail ? " ({$detail})" : '') . "\n";
    }
}

echo "=== Test rate-limit IP en auth/login ===\n";

$pdo = Database::pdo();
$pdo->exec("DELETE FROM rate_limits WHERE scope = 'login_ip'");
echo "  Limpia rate_limits previos.\n";

[$status, $data] = http_get($base . '/api/csrf', $cookieFile);
$csrf = $data['data']['csrf_token'] ?? null;
assert_t('GET /api/csrf devuelve token', $status === 200 && $csrf !== null);

$blocked = false;
$blockedAt = 0;
for ($i = 1; $i <= 20; $i++) {
    // Refrescar token CSRF en cada intento (login lo rota tras success/fail).
    [, $cd] = http_get($base . '/api/csrf', $cookieFile);
    $csrf = $cd['data']['csrf_token'] ?? $csrf;

    $body = json_encode([
        'email' => "noexiste{$i}@test.invalid",
        'password' => 'wrong_password',
    ]);
    [$st, $d] = http_post($base . '/api/auth/login', $cookieFile, $body, [
        'X-CSRF-Token: ' . $csrf,
    ]);
    $code = $d['error']['code'] ?? '';
    if ($i <= 3 || $i >= 15) {
        echo "    intento {$i}: HTTP {$st} code={$code}\n";
    }
    if ($code === 'RATE_LIMITED' && $st === 429) {
        $blocked = true;
        $blockedAt = $i;
        break;
    }
}

assert_t('Rate-limit IP dispara con HTTP 429', $blocked, "Se quedo intentando sin bloquear");
assert_t('Bloqueo entre intento 15 y 17 (limite=15)', $blockedAt >= 15 && $blockedAt <= 17, "Bloqueo en intento {$blockedAt}");

$pdo->exec("DELETE FROM rate_limits WHERE scope = 'login_ip'");
echo "\n=========================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

@unlink($cookieFile);
exit($failed === 0 ? 0 : 1);
