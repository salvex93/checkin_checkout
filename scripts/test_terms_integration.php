<?php
declare(strict_types=1);

// Tests de integracion T&C + geo (sin mocks de BD).
// Requiere servidor PHP local. Levantar:
//   C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public scripts/router.php
// Ejecutar:
//   C:\xampp\php\php.exe scripts/test_terms_integration.php
//
// Crea un consultor temporal directamente en SQLite, ejecuta el flujo HTTP completo
// (login -> jornada bloqueada por T&C -> aceptar -> clockin libera geo) y limpia.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$base = $_SERVER['argv'][1] ?? 'http://127.0.0.1:8080';
$testEmail = 'qa.terms.test+' . bin2hex(random_bytes(3)) . '@melius.test';
$testPassword = 'QaTermsTest!2026';

$pdo = Database::pdo();
// SQLite + servidor concurrente: aumentar busy_timeout para evitar "database is locked".
try { $pdo->exec('PRAGMA busy_timeout = 5000'); } catch (Throwable $_) {}

function http_call(string $method, string $url, string $cookieFile, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 5,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$resp, true);
    return [$status, is_array($decoded) ? $decoded : []];
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
        echo "  FAIL — {$name}" . ($detail !== '' ? " | {$detail}" : '') . "\n";
    }
}

// === Setup: crear consultor de prueba ===
echo "Setup: crear usuario de prueba {$testEmail}\n";
$company = $pdo->query("SELECT id FROM companies LIMIT 1")->fetch();
if (!$company) { fwrite(STDERR, "No hay empresas en BD\n"); exit(2); }
$hash = password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 10]);
$stmt = $pdo->prepare("INSERT INTO users (email, name, password_hash, role, company_id, status, is_active) VALUES (?, ?, ?, 'consultant', ?, 'active', 1)");
$stmt->execute([$testEmail, 'QA Terms', $hash, $company['id']]);
$userId = (int)$pdo->lastInsertId();

// Asegurar que existe terms_versions activa
$active = $pdo->query("SELECT id, version FROM terms_versions WHERE is_active=1 LIMIT 1")->fetch();
if (!$active) { fwrite(STDERR, "No hay terms_versions activos; corre migrate_terms_and_geo.php primero\n"); exit(2); }

// Limpiar cualquier aceptacion previa de este usuario
$pdo->prepare("DELETE FROM user_terms_acceptance WHERE user_id = ?")->execute([$userId]);

$cookieFile = tempnam(sys_get_temp_dir(), 'terms_test_');
$cleanup = function() use ($pdo, $userId, $cookieFile) {
    $tries = 0;
    while ($tries++ < 6) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM user_terms_acceptance WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM attendance_records WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $pdo->commit();
            break;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (stripos($e->getMessage(), 'locked') === false) break;
            usleep(400_000);
        }
    }
    @unlink($cookieFile);
};
register_shutdown_function($cleanup);

// === T1: GET /api/terms/current devuelve version activa ===
echo "\nT1: GET /api/terms/current\n";
[$s, $r] = http_call('GET', "{$base}/api/terms/current", $cookieFile);
assert_t('status 200', $s === 200, "got {$s}");
assert_t('terms presente', !empty($r['data']['terms']));
assert_t('version coincide', ($r['data']['terms']['version'] ?? '') === $active['version']);
assert_t('privacy_html no vacio', !empty($r['data']['terms']['privacy_html']));

// === T2: Login + auth/me devuelve terms_pending=true ===
echo "\nT2: Login y verificar terms_pending\n";
[, $r] = http_call('GET', "{$base}/api/csrf", $cookieFile);
$csrf = $r['data']['csrf_token'] ?? '';
assert_t('csrf token recibido', $csrf !== '');

[$s, $r] = http_call('POST', "{$base}/api/auth/login", $cookieFile,
    ['X-CSRF-Token: ' . $csrf],
    json_encode(['email' => $testEmail, 'password' => $testPassword])
);
assert_t('login 200', $s === 200, "got {$s}");
$csrf = $r['data']['csrf_token'] ?? $csrf;
assert_t('user.terms_pending = true', ($r['data']['user']['terms_pending'] ?? false) === true);
assert_t('user.terms_version coincide', ($r['data']['user']['terms_version'] ?? null) === $active['version']);

// === T3: clockin sin aceptar T&C -> TERMS_REQUIRED ===
echo "\nT3: clockin antes de aceptar T&C\n";
[$s, $r] = http_call('POST', "{$base}/api/records/clockin", $cookieFile,
    ['X-CSRF-Token: ' . $csrf],
    json_encode([])
);
assert_t('status 403', $s === 403, "got {$s}");
assert_t('error code TERMS_REQUIRED', ($r['error']['code'] ?? '') === 'TERMS_REQUIRED');

// === T4: aceptar T&C ===
echo "\nT4: POST /api/terms/accept\n";
[$s, $r] = http_call('POST', "{$base}/api/terms/accept", $cookieFile,
    ['X-CSRF-Token: ' . $csrf],
    json_encode(['version' => $active['version']])
);
assert_t('status 200', $s === 200, "got {$s}");
$row = $pdo->prepare("SELECT id, ip_address, accepted_at FROM user_terms_acceptance WHERE user_id = ? AND terms_version_id = ?");
$row->execute([$userId, $active['id']]);
$acc = $row->fetch();
assert_t('aceptacion persistida en BD', $acc !== false);
assert_t('ip_address registrada', !empty($acc['ip_address']));

