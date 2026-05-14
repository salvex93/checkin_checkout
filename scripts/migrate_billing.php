<?php
declare(strict_types=1);

// =====================================================================
// migrate_billing.php — Tablas para licenciamiento mensual.
//   subscription_plans : catalogo de planes disponibles (free, pro, etc.)
//   subscriptions      : estado actual del tenant (uno por tenant)
// Sin integracion real con Stripe/PayPal aun. Esquema agnostico que
// soporta cualquier proveedor via campos genericos.
// Idempotente. SQLite y MySQL.
// =====================================================================

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

function bil_table_exists(PDO $pdo, string $driver, string $table): bool {
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }
    $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

$report = [];

// 1) Tabla subscription_plans (catalogo). Codigo: 'free', 'starter', 'pro'.
if (!bil_table_exists($pdo, $driver, 'subscription_plans')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE subscription_plans (
            code TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            price_monthly_cents INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'USD',
            max_users INTEGER NULL,
            max_companies INTEGER NULL,
            features TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )");
    } else {
        $pdo->exec("CREATE TABLE subscription_plans (
            code VARCHAR(40) PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            price_monthly_cents INT NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            max_users INT NULL,
            max_companies INT NULL,
            features TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $report[] = 'tabla subscription_plans creada';
}

// 2) Seed planes default. Solo si la tabla esta vacia.
$count = (int)$pdo->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn();
if ($count === 0) {
    $plans = [
        ['free', 'Trial', 0, 'USD', 5, 1, 'Hasta 5 usuarios, 1 empresa. Sin pagos.', 1, 0],
        ['starter', 'Starter', 2900, 'USD', 25, 3, 'Hasta 25 usuarios, 3 empresas, 1 marca paraguas.', 1, 10],
        ['pro', 'Pro', 9900, 'USD', 100, 10, 'Hasta 100 usuarios, 10 empresas, marcas ilimitadas.', 1, 20],
        ['enterprise', 'Enterprise', 0, 'USD', null, null, 'Usuarios y empresas ilimitados. Precio bajo cotizacion.', 1, 30],
    ];
    $stmt = $pdo->prepare('INSERT INTO subscription_plans (code, name, price_monthly_cents, currency, max_users, max_companies, features, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($plans as $p) $stmt->execute($p);
    $report[] = 'seed inicial: 4 planes (trial, starter, pro, enterprise)';
}

// 3) Tabla subscriptions (estado del tenant, fila singleton id=1 por ahora).
//    provider: 'none' | 'stripe' | 'paypal' | 'manual'
//    status:   'trial' | 'active' | 'past_due' | 'canceled' | 'suspended'
if (!bil_table_exists($pdo, $driver, 'subscriptions')) {
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE subscriptions (
            id INTEGER PRIMARY KEY,
            plan_code TEXT NOT NULL DEFAULT 'free',
            provider TEXT NOT NULL DEFAULT 'none',
            provider_customer_id TEXT NULL,
            provider_subscription_id TEXT NULL,
            status TEXT NOT NULL DEFAULT 'trial',
            current_period_start TEXT NULL,
            current_period_end TEXT NULL,
            cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
            metadata TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (plan_code) REFERENCES subscription_plans(code)
        )");
    } else {
        $pdo->exec("CREATE TABLE subscriptions (
            id INT PRIMARY KEY,
            plan_code VARCHAR(40) NOT NULL DEFAULT 'free',
            provider VARCHAR(20) NOT NULL DEFAULT 'none',
            provider_customer_id VARCHAR(120) NULL,
            provider_subscription_id VARCHAR(120) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'trial',
            current_period_start DATETIME NULL,
            current_period_end DATETIME NULL,
            cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
            metadata TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_code) REFERENCES subscription_plans(code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $report[] = 'tabla subscriptions creada';
}

// 4) Seed fila singleton id=1 (estado inicial: trial).
$existing = $pdo->query('SELECT id FROM subscriptions WHERE id = 1')->fetchColumn();
if (!$existing) {
    $pdo->exec("INSERT INTO subscriptions (id, plan_code, provider, status) VALUES (1, 'free', 'none', 'trial')");
    $report[] = 'subscription id=1 seedeada con plan free + status trial';
}

if (empty($report)) {
    echo "Sin cambios. Migracion billing ya aplicada.\n";
} else {
    echo "Cambios aplicados:\n";
    foreach ($report as $r) echo " - {$r}\n";
}
