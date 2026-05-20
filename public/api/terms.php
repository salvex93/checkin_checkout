<?php
declare(strict_types=1);

// =====================================================================
// terms.php — Versionado y aceptacion de Terminos y Aviso de Privacidad.
//
// Flujo: la sesion del usuario queda bloqueada en endpoints de jornada
// hasta que acepte la version activa. La aceptacion deja huella inmutable
// (version, fecha, IP, user_agent). Si super_admin publica una nueva version,
// se fuerza re-aceptacion automaticamente (cambia el id activo).
// =====================================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

/**
 * Devuelve la version de T&C activa o null si no hay ninguna sembrada.
 */
function terms_active_version(): ?array {
    return db_one(
        'SELECT id, version, title, body_html, privacy_html, published_at
           FROM terms_versions WHERE is_active = 1
          ORDER BY id DESC LIMIT 1'
    ) ?: null;
}

/**
 * True si el usuario ha aceptado la version activa actual.
 * Si no hay version activa (instalacion fresca sin seed), considera aceptado.
 */
function user_has_accepted_active_terms(int $userId): bool {
    $active = terms_active_version();
    if (!$active) return true;
    $row = db_one(
        'SELECT id FROM user_terms_acceptance WHERE user_id = ? AND terms_version_id = ? LIMIT 1',
        [$userId, (int)$active['id']]
    );
    return $row !== null && $row !== false;
}

/**
 * Gate para endpoints que requieren T&C aceptados (jornada, overtime).
 * Solo aplica si existe version activa.
 */
function require_terms_accepted(array $user): void {
    $active = terms_active_version();
    if (!$active) return;
    if (user_has_accepted_active_terms((int)$user['id'])) return;
    err(
        'TERMS_REQUIRED',
        'Debes aceptar los Terminos y el Aviso de Privacidad antes de continuar.',
        403,
        ['version' => $active['version']]
    );
}

// === Endpoints publicos / autenticados ===

/**
 * GET terms/current — devuelve la version activa (publico).
 */
function terms_current(): never {
    $active = terms_active_version();
    if (!$active) {
        ok(['terms' => null]);
    }
    ok([
        'terms' => [
            'id' => (int)$active['id'],
            'version' => $active['version'],
            'title' => $active['title'],
            'body_html' => $active['body_html'],
            'privacy_html' => $active['privacy_html'],
            'published_at' => $active['published_at'],
        ]
    ]);
}

/**
 * POST terms/accept — usuario autenticado acepta la version activa.
 * Idempotente: si ya acepto, devuelve OK sin reinsertar.
 */
function terms_accept(array $body): never {
    require_csrf();
    $u = require_login();
    $active = terms_active_version();
    if (!$active) {
        err('NO_TERMS_PUBLISHED', 'No hay terminos publicados.', 409);
    }
    $versionId = (int)$active['id'];
    $declaredVersion = validate_string($body, 'version', 1, 20, false);
    if ($declaredVersion !== null && $declaredVersion !== $active['version']) {
        err('TERMS_VERSION_MISMATCH', 'La version aceptada no coincide con la activa.', 409, [
            'expected' => $active['version'],
            'received' => $declaredVersion,
        ]);
    }

    $existing = db_one(
        'SELECT id FROM user_terms_acceptance WHERE user_id = ? AND terms_version_id = ?',
        [(int)$u['id'], $versionId]
    );
    if (!$existing) {
        db_exec(
            'INSERT INTO user_terms_acceptance (user_id, terms_version_id, ip_address, user_agent)
                  VALUES (?, ?, ?, ?)',
            [
                (int)$u['id'],
                $versionId,
                client_ip(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
        audit_log((int)$u['id'], 'terms_accepted', ['version' => $active['version']]);
    }
    ok(['message' => 'Terminos aceptados.', 'version' => $active['version']]);
}

// === Admin: super_admin gestiona versiones ===

/**
 * GET admin/terms — lista todas las versiones (solo super_admin).
 */
function admin_terms_list(): never {
    require_super_admin();
    $rows = db_all(
        'SELECT id, version, title, published_at, is_active
           FROM terms_versions ORDER BY id DESC'
    );
    ok(['versions' => array_map(fn($r) => [
        'id' => (int)$r['id'],
        'version' => $r['version'],
        'title' => $r['title'],
        'published_at' => $r['published_at'],
        'is_active' => (int)$r['is_active'] === 1,
    ], $rows)]);
}

/**
 * POST admin/terms — crea una nueva version y la activa, desactivando previas.
 * Fuerza re-aceptacion para todos los usuarios al cambiar el active id.
 */
function admin_terms_create(array $body): never {
    require_csrf();
    require_super_admin();

    $version = validate_string($body, 'version', 1, 20);
    $title = validate_string($body, 'title', 1, 200);
    $body_html = validate_string($body, 'body_html', 10, 200_000);
    $privacy_html = validate_string($body, 'privacy_html', 10, 200_000);

    $dup = db_one('SELECT id FROM terms_versions WHERE version = ?', [$version]);
    if ($dup) {
        err('VERSION_EXISTS', 'Ya existe una version con ese identificador.', 409, ['field' => 'version']);
    }

    Database::pdo()->beginTransaction();
    try {
        db_exec('UPDATE terms_versions SET is_active = 0');
        db_exec(
            'INSERT INTO terms_versions (version, title, body_html, privacy_html, is_active)
                  VALUES (?, ?, ?, ?, 1)',
            [$version, $title, $body_html, $privacy_html]
        );
        $newId = (int)db_last_id();
        Database::pdo()->commit();
    } catch (Throwable $e) {
        if (Database::pdo()->inTransaction()) Database::pdo()->rollBack();
        error_log('[admin_terms_create] ' . $e->getMessage());
        err('SERVER_ERROR', 'No se pudo publicar la version.', 500);
    }

    audit_log((int)($_SESSION['user_id'] ?? 0), 'terms_version_published', [
        'version' => $version, 'version_id' => $newId
    ]);
    ok(['id' => $newId, 'version' => $version, 'is_active' => true]);
}
