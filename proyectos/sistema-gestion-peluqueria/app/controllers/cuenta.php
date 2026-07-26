<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function cuenta_index(): void
{
    requiere_login();
    $u = fetch_one(
        "SELECT u.username,u.nombre,u.apellido,u.email,u.telefono,r.nombre AS rol
           FROM usuario u JOIN rol r ON r.id_rol=u.id_rol WHERE u.id_usuario=?",
        [(int)$_SESSION['uid']]
    );
    $bioActivo = (int)fetch_val("SELECT COUNT(*) FROM credencial_webauthn WHERE id_usuario=?", [(int)$_SESSION['uid']]) > 0;
    view('cuenta/index', ['perfil' => $u, 'bioActivo' => $bioActivo], 'Mi cuenta');
}

function cuenta_password(): void
{
    requiere_login();
    $actual = (string)post('actual', '');
    $nueva  = (string)post('nueva', '');
    $nueva2 = (string)post('nueva2', '');
    $uid = (int)$_SESSION['uid'];

    $hash = (string)fetch_val("SELECT password_hash FROM usuario WHERE id_usuario=?", [$uid]);
    if (!password_verify($actual, $hash)) {
        flash('La contraseña actual no es correcta.', 'error');
    } elseif (strlen($nueva) < 6) {
        flash('La nueva contraseña debe tener al menos 6 caracteres.', 'error');
    } elseif ($nueva !== $nueva2) {
        flash('Las contraseñas nuevas no coinciden.', 'error');
    } else {
        q("UPDATE usuario SET password_hash=? WHERE id_usuario=?", [password_hash($nueva, PASSWORD_DEFAULT), $uid]);
        auditar('CAMBIO_PASSWORD', 'Cuenta', 'usuario', $uid, 'El usuario cambió su contraseña');
        flash('Contraseña actualizada.');
    }
    redirect('index.php?r=cuenta/index');
}
