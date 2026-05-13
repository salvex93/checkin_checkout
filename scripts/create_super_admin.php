<?php
declare(strict_types=1);

// =====================================================================
// scripts/create_super_admin.php
// Crea (o promueve) un super_admin con password temporal generada.
// Uso: php scripts/create_super_admin.php <email> "<Nombre Completo>"
// =====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("No disponible via HTTP.\n");
}

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php scripts/create_super_admin.php <email> \"<Nombre Completo>\"\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));
$name = trim($argv[2]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email invalido.\n");
    exit(1);
}
if ($name === '') {
    fwrite(STDERR, "El nombre no puede estar vacio.\n");
    exit(1);
}

// Generar password temporal de 14 chars: letras+digitos+simbolos.
// random_bytes -> base64 sin chars confusos.
function generate_temp_password(int $len = 14): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

$tempPass = generate_temp_password(14);
$hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);

$existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    db_exec(
        "UPDATE users
            SET role = 'super_admin',
                name = ?,
                password_hash = ?,
                is_active = 1,
                status = 'active',
                company_id = NULL,
                email_verified_at = CURRENT_TIMESTAMP,
                failed_attempts = 0,
                locked_until = NULL,
                must_change_password = 1,
                password_changed_at = NULL
          WHERE id = ?",
        [$name, $hash, $existing['id']]
    );
    echo "Usuario {$email} promovido a super_admin. Password temporal reseteada.\n";
} else {
    db_exec(
        "INSERT INTO users (email, name, password_hash, role, is_active, status,
                            company_id, email_verified_at, must_change_password)
         VALUES (?, ?, ?, 'super_admin', 1, 'active', NULL, CURRENT_TIMESTAMP, 1)",
        [$email, $name, $hash]
    );
    echo "Super admin {$email} creado.\n";
}

echo "\n=========================================\n";
echo "  CREDENCIALES TEMPORALES\n";
echo "=========================================\n";
echo "  Email:    {$email}\n";
echo "  Password: {$tempPass}\n";
echo "=========================================\n";
echo "Esta password se debera cambiar en el primer login.\n";
echo "Guardala en un gestor de contrasenas y NO la compartas.\n";
