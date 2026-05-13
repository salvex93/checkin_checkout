<?php
declare(strict_types=1);

// =====================================================================
// scripts/assign_company_to_super_admin.php
// Asigna company_id de "Melius Services" al super_admin indicado para
// habilitar el flujo de marcado de jornada (tarea #35, Opcion B).
// Idempotente: si ya esta asignado correctamente, no hace cambios.
// El super_admin sigue siendo "ghost" en listados para admins normales;
// solo se le anade empresa para poder marcar jornada como empleado.
// Uso: php scripts/assign_company_to_super_admin.php <email> [<nombre_empresa>]
// =====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("No disponible via HTTP.\n");
}

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';

$email = isset($argv[1]) ? strtolower(trim($argv[1])) : '';
$companyName = isset($argv[2]) ? trim($argv[2]) : 'Melius Services';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/assign_company_to_super_admin.php <email> [<nombre_empresa>]\n");
    fwrite(STDERR, "Email invalido o ausente.\n");
    exit(1);
}

$user = db_one('SELECT id, email, role, company_id FROM users WHERE email = ?', [$email]);
if (!$user) {
    fwrite(STDERR, "Usuario {$email} no existe.\n");
    exit(1);
}
if ($user['role'] !== 'super_admin') {
    fwrite(STDERR, "Usuario {$email} no es super_admin (role={$user['role']}). Abortando.\n");
    exit(1);
}

$company = db_one('SELECT id, name FROM companies WHERE name = ?', [$companyName]);
if (!$company) {
    fwrite(STDERR, "Empresa '{$companyName}' no existe en la tabla companies. Corre antes scripts/migrate_super_admin.php\n");
    exit(1);
}

if ((int)($user['company_id'] ?? 0) === (int)$company['id']) {
    echo "Sin cambios: {$email} ya tiene company_id={$company['id']} ({$company['name']}).\n";
    exit(0);
}

db_exec('UPDATE users SET company_id = ? WHERE id = ?', [(int)$company['id'], (int)$user['id']]);
echo "OK: super_admin {$email} asignado a empresa '{$company['name']}' (id={$company['id']}).\n";
echo "Sigue oculto en listados para admins normales; ahora puede marcar jornada como empleado.\n";
