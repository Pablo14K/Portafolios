<?php
// =====================================================================
//  Front controller — punto de entrada único
//  URL:  index.php?r=<controlador>/<accion>
// =====================================================================
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/mail.php';
require_once __DIR__ . '/../app/webauthn.php';
require_once __DIR__ . '/../app/migrations.php';

// Si la base se reimportó, recrea las tablas de apoyo automáticamente
asegurar_migraciones();

// Ruta pedida (por defecto: dashboard)
$r = (string)($_GET['r'] ?? 'dashboard/index');
$r = preg_replace('/[^a-z0-9_\/]/i', '', $r);
[$ctrl, $accion] = array_pad(explode('/', $r, 2), 2, 'index');

$ctrl   = $ctrl ?: 'dashboard';
$accion = $accion ?: 'index';

$archivo = __DIR__ . '/../app/controllers/' . $ctrl . '.php';
if (!is_file($archivo)) {
    http_response_code(404);
    $titulo = 'Página no encontrada';
    require __DIR__ . '/../app/views/error404.php';
    exit;
}

require_once $archivo;
$funcion = $ctrl . '_' . $accion;   // ej: clientes_index()

if (!function_exists($funcion)) {
    http_response_code(404);
    $titulo = 'Acción no encontrada';
    require __DIR__ . '/../app/views/error404.php';
    exit;
}

csrf_check();      // valida token en cualquier POST
$funcion();        // ejecuta la acción
