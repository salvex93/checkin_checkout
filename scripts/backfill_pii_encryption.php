<?php
declare(strict_types=1);

// Backfill de PII cifrada. Idempotente: solo procesa filas con email_hash NULL.
// Lee email/name (plaintext) -> escribe email_enc/email_hash/full_name_enc.
// Lotes de 100 con commit por lote para minimizar bloqueos.

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';
require_once __DIR__ . '/../public/api/helpers.php';
require_once __DIR__ . '/../public/api/crypto.php';

$pdo = Database::pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "[backfill_pii] driver: {$driver}\n";

// Verificacion previa: columnas existen
try {
    $pdo->query("SELECT email_hash FROM users LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: columna email_hash no existe. Corre migrate_pii_encryption.php primero.\n");
    exit(1);
}

$totalStmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE email_hash IS NULL OR email_hash = ''");
$total = (int)$totalStmt->fetch(PDO::FETCH_ASSOC)['c'];
echo "[backfill_pii] usuarios pendientes: {$total}\n";

if ($total === 0) { echo "[backfill_pii] nada que hacer\n"; exit(0); }

$batchSize = 100;
$processed = 0;
$errors = 0;

while (true) {
    $rows = $pdo->query("SELECT id, email, name FROM users WHERE email_hash IS NULL OR email_hash = '' LIMIT {$batchSize}")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE users SET email_enc = ?, email_hash = ?, full_name_enc = ? WHERE id = ?");
        foreach ($rows as $r) {
            $email = (string)($r['email'] ?? '');
            $name  = (string)($r['name'] ?? '');
            if ($email === '') { $errors++; continue; }
            $enc  = pii_encrypt($email);
            $hash = pii_hash($email);
            $nameEnc = $name !== '' ? pii_encrypt($name) : null;
            $upd->execute([$enc, $hash, $nameEnc, (int)$r['id']]);
            $processed++;
        }
        $pdo->commit();
        echo "  + lote OK: {$processed}/{$total}\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[backfill_pii] error en lote: " . $e->getMessage() . "\n");
        exit(2);
    }
}

echo "[backfill_pii] DONE. procesados={$processed} errores={$errors}\n";
