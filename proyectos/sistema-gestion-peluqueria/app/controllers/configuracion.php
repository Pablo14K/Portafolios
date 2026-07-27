<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function configuracion_index(): void
{
    requiere_modulo('configuracion');
    $subs = [
        ['r' => 'configuracion/sucursales','ic' => 'shop',        't' => 'Sucursales',      'd' => 'Locales y datos del emisor'],
        ['r' => 'configuracion/roles',     'ic' => 'shield-lock', 't' => 'Roles',           'd' => 'Perfiles y permisos de acceso'],
        ['r' => 'configuracion/catalogos', 'ic' => 'tags',        't' => 'Catálogos',       'd' => 'Categorías y niveles'],
        ['r' => 'configuracion/auditoria', 'ic' => 'journal-text','t' => 'Auditoría',       'd' => 'Registro de acciones'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Configuración', 'icono' => 'gear',
        'desc' => 'Ajustes del sistema y del negocio.', 'subs' => $subs], 'Configuración');
}

// ---------- Sucursales (multisucursal) ----------
// (La antigua pantalla "Datos del local" se retiró: editaba únicamente la
//  sucursal 1 y quedó cubierta por el alta/edición de sucursales.)
function configuracion_sucursales(): void
{
    requiere_modulo('configuracion');
    $rows = fetch_all("SELECT s.*, (SELECT COUNT(*) FROM usuario u WHERE u.id_sucursal=s.id_sucursal) AS usuarios FROM sucursal s ORDER BY nombre");
    view('configuracion/sucursales', ['rows' => $rows], 'Sucursales');
}

function configuracion_sucursal_form(): void
{
    requiere_modulo('configuracion');
    $id = (int)get('id', 0);
    $s = $id ? fetch_one("SELECT * FROM sucursal WHERE id_sucursal=?", [$id]) : null;
    if ($id && !$s) { flash('Sucursal no encontrada.', 'error'); redirect('index.php?r=configuracion/sucursales'); }
    view('configuracion/sucursal_form', ['s' => $s], $id ? 'Editar sucursal' : 'Nueva sucursal');
}

