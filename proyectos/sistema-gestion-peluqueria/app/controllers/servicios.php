<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function servicios_index(): void
{
    requiere_modulo('servicios');
    $subs = [
        ['r' => 'servicios/lista',      'ic' => 'scissors',   't' => 'Catálogo de servicios', 'd' => 'Nombre, precio y duración'],
        ['r' => 'servicios/categorias', 'ic' => 'tags',       't' => 'Categorías',            'd' => 'Tipos de servicio'],
        ['r' => 'servicios/descuentos', 'ic' => 'percent',    't' => 'Descuentos y promos',   'd' => 'Vigencia y valor'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Servicios', 'icono' => 'scissors',
        'desc' => 'Catálogo de servicios, sus categorías y los descuentos.', 'subs' => $subs], 'Servicios');
}

function servicios_lista(): void
{
    requiere_modulo('servicios');
    $rows = fetch_all(
        "SELECT s.*, cs.nombre AS categoria
           FROM servicio s JOIN categoria_servicio cs ON cs.id_categoria_servicio = s.id_categoria_servicio
          ORDER BY cs.nombre, s.nombre"
    );
    view('servicios/lista', ['rows' => $rows], 'Servicios');
}

function servicios_form(): void
{
    requiere_modulo('servicios');
    $id = (int)get('id', 0);
    $s = $id ? fetch_one("SELECT * FROM servicio WHERE id_servicio=?", [$id]) : null;
    if ($id && !$s) { flash('Servicio no encontrado.', 'error'); redirect('index.php?r=servicios/lista'); }
    $cats = fetch_all("SELECT * FROM categoria_servicio ORDER BY nombre");
    view('servicios/form', ['s' => $s, 'cats' => $cats], $id ? 'Editar servicio' : 'Nuevo servicio');
}

