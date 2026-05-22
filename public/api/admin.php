<?php
declare(strict_types=1);

// =====================================================================
// admin.php — Endpoints reservados a rol admin.
// Cada handler valida `require_admin()` que verifica sesion + rol en server.
// El rol jamas se acepta desde el cliente.
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

/**
 * Convierte HH:MM o HH:MM:SS desde una TZ origen a America/Mexico_City.
 * Devuelve HH:MM o null si no se puede convertir.
 */
function _to_cdmx_hhmm(?string $hhmm, ?string $workDate, ?string $sourceTz): ?string {
    if (!$hhmm || !$workDate || !$sourceTz) return null;
    try {
        $src = new DateTimeZone($sourceTz);
        $dst = new DateTimeZone('America/Mexico_City');
        // Tomamos HH:MM (ignoramos segundos para evitar drift de formato)
        $t = substr($hhmm, 0, 5);
        $dt = new DateTimeImmutable($workDate . ' ' . $t . ':00', $src);
        return $dt->setTimezone($dst)->format('H:i');
    } catch (Throwable $_) {
        return null;
    }
}

function admin_records(): never {
    require_admin();
    $rows = db_all(
        'SELECT ar.*, u.name as user_name, u.email as user_email, c.name as company_name
         FROM attendance_records ar
         JOIN users u ON u.id = ar.user_id
         LEFT JOIN companies c ON c.id = u.company_id
         ORDER BY ar.work_date DESC, ar.id DESC
         LIMIT 500'
    );
    ok(['records' => array_map(function ($r) {
        $sourceTz = $r['client_timezone'] ?: ($r['timezone'] ?? 'America/Mexico_City');
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'user_email' => $r['user_email'],
            'company_name' => $r['company_name'],
            'work_date' => $r['work_date'],
            'entry_time' => $r['entry_time'],
            'exit_time' => $r['exit_time'],
            'entry_time_cdmx' => _to_cdmx_hhmm($r['entry_time'] ?? null, $r['work_date'] ?? null, $sourceTz),
            'exit_time_cdmx' => _to_cdmx_hhmm($r['exit_time'] ?? null, $r['work_date'] ?? null, $sourceTz),
            'source_tz' => $sourceTz,
            'timezone' => $r['timezone'] ?? null,
            'client_timezone' => $r['client_timezone'] ?? null,
            'tz_mismatch' => isset($r['tz_mismatch']) ? (bool)$r['tz_mismatch'] : false,
            'closed_reason' => $r['closed_reason'],
            'late_close' => isset($r['late_close']) ? (bool)$r['late_close'] : false,
            'late_minutes' => isset($r['late_minutes']) ? (int)$r['late_minutes'] : 0,
            'overtime_hours' => (float)$r['overtime_hours'],
            'overtime_status' => $r['overtime_status'],
            'geo_country_code' => $r['geo_country_code'] ?? null,
            'geo_country_name' => $r['geo_country_name'] ?? null,
            'geo_city' => $r['geo_city'] ?? null,
            'geo_region' => $r['geo_region'] ?? null,
            'geo_source' => $r['geo_source'] ?? null,
            'geo_alert_flag' => isset($r['geo_alert_flag']) ? (bool)$r['geo_alert_flag'] : false,
            'geo_alert_reasons' => $r['geo_alert_reasons'] ?? null,
            'geo_exit_country_code' => $r['geo_exit_country_code'] ?? null,
            'geo_exit_city' => $r['geo_exit_city'] ?? null,
        ];
    }, $rows)]);
}

function admin_change_requests(): never {
    require_admin();
    $rows = db_all(
        "SELECT cr.*, u.name as user_name, oc.name as old_company_name, nc.name as new_company_name
         FROM change_requests cr
         JOIN users u ON u.id = cr.user_id
         LEFT JOIN companies oc ON oc.id = cr.old_company_id
         JOIN companies nc ON nc.id = cr.new_company_id
         WHERE cr.status = 'pending'
         ORDER BY cr.requested_at DESC"
    );
    ok(['requests' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'old_company_name' => $r['old_company_name'],
            'new_company_name' => $r['new_company_name'],
            'new_company_id' => (int)$r['new_company_id'],
            'requested_at' => $r['requested_at']
        ];
    }, $rows)]);
}

function admin_overtime_requests(): never {
    require_admin();
    $rows = db_all(
        "SELECT ot.*, u.name as user_name, ar.work_date
         FROM overtime_requests ot
         JOIN users u ON u.id = ot.user_id
         JOIN attendance_records ar ON ar.id = ot.record_id
         WHERE ot.status = 'pending'
         ORDER BY ot.requested_at DESC"
    );
    ok(['requests' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'record_id' => (int)$r['record_id'],
            'date' => $r['work_date'],
            'hours' => (float)$r['hours'],
            'new_hours' => $r['new_hours'] !== null ? (float)$r['new_hours'] : null,
            'request_type' => $r['request_type'] ?? 'new',
            'referenced_request_id' => $r['referenced_request_id'] !== null ? (int)$r['referenced_request_id'] : null,
            'reason' => $r['reason'],
            'requested_at' => $r['requested_at']
        ];
    }, $rows)]);
}

function admin_decide(array $body): never {
    require_csrf();
    $admin = require_admin();
    $type = validate_string($body, 'type', 1, 20);     // 'change' o 'overtime'
    $id = validate_int($body, 'id', 1);
    $decision = validate_string($body, 'decision', 1, 20); // 'approve' o 'reject'

    if (!in_array($type, ['change', 'overtime'], true)) {
        err('INVALID_INPUT', 'Tipo de solicitud invalido.', 400, ['field' => 'type']);
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        err('INVALID_INPUT', 'Decision invalida.', 400, ['field' => 'decision']);
    }
    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';

    Database::pdo()->beginTransaction();
    try {
        if ($type === 'change') {
            $req = db_one('SELECT * FROM change_requests WHERE id = ? AND status = ?', [$id, 'pending']);
            if (!$req) { Database::pdo()->rollBack(); err('NOT_FOUND', 'Solicitud no encontrada o ya procesada.', 404); }
            if ($decision === 'approve') {
                db_exec('UPDATE users SET company_id = ? WHERE id = ?', [$req['new_company_id'], $req['user_id']]);
            }
            db_exec(
                'UPDATE change_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$newStatus, $id]
            );
        } else { // overtime
            $req = db_one('SELECT * FROM overtime_requests WHERE id = ? AND status = ?', [$id, 'pending']);
            if (!$req) { Database::pdo()->rollBack(); err('NOT_FOUND', 'Solicitud no encontrada o ya procesada.', 404); }

            $isEdit = ($req['request_type'] ?? 'new') === 'edit';

            db_exec(
                'UPDATE overtime_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$newStatus, $id]
            );

            if ($isEdit) {
                // Edicion: solo modifica al original si aprobamos. Si rechazamos,
                // el original queda intacto y nada cambia en attendance_records.
                if ($decision === 'approve' && $req['referenced_request_id'] !== null && $req['new_hours'] !== null) {
                    $original = db_one(
                        'SELECT id, record_id, hours FROM overtime_requests WHERE id = ?',
                        [$req['referenced_request_id']]
                    );
                    if ($original) {
                        $oldHours = (float)$original['hours'];
                        $newHours = (float)$req['new_hours'];
                        $delta = $newHours - $oldHours;
                        db_exec(
                            'UPDATE overtime_requests SET hours = ? WHERE id = ?',
                            [$newHours, $original['id']]
                        );
                        db_exec(
                            'UPDATE attendance_records SET overtime_hours = overtime_hours + ? WHERE id = ?',
                            [$delta, $original['record_id']]
                        );
                    }
                }
            } else {
                // Solicitud nueva: reflejar status en el registro asociado.
                db_exec(
                    'UPDATE attendance_records SET overtime_status = ? WHERE id = ?',
                    [$newStatus, $req['record_id']]
                );
            }
        }
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[admin_decide] error: ' . $e->getMessage());
        err('SERVER_ERROR', 'No se pudo procesar la decision.', 500);
    }

    audit_log((int)$admin['id'], "admin_{$type}_{$decision}", ['target_id' => $id]);
    ok(['message' => 'Decision aplicada.']);
}

// =====================================================================
// Fase 2 — Administracion de empresas y agentes.
// =====================================================================

