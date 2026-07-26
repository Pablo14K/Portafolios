<?php
// =====================================================================
//  Instalador rápido — crea los usuarios iniciales.
//  La base peluqueria_bd no trae usuarios cargados (las contraseñas se
//  hashean desde PHP). Ejecutá este archivo UNA vez y luego borralo.
// =====================================================================
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/migrations.php';

header('Content-Type: text/html; charset=utf-8');
echo '<div style="font-family:sans-serif;max-width:640px;margin:2rem auto;line-height:1.6">';
echo '<h2>Instalador — ' . APP_NAME . '</h2>';

try {
    $pdo = db();
    migrar();   // crea las tablas de apoyo (verificación, biométrico, roles)
    echo '<p style="color:#2f5d2f">✔ Tablas de apoyo verificadas.</p>';

    // ¿Ya hay usuarios?
    $existentes = (int)$pdo->query("SELECT COUNT(*) FROM usuario")->fetchColumn();
    if ($existentes > 0) {
        echo '<p style="color:#8a6d0b">Ya existen ' . $existentes . ' usuario(s). No se crea nada para no pisar datos.</p>';
        echo '<p>Si querés recrear el admin, borrá los usuarios en phpMyAdmin y volvé a correr este archivo.</p>';
        echo '</div>';
        exit;
    }

    // --- Usuario administrador (Propietaria) ---
    $adminPass = 'admin123';
    $pdo->prepare(
        "INSERT INTO usuario (id_rol,id_sucursal,username,nombre,apellido,email,password_hash,fecha_ingreso,activo)
         VALUES (1,1,'admin','Ana','Propietaria','admin@peluqueria.com',?,CURDATE(),1)"
    )->execute([password_hash($adminPass, PASSWORD_DEFAULT)]);

    // --- Cliente demo con acceso al portal ---
    $pdo->prepare(
        "INSERT INTO usuario (id_rol,username,nombre,apellido,email,password_hash,activo)
         VALUES (4,'cliente','Ana','Giménez','ana.cliente@example.com',?,1)"
    )->execute([password_hash('cliente123', PASSWORD_DEFAULT)]);
    $idUsuarioCliente = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO cliente (id_usuario,nombre,apellido,telefono,email,activo)
         VALUES (?, 'Ana','Giménez','0981-000000','ana.cliente@example.com',1)"
    )->execute([$idUsuarioCliente]);

    echo '<p style="color:#2f5d2f">✔ Usuarios creados correctamente.</p>';
    echo '<table cellpadding="6" style="border-collapse:collapse;margin:1rem 0">';
    echo '<tr style="background:#f7f5f2"><th align="left">Perfil</th><th align="left">Usuario</th><th align="left">Contraseña</th></tr>';
    echo '<tr><td>Propietaria (gestión)</td><td><code>admin</code></td><td><code>admin123</code></td></tr>';
    echo '<tr><td>Cliente (portal)</td><td><code>cliente</code></td><td><code>cliente123</code></td></tr>';
    echo '</table>';
    echo '<p style="color:#993535;font-weight:bold">Importante: por seguridad, borrá este archivo (public/install.php) y cambiá las contraseñas.</p>';
    echo '<p><a href="index.php?r=auth/login">Ir al login →</a></p>';
} catch (Throwable $e) {
    echo '<p style="color:#993535">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Verificá que MySQL esté encendido y que la base <code>' . DB_NAME . '</code> esté importada.</p>';
}
echo '</div>';
