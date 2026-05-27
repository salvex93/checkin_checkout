<?php
declare(strict_types=1);

// =====================================================================
// security_headers.php — Headers de seguridad obligatorios (OWASP A05).
// Se invoca al inicio de cada request HTTP, antes de emitir contenido.
// =====================================================================

function emit_security_headers(): void {
    // X-Content-Type-Options: evita MIME sniffing por parte del navegador.
    header('X-Content-Type-Options: nosniff');

    // X-Frame-Options: bloquea embebido en iframes (anti-clickjacking).
    header('X-Frame-Options: DENY');

    // Referrer-Policy: no fugar URLs internas a recursos externos.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions-Policy: deshabilita APIs sensibles que no usamos.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // HSTS: solo cuando estamos en produccion (HTTPS real).
    // En localhost http no se emite porque rompe el dominio si el usuario
    // luego accede por http intencionalmente.
    if (IS_PROD) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // CSP endurecido: 'unsafe-inline' eliminado de script-src. El SPA se sirve
    // precompilado desde /assets/app.js (build local). El unico bloque inline
    // sobreviviente (bootstrap Tailwind + theme) se autoriza por hash SHA-256.
    // 'unsafe-eval' permanece porque Tailwind CDN compila utilidades en runtime;
    // se eliminara cuando migremos Tailwind a build offline.
    $csp = "default-src 'self'; "
         . "script-src 'self' "
            . "https://unpkg.com/react@18/ "
            . "https://unpkg.com/react-dom@18/ "
            . "https://cdn.tailwindcss.com "
            . "https://cdnjs.cloudflare.com/ajax/libs/color-thief/ "
            . "'sha256-1kDBUhvyKLul8Jz9ors9S4ZyaJjRctUx1H1Du3bSZj0=' "
            . "'unsafe-eval'; "
         . "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "img-src 'self' data: blob: https:; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'";
    header("Content-Security-Policy: {$csp}");

    // Cache control para endpoints API: no cachear respuestas con datos del usuario.
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Cache-Control: no-store');
    }
}

/**
 * CORS controlado por lista blanca de origenes. Solo se aplican headers CORS
 * cuando el Origin del request coincide con la lista configurada.
 * Esto evita el patron inseguro de 'Access-Control-Allow-Origin: *' con credenciales.
 */
function handle_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $allowed = array_map('trim', explode(',', (string)env('CORS_ALLOWED_ORIGINS', '')));
    if (!in_array($origin, $allowed, true)) return;

    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
