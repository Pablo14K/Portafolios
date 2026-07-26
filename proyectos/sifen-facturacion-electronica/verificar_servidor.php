<?php
/*
 * verificar_servidor.php — Diagnóstico de entorno para SIFEN v150 en cPanel.
 *
 * USO:
 *   1) Subí este archivo a la carpeta pública (donde está index.php, o la raíz
 *      del proyecto si usás el .htaccess que enruta a app/public).
 *   2) Abrilo en el navegador:  https://tudominio.com/verificar_servidor.php
 *   3) Mirá que todo esté en verde.
 *   4) ⚠️ BORRÁ ESTE ARCHIVO cuando termines (no debe quedar en producción).
 *
 * No envía nada a ningún lado, no modifica datos. Solo lee y prueba.
 */

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

// ---- Buscar .env subiendo hasta 5 niveles ----
function buscarEnv(): ?array {
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $p = $dir . '/.env';
        if (is_file($p)) {
            $vars = [];
            foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim(trim($v), "\"'");
            }
            return ['path' => $p, 'vars' => $vars];
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

$rows = [];
$fail = 0;
$warn = 0;
function add(array &$rows, int &$fail, int &$warn, string $label, string $estado, string $detalle): void {
    $rows[] = [$label, $estado, $detalle];
    if ($estado === 'FAIL') $fail++;
    if ($estado === 'WARN') $warn++;
}

// ---- 1) PHP ----
$okPhp = version_compare(PHP_VERSION, '8.1.0', '>=');
add($rows, $fail, $warn, 'PHP 8.1 o superior', $okPhp ? 'OK' : 'FAIL', 'Versión detectada: ' . PHP_VERSION);

// ---- 2) Extensiones ----
foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'dom'] as $ext) {
    $ok = extension_loaded($ext);
    add($rows, $fail, $warn, "Extensión $ext", $ok ? 'OK' : 'FAIL', $ok ? 'cargada' : 'FALTA — activala en "Select PHP Version"');
}

