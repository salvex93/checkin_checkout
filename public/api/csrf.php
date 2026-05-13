<?php
declare(strict_types=1);

// =====================================================================
// csrf.php — Proteccion CSRF via token sincronizado por sesion.
// El cliente recibe el token en GET /api/csrf y debe enviarlo en el header
// X-CSRF-Token en TODA request mutante (POST/PUT/DELETE). El server compara
// con timing-safe el token del header contra el token guardado en sesion.
// =====================================================================

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // GET y HEAD no mutan estado y no requieren CSRF
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return true;
    }
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($headerToken === '' || $sessionToken === '') return false;
    // hash_equals previene timing attacks
    return hash_equals($sessionToken, $headerToken);
}

function require_csrf(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['ok' => false, 'error' => ['code' => 'CSRF_INVALID', 'message' => 'Token CSRF invalido o ausente.']]));
    }
}
