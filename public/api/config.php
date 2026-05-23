<?php
declare(strict_types=1);

// =====================================================================
// config.php — Carga del archivo .env y constantes globales.
// Se invoca al inicio de cada request via require_once en index.php.
// =====================================================================

/**
 * Lee el archivo .env del proyecto y lo carga a $_ENV.
 * Implementacion minima sin dependencias externas (no usamos vlucas/phpdotenv
 * para evitar requerir composer en HostGator). Soporta:
 *   - KEY=VALUE
 *   - Comentarios con # al inicio de linea
 *   - Lineas vacias
 * Sin interpolacion ni multi-linea: si necesitas un secreto con caracteres
 * raros, encodealo en base64 y decodealo en el codigo que lo consume.
 */
function load_env(string $path): void {
    if (!is_readable($path)) {
        // En produccion el archivo .env ES obligatorio. En desarrollo permitimos
        // ausencia si las variables ya estan en el entorno del sistema.
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        // Quitar comillas envolventes opcionales
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

function env(string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}

function env_bool(string $key, bool $default = false): bool {
    $v = env($key);
    if ($v === null) return $default;
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function env_int(string $key, int $default = 0): int {
    $v = env($key);
    return $v === null ? $default : (int)$v;
}

// Cargar .env desde la raiz del proyecto (dos niveles arriba de api/)
load_env(__DIR__ . '/../../.env');
// Sobrecarga opcional con .env.pii: archivo separado para claves de cifrado
// PII. Permite gestionar las claves sin tocar el .env principal y rotar
// independientemente. Si el archivo no existe, no pasa nada.
load_env(__DIR__ . '/../../.env.pii');

// Constantes de la aplicacion
define('APP_ENV', env('APP_ENV', 'production'));
define('IS_PROD', APP_ENV === 'production');
define('APP_KEY', env('APP_KEY', ''));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'andrew.arizmendi@meliusservices.com'));

// Validacion critica de configuracion en produccion
if (IS_PROD && (APP_KEY === '' || strlen(APP_KEY) < 32)) {
    http_response_code(500);
    // Mensaje deliberadamente generico al cliente; el detalle queda en log servidor.
    error_log('[config] APP_KEY ausente o demasiado corta en produccion.');
    exit(json_encode(['ok' => false, 'error' => ['code' => 'CONFIG_ERROR', 'message' => 'Configuracion invalida.']]));
}