// ---- 3) OpenSSL puede generar claves (firma del XML + certificado demo) ----
// Reproduce la misma lógica que DemoCertificateService: intenta normal y, si falla
// (típico de Windows), reintenta apuntando a un openssl.cnf. En Linux/cPanel pasa
// al primer intento. Así el resultado refleja lo que hará realmente la app.
$args = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$key = @openssl_pkey_new($args);
if ($key === false) {
    foreach (array_filter([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/php/extras/ssl/openssl.cnf']) as $cnf) {
        if (is_file($cnf)) { $key = @openssl_pkey_new($args + ['config' => $cnf]); if ($key !== false) break; }
    }
}
if ($key !== false) {
    add($rows, $fail, $warn, 'OpenSSL genera claves (firma / cert demo)', 'OK', 'puede generar la clave RSA para firmar');
} else {
    $errs = [];
    while (($e = openssl_error_string()) !== false) $errs[] = $e;
    add($rows, $fail, $warn, 'OpenSSL genera claves (firma / cert demo)', 'FAIL', 'openssl_pkey_new falló: ' . implode('; ', $errs));
}

// ---- 4) .env y storage ----
$env = buscarEnv();
$root = $env ? dirname($env['path']) : dirname(__DIR__);
$storageDir = $root . '/storage';
@mkdir($storageDir, 0775, true);
$tmp = $storageDir . '/_check_write_' . bin2hex(random_bytes(4)) . '.tmp';
$wrote = @file_put_contents($tmp, 'ok') !== false;
if ($wrote) @unlink($tmp);
add($rows, $fail, $warn, 'Carpeta storage/ escribible', $wrote ? 'OK' : 'FAIL', $storageDir . ($wrote ? '' : ' — ajustá permisos a 755/775'));

// ---- 5) Base de datos ----
if ($env === null) {
    add($rows, $fail, $warn, 'Archivo .env', 'WARN', 'No se encontró .env todavía (subilo y configurá DB_*). Sin esto no se prueba la BD.');
} else {
    add($rows, $fail, $warn, 'Archivo .env', 'OK', 'Encontrado en: ' . $env['path']);
    $v = $env['vars'];
    $host = $v['DB_HOST'] ?? 'localhost';
    $port = $v['DB_PORT'] ?? '3306';
    $name = $v['DB_NAME'] ?? '';
    $user = $v['DB_USER'] ?? '';
    $pass = $v['DB_PASS'] ?? '';
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tablas = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
        add($rows, $fail, $warn, 'Conexión MySQL', 'OK', "Conectado a '$name' ($tablas tablas)");
        $hayInv = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'invoices'")->fetchColumn();
        add($rows, $fail, $warn, 'Schema importado', $hayInv > 0 ? 'OK' : 'FAIL', $hayInv > 0 ? 'tabla invoices presente' : 'Importá db/init/base_de_datos_completa.sql en phpMyAdmin');
    } catch (Throwable $e) {
        add($rows, $fail, $warn, 'Conexión MySQL', 'FAIL', 'No conecta — revisá DB_HOST/DB_NAME/DB_USER/DB_PASS en .env. (' . $e->getMessage() . ')');
    }
}

// ---- 6) Info extra (no bloqueante) ----
$mailFns = function_exists('fsockopen') || function_exists('stream_socket_client');
add($rows, $fail, $warn, 'Sockets para SMTP', $mailFns ? 'OK' : 'WARN', $mailFns ? 'disponibles (el envío usa sockets)' : 'fsockopen/stream_socket_client deshabilitados');

$color = ['OK' => '#16a34a', 'WARN' => '#d97706', 'FAIL' => '#dc2626'];
$icono = ['OK' => '✅', 'WARN' => '⚠️', 'FAIL' => '❌'];
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verificación de servidor — SIFEN v150</title>
<style>
 body{font:15px/1.5 'Segoe UI',Arial,sans-serif;background:#f0f2f6;color:#0f172a;margin:0;padding:24px}
 .box{max-width:780px;margin:0 auto;background:#fff;border:1px solid #d1d9e6;border-radius:12px;padding:24px 26px;box-shadow:0 1px 6px rgba(0,0,0,.06)}
 h1{font-size:20px;margin:0 0 4px}
 .sub{color:#64748b;font-size:13px;margin-bottom:18px}
 table{width:100%;border-collapse:collapse}
 th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;padding:8px 6px;border-bottom:2px solid #eef2f7}
 td{padding:10px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top}
 .estado{font-weight:800;white-space:nowrap}
 .det{color:#475569;font-size:13px}
 .resumen{margin-top:18px;padding:14px 16px;border-radius:9px;font-weight:700}
 .ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
 .bad{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
 .borrar{margin-top:16px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:12px 14px;border-radius:9px;font-size:13px}
</style></head>
<body><div class="box">
 <h1>🔍 Verificación de servidor — SIFEN v150</h1>
 <div class="sub">Entorno PHP en <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'este servidor') ?> · <?= date('Y-m-d H:i') ?></div>
 <table>
  <thead><tr><th>Chequeo</th><th>Estado</th><th>Detalle</th></tr></thead>
  <tbody>
  <?php foreach ($rows as [$label, $estado, $detalle]): ?>
   <tr>
    <td><strong><?= htmlspecialchars($label) ?></strong></td>
    <td class="estado" style="color:<?= $color[$estado] ?>"><?= $icono[$estado] ?> <?= $estado ?></td>
    <td class="det"><?= htmlspecialchars($detalle) ?></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table>
 <?php if ($fail === 0): ?>
  <div class="resumen ok">✅ Entorno listo<?= $warn ? " (con $warn advertencia(s) no bloqueante(s))" : '' ?>. Podés desplegar y usar el sistema.</div>
 <?php else: ?>
  <div class="resumen bad">❌ Hay <?= $fail ?> problema(s) que corregir antes de usar el sistema (ver filas en rojo).</div>
 <?php endif; ?>
 <div class="borrar">⚠️ <strong>Importante:</strong> borrá <code>verificar_servidor.php</code> del servidor cuando termines. No debe quedar accesible en producción.</div>
</div></body></html>
