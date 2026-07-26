<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function auth_login(): void
{
    if (esta_logueado()) {
        redirect('index.php?r=' . (es_cliente() ? 'portal/index' : 'dashboard/index'));
    }

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $usuario = trim((string)post('usuario', ''));
        $pass    = (string)post('password', '');
        if ($usuario === '' || $pass === '') {
            $error = 'Ingresá usuario y contraseña.';
        } elseif (intentar_login($usuario, $pass)) {
            // Primera vez: ofrecer el login con huella si nunca se preguntó
            $uid = (int)$_SESSION['uid'];
            $tiene = (int)fetch_val("SELECT COUNT(*) FROM credencial_webauthn WHERE id_usuario=?", [$uid]);
            $pregunt = (int)(fetch_val("SELECT biometrico_pregunt FROM preferencia_usuario WHERE id_usuario=?", [$uid]) ?: 0);
            if (!$tiene && !$pregunt) {
                redirect('index.php?r=webauthn/preguntar');
            }
            redirect('index.php?r=' . (es_cliente() ? 'portal/index' : 'dashboard/index'));
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
    require __DIR__ . '/../views/auth/login.php';
}

function auth_logout(): void
{
    logout();
    redirect('index.php?r=auth/login');
}

// --- Registro de clientes nuevos (autoservicio) ---
function auth_registro(): void
{
    if (esta_logueado()) {
        redirect('index.php?r=' . (es_cliente() ? 'portal/index' : 'dashboard/index'));
    }
    $error = null;
    $old = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $old = [
            'nombre'   => trim((string)post('nombre', '')),
            'apellido' => trim((string)post('apellido', '')),
            'email'    => trim((string)post('email', '')),
            'telefono' => trim((string)post('telefono', '')),
            'username' => trim((string)post('username', '')),
        ];
        $pass  = (string)post('password', '');
        $pass2 = (string)post('password2', '');

        if ($old['nombre'] === '' || $old['apellido'] === '' || $old['email'] === '' || $old['username'] === '' || $pass === '') {
            $error = 'Completá nombre, apellido, email, usuario y contraseña.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no tiene un formato válido.';
        } elseif (strlen($pass) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($pass !== $pass2) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (fetch_val('SELECT COUNT(*) FROM usuario WHERE username=?', [$old['username']])) {
            $error = 'Ese nombre de usuario ya está en uso.';
        } elseif (fetch_val('SELECT COUNT(*) FROM usuario WHERE email=?', [$old['email']])) {
            $error = 'Ya existe una cuenta con ese email.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                // Cuenta creada INACTIVA hasta verificar el correo
                $st = $pdo->prepare(
                    'INSERT INTO usuario (id_rol,username,nombre,apellido,telefono,email,password_hash,activo)
                     VALUES (?,?,?,?,?,?,?,0)'
                );
                $st->execute([
                    ROL_CLIENTE, $old['username'], $old['nombre'], $old['apellido'],
                    $old['telefono'] ?: null, $old['email'], password_hash($pass, PASSWORD_DEFAULT),
                ]);
                $idu = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO cliente (id_usuario,nombre,apellido,telefono,email,activo)
                     VALUES (?,?,?,?,?,1)'
                )->execute([$idu, $old['nombre'], $old['apellido'], $old['telefono'] ?: null, $old['email']]);
                $pdo->commit();

                // Genera y envía el código de verificación
                enviar_codigo_seguridad($idu, 'VERIFICACION', $old['email'], $old['nombre']);
                $_SESSION['verif_uid'] = $idu;
                $_SESSION['verif_email'] = $old['email'];
                flash('Te enviamos un código de verificación a ' . $old['email'] . '.');
                redirect('index.php?r=auth/verificar');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'No se pudo crear la cuenta. Intentá con otro usuario o email.';
            }
        }
    }
    require __DIR__ . '/../views/auth/registro.php';
}

// --- Helper: genera un código, lo guarda y lo envía por correo ---
function enviar_codigo_seguridad(int $idUsuario, string $tipo, string $email, string $nombre = ''): bool
{
    $codigo = generar_codigo();
    // Invalida códigos anteriores del mismo tipo y crea el nuevo (30 min de validez)
    q("UPDATE token_seguridad SET usado=1 WHERE id_usuario=? AND tipo=? AND usado=0", [$idUsuario, $tipo]);
    q("INSERT INTO token_seguridad (id_usuario,tipo,codigo,expira_en) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))",
      [$idUsuario, $tipo, $codigo]);

    $asunto = $tipo === 'VERIFICACION' ? 'Verificá tu cuenta' : 'Recuperación de contraseña';
    $intro  = $tipo === 'VERIFICACION'
        ? 'Usá este código para terminar de crear tu cuenta:'
        : 'Usá este código para restablecer tu contraseña:';
    $html = plantilla_correo($asunto,
        ($nombre ? '<p>Hola ' . htmlspecialchars($nombre) . ',</p>' : '')
        . '<p>' . $intro . '</p>'
        . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;color:#8A6C1E;text-align:center;margin:18px 0">' . $codigo . '</p>'
        . '<p style="color:#888;font-size:13px">El código vence en 30 minutos. Si no fuiste vos, ignorá este correo.</p>');

    $err = null;
    $ok = enviar_correo($email, $asunto . ' · Peluquería Luque', $html, $err);
    if (!$ok) { error_log('SPG mail error: ' . $err); }
    return $ok;
}