function admin_companies_list(): never {
    require_admin();
    $rows = db_all(
        'SELECT c.id, c.name, c.brand_id, c.timezone, c.work_start_time, c.work_end_time,
                c.work_days_mask, c.grace_minutes_late, c.is_configured, c.created_at,
                c.branding_logo_url, c.branding_primary, c.branding_secondary,
                b.slug AS brand_slug, b.name AS brand_name, b.logo_url AS brand_logo_url,
                b.primary_color AS brand_primary, b.secondary_color AS brand_secondary,
                b.welcome_intro AS brand_welcome,
                (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.is_active = 1) AS active_users
           FROM companies c
           LEFT JOIN brands b ON b.id = c.brand_id
          ORDER BY c.name ASC'
    );
    ok(['companies' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'brand_id' => $r['brand_id'] !== null ? (int)$r['brand_id'] : null,
        'brand_slug' => $r['brand_slug'] ?? null,
        'brand_name' => $r['brand_name'] ?? null,
        'brand_logo_url' => $r['brand_logo_url'] ?? null,
        'brand_primary' => $r['brand_primary'] ?? null,
        'brand_secondary' => $r['brand_secondary'] ?? null,
        'brand_welcome' => $r['brand_welcome'] ?? null,
        'branding_logo_url' => $r['branding_logo_url'] ?? null,
        'branding_primary' => $r['branding_primary'] ?? null,
        'branding_secondary' => $r['branding_secondary'] ?? null,
        'timezone' => $r['timezone'],
        'work_start_time' => substr($r['work_start_time'], 0, 5),
        'work_end_time' => substr($r['work_end_time'], 0, 5),
        'work_days_mask' => (int)$r['work_days_mask'],
        'grace_minutes_late' => (int)$r['grace_minutes_late'],
        'is_configured' => (int)$r['is_configured'] === 1,
        'active_users' => (int)$r['active_users'],
        'created_at' => $r['created_at'],
    ], $rows)]);
}

/**
 * GET admin/brands — listado.
 * Super_admin ve todas (activas e inactivas). Admin ve solo activas.
 */
function admin_brands_list(): never {
    $admin = require_admin();
    $isSuper = ($admin['role'] ?? '') === 'super_admin';
    $sql = 'SELECT b.id, b.slug, b.name, b.logo_url, b.primary_color, b.secondary_color,
                   b.welcome_intro, b.is_active, b.created_at,
                   (SELECT COUNT(*) FROM companies c WHERE c.brand_id = b.id) AS companies_count
              FROM brands b';
    if (!$isSuper) $sql .= ' WHERE b.is_active = 1';
    $sql .= ' ORDER BY b.name ASC';
    $rows = db_all($sql);
    ok(['brands' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'slug' => $r['slug'],
        'name' => $r['name'],
        'logo_url' => $r['logo_url'],
        'primary_color' => $r['primary_color'],
        'secondary_color' => $r['secondary_color'],
        'welcome_intro' => $r['welcome_intro'] ?? null,
        'is_active' => (int)$r['is_active'] === 1,
        'companies_count' => (int)$r['companies_count'],
        'created_at' => $r['created_at'],
    ], $rows)]);
}

/**
 * Genera un slug seguro a partir de un nombre. Solo a-z 0-9 y guiones.
 */
function brand_slugify(string $name): string {
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? '';
    $name = trim($name, '-');
    return $name === '' ? 'brand' : $name;
}

/**
 * Valida color hex #RRGGBB o #RGB. Devuelve forma canonica #rrggbb.
 */
function validate_hex_color(array $body, string $field, bool $required = true): ?string {
    $v = $body[$field] ?? null;
    if ($v === null || $v === '') {
        if ($required) err('INVALID_INPUT', "{$field} requerido.", 400, ['field' => $field]);
        return null;
    }
    $v = strtolower(trim((string)$v));
    if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) {
        err('INVALID_INPUT', "{$field} debe ser un color hex (#RRGGBB).", 400, ['field' => $field]);
    }
    return $v;
}

/**
 * POST admin/brands — crea marca. Solo super_admin.
 * Body: { name, primary_color, secondary_color?, welcome_intro?, logo_url? }
 * Si logo_url no se manda, queda con placeholder. Se actualiza luego con
 * POST admin/brands/{id}/logo.
 */
function admin_brands_create(array $body): never {
    require_csrf();
    $admin = require_super_admin();

    $name = validate_string($body, 'name', 2, 120);
    $primary = validate_hex_color($body, 'primary_color', true);
    $secondary = isset($body['secondary_color']) && $body['secondary_color'] !== ''
        ? validate_hex_color($body, 'secondary_color', false)
        : null;
    $welcomeIntro = null;
    if (isset($body['welcome_intro']) && trim((string)$body['welcome_intro']) !== '') {
        $welcomeIntro = validate_string($body, 'welcome_intro', 1, 2000);
    }
    $logoUrl = isset($body['logo_url']) && $body['logo_url'] !== ''
        ? validate_string($body, 'logo_url', 1, 255)
        : '/assets/brands/melius.webp'; // placeholder hasta que suba logo real

    // Slug unico: derivar del nombre y desambiguar con sufijo numerico si choca.
    $slug = brand_slugify($name);
    $base = $slug;
    $i = 2;
    while (db_one('SELECT id FROM brands WHERE slug = ?', [$slug])) {
        $slug = $base . '-' . $i;
        $i++;
        if ($i > 50) err('CONFLICT', 'No se pudo generar slug unico.', 409);
    }

    if (db_one('SELECT id FROM brands WHERE name = ?', [$name])) {
        err('CONFLICT', 'Ya existe una marca con ese nombre.', 409, ['field' => 'name']);
    }

    db_exec(
        'INSERT INTO brands (slug, name, logo_url, primary_color, secondary_color, welcome_intro, is_active)
              VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$slug, $name, $logoUrl, $primary, $secondary, $welcomeIntro]
    );
    $id = (int)db_last_id();
    audit_log((int)$admin['id'], 'admin_brand_create', ['brand_id' => $id, 'name' => $name, 'slug' => $slug]);
    ok(['id' => $id, 'slug' => $slug, 'message' => 'Marca creada.'], 201);
}

/**
 * PUT admin/brands/{id} — actualiza campos editables. Solo super_admin.
 * No cambia el slug (es estable como referencia).
 */
function admin_brands_update(int $id, array $body): never {
    require_csrf();
    $admin = require_super_admin();
    $existing = db_one('SELECT id FROM brands WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Marca no encontrada.', 404);

    $name = validate_string($body, 'name', 2, 120);
    $primary = validate_hex_color($body, 'primary_color', true);
    $secondary = isset($body['secondary_color']) && $body['secondary_color'] !== ''
        ? validate_hex_color($body, 'secondary_color', false)
        : null;
    $welcomeIntro = null;
    if (isset($body['welcome_intro']) && trim((string)$body['welcome_intro']) !== '') {
        $welcomeIntro = validate_string($body, 'welcome_intro', 1, 2000);
    }

    $dup = db_one('SELECT id FROM brands WHERE name = ? AND id <> ?', [$name, $id]);
    if ($dup) err('CONFLICT', 'Ya existe otra marca con ese nombre.', 409, ['field' => 'name']);

    db_exec(
        'UPDATE brands SET name = ?, primary_color = ?, secondary_color = ?, welcome_intro = ? WHERE id = ?',
        [$name, $primary, $secondary, $welcomeIntro, $id]
    );
    audit_log((int)$admin['id'], 'admin_brand_update', ['brand_id' => $id]);
    ok(['message' => 'Marca actualizada.']);
}

/**
 * DELETE admin/brands/{id} — desactiva una marca (soft delete).
 * Si tiene empresas asociadas, las desvincula (brand_id -> NULL) y desactiva.
 * No borra fila para preservar historico/audit.
 */
function admin_brands_delete(int $id): never {
    require_csrf();
    $admin = require_super_admin();
    $existing = db_one('SELECT id, name FROM brands WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Marca no encontrada.', 404);

    db_exec('UPDATE companies SET brand_id = NULL WHERE brand_id = ?', [$id]);
    db_exec('UPDATE brands SET is_active = 0 WHERE id = ?', [$id]);
    audit_log((int)$admin['id'], 'admin_brand_delete', ['brand_id' => $id, 'name' => $existing['name']]);
    ok(['message' => 'Marca desactivada y empresas desvinculadas.']);
}

/**
 * POST admin/brands/{id}/logo — sube logo en multipart/form-data (campo "logo").
 * Acepta image/png, image/jpeg, image/webp, image/svg+xml. Max 512 KB.
 * Renombra a <slug>-<timestamp>.<ext> en public/uploads/brands/.
 * Actualiza brands.logo_url. Devuelve la URL nueva.
 */
