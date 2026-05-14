<?php
declare(strict_types=1);

// =====================================================================
// tenant.php — Branding white-label en 2 niveles.
//   GET  /branding                          publico (sin auth) para login
//   GET  /admin/tenant-settings             super_admin lee config
//   PUT  /admin/tenant-settings             super_admin guarda config
//   POST /admin/tenant-settings/logo        super_admin sube logo tenant
//   PUT  /admin/companies/{id}/branding     super_admin o admin de empresa
// El branding efectivo se resuelve por cascada: empresa.branding_* >
// marca paraguas > tenant > defaults Melius.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Lee la fila singleton de tenant_settings. Si no existe (instalacion vieja
 * sin migracion ejecutada) devuelve defaults Melius sin crear nada.
 */
function tenant_load(): array {
    $row = db_one('SELECT product_name, logo_url, primary_color, secondary_color FROM tenant_settings WHERE id = 1');
    if (!$row) {
        return [
            'product_name' => 'Melius Clockin',
            'logo_url' => null,
            'primary_color' => '#07d6da',
            'secondary_color' => '#9909fe',
        ];
    }
    return [
        'product_name' => (string)$row['product_name'],
        'logo_url' => $row['logo_url'] ? absolute_asset_url((string)$row['logo_url']) : null,
        'primary_color' => (string)$row['primary_color'],
        'secondary_color' => $row['secondary_color'] !== null ? (string)$row['secondary_color'] : null,
    ];
}

/** GET /branding — publico (pre-login). */
function tenant_public_branding(): never {
    ok(['branding' => tenant_load()]);
}

/** GET /admin/tenant-settings — super_admin lee config para editar. */
function admin_tenant_get(): never {
    require_super_admin();
    ok(['tenant' => tenant_load()]);
}

/** PUT /admin/tenant-settings — super_admin actualiza nombre y colores. */
function admin_tenant_update(array $body): never {
    require_csrf();
    $admin = require_super_admin();

    $productName = validate_string($body, 'product_name', 2, 120);
    $primary = validate_string($body, 'primary_color', 4, 9);
    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $primary)) {
        err('INVALID_INPUT', 'primary_color debe ser hex tipo #rrggbb.', 400, ['field' => 'primary_color']);
    }
    $secondary = null;
    if (array_key_exists('secondary_color', $body) && $body['secondary_color'] !== null && $body['secondary_color'] !== '') {
        $secondary = (string)$body['secondary_color'];
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $secondary)) {
            err('INVALID_INPUT', 'secondary_color debe ser hex.', 400, ['field' => 'secondary_color']);
        }
    }

    // Upsert manual: garantiza fila id=1 incluso si la migracion no se corrio aun.
    $exists = db_one('SELECT id FROM tenant_settings WHERE id = 1');
    if ($exists) {
        db_exec('UPDATE tenant_settings SET product_name = ?, primary_color = ?, secondary_color = ? WHERE id = 1',
            [$productName, $primary, $secondary]);
    } else {
        db_exec('INSERT INTO tenant_settings (id, product_name, primary_color, secondary_color) VALUES (1, ?, ?, ?)',
            [$productName, $primary, $secondary]);
    }
    audit_log((int)$admin['id'], 'tenant_settings_update', [
        'product_name' => $productName,
        'primary_color' => $primary,
        'secondary_color' => $secondary,
    ]);
    ok(['tenant' => tenant_load(), 'message' => 'Configuracion guardada.']);
}