// === T5: clockin tras aceptar -> 200 + geo_source en respuesta ===
echo "\nT5: clockin tras aceptar libera y registra geo\n";
[$s, $r] = http_call('POST', "{$base}/api/records/clockin", $cookieFile,
    ['X-CSRF-Token: ' . $csrf],
    json_encode([])
);
assert_t('status 200', $s === 200, "got {$s}");
$record = $r['data']['record'] ?? null;
assert_t('record creado', is_array($record) && isset($record['id']));
// geo_source debe ser 'none' o 'ip' segun si el server local pudo resolver. En test local
// es 127.0.0.1, asi que esperamos 'none'.
assert_t('geo_source definido', isset($record['geo_source']) && in_array($record['geo_source'], ['none','ip'], true));

// Validar contrato de la API (no abrir BD en paralelo con SQLite + servidor).
// Para IP local 127.0.0.1: country_code = null, source = 'none'.
assert_t('API: geo_country_code null para IP local', array_key_exists('geo_country_code', $record) && $record['geo_country_code'] === null);
assert_t('API: geo_source = none para IP local', ($record['geo_source'] ?? null) === 'none');

// === T6: re-aceptacion idempotente ===
echo "\nT6: POST /api/terms/accept dos veces es idempotente\n";
[$s, $r] = http_call('POST', "{$base}/api/terms/accept", $cookieFile,
    ['X-CSRF-Token: ' . $csrf],
    json_encode(['version' => $active['version']])
);
assert_t('segunda aceptacion 200', $s === 200);
$count = $pdo->prepare("SELECT COUNT(*) AS c FROM user_terms_acceptance WHERE user_id = ?");
$count->execute([$userId]);
$ct = (int)$count->fetch()['c'];
assert_t('solo 1 fila de aceptacion (UNIQUE)', $ct === 1, "got {$ct}");

// === T7: nueva version publicada via API admin -> fuerza re-aceptacion ===
// Solo se ejecuta si TEST_SUPER_ADMIN_EMAIL y TEST_SUPER_ADMIN_PASSWORD estan en env.
// Si no, se reporta como skipped para no contaminar resultados con falsos negativos
// causados por concurrencia SQLite (servidor + script comparten DB local).
$superEmail = getenv('TEST_SUPER_ADMIN_EMAIL') ?: '';
$superPass = getenv('TEST_SUPER_ADMIN_PASSWORD') ?: '';

if ($superEmail === '' || $superPass === '') {
    echo "\nT7: SKIP (definir TEST_SUPER_ADMIN_EMAIL y TEST_SUPER_ADMIN_PASSWORD para ejecutar)\n";
} else {
    echo "\nT7: Publicar nueva version via API admin\n";
    $adminCookie = tempnam(sys_get_temp_dir(), 'terms_admin_');
    [, $r] = http_call('GET', "{$base}/api/csrf", $adminCookie);
    $adminCsrf = $r['data']['csrf_token'] ?? '';
    [$s, $r] = http_call('POST', "{$base}/api/auth/login", $adminCookie,
        ['X-CSRF-Token: ' . $adminCsrf],
        json_encode(['email' => $superEmail, 'password' => $superPass])
    );
    assert_t('super_admin login 200', $s === 200);
    $adminCsrf = $r['data']['csrf_token'] ?? $adminCsrf;
    $newVersion = '1.1-test-' . bin2hex(random_bytes(2));
    [$s, $r] = http_call('POST', "{$base}/api/admin/terms", $adminCookie,
        ['X-CSRF-Token: ' . $adminCsrf],
        json_encode([
            'version' => $newVersion,
            'title' => 'T&C ' . $newVersion,
            'body_html' => '<p>nuevo cuerpo de prueba</p>',
            'privacy_html' => '<p>nuevo aviso de prueba</p>',
        ])
    );
    assert_t('admin/terms 200', $s === 200, "got {$s}");

    // Verificar que el consultor tiene terms_pending=true tras publicacion
    [$s, $r] = http_call('GET', "{$base}/api/auth/me", $cookieFile);
    assert_t('auth/me 200', $s === 200);
    assert_t('terms_pending=true tras nueva version', ($r['data']['user']['terms_pending'] ?? false) === true);
    assert_t('terms_version refleja la nueva', ($r['data']['user']['terms_version'] ?? null) === $newVersion);

    [$s, $r] = http_call('POST', "{$base}/api/records/clockout", $cookieFile,
        ['X-CSRF-Token: ' . $csrf],
        json_encode([])
    );
    assert_t('clockout 403 TERMS_REQUIRED tras nueva version', $s === 403 && ($r['error']['code'] ?? '') === 'TERMS_REQUIRED');

    // Restaurar version original via super_admin (no podemos borrar via API actual,
    // pero podemos reactivar la 1.0 publicando una "1.0-restored" — para no contaminar
    // produccion, dejamos la nueva activa y avisamos en consola.
    echo "  NOTE: version {$newVersion} quedo como activa. Restaurar manualmente si lo deseas.\n";
    @unlink($adminCookie);
}

echo "\n=========================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