function admin_brands_upload_logo(int $id): never {
    require_csrf();
    $admin = require_super_admin();
    $brand = db_one('SELECT id, slug FROM brands WHERE id = ?', [$id]);
    if (!$brand) err('NOT_FOUND', 'Marca no encontrada.', 404);

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
    // Fallback: usa el tipo del browser si finfo no esta disponible.
    if ($mime === '' || !isset($allowed[$mime])) {
        $mime = (string)($file['type'] ?? '');
    }
    if (!isset($allowed[$mime])) {
        err('INVALID_INPUT', 'Tipo de archivo no permitido. Usa PNG, JPG, WebP o SVG.', 400, ['field' => 'logo', 'mime' => $mime]);
    }
    $ext = $allowed[$mime];

    $uploadsDir = __DIR__ . '/../uploads/brands';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
        err('SERVER_ERROR', 'No se pudo crear el directorio de uploads.', 500);
    }
    $filename = sprintf('%s-%d.%s', $brand['slug'], time(), $ext);
    $dest = $uploadsDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        // En CLI / dev server PHP integrado puede no funcionar move_uploaded_file:
        // fallback con rename si el tmp_name no fue cargado por POST real.
        if (!@rename($file['tmp_name'], $dest)) {
            err('SERVER_ERROR', 'No se pudo guardar el logo.', 500);
        }
    }

    $publicUrl = '/uploads/brands/' . $filename;
    db_exec('UPDATE brands SET logo_url = ? WHERE id = ?', [$publicUrl, $id]);
    audit_log((int)$admin['id'], 'admin_brand_logo_update', ['brand_id' => $id, 'logo_url' => $publicUrl]);
    ok(['logo_url' => $publicUrl, 'message' => 'Logo actualizado.']);
}

function admin_companies_create(array $body): never {
    require_csrf();
    $admin = require_admin();
    $name = validate_string($body, 'name', 1, 100);
    $tz = validate_timezone($body, 'timezone');
    $start = validate_time_hhmm($body, 'work_start_time');
    $end = validate_time_hhmm($body, 'work_end_time');
    $mask = validate_days_mask($body, 'work_days_mask');
    $grace = validate_int($body, 'grace_minutes_late', 0, 60);

    if ($start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }
    if (db_one('SELECT id FROM companies WHERE name = ?', [$name])) {
        err('CONFLICT', 'Ya existe una empresa con ese nombre.', 409, ['field' => 'name']);
    }

    $brandId = null;
    if (array_key_exists('brand_id', $body) && $body['brand_id'] !== null && $body['brand_id'] !== '') {
        $brandId = validate_int($body, 'brand_id', 1);
        if (!db_one('SELECT id FROM brands WHERE id = ? AND is_active = 1', [$brandId])) {
            err('INVALID_INPUT', 'Marca no existe o esta inactiva.', 400, ['field' => 'brand_id']);
        }
    }

    db_exec(
        'INSERT INTO companies (name, brand_id, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late, is_configured)
              VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        [$name, $brandId, $tz, $start, $end, $mask, $grace]
    );
    $id = (int)db_last_id();
    audit_log((int)$admin['id'], 'admin_company_create', ['company_id' => $id, 'name' => $name, 'brand_id' => $brandId]);
    ok(['id' => $id, 'message' => 'Empresa creada.'], 201);
}

function admin_companies_update(int $id, array $body): never {
    require_csrf();
    $admin = require_admin();
    $existing = db_one('SELECT id, name FROM companies WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Empresa no encontrada.', 404);

    $name = validate_string($body, 'name', 1, 100);
    $tz = validate_timezone($body, 'timezone');
    $start = validate_time_hhmm($body, 'work_start_time');
    $end = validate_time_hhmm($body, 'work_end_time');
    $mask = validate_days_mask($body, 'work_days_mask');
    $grace = validate_int($body, 'grace_minutes_late', 0, 60);

    if ($start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }
    $dup = db_one('SELECT id FROM companies WHERE name = ? AND id <> ?', [$name, $id]);
    if ($dup) err('CONFLICT', 'Ya existe otra empresa con ese nombre.', 409, ['field' => 'name']);

    $brandId = null;
    if (array_key_exists('brand_id', $body) && $body['brand_id'] !== null && $body['brand_id'] !== '') {
        $brandId = validate_int($body, 'brand_id', 1);
        if (!db_one('SELECT id FROM brands WHERE id = ? AND is_active = 1', [$brandId])) {
            err('INVALID_INPUT', 'Marca no existe o esta inactiva.', 400, ['field' => 'brand_id']);
        }
    }

    db_exec(
        'UPDATE companies SET name = ?, brand_id = ?, timezone = ?, work_start_time = ?, work_end_time = ?,
                              work_days_mask = ?, grace_minutes_late = ?, is_configured = 1
                 WHERE id = ?',
        [$name, $brandId, $tz, $start, $end, $mask, $grace, $id]
    );
    audit_log((int)$admin['id'], 'admin_company_update', ['company_id' => $id, 'brand_id' => $brandId]);
    ok(['message' => 'Empresa actualizada.']);
}

function admin_companies_delete(int $id): never {
    require_csrf();
    $admin = require_admin();
    $existing = db_one('SELECT id, name FROM companies WHERE id = ?', [$id]);
    if (!$existing) err('NOT_FOUND', 'Empresa no encontrada.', 404);

    $active = db_one('SELECT COUNT(*) AS c FROM users WHERE company_id = ? AND is_active = 1', [$id]);
    if ((int)$active['c'] > 0) {
        err('CONFLICT', 'No se puede eliminar: la empresa tiene consultores activos asignados.', 409, ['active_users' => (int)$active['c']]);
    }
    db_exec('DELETE FROM companies WHERE id = ?', [$id]);
    audit_log((int)$admin['id'], 'admin_company_delete', ['company_id' => $id, 'name' => $existing['name']]);
    ok(['message' => 'Empresa eliminada.']);
}

function admin_users_list(): never {
    $admin = require_admin();
    $isSuper = ($admin['role'] ?? '') === 'super_admin';
    $where = $isSuper ? '' : "WHERE u.role <> 'super_admin'";
    $rows = db_all(
        "SELECT u.id, u.email, u.name, u.role, u.company_id, u.is_active, u.status,
                u.timezone, u.work_start_time, u.work_end_time, u.work_days_mask,
                u.must_change_password,
                u.created_at, c.name AS company_name
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
           {$where}
          ORDER BY u.created_at DESC"
    );
    ok(['users' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'email' => $r['email'],
        'name' => $r['name'],
        'role' => $r['role'],
        'company_id' => $r['company_id'] !== null ? (int)$r['company_id'] : null,
        'company_name' => $r['company_name'],
        'is_active' => (int)$r['is_active'] === 1,
        'status' => $r['status'],
        'must_change_password' => (int)($r['must_change_password'] ?? 0) === 1,
        'timezone' => $r['timezone'],
        'work_start_time' => $r['work_start_time'] !== null ? substr($r['work_start_time'], 0, 5) : null,
        'work_end_time' => $r['work_end_time'] !== null ? substr($r['work_end_time'], 0, 5) : null,
        'work_days_mask' => $r['work_days_mask'] !== null ? (int)$r['work_days_mask'] : null,
        'created_at' => $r['created_at'],
    ], $rows)]);
}

