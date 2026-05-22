<?php
declare(strict_types=1);

// =====================================================================
// crypto.php — Capa de cifrado de PII en reposo.
// AES-256-GCM via openssl_encrypt (AEAD = confidencialidad + integridad).
// Formato del ciphertext almacenado: v1:base64(iv|tag|cipher)
//   - iv  = 12 bytes (recomendacion NIST para GCM)
//   - tag = 16 bytes (autenticacion)
//   - cipher = bytes del texto cifrado
// Para busquedas indexadas (login por email) usamos HMAC-SHA256
// deterministico con clave separada (PII_HMAC_KEY). El email crudo nunca
// se almacena; solo email_enc (reversible) y email_hash (lookup).
// =====================================================================

function pii_keys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $encB64 = env('PII_ENC_KEY', '');
    $hmacB64 = env('PII_HMAC_KEY', '');
    if ($encB64 === '' || $hmacB64 === '') {
        // En produccion, ausencia de claves rompe deliberadamente: PII no se
        // puede cifrar sin claves y trabajar con plaintext violaria la migracion.
        if (defined('IS_PROD') && IS_PROD) {
            http_response_code(500);
            error_log('[crypto] PII_ENC_KEY o PII_HMAC_KEY ausentes en produccion');
            exit(json_encode(['ok' => false, 'error' => ['code' => 'CRYPTO_KEYS_MISSING', 'message' => 'Configuracion invalida.']]));
        }
        // En dev: derivacion local desde APP_KEY para no bloquear el entorno.
        $seed = defined('APP_KEY') ? (string)APP_KEY : 'dev-seed';
        $enc = hash('sha256', $seed . ':enc', true);
        $hmac = hash('sha256', $seed . ':hmac', true);
    } else {
        $enc = base64_decode($encB64, true);
        $hmac = base64_decode($hmacB64, true);
    }
    if ($enc === false || strlen($enc) !== 32) {
        throw new RuntimeException('PII_ENC_KEY debe ser 32 bytes en base64');
    }
    if ($hmac === false || strlen($hmac) !== 32) {
        throw new RuntimeException('PII_HMAC_KEY debe ser 32 bytes en base64');
    }
    $cache = ['enc' => $enc, 'hmac' => $hmac];
    return $cache;
}

/**
 * Cifra texto PII con AES-256-GCM. Devuelve string con prefijo de version
 * para permitir rotacion futura sin migracion forzada.
 * @throws RuntimeException si openssl no soporta GCM o la operacion falla.
 */
function pii_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $keys = pii_keys();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $keys['enc'], OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('pii_encrypt: openssl_encrypt fallo');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

/**
 * Descifra un valor producido por pii_encrypt. Si el valor parece plaintext
 * (sin prefijo de version), lo devuelve tal cual para compatibilidad
 * durante el periodo de backfill.
 */
function pii_decrypt(?string $stored): ?string {
    if ($stored === null || $stored === '') return $stored;
    if (!str_starts_with($stored, 'v1:')) {
        // Compatibilidad: dato aun no migrado.
        return $stored;
    }
    $blob = base64_decode(substr($stored, 3), true);
    if ($blob === false || strlen($blob) < 12 + 16 + 1) {
        throw new RuntimeException('pii_decrypt: blob malformado');
    }
    $iv = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $cipher = substr($blob, 28);
    $keys = pii_keys();
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $keys['enc'], OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        // Tag invalido o clave incorrecta: posible tampering.
        throw new RuntimeException('pii_decrypt: autenticacion fallida');
    }
    return $plain;
}

/**
 * Hash deterministico para busquedas indexadas. Normaliza con strtolower+trim
 * para que la BD pueda buscar emails sin importar casing/espacios.
 * Es deterministico por diseño: la misma entrada da la misma salida
 * (necesario para login). Compensa con HMAC + clave secreta para que
 * un atacante con acceso a la BD no pueda hacer fuerza bruta por diccionario.
 */
function pii_hash(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;
    $keys = pii_keys();
    $norm = strtolower(trim($plain));
    return hash_hmac('sha256', $norm, $keys['hmac']);
}

/**
 * Devuelve el fragmento SELECT con columnas PII opcionales, listo para
 * concatenar en una query con prefijo de tabla (p.ej. 'u'). Si la migracion
 * no corrio, devuelve cadena vacia. Si corrio, agrega email_enc y full_name_enc
 * con coma trailing para insertar entre selects normales.
 */
function pii_columns_select(string $alias = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = false;
        try {
            $driver = Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $r = db_one("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_enc'");
                $cache = ((int)($r['c'] ?? 0)) > 0;
            } else {
                $r = db_all("PRAGMA table_info(users)");
                foreach ($r as $col) {
                    if (($col['name'] ?? '') === 'email_enc') { $cache = true; break; }
                }
            }
        } catch (Throwable $e) { $cache = false; }
    }
    if (!$cache) return '';
    $pre = $alias !== '' ? ($alias . '.') : '';
    return "{$pre}email_enc, {$pre}full_name_enc,";
}

/**
 * Lookup centralizado de usuario por email. Internamente busca por
 * email_hash si la columna existe, con fallback a WHERE email = ? para
 * entornos que aun no corrieron la migracion.
 *
 * Devuelve la fila completa con email y full_name descifrados (si estaban
 * cifrados) en las claves estandar 'email' y 'name'.
 */
function db_user_by_email(string $email, string $columns = '*'): ?array {
    static $hasHash = null;
    if ($hasHash === null) {
        try {
            $driver = Database::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $r = db_one("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_hash'");
                $hasHash = ((int)($r['c'] ?? 0)) > 0;
            } else {
                $r = db_all("PRAGMA table_info(users)");
                $hasHash = false;
                foreach ($r as $col) {
                    if (($col['name'] ?? '') === 'email_hash') { $hasHash = true; break; }
                }
            }
        } catch (Throwable $e) {
            $hasHash = false;
        }
    }
    $row = null;
    if ($hasHash) {
        $hash = pii_hash($email);
        $row = db_one("SELECT {$columns} FROM users WHERE email_hash = ? LIMIT 1", [$hash]);
    }
    if (!$row) {
        // Fallback: usuario sin backfill o entorno pre-migracion.
        $row = db_one("SELECT {$columns} FROM users WHERE email = ? LIMIT 1", [$email]);
    }
    return $row ? user_decrypt_pii($row) : null;
}

/**
 * Descifra in-place columnas PII de una fila de usuario. Mantiene las
 * claves 'email' y 'name' apuntando al valor en claro para que el resto
 * del codigo no necesite saber del cifrado.
 */
function user_decrypt_pii(array $row): array {
    if (isset($row['email_enc']) && $row['email_enc'] !== '' && $row['email_enc'] !== null) {
        try { $row['email'] = pii_decrypt($row['email_enc']); } catch (Throwable $e) { /* mantener email plaintext */ }
    }
    if (isset($row['full_name_enc']) && $row['full_name_enc'] !== '' && $row['full_name_enc'] !== null) {
        try { $row['name'] = pii_decrypt($row['full_name_enc']); } catch (Throwable $e) { /* mantener name plaintext */ }
    }
    return $row;
}