// Valida un código; si es correcto, lo marca usado y devuelve true
function validar_codigo(int $idUsuario, string $tipo, string $codigo): bool
{
    $row = fetch_one(
        "SELECT id_token FROM token_seguridad
          WHERE id_usuario=? AND tipo=? AND codigo=? AND usado=0 AND expira_en >= NOW()
          ORDER BY id_token DESC LIMIT 1",
        [$idUsuario, $tipo, $codigo]
    );
    if (!$row) return false;
    q("UPDATE token_seguridad SET usado=1 WHERE id_token=?", [$row['id_token']]);
    return true;
}

// --- Verificación de cuenta (tras el registro) ---
function auth_verificar(): void
{
    $idu = (int)($_SESSION['verif_uid'] ?? 0);
    $email = (string)($_SESSION['verif_email'] ?? '');
    if (!$idu) { redirect('index.php?r=auth/login'); }

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (post('reenviar')) {
            $u = fetch_one("SELECT nombre FROM usuario WHERE id_usuario=?", [$idu]);
            enviar_codigo_seguridad($idu, 'VERIFICACION', $email, $u['nombre'] ?? '');
            flash('Te reenviamos el código.');
            redirect('index.php?r=auth/verificar');
        }
        $codigo = trim((string)post('codigo', ''));
        if (validar_codigo($idu, 'VERIFICACION', $codigo)) {
            q("UPDATE usuario SET activo=1 WHERE id_usuario=?", [$idu]);
            $username = (string)fetch_val("SELECT username FROM usuario WHERE id_usuario=?", [$idu]);
            unset($_SESSION['verif_uid'], $_SESSION['verif_email']);
            // Inicia sesión (login por username sin re-pedir contraseña ya no aplica; marcamos sesión)
            $_SESSION['uid'] = $idu;
            $_SESSION['rol'] = ROL_CLIENTE;
            $u = fetch_one("SELECT nombre,apellido FROM usuario WHERE id_usuario=?", [$idu]);
            $_SESSION['nombre'] = $u['nombre'] . ' ' . $u['apellido'];
            $_SESSION['rol_nom'] = 'Cliente';
            $_SESSION['id_cliente'] = fetch_val("SELECT id_cliente FROM cliente WHERE id_usuario=?", [$idu]) ?: null;
            auditar('VERIFICACION', 'Seguridad', 'usuario', $idu, 'Cuenta verificada por correo');
            flash('¡Cuenta verificada! Ya podés reservar tu cita.');
            redirect('index.php?r=portal/index');
        }
        $error = 'Código incorrecto o vencido.';
    }
    require __DIR__ . '/../views/auth/verificar.php';
}

// --- Recuperación de contraseña: pedir el email ---
function auth_recuperar(): void
{
    if (esta_logueado()) redirect('index.php?r=dashboard/index');
    $enviado = false; $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $email = trim((string)post('email', ''));
        $u = fetch_one("SELECT id_usuario,nombre FROM usuario WHERE email=? AND activo=1 LIMIT 1", [$email]);
        if ($u) {
            enviar_codigo_seguridad((int)$u['id_usuario'], 'RECUPERACION', $email, $u['nombre']);
            $_SESSION['recup_uid'] = (int)$u['id_usuario'];
            $_SESSION['recup_email'] = $email;
            redirect('index.php?r=auth/recuperar_codigo');
        }
        // No revelamos si el email existe o no
        $enviado = true;
    }
    require __DIR__ . '/../views/auth/recuperar.php';
}

// --- Recuperación: ingresar código + nueva contraseña ---
function auth_recuperar_codigo(): void
{
    $idu = (int)($_SESSION['recup_uid'] ?? 0);
    $email = (string)($_SESSION['recup_email'] ?? '');
    if (!$idu) redirect('index.php?r=auth/recuperar');

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $codigo = trim((string)post('codigo', ''));
        $nueva  = (string)post('nueva', '');
        $nueva2 = (string)post('nueva2', '');
        if (strlen($nueva) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $nueva2) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!validar_codigo($idu, 'RECUPERACION', $codigo)) {
            $error = 'Código incorrecto o vencido.';
        } else {
            q("UPDATE usuario SET password_hash=? WHERE id_usuario=?", [password_hash($nueva, PASSWORD_DEFAULT), $idu]);
            unset($_SESSION['recup_uid'], $_SESSION['recup_email']);
            auditar('RECUPERACION', 'Seguridad', 'usuario', $idu, 'Contraseña restablecida por correo');
            flash('Tu contraseña fue restablecida. Ya podés iniciar sesión.');
            redirect('index.php?r=auth/login');
        }
    }
    require __DIR__ . '/../views/auth/recuperar_codigo.php';
}