function admin_users_update(int $id, array $body): never {
    require_csrf();
    $admin = require_admin();
    $user = db_one('SELECT id, role, company_id FROM users WHERE id = ?', [$id]);
    if (!$user) err('NOT_FOUND', 'Consultor no encontrado.', 404);

    // Blindaje super_admin: solo otro super_admin puede tocarlo. Para admins
    // normales, el super_admin es invisible — respondemos NOT_FOUND para no
    // filtrar su existencia (anti-enumeracion).
    if ($user['role'] === 'super_admin' && ($admin['role'] ?? '') !== 'super_admin') {
        err('NOT_FOUND', 'Consultor no encontrado.', 404);
    }

    // Scope por empresa: admin normal solo puede modificar usuarios DE SU EMPRESA.
    // Si no, NOT_FOUND (no filtramos que el usuario existe en otra empresa).
    $isSuper = ($admin['role'] ?? '') === 'super_admin';
    if (!$isSuper && (int)($user['company_id'] ?? 0) !== (int)($admin['company_id'] ?? -1)) {
        err('NOT_FOUND', 'Consultor no encontrado.', 404);
    }

    $companyId = null;
    if (array_key_exists('company_id', $body) && $body['company_id'] !== null && $body['company_id'] !== '') {
        $companyId = validate_int($body, 'company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
            err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
        }
        // Admin normal no puede mover usuarios a otra empresa.
        if (!$isSuper && $companyId !== (int)$admin['company_id']) {
            err('FORBIDDEN', 'No puedes asignar consultores a otra empresa.', 403, ['field' => 'company_id']);
        }
    }

    $status = validate_string($body, 'status', 1, 30);
    if (!in_array($status, ['pending_confirmation', 'active', 'disabled'], true)) {
        err('INVALID_INPUT', 'Status invalido.', 400, ['field' => 'status']);
    }
    if ($user['role'] === 'admin' && $status === 'disabled' && (int)$admin['id'] === $id) {
        err('CONFLICT', 'No puedes desactivarte a ti mismo.', 409);
    }

    // Cambio de rol entre consultant y admin (super_admin nunca via este endpoint).
    // Reglas:
    //   - super_admin como TARGET ya fue bloqueado arriba.
    //   - Solo super_admin puede promover/degradar entre admin <-> consultant
    //     (decision de gobierno: un admin normal no asciende a sus pares).
    //   - admin requiere company_id obligatorio para marcar jornada (mismo
    //     contrato que admin_users_invite).
    //   - No auto-degradarse.
    $newRole = null;
    if (array_key_exists('role', $body) && $body['role'] !== null && $body['role'] !== '') {
        $newRole = validate_string($body, 'role', 1, 20);
        if (!in_array($newRole, ['consultant', 'admin'], true)) {
            err('INVALID_INPUT', 'Rol invalido. Solo consultant o admin.', 400, ['field' => 'role']);
        }
        if ($newRole !== $user['role']) {
            if (!$isSuper) {
                err('FORBIDDEN', 'Solo super_admin puede cambiar el rol entre admin y consultor.', 403, ['field' => 'role']);
            }
            if ((int)$admin['id'] === $id) {
                err('CONFLICT', 'No puedes cambiar tu propio rol.', 409, ['field' => 'role']);
            }
            $effectiveCompany = $companyId ?? (int)($user['company_id'] ?? 0);
            if ($newRole === 'admin' && $effectiveCompany <= 0) {
                err('INVALID_INPUT', 'Para promover a admin se requiere empresa asignada.', 400, ['field' => 'company_id']);
            }
        }
    }

    $tz = isset($body['timezone']) && $body['timezone'] !== '' ? validate_timezone($body, 'timezone') : null;
    $start = isset($body['work_start_time']) && $body['work_start_time'] !== '' ? validate_time_hhmm($body, 'work_start_time') : null;
    $end = isset($body['work_end_time']) && $body['work_end_time'] !== '' ? validate_time_hhmm($body, 'work_end_time') : null;
    $mask = isset($body['work_days_mask']) && $body['work_days_mask'] !== '' ? validate_days_mask($body, 'work_days_mask') : null;

    if ($start !== null && $end !== null && $start >= $end) {
        err('INVALID_INPUT', 'work_start_time debe ser menor que work_end_time.', 400, ['field' => 'work_end_time']);
    }

    $isActive = $status === 'active' ? 1 : 0;
    $roleToSet = $newRole ?? $user['role'];
    db_exec(
        'UPDATE users SET company_id = ?, status = ?, is_active = ?, role = ?,
                          timezone = ?, work_start_time = ?, work_end_time = ?, work_days_mask = ?
                 WHERE id = ?',
        [$companyId, $status, $isActive, $roleToSet, $tz, $start, $end, $mask, $id]
    );
    audit_log((int)$admin['id'], 'admin_user_update', [
        'user_id' => $id, 'company_id' => $companyId, 'status' => $status,
        'role_from' => $user['role'], 'role_to' => $roleToSet,
    ]);
    ok(['message' => 'Consultor actualizado.']);
}

/**
 * Crea un usuario invitado con password temporal y envia el email v2.
 * Hace insert + envio fuera de transaccion (si falla SMTP se revierte el insert).
 * Devuelve ['user_id' => int] en exito, lanza Throwable con mensaje en error.
 * No emite respuesta HTTP: pensado para reuso desde admin_users_invite (uno por uno)
 * y desde admin_users_bulk_invite (carga masiva CSV).
 *
 * Caller es responsable de:
 *   - require_csrf() / require_admin() previo.
 *   - Validar role/company_id segun reglas de negocio antes de llamar.
 *   - Manejar duplicados (ya existe email) antes de llamar.
 */
function admin_users_create_invited(string $email, string $name, string $role, ?int $companyId, int $actorAdminId): int {
    $tempPassword = password_temp_generate(14);
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    Database::pdo()->beginTransaction();
    try {
        db_exec(
            'INSERT INTO users (email, name, password_hash, role, company_id, status, is_active, must_change_password)
                  VALUES (?, ?, ?, ?, ?, ?, 1, 1)',
            [$email, $name, $hash, $role, $companyId, 'active']
        );
        $userId = (int)db_last_id();
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[admin_users_create_invited] insert fallo: ' . $e->getMessage());
        throw new RuntimeException('No se pudo crear el usuario.');
    }

    $loginUrl = app_base_url() . '/';
    $companyRow = $companyId
        ? db_one(
            'SELECT c.name AS company_name,
                    b.id AS brand_id,
                    b.name AS brand_name,
                    b.logo_url AS brand_logo_url,
                    b.primary_color AS brand_primary,
                    b.secondary_color AS brand_secondary,
                    b.welcome_intro AS brand_welcome
               FROM companies c
               LEFT JOIN brands b ON b.id = c.brand_id
              WHERE c.id = ?',
            [$companyId]
          )
        : null;
    $companyName = $companyRow['company_name'] ?? 'Melius Services';
    $brandLogoUrl = !empty($companyRow['brand_logo_url'])
        ? absolute_asset_url($companyRow['brand_logo_url'])
        : null;
    $brandId = isset($companyRow['brand_id']) ? (int)$companyRow['brand_id'] : null;
    $override = email_template_load($brandId, 'invitation');
    $tpl = mail_template_invitation_v2([
        'name' => $name,
        'companyName' => $companyName,
        'loginUrl' => $loginUrl,
        'email' => $email,
        'tempPassword' => $tempPassword,
        'brandName' => $companyRow['brand_name'] ?? 'Melius',
        'brandLogoUrl' => $brandLogoUrl,
        'brandPrimary' => $companyRow['brand_primary'] ?? null,
        'brandSecondary' => $companyRow['brand_secondary'] ?? null,
        'brandWelcome' => $companyRow['brand_welcome'] ?? null,
        'subjectOverride' => $override['subject'] ?? null,
        'introOverride' => $override['intro_html'] ?? null,
        'ctaOverride' => $override['cta_label'] ?? null,
    ]);
    $sent = mail_send($email, $tpl['subject'], $tpl['html'], $tpl['text']);

    if (!$sent) {
        db_exec('DELETE FROM users WHERE id = ?', [$userId]);
        audit_log($actorAdminId, 'admin_invite_mail_failed', ['email' => $email]);
        throw new RuntimeException('No se pudo enviar el correo. Verifica configuracion SMTP.');
    }

    audit_log($actorAdminId, 'admin_invite_created', [
        'user_id' => $userId, 'email' => $email, 'role' => $role, 'company_id' => $companyId
    ]);
    return $userId;
}

/**
 * POST admin/users/invite — crea cuenta con password temporal y envia email.
 * Reemplaza el flujo publico de auth/register. super_admin puede crear admin
 * o consultant; admin solo consultant. Anti-enumeracion: respuesta identica
 * si el email ya existe (no crea ni envia).
 */
