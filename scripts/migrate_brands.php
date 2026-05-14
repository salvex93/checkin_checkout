<?php
declare(strict_types=1);

// =====================================================================
// migrate_brands.php — Crea tabla brands y columna companies.brand_id.
// Idempotente: detecta existencia antes de crear. Hace seed inicial de
// marcas paraguas (melius, fullman, netfy) y asigna brand_id a las
// empresas existentes por nombre. Soporta SQLite y MySQL.
// Ejecucion: php scripts/migrate_brands.php
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function table_exists(PDO $pdo, string $driver, string $table): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function table_columns(PDO $pdo, string $driver, string $table): array {
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        return array_map(fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$report = [];

// 1) Tabla brands
if (!table_exists($pdo, $driver, 'brands')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE brands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            logo_url TEXT NOT NULL,
            primary_color TEXT NOT NULL DEFAULT '#2563eb',
            secondary_color TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE brands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            logo_url VARCHAR(255) NOT NULL,
            primary_color VARCHAR(9) NOT NULL DEFAULT '#2563eb',
            secondary_color VARCHAR(9) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $report[] = 'tabla brands creada';
}

// 1.5) Columna brands.welcome_intro (brief editable para el email de invitacion)
if (table_exists($pdo, $driver, 'brands')) {
    $brandCols = table_columns($pdo, $driver, 'brands');
    if (!in_array('welcome_intro', $brandCols, true)) {
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE brands ADD COLUMN welcome_intro TEXT NULL');
        } else {
            $pdo->exec('ALTER TABLE brands ADD COLUMN welcome_intro TEXT NULL');
        }
        $report[] = 'columna brands.welcome_intro creada';
    }
}

// 2) Columna companies.brand_id
$companyCols = table_columns($pdo, $driver, 'companies');
if (!in_array('brand_id', $companyCols, true)) {
    if ($driver === 'sqlite') {
        $pdo->exec('ALTER TABLE companies ADD COLUMN brand_id INTEGER NULL REFERENCES brands(id) ON DELETE SET NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_companies_brand ON companies(brand_id)');
    } else {
        $pdo->exec('ALTER TABLE companies ADD COLUMN brand_id INT NULL');
        $pdo->exec('ALTER TABLE companies ADD INDEX idx_companies_brand (brand_id)');
        $pdo->exec('ALTER TABLE companies ADD CONSTRAINT fk_companies_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL');
    }
    $report[] = 'columna companies.brand_id creada';
}

// 3) Seed marcas
$seedBrands = [
    ['melius',  'Melius Services',  '/assets/brands/melius.webp',  '#07d6da', '#9909fe'],
    ['fullman', 'Fullman Strategy', '/assets/brands/fullman.webp', '#65422a', '#f2e484'],
    ['netfy',   'Netfy Technology', '/assets/brands/netfy.webp',   '#06c7f4', '#2c2e3a'],
];
foreach ($seedBrands as [$slug, $name, $logo, $pri, $sec]) {
    $exists = db_one('SELECT id FROM brands WHERE slug = ?', [$slug]);
    if ($exists) continue;
    db_exec(
        'INSERT INTO brands (slug, name, logo_url, primary_color, secondary_color) VALUES (?, ?, ?, ?, ?)',
        [$slug, $name, $logo, $pri, $sec]
    );
    $report[] = "brand seed: {$slug}";
}

// 4) Mapeo cliente -> marca (idempotente: solo asigna si brand_id IS NULL)
$companyBrandMap = [
    'Melius Services' => 'melius',
    'Arajet'          => 'melius',
    'Elektra'         => 'melius',
    'Ecuaquimica'     => 'melius',
    'Coppel'          => 'fullman',
    'BanCoppel'       => 'fullman',
    'Hyatt'           => 'netfy',
    'Philip Morris'   => 'netfy',
];

foreach ($companyBrandMap as $companyName => $brandSlug) {
    $brand = db_one('SELECT id FROM brands WHERE slug = ?', [$brandSlug]);
    if (!$brand) continue;
    $brandId = (int)$brand['id'];

    $company = db_one('SELECT id, brand_id FROM companies WHERE name = ?', [$companyName]);

    if (!$company) {
        db_exec(
            "INSERT INTO companies (name, brand_id, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late)
             VALUES (?, ?, 'America/Mexico_City', '09:00', '18:00', 31, 15)",
            [$companyName, $brandId]
        );
        $report[] = "company creada: {$companyName} -> {$brandSlug}";
        continue;
    }

    if ($company['brand_id'] === null) {
        db_exec('UPDATE companies SET brand_id = ? WHERE id = ?', [$brandId, (int)$company['id']]);
        $report[] = "company vinculada: {$companyName} -> {$brandSlug}";
    }
}

echo $report
    ? "Migracion aplicada:\n - " . implode("\n - ", $report) . "\n"
    : "Schema ya estaba actualizado. Sin cambios.\n";
