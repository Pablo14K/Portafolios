<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function personal_index(): void
{
    requiere_modulo('personal');
    $subs = [
        ['r' => 'personal/usuarios',   'ic' => 'person-badge', 't' => 'Usuarios',    'd' => 'Cuentas y roles del personal'],
        ['r' => 'personal/turnos',     'ic' => 'clock',        't' => 'Turnos',      'd' => 'Jornadas laborales'],
        ['r' => 'personal/comisiones', 'ic' => 'percent',      't' => 'Comisiones',  'd' => 'Porcentaje por servicio'],
        ['r' => 'personal/asistencia', 'ic' => 'calendar-check','t' => 'Asistencia', 'd' => 'Entradas y salidas'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Personal', 'icono' => 'person-badge',
        'desc' => 'Usuarios, turnos, comisiones y asistencia.', 'subs' => $subs], 'Personal');
}

// ---------- Usuarios ----------
function personal_usuarios(): void
{
    requiere_modulo('personal');
    $rows = fetch_all(
        "SELECT u.id_usuario,u.nombre,u.apellido,u.username,u.email,u.activo,r.nombre AS rol
           FROM usuario u JOIN rol r ON r.id_rol=u.id_rol
          WHERE r.es_personal=1 ORDER BY u.nombre,u.apellido"
    );
    view('personal/usuarios', ['rows' => $rows], 'Usuarios');
}

function personal_usuario_form(): void
{
    // Solo la propietaria administra cuentas
    requiere_rol([ROL_PROPIETARIA]);
    $id = (int)get('id', 0);
    $u = $id ? fetch_one("SELECT * FROM usuario WHERE id_usuario=?", [$id]) : null;
    if ($id && !$u) { flash('Usuario no encontrado.', 'error'); redirect('index.php?r=personal/usuarios'); }
    $roles = fetch_all("SELECT * FROM rol WHERE es_personal=1 ORDER BY id_rol");
    $sucursales = fetch_all("SELECT id_sucursal, nombre FROM sucursal WHERE activo=1 ORDER BY nombre");
    view('personal/usuario_form', ['u' => $u, 'roles' => $roles, 'sucursales' => $sucursales], $id ? 'Editar usuario' : 'Nuevo usuario');
}