function admin_users_invite(array $body): never {
    require_csrf();
    $admin = require_admin();

    $email = validate_email($body, 'email');
    $name = validate_string($body, 'name', 2, 120);
    $role = validate_string($body, 'role', 1, 20);
    if (!in_array($role, ['consultant', 'admin'], true)) {
        err('INVALID_INPUT', 'Rol invalido. Permitidos: consultant, admin.', 400, ['field' => 'role']);
    }

    $companyId = null;
    if (array_key_exists('company_id', $body) && $body['company_id'] !== null && $body['company_id'] !== '') {
        $companyId = validate_int($body, 'company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
            err('INVALID_INPUT', 'Empresa no existe.', 400, ['field' => 'company_id']);
        }
    }
    // Consultor obligatoriamente vinculado a una empresa.
    if ($role === 'consultant' && $companyId === null) {
        err('INVALID_INPUT', 'Los consultores requieren empresa asignada.', 400, ['field' => 'company_id']);
    }
    // Admin tambien marca jornada como empleado: requiere empresa al darlo de alta.
    // super_admin queda fuera de este flujo (no se crea via admin_users_invite).
    if ($role === 'admin' && $companyId === null) {
        err('INVALID_INPUT', 'Los administradores requieren empresa asignada para poder marcar jornada.', 400, ['field' => 'company_id']);
    }

    // Anti-enumeracion: si el email ya existe no creamos pero respondemos OK.
    // Para evitar timing-leak, simulamos el costo del flujo real (bcrypt + sleep
    // aleatorio comparable al envio SMTP) antes de responder.
    $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        // bcrypt dummy: mismo costo que el flujo de alta real (cost 12).
        password_hash('dummy-' . random_bytes(8), PASSWORD_BCRYPT, ['cost' => 12]);
        // Sleep aleatorio 200-600ms para acercarnos al tiempo de envio SMTP.
        usleep(random_int(200_000, 600_000));
        audit_log((int)$admin['id'], 'admin_invite_duplicate', ['email' => $email]);
        ok(['message' => 'Invitacion enviada.']);
    }

    try {
        $userId = admin_users_create_invited($email, $name, $role, $companyId, (int)$admin['id']);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'correo')) {
            err('MAIL_FAILED', $msg, 502);
        }
        err('SERVER_ERROR', $msg, 500);
    }
    ok(['message' => 'Invitacion enviada.', 'user_id' => $userId], 201);
}

/**
 * POST admin/users/{id}/resend-invite — regenera password temporal y reenvia
 * el correo de invitacion al usuario. Solo aplicable a usuarios que aun no
 * han cambiado su password inicial (must_change_password=1).
 *
 * Reglas:
 *   - super_admin invisible para admins normales.
 *   - admin normal solo puede reenviar a usuarios de su misma empresa.
 *   - usuario disabled: rechazado.
 *   - usuario que ya cambio password: rechazado (no es invitacion pendiente).
 *   - super_admin como target: bloqueado (no se invita por este flujo).
 */
function admin_users_resend_invite(int $id): never {
    require_csrf();
    $admin = require_admin();

    $target = db_one(
        'SELECT u.id, u.email, u.name, u.role, u.status, u.company_id, u.must_change_password,
                c.name AS company_name,
                b.id AS brand_id,
                b.name AS brand_name,
                b.logo_url AS brand_logo_url,
                b.primary_color AS brand_primary,
                b.secondary_color AS brand_secondary,
                b.welcome_intro AS brand_welcome
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
           LEFT JOIN brands b ON b.id = c.brand_id
          WHERE u.id = ?',
        [$id]
    );
    if (!$target) err('NOT_FOUND', 'Usuario no encontrado.', 404);

    $isSuper = ($admin['role'] ?? '') === 'super_admin';
    if ($target['role'] === 'super_admin') {
        err($isSuper ? 'CONFLICT' : 'NOT_FOUND',
            $isSuper ? 'No puedes reenviar invitacion a un super_admin.' : 'Usuario no encontrado.',
            $isSuper ? 409 : 404);
    }
    if (!$isSuper && (int)$target['company_id'] !== (int)($admin['company_id'] ?? 0)) {
        err('NOT_FOUND', 'Usuario no encontrado.', 404);
    }
    if ($target['status'] === 'disabled') {
        err('CONFLICT', 'El usuario esta desactivado.', 409);
    }
    if ((int)$target['must_change_password'] !== 1) {
        err('CONFLICT', 'El usuario ya activo su cuenta. No se puede reenviar la invitacion.', 409);
    }

    $tempPassword = password_temp_generate(14);
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    db_exec(
        'UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL
          WHERE id = ?',
        [$hash, $id]
    );

    $brandLogoUrl = !empty($target['brand_logo_url'])
        ? absolute_asset_url((string)$target['brand_logo_url'])
        : null;

    $brandIdResend = isset($target['brand_id']) ? (int)$target['brand_id'] : null;
    $overrideResend = email_template_load($brandIdResend, 'invitation');
    $tpl = mail_template_invitation_v2([
        'name' => (string)$target['name'],
        'companyName' => (string)($target['company_name'] ?? 'Melius Services'),
        'loginUrl' => app_base_url() . '/',
        'email' => (string)$target['email'],
        'tempPassword' => $tempPassword,
        'brandName' => (string)($target['brand_name'] ?? 'Melius'),
        'brandLogoUrl' => $brandLogoUrl,
        'brandPrimary' => $target['brand_primary'] ?? null,
        'brandSecondary' => $target['brand_secondary'] ?? null,
        'brandWelcome' => $target['brand_welcome'] ?? null,
        'subjectOverride' => $overrideResend['subject'] ?? null,
        'introOverride' => $overrideResend['intro_html'] ?? null,
        'ctaOverride' => $overrideResend['cta_label'] ?? null,
    ]);
    $sent = mail_send((string)$target['email'], $tpl['subject'], $tpl['html'], $tpl['text']);

    if (!$sent) {
        audit_log((int)$admin['id'], 'admin_resend_mail_failed', ['user_id' => $id, 'email' => $target['email']]);
        err('MAIL_FAILED', 'No se pudo enviar el correo. Verifica la configuracion de envio.', 502);
    }

    audit_log((int)$admin['id'], 'admin_invite_resent', [
        'user_id' => $id, 'email' => $target['email'], 'role' => $target['role'],
    ]);
    ok(['message' => 'Invitacion reenviada con nueva password temporal.']);
}

/**
 * GET admin/users/template.csv — descarga plantilla CSV vacia con cabeceras.
 * Cabeceras: email, name, role, company. La plantilla viene con UNA fila de
 * ejemplo prellenada con la primera empresa real de la DB para guiar al usuario.
 * Admin normal no necesita la columna company (ya queda asignado a su empresa).
 */
function admin_users_template_csv(): never {
    $admin = require_admin();
    $isSuper = ($admin['role'] ?? '') === 'super_admin';

    // Toma una empresa real para los ejemplos (la primera por nombre).
    $sampleCompany = db_one('SELECT name FROM companies ORDER BY name ASC LIMIT 1')['name'] ?? 'Melius Services';

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_consultores.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

    if ($isSuper) {
        fputcsv($out, ['email', 'name', 'role', 'company']);
        fputcsv($out, ['ana.gomez@empresa.com',  'Ana Gomez',  'consultant', $sampleCompany]);
        fputcsv($out, ['luis.perez@empresa.com', 'Luis Perez', 'consultant', $sampleCompany]);
    } else {
        fputcsv($out, ['email', 'name', 'role']);
        fputcsv($out, ['ana.gomez@empresa.com',  'Ana Gomez',  'consultant']);
        fputcsv($out, ['luis.perez@empresa.com', 'Luis Perez', 'consultant']);
    }
    fclose($out);
    exit;
}

/**
 * POST admin/users/bulk-invite — carga masiva de consultores desde CSV.
 * Body JSON: { csv: "<contenido literal>", default_company_id?: int }
 * Cabeceras esperadas: email, name, role, company (en cualquier orden).
 *   - "company" admite nombre exacto, case-insensitive (recomendado).
 *   - Si una fila no trae company usa default_company_id como respaldo.
 *   - Admin normal: company forzado a su empresa (columna ignorada).
 *   - Super_admin: respeta el nombre de cada fila.
 * Procesa fila por fila: errores en una NO bloquean las demas.
 * Response: { summary, created, failed, skipped }
 */
