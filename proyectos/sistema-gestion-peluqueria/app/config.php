<?php
// =====================================================================
//  Configuración general del Sistema de Gestión de Peluquería (SPG)
// =====================================================================
declare(strict_types=1);

// --- Base de datos (XAMPP por defecto: root sin contraseña) ---
// Se pueden sobrescribir con variables de entorno (SPG_DB_HOST, etc.) sin
// tocar este archivo; si no existen, se usan los valores por defecto de XAMPP.
define('DB_HOST', getenv('SPG_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('SPG_DB_PORT') ?: '3306');
define('DB_NAME', getenv('SPG_DB_NAME') ?: 'peluqueria_bd');
define('DB_USER', getenv('SPG_DB_USER') ?: 'root');
define('DB_PASS', getenv('SPG_DB_PASS') !== false ? getenv('SPG_DB_PASS') : '');

// --- Aplicación ---
const APP_NAME = 'Peluquería Luque';
const APP_TZ   = 'America/Asuncion';
const MONEDA   = 'Gs.';   // Guaraní paraguayo

date_default_timezone_set(APP_TZ);

// Ruta base para las URLs. Se calcula a partir de la carpeta donde vive
// public/index.php, para que funcione tanto en la raíz de htdocs como en
// un subdirectorio (ej. http://localhost/Sistema_Gestion_Peluqueria/public).
function base_url(string $path = ''): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim($dir, '/');
    return $dir . '/' . ltrim($path, '/');
}