function personal_usuario_guardar(): void
{
    requiere_rol([ROL_PROPIETARIA]);
    $id = (int)post('id_usuario', 0);
    $d = [
        'id_rol'   => (int)post('id_rol', 3),
        'id_sucursal' => ((int)post('id_sucursal', 0)) ?: null,
        'username' => trim((string)post('username', '')),
        'nombre'   => trim((string)post('nombre', '')),
        'apellido' => trim((string)post('apellido', '')),
        'cedula'   => trim((string)post('cedula', '')) ?: null,
        'telefono' => trim((string)post('telefono', '')) ?: null,
        'email'    => trim((string)post('email', '')),
    ];
    $pass = (string)post('password', '');
    if ($d['username'] === '' || $d['nombre'] === '' || $d['apellido'] === '' || $d['email'] === '') {
        flash('Usuario, nombre, apellido y email son obligatorios.', 'error');
        redirect('index.php?r=personal/usuario_form' . ($id ? '&id=' . $id : ''));
    }
    try {
        if ($id) {
            q("UPDATE usuario SET id_rol=:id_rol,id_sucursal=:id_sucursal,username=:username,nombre=:nombre,apellido=:apellido,
                 cedula=:cedula,telefono=:telefono,email=:email WHERE id_usuario=:id", $d + ['id' => $id]);
            if ($pass !== '') {
                q("UPDATE usuario SET password_hash=? WHERE id_usuario=?", [password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            auditar('MODIFICACION', 'Personal', 'usuario', $id, $d['nombre'] . ' ' . $d['apellido']);
            flash('Usuario actualizado.');
        } else {
            if ($pass === '') { flash('La contraseña es obligatoria para un usuario nuevo.', 'error'); redirect('index.php?r=personal/usuario_form'); }
            $d['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            q("INSERT INTO usuario (id_rol,id_sucursal,username,nombre,apellido,cedula,telefono,email,password_hash)
               VALUES (:id_rol,:id_sucursal,:username,:nombre,:apellido,:cedula,:telefono,:email,:password_hash)", $d);
            auditar('ALTA', 'Personal', 'usuario', (int)db()->lastInsertId(), $d['nombre'] . ' ' . $d['apellido']);
            flash('Usuario creado.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿usuario, email o cédula duplicado?).', 'error');
        redirect('index.php?r=personal/usuario_form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=personal/usuarios');
}

function personal_usuario_baja(): void
{
    requiere_rol([ROL_PROPIETARIA]);
    $id = (int)post('id_usuario', 0);
    if ($id === (int)($_SESSION['uid'] ?? 0)) { flash('No podés desactivar tu propia cuenta.', 'warning'); redirect('index.php?r=personal/usuarios'); }
    q("UPDATE usuario SET activo = 1 - activo WHERE id_usuario=?", [$id]);
    flash('Estado del usuario actualizado.');
    redirect('index.php?r=personal/usuarios');
}

// ---------- Turnos ----------
function personal_turnos(): void
{
    requiere_modulo('personal');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $d = [
            'id_usuario'  => (int)post('id_usuario', 0),
            'id_sucursal' => 1,
            'fecha'       => (string)post('fecha', ''),
            'hora_inicio' => (string)post('hora_inicio', ''),
            'hora_fin'    => (string)post('hora_fin', ''),
        ];
        if ($d['id_usuario'] && $d['fecha'] && $d['hora_inicio'] && $d['hora_fin']) {
            try {
                q("INSERT INTO turno_laboral (id_usuario,id_sucursal,fecha,hora_inicio,hora_fin)
                   VALUES (:id_usuario,:id_sucursal,:fecha,:hora_inicio,:hora_fin)", $d);
                flash('Turno registrado.');
            } catch (PDOException $ex) { flash('Revisá el horario (fin > inicio) o si ya existe.', 'error'); }
        } else { flash('Completá todos los campos.', 'error'); }
        redirect('index.php?r=personal/turnos');
    }
    $rows = fetch_all(
        "SELECT t.*, CONCAT(u.nombre,' ',u.apellido) AS profesional
           FROM turno_laboral t JOIN usuario u ON u.id_usuario=t.id_usuario
          WHERE t.activo=1 ORDER BY t.fecha DESC, t.hora_inicio LIMIT 100"
    );
    $profs = fetch_all("SELECT id_usuario,nombre,apellido FROM usuario WHERE activo=1 AND id_rol IN (1,2,3) ORDER BY nombre");
    view('personal/turnos', ['rows' => $rows, 'profs' => $profs], 'Turnos');
}

// ---------- Comisiones ----------
function personal_comisiones(): void
{
    requiere_modulo('personal');
    $rows = fetch_all(
        "SELECT c.*, CONCAT(u.nombre,' ',u.apellido) AS profesional, COALESCE(s.nombre,'Todos los servicios') AS servicio
           FROM comision c JOIN usuario u ON u.id_usuario=c.id_usuario
           LEFT JOIN servicio s ON s.id_servicio=c.id_servicio
          WHERE c.activo=1 ORDER BY u.nombre, c.vigente_desde DESC"
    );
    view('personal/comisiones', ['rows' => $rows], 'Comisiones');
}

function personal_comision_form(): void
{
    requiere_modulo('personal');
    $profs = fetch_all("SELECT id_usuario,nombre,apellido FROM usuario WHERE activo=1 AND id_rol IN (1,2,3) ORDER BY nombre");
    $servicios = fetch_all("SELECT id_servicio,nombre FROM servicio WHERE activo=1 ORDER BY nombre");
    view('personal/comision_form', ['profs' => $profs, 'servicios' => $servicios], 'Nueva comisión');
}

function personal_comision_guardar(): void
{
    requiere_modulo('personal');
    $d = [
        'id_usuario'  => (int)post('id_usuario', 0),
        'id_servicio' => ((int)post('id_servicio', 0)) ?: null,   // NULL = todos los servicios
        'tipo'        => (string)post('tipo', 'PORCENTAJE'),
        'valor'       => (float)post('valor', 0),
        'vigente_desde' => (string)post('vigente_desde', date('Y-m-d')) ?: date('Y-m-d'),
    ];
    if (!$d['id_usuario'] || !in_array($d['tipo'], ['PORCENTAJE', 'MONTO'], true) || $d['valor'] < 0) {
        flash('Completá profesional, tipo y valor.', 'error');
        redirect('index.php?r=personal/comision_form');
    }
    try {
        q("INSERT INTO comision (id_usuario,id_servicio,tipo,valor,vigente_desde)
           VALUES (:id_usuario,:id_servicio,:tipo,:valor,:vigente_desde)", $d);
        auditar('ALTA', 'Personal', 'comision', (int)db()->lastInsertId(), 'Comisión ' . $d['tipo'] . ' ' . $d['valor']);
        flash('Comisión registrada.');
    } catch (PDOException $ex) {
        flash('Ya existe una comisión para ese profesional/servicio en esa fecha.', 'error');
        redirect('index.php?r=personal/comision_form');
    }
    redirect('index.php?r=personal/comisiones');
}

// ---------- Asistencia ----------
function personal_asistencia(): void
{
    requiere_modulo('personal');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $u = usuario_actual();
        $d = [
            'id_turno'      => (int)post('id_turno', 0),
            'reg'           => $u['id'],
            'hora_entrada'  => (string)post('hora_entrada', '') ?: null,
            'hora_salida'   => (string)post('hora_salida', '') ?: null,
            'motivo'        => trim((string)post('motivo_ausencia', '')) ?: null,
            'horas_extras'  => (float)post('horas_extras', 0),
            'obs'           => trim((string)post('observaciones', '')) ?: null,
        ];
        if ($d['id_turno']) {
            // id_turno es UNIQUE: si ya hay asistencia para ese turno, se actualiza
            q("INSERT INTO asistencia (id_turno,id_usuario_registro,hora_entrada,hora_salida,motivo_ausencia,horas_extras,observaciones)
               VALUES (:id_turno,:reg,:hora_entrada,:hora_salida,:motivo,:horas_extras,:obs)
               ON DUPLICATE KEY UPDATE id_usuario_registro=VALUES(id_usuario_registro),
                 hora_entrada=VALUES(hora_entrada), hora_salida=VALUES(hora_salida),
                 motivo_ausencia=VALUES(motivo_ausencia), horas_extras=VALUES(horas_extras),
                 observaciones=VALUES(observaciones)", $d);
            auditar('REGISTRO', 'Personal', 'asistencia', $d['id_turno'], 'Asistencia del turno #' . $d['id_turno']);
            flash('Asistencia registrada.');
        } else {
            flash('Elegí un turno.', 'error');
        }
        redirect('index.php?r=personal/asistencia');
    }

    $rows = fetch_all(
        "SELECT a.*, t.fecha, CONCAT(u.nombre,' ',u.apellido) AS profesional
           FROM asistencia a
           JOIN turno_laboral t ON t.id_turno=a.id_turno
           JOIN usuario u ON u.id_usuario=t.id_usuario
          ORDER BY t.fecha DESC LIMIT 100"
    );
    // Turnos disponibles para registrar (con su estado de asistencia)
    $turnos = fetch_all(
        "SELECT t.id_turno, t.fecha, t.hora_inicio, t.hora_fin, CONCAT(u.nombre,' ',u.apellido) AS profesional,
                (a.id_asistencia IS NOT NULL) AS tiene_asistencia
           FROM turno_laboral t
           JOIN usuario u ON u.id_usuario=t.id_usuario
           LEFT JOIN asistencia a ON a.id_turno=t.id_turno
          WHERE t.activo=1 ORDER BY t.fecha DESC, t.hora_inicio LIMIT 60"
    );
    view('personal/asistencia', ['rows' => $rows, 'turnos' => $turnos], 'Asistencia');
}