function admin_users_bulk_invite(array $body): never {
    require_csrf();
    $admin = require_admin();
    $isSuper = ($admin['role'] ?? '') === 'super_admin';

    // Rate limit: 5 bulk-invites por hora por admin para evitar denial-of-wallet
    // via amplificacion SMTP (cada bulk puede mandar hasta 500 mails).
    rate_limit_or_block('bulk_invite', (string)$admin['id'], 5, 3600);

    $csv = $body['csv'] ?? '';
    if (!is_string($csv) || trim($csv) === '') {
        err('INVALID_INPUT', 'CSV vacio o ausente.', 400, ['field' => 'csv']);
    }
    if (strlen($csv) > 2 * 1024 * 1024) {
        err('PAYLOAD_TOO_LARGE', 'CSV supera 2 MB.', 413, ['field' => 'csv']);
    }

    $defaultCompanyId = null;
    if (array_key_exists('default_company_id', $body) && $body['default_company_id'] !== null && $body['default_company_id'] !== '') {
        $defaultCompanyId = validate_int($body, 'default_company_id', 1);
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$defaultCompanyId])) {
            err('INVALID_INPUT', 'default_company_id no existe.', 400, ['field' => 'default_company_id']);
        }
    }

    // Admin normal solo puede cargar a su propia empresa.
    if (!$isSuper) {
        $adminCo = $admin['company_id'] !== null ? (int)$admin['company_id'] : null;
        if ($adminCo === null) {
            err('COMPANY_REQUIRED', 'Tu cuenta admin no tiene empresa asignada.', 400);
        }
        $defaultCompanyId = $adminCo; // fuerza scope
    }

    // Cache de companies (name lower -> id) para resolver por fila sin N queries.
    $companyRows = db_all('SELECT id, name FROM companies');
    $companiesByName = [];
    foreach ($companyRows as $cr) {
        $companiesByName[mb_strtolower(trim($cr['name']))] = (int)$cr['id'];
    }

    // Parseo CSV en memoria. Quita BOM si viene de Excel.
    if (substr($csv, 0, 3) === "\xEF\xBB\xBF") $csv = substr($csv, 3);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    $header = fgetcsv($stream);
    if (!$header) {
        fclose($stream);
        err('INVALID_INPUT', 'CSV sin cabeceras.', 400, ['field' => 'csv']);
    }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $required = ['email', 'name', 'role'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($stream);
            err('INVALID_INPUT', "Falta columna obligatoria: {$col}", 400, ['field' => 'csv']);
        }
    }
    $idx = array_flip($header);
    // Aceptamos "company" (nuevo, recomendado) o "company_id" (compat).
    $idxCompanyName = $idx['company'] ?? null;
    $idxCompanyId = $idx['company_id'] ?? null;

    $created = [];
    $failed = [];
    $skipped = [];
    $rowNum = 1;
    $maxRows = 500;

    while (($row = fgetcsv($stream)) !== false) {
        $rowNum++;
        // Fila vacia
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        if (count($created) + count($failed) >= $maxRows) {
            $skipped[] = ['row' => $rowNum, 'email' => '', 'reason' => "Limite {$maxRows} filas. Sube el resto en otro CSV."];
            continue;
        }

        $email = trim((string)($row[$idx['email']] ?? ''));
        $name = trim((string)($row[$idx['name']] ?? ''));
        $role = strtolower(trim((string)($row[$idx['role']] ?? '')));
        $companyId = $defaultCompanyId;

        // Solo super_admin puede usar la columna company por fila.
        if ($isSuper) {
            if ($idxCompanyName !== null) {
                $rawCo = trim((string)($row[$idxCompanyName] ?? ''));
                if ($rawCo !== '') {
                    $key = mb_strtolower($rawCo);
                    if (!isset($companiesByName[$key])) {
                        $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => "empresa \"{$rawCo}\" no existe"];
                        continue;
                    }
                    $companyId = $companiesByName[$key];
                }
            } elseif ($idxCompanyId !== null) {
                $rawCo = trim((string)($row[$idxCompanyId] ?? ''));
                if ($rawCo !== '') {
                    if (!ctype_digit($rawCo)) {
                        $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'company_id no es numerico'];
                        continue;
                    }
                    $companyId = (int)$rawCo;
                }
            }
        }

        // Validacion por fila
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'email invalido'];
            continue;
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'name debe tener 2-120 caracteres'];
            continue;
        }
        if (!in_array($role, ['consultant', 'admin'], true)) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => "role invalido (permitidos: consultant, admin)"];
            continue;
        }
        if (!$isSuper && $role === 'admin') {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'solo super_admin puede crear admins'];
            continue;
        }
        if ($companyId === null) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'company_id requerido (en fila o default_company_id)'];
            continue;
        }
        if (!db_one('SELECT id FROM companies WHERE id = ?', [$companyId])) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => "company_id {$companyId} no existe"];
            continue;
        }
        if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            $skipped[] = ['row' => $rowNum, 'email' => $email, 'reason' => 'email ya existe'];
            continue;
        }

        try {
            $userId = admin_users_create_invited($email, $name, $role, $companyId, (int)$admin['id']);
            $created[] = ['row' => $rowNum, 'email' => $email, 'user_id' => $userId];
        } catch (RuntimeException $e) {
            $failed[] = ['row' => $rowNum, 'email' => $email, 'reason' => $e->getMessage()];
        }
    }
    fclose($stream);

    audit_log((int)$admin['id'], 'admin_bulk_invite', [
        'created' => count($created), 'failed' => count($failed), 'skipped' => count($skipped)
    ]);

    ok([
        'summary' => [
            'created' => count($created),
            'failed' => count($failed),
            'skipped' => count($skipped),
        ],
        'created' => $created,
        'failed' => $failed,
        'skipped' => $skipped,
    ], 201);
}

/**
 * DELETE admin/users/{id} — politica de "eliminacion" segun rol y empresa:
 *   - admin CON company_id  -> downgrade a consultant, queda active.
 *                              Pierde permisos administrativos pero sigue
 *                              pudiendo marcar jornada con su empresa.
 *   - admin SIN company_id  -> soft delete (status=disabled, is_active=0).
 *   - consultant            -> soft delete (status=disabled, is_active=0).
 *
 * Otras reglas:
 *   - super_admin invisible para admins normales (anti-enumeracion).
 *   - Super_admin como target solo lo toca otro super_admin.
 *   - Admin no puede auto-eliminarse.
 *   - email_confirmation obligatorio (defensa server-side anti-click accidental).
 *   - Conserva historico (never DELETE FROM users).
 */
function admin_users_delete(int $id, array $body): never {
    require_csrf();
    $admin = require_admin();

    $target = db_one(
        'SELECT u.id, u.email, u.name, u.role, u.status, u.company_id, c.name AS company_name,
                b.id AS brand_id, b.name AS brand_name
           FROM users u
           LEFT JOIN companies c ON c.id = u.company_id
           LEFT JOIN brands b ON b.id = c.brand_id
          WHERE u.id = ?',
        [$id]
    );
    if (!$target) err('NOT_FOUND', 'Usuario no encontrado.', 404);

    $isSuperAdminActor = ($admin['role'] ?? '') === 'super_admin';
    if ($target['role'] === 'super_admin' && !$isSuperAdminActor) {
        err('NOT_FOUND', 'Usuario no encontrado.', 404);
    }
    if ((int)$target['id'] === (int)$admin['id']) {
        err('CONFLICT', 'No puedes desactivar tu propia cuenta.', 409);
    }

    $emailConfirmation = isset($body['email_confirmation']) ? strtolower(trim((string)$body['email_confirmation'])) : '';
    if ($emailConfirmation === '' || $emailConfirmation !== strtolower((string)$target['email'])) {
        err('INVALID_INPUT', 'La confirmacion del email no coincide.', 400, ['field' => 'email_confirmation']);
    }

    // Decision: downgrade vs disable.
    $isAdminWithCompany = ($target['role'] === 'admin' && (int)($target['company_id'] ?? 0) > 0);
    $mode = $isAdminWithCompany ? 'downgrade' : 'disable';

    if ($mode === 'downgrade') {
        if ($target['status'] === 'disabled') {
            // Si estaba disabled, lo dejamos como esta pero bajamos rol.
            db_exec("UPDATE users SET role = 'consultant' WHERE id = ?", [$id]);
        } else {
            db_exec("UPDATE users SET role = 'consultant', status = 'active', is_active = 1 WHERE id = ?", [$id]);
        }
        audit_log((int)$admin['id'], 'admin_user_downgraded', [
            'user_id' => $id,
            'email' => $target['email'],
            'role_from' => 'admin',
            'role_to' => 'consultant',
            'company_id' => $target['company_id'],
        ]);
        ok(['message' => 'Admin convertido en consultor. Conserva su empresa y puede seguir marcando jornada.']);
    }

    // mode === 'disable'
    if ($target['status'] === 'disabled') {
        err('CONFLICT', 'El usuario ya esta desactivado.', 409);
    }

    db_exec("UPDATE users SET status = 'disabled', is_active = 0 WHERE id = ?", [$id]);

    // Invalidar tokens de reset pendientes para evitar recuperacion post-disable.
    db_exec(
        'UPDATE password_reset_tokens SET consumed_at = CURRENT_TIMESTAMP
          WHERE user_id = ? AND consumed_at IS NULL',
        [$id]
    );

    audit_log((int)$admin['id'], 'admin_user_disabled', [
        'user_id' => $id,
        'email' => $target['email'],
        'role' => $target['role'],
        'company_id' => $target['company_id'],
    ]);

    // Emails: no bloqueamos la respuesta si el envio falla; ya hicimos el soft delete.
    $companyName = $target['company_name'] ?? 'Melius Services';
    $actorName = (string)($admin['name'] ?? $admin['email'] ?? 'Administrador');
    $brandIdDel = isset($target['brand_id']) ? (int)$target['brand_id'] : null;
    $brandNameDel = (string)($target['brand_name'] ?? 'Melius');
    $overrideDisabled = email_template_load($brandIdDel, 'admin_disabled');
    $overrideReceipt = email_template_load($brandIdDel, 'admin_delete_receipt');

    $brandForEmail = resolve_email_brand($brandIdDel);
    $tplTarget = mail_template_admin_disabled((string)$target['name'], $companyName, $actorName, [
        'brandName' => $brandNameDel,
        'subjectOverride' => $overrideDisabled['subject'] ?? null,
        'introOverride' => $overrideDisabled['intro_html'] ?? null,
    ], $brandForEmail);
    @mail_send((string)$target['email'], $tplTarget['subject'], $tplTarget['html'], $tplTarget['text']);

    $tplActor = mail_template_admin_delete_receipt(
        $actorName,
        (string)$target['name'],
        (string)$target['email'],
        $companyName,
        [
            'brandName' => $brandNameDel,
            'subjectOverride' => $overrideReceipt['subject'] ?? null,
            'introOverride' => $overrideReceipt['intro_html'] ?? null,
        ],
        $brandForEmail
    );
    @mail_send((string)$admin['email'], $tplActor['subject'], $tplActor['html'], $tplActor['text']);

    ok(['message' => 'Usuario desactivado.']);
}

