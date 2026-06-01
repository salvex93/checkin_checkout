<?php
declare(strict_types=1);

// Bootstrap de tests. Configura una BD SQLite en archivo temporal (no en memoria
// porque algunas paths hacen realpath y :memory: rompe). El archivo se borra al
// fin del proceso. Carga el schema canonico y opciones de test.

$projectRoot = dirname(__DIR__);

// Usamos un fichero temporal unico por proceso de test.
$dbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clockin_test_' . uniqid() . '.db';
register_shutdown_function(function () use ($dbFile) {
    if (file_exists($dbFile)) @unlink($dbFile);
});

// Sobrescribimos la ruta del SQLite ANTES de cargar config.
$relPath = 'storage/_test_' . basename($dbFile);
$_ENV['DB_SQLITE_PATH'] = $relPath;
putenv("DB_SQLITE_PATH={$relPath}");

// Crear el archivo en la ruta esperada por Database::pdo()
$storageDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
$testDbPath = $storageDir . DIRECTORY_SEPARATOR . '_test_' . basename($dbFile);
register_shutdown_function(function () use ($testDbPath) {
    if (file_exists($testDbPath)) @unlink($testDbPath);
});

require_once $projectRoot . '/public/api/config.php';
require_once $projectRoot . '/public/api/db.php';
require_once $projectRoot . '/public/api/helpers.php';
require_once $projectRoot . '/public/api/csrf.php';
require_once $projectRoot . '/public/api/mailer.php';
require_once $projectRoot . '/public/api/anti_bot.php';

// Cargar schema canonico SQLite.
$pdo = Database::pdo();
$schema = file_get_contents($projectRoot . '/sql/schema.sqlite.sql');
// El schema tiene multiples statements separados por punto y coma.
// Ejecutamos uno a uno descartando vacios.
foreach (preg_split('/;\s*\n/', (string)$schema) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        fwrite(STDERR, "Bootstrap warning: " . $e->getMessage() . "\n");
    }
}

// Seed minimo para tests de integracion. INSERT OR IGNORE porque el schema
// puede tener trigger/default que ya cree la fila id=1 en algunas tablas.
$pdo->exec("INSERT OR REPLACE INTO tenant_settings (id, product_name, primary_color, secondary_color)
            VALUES (1, 'Test Product', '#123456', '#abcdef')");
$pdo->exec("INSERT OR IGNORE INTO brands (id, slug, name, logo_url, primary_color, secondary_color, is_active)
            VALUES (1, 'test-brand', 'Test Brand', '/test.png', '#111111', '#222222', 1)");
$pdo->exec("INSERT OR IGNORE INTO companies (id, name, brand_id, timezone) VALUES (1, 'TestCo A', 1, 'America/Mexico_City')");
$pdo->exec("INSERT OR IGNORE INTO companies (id, name, brand_id, timezone) VALUES (2, 'TestCo B', 1, 'America/Mexico_City')");
$pdo->exec("INSERT OR IGNORE INTO companies (id, name, brand_id, timezone) VALUES (3, 'NoBrand', NULL, 'America/Mexico_City')");

// Helper global para resetear estado entre tests. function_exists guard porque
// el bootstrap puede recargarse en procesos aislados.
if (!function_exists('reset_test_db')) {
    function reset_test_db(): void {
        $pdo = Database::pdo();
        // Solo limpiamos tablas que mutan en tests; las semilla quedan.
        $pdo->exec('DELETE FROM rate_limits');
        $pdo->exec('DELETE FROM users');
    }
}
