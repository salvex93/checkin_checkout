<?php
declare(strict_types=1);

// =====================================================================
// migrate_email_templates.php — Crea tabla email_templates y siembra
// 4 plantillas por marca con el copy actual de mailer.php. Idempotente.
// Ejecucion: php scripts/migrate_email_templates.php
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function tpl_table_exists(PDO $pdo, string $driver): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute(['email_templates']);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['email_templates']);
    return $stmt->fetchColumn() !== false;
}

$report = [];

if (!tpl_table_exists($pdo, $driver)) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_id INTEGER NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('invitation','password_reset','admin_disabled','admin_delete_receipt')),
            subject TEXT NOT NULL,
            intro_html TEXT NOT NULL,
            cta_label TEXT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER NULL,
            UNIQUE (brand_id, kind),
            FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        )");
        $pdo->exec("CREATE INDEX idx_etpl_brand ON email_templates(brand_id)");
    } else {
        $pdo->exec("CREATE TABLE email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand_id INT NOT NULL,
            kind ENUM('invitation','password_reset','admin_disabled','admin_delete_receipt') NOT NULL,
            subject VARCHAR(200) NOT NULL,
            intro_html TEXT NOT NULL,
            cta_label VARCHAR(80) NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT NULL,
            UNIQUE KEY uq_brand_kind (brand_id, kind),
            INDEX idx_etpl_brand (brand_id),
            CONSTRAINT fk_etpl_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
            CONSTRAINT fk_etpl_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $report[] = 'tabla email_templates creada';
} else {
    $report[] = 'tabla email_templates ya existia';
}

// Seed: una fila por (brand, kind) con el copy actual. Si ya existe, no toca.
$seeds = [
    'invitation' => [
        'subject' => 'Bienvenido a {{brand_name}} Clockin · {{company}}',
        'intro_html' => "Tu equipo en {{company}} esta usando {{brand_name}} Clockin para marcar jornada de forma sencilla. Acabas de ser invitado a sumarte.",
        'cta_label' => 'Entrar a {{brand_name}} Clockin',
    ],
    'password_reset' => [
        'subject' => 'Restablecer contrasena - {{brand_name}} Clockin',
        'intro_html' => "Recibimos una solicitud para restablecer tu contrasena en {{brand_name}} Clockin. Si no fuiste tu, ignora este correo.",
        'cta_label' => 'Restablecer contrasena',
    ],
    'admin_disabled' => [
        'subject' => 'Tu cuenta de administrador fue desactivada · {{brand_name}} Clockin',
        'intro_html' => "Tu cuenta de administrador en {{brand_name}} Clockin ({{company}}) fue desactivada por {{actor_name}}. A partir de este momento no podras iniciar sesion. Tus registros historicos se conservan.",
        'cta_label' => null,
    ],
    'admin_delete_receipt' => [
        'subject' => 'Confirmacion: desactivaste a {{target_email}} · {{brand_name}} Clockin',
        'intro_html' => "Confirmamos que desactivaste la cuenta de administrador de {{target_name}} ({{target_email}}) en {{company}}. La desactivacion es reversible desde el panel admin (status active). Los registros historicos del usuario se conservan.",
        'cta_label' => null,
    ],
];

$brands = $pdo->query('SELECT id, slug, name FROM brands ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (!$brands) {
    fwrite(STDERR, "ERROR: no hay marcas en la DB. Ejecuta primero scripts/migrate_brands.php\n");
    exit(1);
}

$insertSql = 'INSERT INTO email_templates (brand_id, kind, subject, intro_html, cta_label) VALUES (?, ?, ?, ?, ?)';
$existsSql = 'SELECT id FROM email_templates WHERE brand_id = ? AND kind = ?';
$ins = $pdo->prepare($insertSql);
$chk = $pdo->prepare($existsSql);

$created = 0;
$skipped = 0;
foreach ($brands as $brand) {
    foreach ($seeds as $kind => $tpl) {
        $chk->execute([$brand['id'], $kind]);
        if ($chk->fetchColumn()) { $skipped++; continue; }
        $ins->execute([$brand['id'], $kind, $tpl['subject'], $tpl['intro_html'], $tpl['cta_label']]);
        $created++;
    }
}

$report[] = "seeds: {$created} creadas, {$skipped} ya existian";

echo "Migracion email_templates completada.\n";
foreach ($report as $line) echo "  - {$line}\n";

$total = (int)$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();
echo "Total filas en email_templates: {$total}\n";
