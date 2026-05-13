<?php
// Router para el servidor integrado de PHP en desarrollo local.
// - Si el path empieza con /api -> delega a public/api/index.php
// - Si el archivo estatico existe en public/ -> lo sirve tal cual
// - Si no existe -> sirve public/index.html (SPA fallback)

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (preg_match('#^/api(/|$)#', $uri)) {
    require __DIR__ . '/../public/api/index.php';
    return true;
}

$publicDir = realpath(__DIR__ . '/../public');
$candidate = realpath($publicDir . $uri);

if ($candidate !== false
    && strpos($candidate, $publicDir) === 0
    && is_file($candidate)) {
    return false;
}

require $publicDir . '/index.html';
return true;
