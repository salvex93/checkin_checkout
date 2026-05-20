<?php
declare(strict_types=1);

// Migracion idempotente: T&C versionados, registro de aceptacion por usuario,
// y columnas de geolocalizacion (IP+pais) en attendance_records y overtime_requests.
// Detecta INFORMATION_SCHEMA/pragma antes de cada ALTER para no fallar si ya existe.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "[migrate_terms_and_geo] driver detectado: {$driver}\n";

if ($driver === 'sqlite') {
    migrate_sqlite($pdo);
} elseif ($driver === 'mysql') {
    migrate_mysql($pdo);
} else {
    fwrite(STDERR, "Driver no soportado: {$driver}\n");
    exit(1);
}

seed_terms_v1($pdo);
echo "[migrate_terms_and_geo] OK\n";

function migrate_sqlite(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS terms_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        body_html TEXT NOT NULL,
        privacy_html TEXT NOT NULL,
        published_at TEXT DEFAULT CURRENT_TIMESTAMP,
        is_active INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_terms_acceptance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        terms_version_id INTEGER NOT NULL,
        accepted_at TEXT DEFAULT CURRENT_TIMESTAMP,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        UNIQUE (user_id, terms_version_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (terms_version_id) REFERENCES terms_versions(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_uta_user ON user_terms_acceptance(user_id)");

    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_country_code', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_country_name', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_ip_masked',    'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'attendance_records', 'geo_source',       "TEXT NULL");

    add_sqlite_column_if_missing($pdo, 'overtime_requests', 'geo_country_code', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'overtime_requests', 'geo_country_name', 'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'overtime_requests', 'geo_ip_masked',    'TEXT NULL');
    add_sqlite_column_if_missing($pdo, 'overtime_requests', 'geo_source',       'TEXT NULL');
}

function add_sqlite_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($col, $cols, true)) {
        echo "  - {$table}.{$col} ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}

function migrate_mysql(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS terms_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(20) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        body_html LONGTEXT NOT NULL,
        privacy_html LONGTEXT NOT NULL,
        published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_terms_acceptance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        terms_version_id INT NOT NULL,
        accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        UNIQUE KEY uq_user_version (user_id, terms_version_id),
        INDEX idx_uta_user (user_id),
        CONSTRAINT fk_uta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_uta_version FOREIGN KEY (terms_version_id) REFERENCES terms_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_country_code', 'CHAR(2) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_country_name', 'VARCHAR(80) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_ip_masked',    'VARCHAR(45) NULL');
    add_mysql_column_if_missing($pdo, 'attendance_records', 'geo_source',       "VARCHAR(10) NULL");

    add_mysql_column_if_missing($pdo, 'overtime_requests', 'geo_country_code', 'CHAR(2) NULL');
    add_mysql_column_if_missing($pdo, 'overtime_requests', 'geo_country_name', 'VARCHAR(80) NULL');
    add_mysql_column_if_missing($pdo, 'overtime_requests', 'geo_ip_masked',    'VARCHAR(45) NULL');
    add_mysql_column_if_missing($pdo, 'overtime_requests', 'geo_source',       'VARCHAR(10) NULL');
}

function add_mysql_column_if_missing(PDO $pdo, string $table, string $col, string $type): void {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "  - {$table}.{$col} ya existe\n";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    echo "  + {$table}.{$col} agregada\n";
}

function seed_terms_v1(PDO $pdo): void {
    $exists = $pdo->query("SELECT id FROM terms_versions WHERE version = '1.0'")->fetch();
    if ($exists) {
        echo "  - terms_versions 1.0 ya existe\n";
        return;
    }
    $title = 'Terminos y Condiciones de Uso';
    $body = <<<HTML
<h3>1. Aceptacion del servicio</h3>
<p>Al usar la plataforma de control de jornada (en adelante, "el Servicio"), aceptas estos Terminos y el Aviso de Privacidad. El Servicio es operado por Melius Services y sus marcas asociadas (Fullman Strategy, Netfy Technology).</p>

<h3>2. Uso aceptable</h3>
<p>El acceso al Servicio se otorga unicamente a consultores, administradores y personal autorizado por la empresa contratante. Queda prohibido compartir credenciales, automatizar marcajes o suplantar identidad. El incumplimiento puede derivar en suspension de cuenta y acciones legales.</p>

<h3>3. Registros de jornada</h3>
<p>Cada marcaje (entrada, salida y horas extra) queda registrado con fecha, hora, zona horaria del dispositivo y pais de origen de la conexion. Esta informacion es prueba laboral conforme a la legislacion aplicable.</p>

<h3>4. Geolocalizacion por IP</h3>
<p>Para verificar el cumplimiento de la jornada, el Servicio registra el pais desde el cual te conectas mediante la resolucion de tu direccion IP. NO se utiliza GPS, posicion satelital ni ubicacion precisa. Esta informacion es visible para tu administrador y se conserva durante el periodo de la relacion laboral mas el plazo legal de archivo.</p>

<h3>5. Modificaciones</h3>
<p>Estos terminos pueden actualizarse. Cuando se publique una nueva version, se te pedira aceptarla antes de continuar usando el Servicio.</p>

<h3>6. Contacto</h3>
<p>Para dudas o ejercicio de derechos ARCO, escribe a noreply@fullman.tech.</p>
HTML;

    $privacy = <<<HTML
<h3>Aviso de Privacidad Simplificado</h3>
<p><strong>Responsable:</strong> Melius Services.</p>
<p><strong>Datos personales recabados:</strong> nombre, correo corporativo, empresa de adscripcion, registros de jornada, zona horaria del dispositivo, direccion IP y pais de conexion.</p>
<p><strong>Finalidades primarias:</strong> control de asistencia, validacion de cumplimiento de horario, generacion de reportes para Recursos Humanos.</p>
<p><strong>Finalidades secundarias:</strong> ninguna. No se ceden datos a terceros con fines comerciales.</p>
<p><strong>Transferencias:</strong> los datos son procesados en infraestructura de hosting contratada por Melius Services y no se transfieren fuera del grupo.</p>
<p><strong>Derechos ARCO:</strong> puedes solicitar Acceso, Rectificacion, Cancelacion u Oposicion al tratamiento escribiendo a noreply@fullman.tech.</p>
<p>Marco legal aplicable: Ley Federal de Proteccion de Datos Personales en Posesion de los Particulares (Mexico) y disposiciones laborales locales que correspondan a tu pais de residencia.</p>
HTML;

    $pdo->exec("UPDATE terms_versions SET is_active = 0");
    $stmt = $pdo->prepare(
        "INSERT INTO terms_versions (version, title, body_html, privacy_html, is_active)
              VALUES (?, ?, ?, ?, 1)"
    );
    $stmt->execute(['1.0', $title, $body, $privacy]);
    echo "  + terms_versions 1.0 sembrada como activa\n";
}
