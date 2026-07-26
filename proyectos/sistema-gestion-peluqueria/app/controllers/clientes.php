<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function clientes_index(): void
{
    requiere_modulo('clientes');
    $subs = [
        ['r' => 'clientes/lista',        'ic' => 'people',       't' => 'Clientes',      'd' => 'Registro y datos de contacto'],
        ['r' => 'clientes/fidelizacion', 'ic' => 'award',        't' => 'Fidelización',  'd' => 'Niveles, visitas y puntos'],
        ['r' => 'clientes/valoraciones', 'ic' => 'star',         't' => 'Valoraciones',  'd' => 'Calificaciones de los servicios'],
    ];
    view('modulo_landing', [
        'titulo_mod' => 'Clientes', 'icono' => 'people',
        'desc' => 'Gestioná el registro de clientes, su fidelización y valoraciones.',
        'subs' => $subs,
    ], 'Clientes');
}

function clientes_lista(): void
{
    requiere_modulo('clientes');
    $buscar = trim((string)get('q', ''));
    $sql = "SELECT id_cliente, nombre, apellido, cedula, telefono, email, activo FROM cliente";
    $par = [];
    if ($buscar !== '') {
        $sql .= " WHERE (nombre LIKE :q OR apellido LIKE :q OR cedula LIKE :q OR telefono LIKE :q)";
        $par['q'] = "%$buscar%";
    }
    $sql .= " ORDER BY apellido, nombre LIMIT 300";
    view('clientes/lista', ['clientes' => fetch_all($sql, $par), 'buscar' => $buscar], 'Clientes');
}

function clientes_form(): void
{
    requiere_modulo('clientes');
    $id = (int)get('id', 0);
    $c = $id ? fetch_one("SELECT * FROM cliente WHERE id_cliente=?", [$id]) : null;
    if ($id && !$c) { flash('Cliente no encontrado.', 'error'); redirect('index.php?r=clientes/lista'); }
    view('clientes/form', ['c' => $c], $id ? 'Editar cliente' : 'Nuevo cliente');
}

function clientes_guardar(): void
{
    requiere_modulo('clientes');
    $id = (int)post('id_cliente', 0);
    $datos = [
        'nombre'   => trim((string)post('nombre', '')),
        'apellido' => trim((string)post('apellido', '')),
        'cedula'   => trim((string)post('cedula', '')) ?: null,
        'ruc'      => trim((string)post('ruc', '')) ?: null,
        'telefono' => trim((string)post('telefono', '')) ?: null,
        'email'    => trim((string)post('email', '')) ?: null,
        'fecha_nacimiento' => post('fecha_nacimiento', '') ?: null,
        'observaciones'    => trim((string)post('observaciones', '')) ?: null,
    ];
    if ($datos['nombre'] === '' || $datos['apellido'] === '') {
        flash('Nombre y apellido son obligatorios.', 'error');
        redirect('index.php?r=clientes/form' . ($id ? '&id=' . $id : ''));
    }
    try {
        if ($id) {
            q("UPDATE cliente SET nombre=:nombre, apellido=:apellido, cedula=:cedula, ruc=:ruc,
                 telefono=:telefono, email=:email, fecha_nacimiento=:fecha_nacimiento,
                 observaciones=:observaciones WHERE id_cliente=:id",
              $datos + ['id' => $id]);
            auditar('MODIFICACION', 'Clientes', 'cliente', $id, $datos['nombre'] . ' ' . $datos['apellido']);
            flash('Cliente actualizado.');
        } else {
            q("INSERT INTO cliente (nombre, apellido, cedula, ruc, telefono, email, fecha_nacimiento, observaciones)
               VALUES (:nombre,:apellido,:cedula,:ruc,:telefono,:email,:fecha_nacimiento,:observaciones)", $datos);
            auditar('ALTA', 'Clientes', 'cliente', (int)db()->lastInsertId(), $datos['nombre'] . ' ' . $datos['apellido']);
            flash('Cliente registrado.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿cédula o RUC duplicado?).', 'error');
        redirect('index.php?r=clientes/form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=clientes/lista');
}

function clientes_baja(): void
{
    requiere_modulo('clientes');
    $id = (int)post('id_cliente', 0);
    q("UPDATE cliente SET activo = 1 - activo WHERE id_cliente=?", [$id]);
    flash('Estado del cliente actualizado.');
    redirect('index.php?r=clientes/lista');
}

function clientes_historial(): void
{
    requiere_modulo('clientes');
    $id = (int)get('id', 0);
    $c = fetch_one("SELECT * FROM cliente WHERE id_cliente=?", [$id]);
    if (!$c) { flash('Cliente no encontrado.', 'error'); redirect('index.php?r=clientes/lista'); }
    $hist = fetch_all("SELECT * FROM vw_historial_cliente WHERE id_cliente=? ORDER BY fecha_hora DESC", [$id]);
    $fid  = fetch_one("SELECT * FROM vw_cliente_fidelizacion WHERE id_cliente=?", [$id]);
    $pref = fetch_all("SELECT * FROM preferencia_cliente WHERE id_cliente=? ORDER BY fecha_registro DESC", [$id]);
    view('clientes/historial', ['c' => $c, 'hist' => $hist, 'fid' => $fid, 'pref' => $pref], 'Historial del cliente');
}

function clientes_fidelizacion(): void
{
    requiere_modulo('clientes');
    $rows = fetch_all("SELECT * FROM vw_cliente_fidelizacion ORDER BY visitas DESC, cliente LIMIT 300");
    view('clientes/fidelizacion', ['rows' => $rows], 'Fidelización');
}

function clientes_valoraciones(): void
{
    requiere_modulo('clientes');
    $rows = fetch_all(
        "SELECT cal.puntaje, cal.comentario, cal.fecha, CONCAT(cl.nombre,' ',cl.apellido) AS cliente,
                CONCAT(u.nombre,' ',u.apellido) AS profesional
           FROM calificacion cal
           JOIN cita c   ON c.id_cita = cal.id_cita
           JOIN cliente cl ON cl.id_cliente = c.id_cliente
           JOIN usuario u  ON u.id_usuario = c.id_usuario
          ORDER BY cal.fecha DESC LIMIT 200"
    );
    $prom = fetch_val("SELECT ROUND(AVG(puntaje),2) FROM calificacion");
    view('clientes/valoraciones', ['rows' => $rows, 'prom' => $prom], 'Valoraciones');
}