function configuracion_sucursal_guardar(): void
{
    requiere_modulo('configuracion');
    $id = (int)post('id_sucursal', 0);
    $d = [
        'nombre'    => trim((string)post('nombre', '')),
        'ruc'       => trim((string)post('ruc', '')) ?: null,
        'telefono'  => trim((string)post('telefono', '')) ?: null,
        'direccion' => trim((string)post('direccion', '')) ?: null,
        'ciudad'    => trim((string)post('ciudad', '')) ?: null,
    ];
    if ($d['nombre'] === '') { flash('El nombre es obligatorio.', 'error'); redirect('index.php?r=configuracion/sucursal_form' . ($id ? '&id=' . $id : '')); }
    try {
        if ($id) {
            q("UPDATE sucursal SET nombre=:nombre,ruc=:ruc,telefono=:telefono,direccion=:direccion,ciudad=:ciudad WHERE id_sucursal=:id", $d + ['id' => $id]);
            auditar('MODIFICACION', 'Configuracion', 'sucursal', $id, $d['nombre']);
            flash('Sucursal actualizada.');
        } else {
            q("INSERT INTO sucursal (nombre,ruc,telefono,direccion,ciudad) VALUES (:nombre,:ruc,:telefono,:direccion,:ciudad)", $d);
            auditar('ALTA', 'Configuracion', 'sucursal', (int)db()->lastInsertId(), $d['nombre']);
            flash('Sucursal creada.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿RUC duplicado?).', 'error');
        redirect('index.php?r=configuracion/sucursal_form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=configuracion/sucursales');
}

function configuracion_sucursal_baja(): void
{
    requiere_modulo('configuracion');
    q("UPDATE sucursal SET activo = 1 - activo WHERE id_sucursal=?", [(int)post('id_sucursal', 0)]);
    flash('Estado de la sucursal actualizado.');
    redirect('index.php?r=configuracion/sucursales');
}

function configuracion_roles(): void
{
    requiere_modulo('configuracion');
    $roles = fetch_all("SELECT r.*, (SELECT COUNT(*) FROM usuario u WHERE u.id_rol=r.id_rol) AS usuarios FROM rol r ORDER BY id_rol");
    // Permisos actuales por rol
    $perm = [];
    foreach (fetch_all("SELECT id_rol, modulo FROM rol_modulo") as $p) {
        $perm[(int)$p['id_rol']][$p['modulo']] = true;
    }
    view('configuracion/roles', ['roles' => $roles, 'modulos' => modulos_sistema(), 'perm' => $perm], 'Roles');
}

function configuracion_rol_crear(): void
{
    requiere_modulo('configuracion');
    $nombre = trim((string)post('nombre', ''));
    $desc   = trim((string)post('descripcion', '')) ?: null;
    if ($nombre === '') { flash('El nombre del rol es obligatorio.', 'error'); redirect('index.php?r=configuracion/roles'); }
    try {
        q("INSERT INTO rol (nombre, descripcion, es_personal, activo) VALUES (?,?,1,1)", [$nombre, $desc]);
        auditar('ALTA', 'Configuracion', 'rol', (int)db()->lastInsertId(), $nombre);
        flash('Rol creado. Ahora asigná sus módulos abajo.');
    } catch (PDOException $ex) {
        flash('Ya existe un rol con ese nombre.', 'error');
    }
    redirect('index.php?r=configuracion/roles');
}

function configuracion_permisos_guardar(): void
{
    requiere_modulo('configuracion');
    $matriz = (array)post('perm', []);   // perm[id_rol][modulo] = 1
    $modulos = array_keys(modulos_sistema());
    // Solo roles de personal, excepto la Propietaria (siempre acceso total)
    $editables = fetch_all("SELECT id_rol FROM rol WHERE es_personal=1 AND id_rol <> " . ROL_PROPIETARIA);
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($editables as $r) {
        $idr = (int)$r['id_rol'];
        $pdo->prepare("DELETE FROM rol_modulo WHERE id_rol=?")->execute([$idr]);
        $ins = $pdo->prepare("INSERT INTO rol_modulo (id_rol, modulo) VALUES (?,?)");
        foreach ($modulos as $m) {
            if (!empty($matriz[$idr][$m])) $ins->execute([$idr, $m]);
        }
    }
    $pdo->commit();
    auditar('MODIFICACION', 'Configuracion', 'rol_modulo', null, 'Actualizó permisos de roles');
    flash('Permisos de los roles actualizados.');
    redirect('index.php?r=configuracion/roles');
}

function configuracion_catalogos(): void
{
    requiere_modulo('configuracion');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $tipo = (string)post('tipo', '');
        $nombre = trim((string)post('nombre', ''));
        if ($nombre !== '') {
            try {
                if ($tipo === 'producto') q("INSERT INTO categoria_producto (nombre) VALUES (?)", [$nombre]);
                elseif ($tipo === 'servicio') q("INSERT INTO categoria_servicio (nombre) VALUES (?)", [$nombre]);
                flash('Categoría agregada.');
            } catch (PDOException $ex) { flash('Esa categoría ya existe.', 'error'); }
        }
        redirect('index.php?r=configuracion/catalogos');
    }
    view('configuracion/catalogos', [
        'cat_prod' => fetch_all("SELECT c.*, (SELECT COUNT(*) FROM producto p WHERE p.id_categoria=c.id_categoria) AS usos FROM categoria_producto c ORDER BY nombre"),
        'cat_serv' => fetch_all("SELECT c.*, (SELECT COUNT(*) FROM servicio s WHERE s.id_categoria_servicio=c.id_categoria_servicio) AS usos FROM categoria_servicio c ORDER BY nombre"),
        'niveles'  => fetch_all("SELECT n.*, d.nombre AS descuento FROM nivel n LEFT JOIN descuento d ON d.id_descuento=n.id_descuento ORDER BY n.visitas_minimas"),
    ], 'Catálogos');
}

// Renombrar una categoría (producto o servicio)
function configuracion_catalogo_editar(): void
{
    requiere_modulo('configuracion');
    $tipo = (string)post('tipo', '');
    $id = (int)post('id', 0);
    $nombre = trim((string)post('nombre', ''));
    if ($id && $nombre !== '') {
        try {
            if ($tipo === 'producto') {
                q("UPDATE categoria_producto SET nombre=? WHERE id_categoria=?", [$nombre, $id]);
            } elseif ($tipo === 'servicio') {
                q("UPDATE categoria_servicio SET nombre=? WHERE id_categoria_servicio=?", [$nombre, $id]);
            }
            auditar('MODIFICACION', 'Configuracion', 'categoria_' . $tipo, $id, $nombre);
            flash('Categoría actualizada.');
        } catch (PDOException $e) { flash('Ya existe otra categoría con ese nombre.', 'error'); }
    } else {
        flash('El nombre no puede quedar vacío.', 'error');
    }
    redirect('index.php?r=configuracion/catalogos');
}

// Eliminar una categoría (solo si no está en uso)
function configuracion_catalogo_borrar(): void
{
    requiere_modulo('configuracion');
    $tipo = (string)post('tipo', '');
    $id = (int)post('id', 0);
    try {
        if ($tipo === 'producto') {
            $usos = (int)fetch_val("SELECT COUNT(*) FROM producto WHERE id_categoria=?", [$id]);
            if ($usos) { flash("No se puede eliminar: hay $usos producto(s) en esa categoría.", 'warning'); redirect('index.php?r=configuracion/catalogos'); }
            q("DELETE FROM categoria_producto WHERE id_categoria=?", [$id]);
        } elseif ($tipo === 'servicio') {
            $usos = (int)fetch_val("SELECT COUNT(*) FROM servicio WHERE id_categoria_servicio=?", [$id]);
            if ($usos) { flash("No se puede eliminar: hay $usos servicio(s) en esa categoría.", 'warning'); redirect('index.php?r=configuracion/catalogos'); }
            q("DELETE FROM categoria_servicio WHERE id_categoria_servicio=?", [$id]);
        }
        auditar('BAJA', 'Configuracion', 'categoria_' . $tipo, $id, 'Categoría eliminada');
        flash('Categoría eliminada.');
    } catch (PDOException $e) {
        flash('No se pudo eliminar la categoría.', 'error');
    }
    redirect('index.php?r=configuracion/catalogos');
}

function configuracion_auditoria(): void
{
    requiere_modulo('configuracion');
    $rows = fetch_all(
        "SELECT a.*, CONCAT(u.nombre,' ',u.apellido) AS usuario
           FROM auditoria a JOIN usuario u ON u.id_usuario=a.id_usuario
          ORDER BY a.fecha_hora DESC LIMIT 200"
    );
    view('configuracion/auditoria', ['rows' => $rows], 'Auditoría');
}