// =====================================================================
// Email templates — CRUD para super_admin. Permite editar subject, intro y
// cta_label por (brand_id, kind). El HTML del layout (hero, footer, colores)
// permanece blindado en mailer.php para mantener compatibilidad Gmail/Outlook.
// =====================================================================

const EMAIL_TEMPLATE_KINDS = ['invitation', 'password_reset', 'admin_disabled', 'admin_delete_receipt'];

/**
 * Verifica que kind sea valido y termina con err si no.
 */
function email_template_validate_kind(string $kind): void {
    if (!in_array($kind, EMAIL_TEMPLATE_KINDS, true)) {
        err('INVALID_INPUT', 'Tipo de plantilla invalido.', 400, ['field' => 'kind']);
    }
}

/**
 * GET admin/email-templates — lista todas las plantillas (brands x kinds).
 */
function admin_email_templates_list(): never {
    require_super_admin();
    $rows = db_all(
        'SELECT et.id, et.brand_id, b.slug AS brand_slug, b.name AS brand_name,
                et.kind, et.subject, et.intro_html, et.cta_label, et.updated_at
           FROM email_templates et
           JOIN brands b ON b.id = et.brand_id
          ORDER BY b.name, et.kind'
    );
    ok(['templates' => $rows]);
}

/**
 * GET admin/email-templates/{brandId}/{kind} — detalle.
 */
function admin_email_templates_get(int $brandId, string $kind): never {
    require_super_admin();
    email_template_validate_kind($kind);
    $row = db_one(
        'SELECT et.id, et.brand_id, b.slug AS brand_slug, b.name AS brand_name,
                et.kind, et.subject, et.intro_html, et.cta_label, et.updated_at
           FROM email_templates et
           JOIN brands b ON b.id = et.brand_id
          WHERE et.brand_id = ? AND et.kind = ?',
        [$brandId, $kind]
    );
    if (!$row) err('NOT_FOUND', 'Plantilla no encontrada.', 404);
    ok(['template' => $row]);
}

/**
 * PUT admin/email-templates/{brandId}/{kind} — upsert.
 * Body: { subject, intro_html, cta_label? }
 */
function admin_email_templates_save(int $brandId, string $kind, array $body): never {
    require_csrf();
    $admin = require_super_admin();
    email_template_validate_kind($kind);

    if (!db_one('SELECT id FROM brands WHERE id = ?', [$brandId])) {
        err('NOT_FOUND', 'Marca no encontrada.', 404);
    }

    $subject = validate_string($body, 'subject', 3, 200);
    $intro = validate_string($body, 'intro_html', 3, 4000);
    $cta = null;
    if (array_key_exists('cta_label', $body) && $body['cta_label'] !== null && $body['cta_label'] !== '') {
        $cta = validate_string($body, 'cta_label', 1, 80);
    }

    $existing = db_one('SELECT id FROM email_templates WHERE brand_id = ? AND kind = ?', [$brandId, $kind]);
    if ($existing) {
        db_exec(
            'UPDATE email_templates SET subject = ?, intro_html = ?, cta_label = ?, updated_by = ? WHERE id = ?',
            [$subject, $intro, $cta, (int)$admin['id'], (int)$existing['id']]
        );
    } else {
        db_exec(
            'INSERT INTO email_templates (brand_id, kind, subject, intro_html, cta_label, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?)',
            [$brandId, $kind, $subject, $intro, $cta, (int)$admin['id']]
        );
    }
    audit_log((int)$admin['id'], 'email_template_save', ['brand_id' => $brandId, 'kind' => $kind]);
    ok(['message' => 'Plantilla guardada.']);
}

/**
 * DELETE admin/email-templates/{brandId}/{kind} — borra override.
 * El sistema vuelve a usar el copy hardcoded de mailer.php.
 */
function admin_email_templates_reset(int $brandId, string $kind): never {
    require_csrf();
    $admin = require_super_admin();
    email_template_validate_kind($kind);
    db_exec('DELETE FROM email_templates WHERE brand_id = ? AND kind = ?', [$brandId, $kind]);
    audit_log((int)$admin['id'], 'email_template_reset', ['brand_id' => $brandId, 'kind' => $kind]);
    ok(['message' => 'Plantilla restablecida al default.']);
}

/**
 * POST admin/email-templates/preview — renderiza una plantilla con datos
 * demo sin tocar la DB ni enviar correo. Body: { kind, brand_id?, subject?,
 * intro_html?, cta_label? }. Si los overrides vienen en el body, se usan;
 * si no, se carga la fila de DB.
 */
