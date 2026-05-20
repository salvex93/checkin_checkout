<?php
declare(strict_types=1);

// Tests unitarios puros de geo.php. NO toca red ni BD.
// Mockeamos geo_http_get definiendola ANTES de cargar geo.php (require_once respeta esto).
// Ejecutar: C:\xampp\php\php.exe scripts\test_geo_unit.php

$GLOBALS['__geo_http_mock'] = null;
function geo_http_get(string $url): ?string {
    $mock = $GLOBALS['__geo_http_mock'] ?? null;
    if (is_callable($mock)) return $mock($url);
    return $mock;
}

require_once __DIR__ . '/../public/api/geo.php';

$passed = 0;
$failed = 0;
$failures = [];

function assert_eq($expected, $actual, string $name): void {
    global $passed, $failed, $failures;
    if ($expected === $actual) {
        $passed++;
        echo "  OK  {$name}\n";
    } else {
        $failed++;
        $failures[] = $name;
        echo "  FAIL {$name}\n";
        echo "       esperado: " . var_export($expected, true) . "\n";
        echo "       obtenido: " . var_export($actual, true) . "\n";
    }
}

// === mask_ip ===
echo "[mask_ip]\n";
assert_eq('192.168.1.0', geo_mask_ip('192.168.1.45'), 'mask_ip_ipv4_zeroes_last_octet');
assert_eq('8.8.8.0',     geo_mask_ip('8.8.8.8'),      'mask_ip_ipv4_public');
assert_eq(null,          geo_mask_ip(''),             'mask_ip_empty_returns_null');
assert_eq(null,          geo_mask_ip('not-an-ip'),    'mask_ip_invalid_returns_null');
assert_eq(null,          geo_mask_ip(null),           'mask_ip_null_input');
$ipv6Masked = geo_mask_ip('2001:db8:85a3:8d3:1319:8a2e:370:7348');
assert_eq('2001:db8:85a3:8d3::', $ipv6Masked, 'mask_ip_ipv6_keeps_first_4_groups');

// === validate_country_code ===
echo "[validate_country_code]\n";
assert_eq('MX', geo_validate_country_code('mx'),  'validate_country_code_lowercase_to_upper');
assert_eq('US', geo_validate_country_code('US'),  'validate_country_code_already_upper');
assert_eq(null, geo_validate_country_code('MEX'), 'validate_country_code_3_chars_rejected');
assert_eq(null, geo_validate_country_code(''),    'validate_country_code_empty_rejected');
assert_eq(null, geo_validate_country_code('M1'),  'validate_country_code_with_digit_rejected');
assert_eq(null, geo_validate_country_code(null),  'validate_country_code_null_rejected');

// === is_local_ip ===
echo "[is_local_ip]\n";
assert_eq(true,  geo_is_local_ip('127.0.0.1'),    'is_local_ip_loopback');
assert_eq(true,  geo_is_local_ip('192.168.1.50'), 'is_local_ip_private_class_c');
assert_eq(true,  geo_is_local_ip('10.0.0.5'),     'is_local_ip_private_class_a');
assert_eq(true,  geo_is_local_ip('172.16.5.1'),   'is_local_ip_private_class_b');
assert_eq(false, geo_is_local_ip('8.8.8.8'),      'is_local_ip_public');
assert_eq(true,  geo_is_local_ip(''),             'is_local_ip_empty_treated_local');

// === geo_resolve con mock ===
echo "[geo_resolve]\n";

// 1. IP local: nunca llama al endpoint
$GLOBALS['__geo_http_mock'] = fn($u) => '{"status":"success","country":"X","countryCode":"XX"}';
$r = geo_resolve('127.0.0.1');
assert_eq(null,    $r['country_code'], 'resolve_local_ip_returns_null_country');
assert_eq('none',  $r['source'],       'resolve_local_ip_source_none');

// 2. Respuesta exitosa con codigo valido
$GLOBALS['__geo_http_mock'] = fn($u) => '{"status":"success","country":"Mexico","countryCode":"MX"}';
$r = geo_resolve('200.1.2.3');
assert_eq('MX',     $r['country_code'], 'resolve_success_country_code');
assert_eq('Mexico', $r['country_name'], 'resolve_success_country_name');
assert_eq('200.1.2.0', $r['ip_masked'], 'resolve_success_masks_ip');
assert_eq('ip',     $r['source'],       'resolve_success_source_ip');

// 3. Status fail
$GLOBALS['__geo_http_mock'] = fn($u) => '{"status":"fail","message":"invalid query"}';
$r = geo_resolve('200.1.2.3');
assert_eq(null,    $r['country_code'], 'resolve_status_fail_no_country');
assert_eq('none',  $r['source'],       'resolve_status_fail_source_none');
assert_eq('200.1.2.0', $r['ip_masked'], 'resolve_status_fail_still_masks_ip');

// 4. Body no es JSON valido
$GLOBALS['__geo_http_mock'] = fn($u) => '<html>500</html>';
$r = geo_resolve('200.1.2.3');
assert_eq(null,    $r['country_code'], 'resolve_invalid_json_degrades');
assert_eq('none',  $r['source'],       'resolve_invalid_json_source_none');

// 5. Timeout simulado (null)
$GLOBALS['__geo_http_mock'] = fn($u) => null;
$r = geo_resolve('200.1.2.3');
assert_eq(null,    $r['country_code'], 'resolve_timeout_degrades');
assert_eq('none',  $r['source'],       'resolve_timeout_source_none');

// 6. Codigo invalido en respuesta exitosa
$GLOBALS['__geo_http_mock'] = fn($u) => '{"status":"success","country":"X","countryCode":"MEX"}';
$r = geo_resolve('200.1.2.3');
assert_eq(null,    $r['country_code'], 'resolve_invalid_country_code_degrades');
assert_eq('none',  $r['source'],       'resolve_invalid_country_code_source_none');

echo "\n=========================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
if ($failed > 0) {
    echo "Fallos:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
exit(0);