function servicios_guardar(): void
{
    requiere_modulo('servicios');
    $id = (int)post('id_servicio', 0);
    $d = [
        'id_categoria_servicio' => (int)post('id_categoria_servicio', 0),
        'nombre'      => trim((string)post('nombre', '')),
        'descripcion' => trim((string)post('descripcion', '')) ?: null,
        'precio'      => (float)post('precio', 0),
        'duracion_min' => (int)post('duracion_min', 0),
        'tasa_iva'    => (int)post('tasa_iva', 10),
    ];
    if ($d['nombre'] === '' || $d['id_categoria_servicio'] <= 0) {
        flash('Nombre y categoría son obligatorios.', 'error');
        redirect('index.php?r=servicios/form' . ($id ? '&id=' . $id : ''));
    }
    try {
        if ($id) {
            q("UPDATE servicio SET id_categoria_servicio=:id_categoria_servicio, nombre=:nombre, descripcion=:descripcion,
                 precio=:precio, duracion_min=:duracion_min, tasa_iva=:tasa_iva WHERE id_servicio=:id", $d + ['id' => $id]);
            auditar('MODIFICACION', 'Servicios', 'servicio', $id, $d['nombre']);
            flash('Servicio actualizado.');
        } else {
            q("INSERT INTO servicio (id_categoria_servicio,nombre,descripcion,precio,duracion_min,tasa_iva)
               VALUES (:id_categoria_servicio,:nombre,:descripcion,:precio,:duracion_min,:tasa_iva)", $d);
            auditar('ALTA', 'Servicios', 'servicio', (int)db()->lastInsertId(), $d['nombre']);
            flash('Servicio creado.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿nombre duplicado?).', 'error');
        redirect('index.php?r=servicios/form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=servicios/lista');
}

function servicios_baja(): void
{
    requiere_modulo('servicios');
    q("UPDATE servicio SET activo = 1 - activo WHERE id_servicio=?", [(int)post('id_servicio', 0)]);
    flash('Estado del servicio actualizado.');
    redirect('index.php?r=servicios/lista');
}

function servicios_categorias(): void
{
    requiere_modulo('servicios');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $nombre = trim((string)post('nombre', ''));
        if ($nombre !== '') {
            try { q("INSERT INTO categoria_servicio (nombre) VALUES (?)", [$nombre]); flash('Categoría agregada.'); }
            catch (PDOException $e) { flash('Esa categoría ya existe.', 'error'); }
        }
        redirect('index.php?r=servicios/categorias');
    }
    $rows = fetch_all("SELECT cs.*, (SELECT COUNT(*) FROM servicio s WHERE s.id_categoria_servicio=cs.id_categoria_servicio) AS usos
                         FROM categoria_servicio cs ORDER BY nombre");
    view('servicios/categorias', ['rows' => $rows], 'Categorías de servicio');
}

function servicios_categoria_editar(): void
{
    requiere_modulo('servicios');
    $id = (int)post('id', 0);
    $nombre = trim((string)post('nombre', ''));
    if ($id && $nombre !== '') {
        try {
            q("UPDATE categoria_servicio SET nombre=? WHERE id_categoria_servicio=?", [$nombre, $id]);
            auditar('MODIFICACION', 'Servicios', 'categoria_servicio', $id, $nombre);
            flash('Categoría actualizada.');
        } catch (PDOException $e) { flash('Ya existe otra categoría con ese nombre.', 'error'); }
    } else {
        flash('El nombre no puede quedar vacío.', 'error');
    }
    redirect('index.php?r=servicios/categorias');
}

function servicios_categoria_borrar(): void
{
    requiere_modulo('servicios');
    $id = (int)post('id', 0);
    $usos = (int)fetch_val("SELECT COUNT(*) FROM servicio WHERE id_categoria_servicio=?", [$id]);
    if ($usos) {
        flash("No se puede eliminar: hay $usos servicio(s) en esa categoría.", 'warning');
    } else {
        q("DELETE FROM categoria_servicio WHERE id_categoria_servicio=?", [$id]);
        auditar('BAJA', 'Servicios', 'categoria_servicio', $id, 'Categoría eliminada');
        flash('Categoría eliminada.');
    }
    redirect('index.php?r=servicios/categorias');
}

function servicios_descuentos(): void
{
    requiere_modulo('servicios');
    $rows = fetch_all("SELECT * FROM descuento ORDER BY activo DESC, nombre");
    view('servicios/descuentos', ['rows' => $rows], 'Descuentos');
}

function servicios_descuento_form(): void
{
    requiere_modulo('servicios');
    $id = (int)get('id', 0);
    $d = $id ? fetch_one("SELECT * FROM descuento WHERE id_descuento=?", [$id]) : null;
    if ($id && !$d) { flash('Descuento no encontrado.', 'error'); redirect('index.php?r=servicios/descuentos'); }
    view('servicios/descuento_form', ['d' => $d], $id ? 'Editar descuento' : 'Nuevo descuento');
}

function servicios_descuento_guardar(): void
{
    requiere_modulo('servicios');
    $id = (int)post('id_descuento', 0);
    $d = [
        'nombre'      => trim((string)post('nombre', '')),
        'descripcion' => trim((string)post('descripcion', '')) ?: null,
        'tipo'        => (string)post('tipo', 'PORCENTAJE'),
        'valor'       => (float)post('valor', 0),
        'fecha_inicio' => post('fecha_inicio', '') ?: null,
        'fecha_fin'   => post('fecha_fin', '') ?: null,
    ];
    if ($d['nombre'] === '' || !in_array($d['tipo'], ['PORCENTAJE', 'MONTO'], true)) {
        flash('Completá nombre y tipo.', 'error');
        redirect('index.php?r=servicios/descuento_form' . ($id ? '&id=' . $id : ''));
    }
    try {
        if ($id) {
            q("UPDATE descuento SET nombre=:nombre,descripcion=:descripcion,tipo=:tipo,valor=:valor,
                 fecha_inicio=:fecha_inicio,fecha_fin=:fecha_fin WHERE id_descuento=:id", $d + ['id' => $id]);
            auditar('MODIFICACION', 'Servicios', 'descuento', $id, $d['nombre']);
            flash('Descuento actualizado.');
        } else {
            q("INSERT INTO descuento (nombre,descripcion,tipo,valor,fecha_inicio,fecha_fin)
               VALUES (:nombre,:descripcion,:tipo,:valor,:fecha_inicio,:fecha_fin)", $d);
            auditar('ALTA', 'Servicios', 'descuento', (int)db()->lastInsertId(), $d['nombre']);
            flash('Descuento creado.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿nombre duplicado o fechas inválidas?).', 'error');
        redirect('index.php?r=servicios/descuento_form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=servicios/descuentos');
}

function servicios_descuento_baja(): void
{
    requiere_modulo('servicios');
    q("UPDATE descuento SET activo = 1 - activo WHERE id_descuento=?", [(int)post('id_descuento', 0)]);
    flash('Estado del descuento actualizado.');
    redirect('index.php?r=servicios/descuentos');
}
