<?php
declare(strict_types=1);

// =====================================================================
// scripts/create_admin.php — Crea o promueve a admin desde CLI.
// Uso (desde la raiz del proyecto):
//   php scripts/create_admin.php email@melius.com "Contraseña Segura"
// NUNCA se expone via web. En HostGator, ejecutar via SSH o via Cron one-shot.
// =====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("No disponible via HTTP.\n");
}

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

if ($argc < 3) {
    fwrite(STDERR, "Uso: php scripts/create_admin.php <email> <password>\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));
$password = $argv[2];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email invalido.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password debe tener al menos 10 caracteres.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    db_exec(
        'UPDATE users
            SET role = ?, password_hash = ?, is_active = 1, status = ?,
                email_verified_at = CURRENT_TIMESTAMP,
                failed_attempts = 0, locked_until = NULL
          WHERE id = ?',
        ['admin', $hash, 'active', $existing['id']]
    );
    echo "Usuario {$email} promovido a admin y password actualizado.\n";
} else {
    db_exec(
        'INSERT INTO users (email, name, password_hash, role, is_active, status, email_verified_at)
         VALUES (?, ?, ?, ?, 1, ?, CURRENT_TIMESTAMP)',
        [$email, 'Administrador', $hash, 'admin', 'active']
    );
    echo "Admin {$email} creado.\n";
}
