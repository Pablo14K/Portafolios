<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Arranque comun de la app PHP. Carga .env, autoload simple y deja disponibles clases usadas por public/index.php y scripts de cola.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


// Raíz absoluta del proyecto (la carpeta que contiene app/, db/, storage/).
// Sirve para anclar las rutas de storage de forma independiente del directorio
// de trabajo (clave en cPanel/Apache, donde el CWD no es la raíz del proyecto).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(function (string $class): void {
    $prefix  = 'App\\';
    $baseDir = __DIR__ . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});

(function (): void {
    $candidates = [__DIR__ . '/../.env', dirname(__DIR__) . '/.env'];
    foreach ($candidates as $path) {
        if (!is_file($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim(trim($v), "\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
            }
        }
        break;
    }
})();

$timezone = getenv('APP_TIMEZONE') ?: 'America/Asuncion';
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'America/Asuncion';
}
date_default_timezone_set($timezone);

/**
 * Comentario de codigo: Funcion auxiliar usada por este archivo para mantener el flujo legible.
 */
function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return $v === false ? $default : $v;
}