function admin_email_templates_preview(array $body): never {
    require_csrf();
    require_super_admin();
    $kind = validate_string($body, 'kind', 1, 40);
    email_template_validate_kind($kind);

    $brandId = isset($body['brand_id']) && $body['brand_id'] !== '' && $body['brand_id'] !== null
        ? validate_int($body, 'brand_id', 1)
        : null;
    $brand = $brandId
        ? db_one('SELECT id, name, logo_url, primary_color, secondary_color, welcome_intro FROM brands WHERE id = ?', [$brandId])
        : null;

    $subjectOverride = isset($body['subject']) && $body['subject'] !== '' ? (string)$body['subject'] : null;
    $introOverride = isset($body['intro_html']) && $body['intro_html'] !== '' ? (string)$body['intro_html'] : null;
    $ctaOverride = isset($body['cta_label']) && $body['cta_label'] !== '' ? (string)$body['cta_label'] : null;

    if ($brandId && !$subjectOverride && !$introOverride && !$ctaOverride) {
        $row = email_template_load($brandId, $kind);
        if ($row) {
            $subjectOverride = $row['subject'];
            $introOverride = $row['intro_html'];
            $ctaOverride = $row['cta_label'];
        }
    }

    $brandName = $brand['name'] ?? 'Melius';
    $brandPrimary = $brand['primary_color'] ?? '#07d6da';
    $brandSecondary = $brand['secondary_color'] ?? '#9909fe';
    $brandLogo = $brand && !empty($brand['logo_url']) ? absolute_asset_url((string)$brand['logo_url']) : null;
    $loginUrl = app_base_url() . '/';
    $brandForLayout = $brand ? [
        'name' => $brandName,
        'logo_url' => $brandLogo,
        'primary_color' => $brandPrimary,
        'secondary_color' => $brandSecondary,
    ] : null;

    switch ($kind) {
        case 'invitation':
            $tpl = mail_template_invitation_v2([
                'name' => 'Ana Gomez',
                'companyName' => 'Empresa Demo',
                'loginUrl' => $loginUrl,
                'email' => 'ana@empresa.com',
                'tempPassword' => 'Demo%2026!',
                'brandName' => $brandName,
                'brandLogoUrl' => $brandLogo,
                'brandPrimary' => $brandPrimary,
                'brandSecondary' => $brandSecondary,
                'brandWelcome' => null,
                'introOverride' => $introOverride,
                'subjectOverride' => $subjectOverride,
                'ctaOverride' => $ctaOverride,
            ]);
            break;
        case 'password_reset':
            $tpl = mail_template_password_reset('Ana Gomez', $loginUrl . '#reset?token=demo', 2, [
                'brandName' => $brandName,
                'subjectOverride' => $subjectOverride,
                'introOverride' => $introOverride,
                'ctaOverride' => $ctaOverride,
            ], $brandForLayout);
            break;
        case 'admin_disabled':
            $tpl = mail_template_admin_disabled('Ana Gomez', 'Empresa Demo', 'Admin Demo', [
                'brandName' => $brandName,
                'subjectOverride' => $subjectOverride,
                'introOverride' => $introOverride,
            ], $brandForLayout);
            break;
        case 'admin_delete_receipt':
            $tpl = mail_template_admin_delete_receipt('Admin Demo', 'Ana Gomez', 'ana@empresa.com', 'Empresa Demo', [
                'brandName' => $brandName,
                'subjectOverride' => $subjectOverride,
                'introOverride' => $introOverride,
            ], $brandForLayout);
            break;
        default:
            err('INVALID_INPUT', 'Tipo de plantilla invalido.', 400);
    }

    ok(['subject' => $tpl['subject'], 'html' => $tpl['html'], 'text' => $tpl['text']]);
}


/**
 * GET admin/location-alerts — Lista alertas geo.
 * super_admin: ve todas las alertas globales.
 * admin: ve solo alertas de empleados de su misma company_id (multi-tenant safe).
 * Filtros opcionales: ?status=pending|reviewed|dismissed (default: pending).
 */
function admin_location_alerts_list(): never {
    $admin = require_admin();
    $statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['pending','reviewed','dismissed'], true)
        ? $_GET['status'] : 'pending';

    $where = ['la.status = ?'];
    $params = [$statusFilter];

    // Tenant isolation: admin no super solo ve su company.
    if (($admin['role'] ?? '') !== 'super_admin') {
        if (empty($admin['company_id'])) {
            ok(['alerts' => []]);
        }
        $where[] = 'u.company_id = ?';
        $params[] = (int)$admin['company_id'];
    }

    $sql = 'SELECT la.*, u.name AS user_name, u.email AS user_email, c.name AS company_name,
                   ar.work_date, ar.entry_time, ar.exit_time
              FROM location_alerts la
              JOIN users u ON u.id = la.user_id
         LEFT JOIN companies c ON c.id = u.company_id
              JOIN attendance_records ar ON ar.id = la.attendance_id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY la.triggered_at DESC
             LIMIT 500';
    $rows = db_all($sql, $params);

    ok(['alerts' => array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'user_email' => $r['user_email'],
            'company_name' => $r['company_name'],
            'attendance_id' => (int)$r['attendance_id'],
            'work_date' => $r['work_date'],
            'entry_time' => $r['entry_time'],
            'exit_time' => $r['exit_time'],
            'triggered_at' => $r['triggered_at'],
            'reason_codes' => $r['reason_codes'],
            'prev_country_code' => $r['prev_country_code'],
            'prev_city' => $r['prev_city'],
            'prev_lat' => $r['prev_lat'] !== null ? (float)$r['prev_lat'] : null,
            'prev_lon' => $r['prev_lon'] !== null ? (float)$r['prev_lon'] : null,
            'prev_marked_at' => $r['prev_marked_at'],
            'curr_country_code' => $r['curr_country_code'],
            'curr_city' => $r['curr_city'],
            'curr_lat' => $r['curr_lat'] !== null ? (float)$r['curr_lat'] : null,
            'curr_lon' => $r['curr_lon'] !== null ? (float)$r['curr_lon'] : null,
            'distance_km' => $r['distance_km'] !== null ? (float)$r['distance_km'] : null,
            'elapsed_minutes' => $r['elapsed_minutes'] !== null ? (int)$r['elapsed_minutes'] : null,
            'implied_speed_kmh' => $r['implied_speed_kmh'] !== null ? (float)$r['implied_speed_kmh'] : null,
            'status' => $r['status'],
            'reviewed_by' => $r['reviewed_by'] !== null ? (int)$r['reviewed_by'] : null,
            'reviewed_at' => $r['reviewed_at'],
            'notes' => $r['notes'],
        ];
    }, $rows)]);
}

/**
 * GET admin/location-alerts/pending-count — Conteo de alertas pendientes para la card del dashboard.
 */
function admin_location_alerts_pending_count(): never {
    $admin = require_admin();
    $where = ["la.status = 'pending'"];
    $params = [];
    if (($admin['role'] ?? '') !== 'super_admin') {
        if (empty($admin['company_id'])) ok(['pending' => 0]);
        $where[] = 'u.company_id = ?';
        $params[] = (int)$admin['company_id'];
    }
    $sql = 'SELECT COUNT(*) AS c FROM location_alerts la
              JOIN users u ON u.id = la.user_id
             WHERE ' . implode(' AND ', $where);
    $row = db_one($sql, $params);
    ok(['pending' => (int)($row['c'] ?? 0)]);
}

/**
 * POST admin/location-alerts/{id}/review — Cambia status a reviewed o dismissed.
 * Body: { decision: 'reviewed'|'dismissed', notes?: string }.
 */
function admin_location_alerts_review(int $alertId, array $body): never {
    require_csrf();
    $admin = require_admin();
    $decision = validate_string($body, 'decision', 1, 20);
    if (!in_array($decision, ['reviewed', 'dismissed'], true)) {
        err('INVALID_INPUT', 'Decision invalida.', 400, ['field' => 'decision']);
    }
    $notes = validate_string($body, 'notes', 0, 500, false) ?? '';

    $alert = db_one(
        'SELECT la.*, u.company_id FROM location_alerts la
           JOIN users u ON u.id = la.user_id
          WHERE la.id = ?',
        [$alertId]
    );
    if (!$alert) err('NOT_FOUND', 'Alerta no existe.', 404);

    // Tenant isolation.
    if (($admin['role'] ?? '') !== 'super_admin') {
        if (empty($admin['company_id']) || (int)$alert['company_id'] !== (int)$admin['company_id']) {
            err('FORBIDDEN', 'No puedes revisar alertas de otra empresa.', 403);
        }
    }

    if ($alert['status'] !== 'pending') {
        err('CONFLICT', 'La alerta ya fue resuelta.', 409, ['status' => $alert['status']]);
    }

    db_exec(
        "UPDATE location_alerts
            SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, notes = ?
          WHERE id = ?",
        [$decision, (int)$admin['id'], $notes !== '' ? $notes : null, $alertId]
    );
    audit_log((int)$admin['id'], 'location_alert_review', [
        'alert_id' => $alertId, 'decision' => $decision
    ]);
    ok(['message' => 'Alerta actualizada.', 'status' => $decision]);
}


/**
 * POST admin/migrations/run — Ejecuta migraciones idempotentes desde HTTP.
 * Solo super_admin. Usado cuando el hosting bloquea SSH y no se pueden
 * correr scripts/migrate_*.php via CLI.
 * Body: { name: 'location_alerts' }
 */
function admin_migrations_run(array $body): never {
    require_csrf();
    require_super_admin();
    $name = validate_string($body, 'name', 1, 60);
    require_once __DIR__ . '/migrations.php';
    $log = [];
    try {
        if ($name === 'location_alerts') {
            $log = run_migration_location_alerts(Database::pdo());
        } else {
            err('INVALID_INPUT', "Migracion desconocida: {$name}", 400, ['field' => 'name']);
        }
    } catch (Throwable $e) {
        $log[] = 'ERROR: ' . $e->getMessage();
        err('SERVER_ERROR', 'Migracion fallo. Revisa el log.', 500, ['log' => $log]);
    }
    audit_log((int)(current_user()['id'] ?? 0), 'migration_run', ['name' => $name]);
    ok(['migration' => $name, 'log' => $log]);
}
