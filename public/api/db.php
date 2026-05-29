<?php
declare(strict_types=1);

// =====================================================================
// db.php — Conexion PDO con soporte SQLite (local) y MySQL (HostGator).
// Toda la aplicacion usa esta misma instancia (singleton). Prepared
// statements obligatorios: cero string concatenation con datos de usuario.
// =====================================================================

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = env('DB_DRIVER', 'sqlite');

        try {
            if ($driver === 'sqlite') {
                $relPath = env('DB_SQLITE_PATH', 'storage/melius.db');
                $absPath = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                $dir = dirname($absPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $dsn = 'sqlite:' . $absPath;
                self::$pdo = new PDO($dsn);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
            } elseif ($driver === 'mysql') {
                $host    = env('DB_HOST', 'localhost');
                $port    = env_int('DB_PORT', 3306);
                $dbname  = env('DB_NAME', '');
                $user    = env('DB_USER', '');
                $pass    = env('DB_PASS', '');
                $charset = env('DB_CHARSET', 'utf8mb4');
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                self::$pdo = new PDO($dsn, $user, $pass);
            } else {
                throw new RuntimeException("Driver de DB no soportado: {$driver}");
            }

            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Por que: ATTR_EMULATE_PREPARES=false fuerza prepared statements
            // reales y previene una clase entera de bugs/inyecciones cuando se
            // mezclan tipos. SQLite ignora la opcion.
            self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // Auto-bootstrap del schema en SQLite si la DB es nueva.
            if ($driver === 'sqlite') {
                self::ensureSchemaSqlite();
            }
        } catch (Throwable $e) {
            error_log('[db] conexion fallida: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            exit(json_encode(['ok' => false, 'error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Base de datos no disponible.']]));
        }

        return self::$pdo;
    }

    private static function ensureSchemaSqlite(): void {
        $check = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $hasUsers = $check && $check->fetchColumn();
        if (!$hasUsers) {
            $schemaPath = __DIR__ . '/../../sql/schema.sqlite.sql';
            if (!is_readable($schemaPath)) {
                error_log("[db] schema.sqlite.sql no encontrado en {$schemaPath}");
                return;
            }
            $sql = file_get_contents($schemaPath);
            self::$pdo->exec($sql);
            return;
        }
        // Migraciones incrementales para DBs existentes (idempotentes via IF NOT EXISTS).
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS vacation_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                company_id INTEGER NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                days_count INTEGER NOT NULL,
                reason TEXT NULL,
                status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','rejected','cancelled')),
                decided_by INTEGER NULL,
                decided_at TEXT NULL,
                decision_note TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_vac_user ON vacation_requests(user_id)");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_vac_status ON vacation_requests(status, start_date)");
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL CHECK (event_type IN ('scraping','dom_manipulation','brute_force','bot_blocked','ip_blocked')),
                ip TEXT NOT NULL,
                user_agent TEXT NULL,
                uri TEXT NULL,
                user_id INTEGER NULL,
                detail TEXT NULL,
                reviewed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_secevt_type ON security_events(event_type, created_at)");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_secevt_ip ON security_events(ip, created_at)");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_secevt_reviewed ON security_events(reviewed, created_at)");
    }
}

/**
 * Helper para queries de un solo resultado.
 * Uso: $u = db_one('SELECT * FROM users WHERE id = ?', [$id]);
 */
function db_one(string $sql, array $params = []): ?array {
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Helper para queries de multiples resultados.
 */
function db_all(string $sql, array $params = []): array {
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Helper para INSERT/UPDATE/DELETE. Devuelve filas afectadas.
 */
function db_exec(string $sql, array $params = []): int {
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_last_id(): string {
    return Database::pdo()->lastInsertId();
}