/** POST /admin/tenant-settings/logo — super_admin sube logo del tenant. */
function admin_tenant_upload_logo(): never {
    require_csrf();
    $admin = require_super_admin();

    if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
        err('INVALID_INPUT', 'Sube el archivo en el campo "logo".', 400, ['field' => 'logo']);
    }
    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        err('INVALID_INPUT', 'Error al recibir el archivo.', 400, ['field' => 'logo', 'php_error' => (int)$file['error']]);
    }
    if (($file['size'] ?? 0) > 512 * 1024) {
        err('PAYLOAD_TOO_LARGE', 'El logo supera 512 KB.', 413, ['field' => 'logo']);
    }

    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);
    }
    if ($mime === '' || !isset($allowed[$mime])) {
        $mime = (string)($file['type'] ?? '');
    }
    if (!isset($allowed[$mime])) {
        err('INVALID_INPUT', 'Tipo no permitido. Usa PNG, JPG, WebP o SVG.', 400, ['field' => 'logo', 'mime' => $mime]);
    }
    $ext = $allowed[$mime];

    $uploadsDir = __DIR__ . '/../uploads/tenant';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
        err('SERVER_ERROR', 'No se pudo crear el directorio de uploads.', 500);
    }
    $filename = sprintf('tenant-%d.%s', time(), $ext);
    $dest = $uploadsDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        if (!@rename($file['tmp_name'], $dest)) {
            err('SERVER_ERROR', 'No se pudo guardar el logo.', 500);
        }
    }

    $publicUrl = '/uploads/tenant/' . $filename;
    $exists = db_one('SELECT id FROM tenant_settings WHERE id = 1');
    if ($exists) {
        db_exec('UPDATE tenant_settings SET logo_url = ? WHERE id = 1', [$publicUrl]);
    } else {
        db_exec("INSERT INTO tenant_settings (id, product_name, logo_url, primary_color) VALUES (1, 'Melius Clockin', ?, '#07d6da')", [$publicUrl]);
    }
    audit_log((int)$admin['id'], 'tenant_logo_update', ['logo_url' => $publicUrl]);
    ok(['logo_url' => absolute_asset_url($publicUrl), 'message' => 'Logo del tenant actualizado.']);
}

/**
 * PUT /admin/companies/{id}/branding — override por empresa.
 * Super_admin puede tocar cualquier empresa; admin solo la suya.
 * Si los campos llegan null o vacios, se borra el override (vuelve a usar
 * marca paraguas o tenant).
 */
function admin_company_branding_update(int $companyId, array $body): never {
    require_csrf();
    $admin = require_admin();
    $isSuper = ($admin['role'] ?? '') === 'super_admin';
    if (!$isSuper && (int)($admin['company_id'] ?? 0) !== $companyId) {
        err('FORBIDDEN', 'Solo puedes editar tu propia empresa.', 403);
    }
    $company = db_one('SELECT id FROM companies WHERE id = ?', [$companyId]);
    if (!$company) err('NOT_FOUND', 'Empresa no encontrada.', 404);

    // Helper para hex opcional o null.
    $parseHex = function(?string $value, string $field): ?string {
        if ($value === null || $value === '') return null;
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            err('INVALID_INPUT', "{$field} debe ser hex tipo #rrggbb.", 400, ['field' => $field]);
        }
        return $value;
    };

    $primary = $parseHex($body['branding_primary'] ?? null, 'branding_primary');
    $secondary = $parseHex($body['branding_secondary'] ?? null, 'branding_secondary');
    $logoUrl = isset($body['branding_logo_url']) && $body['branding_logo_url'] !== ''
        ? (string)$body['branding_logo_url']
        : null;

    db_exec(
        'UPDATE companies SET branding_logo_url = ?, branding_primary = ?, branding_secondary = ? WHERE id = ?',
        [$logoUrl, $primary, $secondary, $companyId]
    );
    audit_log((int)$admin['id'], 'company_branding_update', [
        'company_id' => $companyId,
        'logo_url' => $logoUrl,
        'primary' => $primary,
        'secondary' => $secondary,
    ]);

    ok([
        'branding' => [
            'branding_logo_url' => $logoUrl ? absolute_asset_url($logoUrl) : null,
            'branding_primary' => $primary,
            'branding_secondary' => $secondary,
        ],
        'message' => 'Branding de empresa actualizado.',
    ]);
}
