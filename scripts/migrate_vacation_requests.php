<?php
declare(strict_types=1);

// Migracion: crea la tabla vacation_requests que sustituye al modulo
// de horas extra. La tabla overtime_requests se conserva por historicos.

require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'mysql') {
    $sql = "CREATE TABLE IF NOT EXISTS vacation_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        days_count SMALLINT UNSIGNED NOT NULL,
        reason VARCHAR(500) NULL,
        status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        decision_note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_vac_user (user_id),
        KEY idx_vac_company (company_id),
        KEY idx_vac_status (status),
        KEY idx_vac_range (start_date, end_date),
        CONSTRAINT fk_vac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_vac_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        CONSTRAINT fk_vac_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
} else {
    // SQLite
    $pdo->exec("CREATE TABLE IF NOT EXISTS vacation_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        company_id INTEGER NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        days_count INTEGER NOT NULL,
        reason TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        decided_by INTEGER NULL,
        decided_at TEXT NULL,
        decision_note TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vac_user ON vacation_requests(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vac_company ON vacation_requests(company_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vac_status ON vacation_requests(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vac_range ON vacation_requests(start_date, end_date)');
}

echo "OK vacation_requests creada (driver={$driver})\n";
