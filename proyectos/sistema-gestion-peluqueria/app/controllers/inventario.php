<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function inventario_index(): void
{
    requiere_modulo('inventario');
    $subs = [
        ['r' => 'inventario/productos',  'ic' => 'box-seam',   't' => 'Productos',   'd' => 'Catálogo, precios y stock mínimo'],
        ['r' => 'inventario/proveedores','ic' => 'truck',      't' => 'Proveedores', 'd' => 'Datos y saldos'],
        ['r' => 'inventario/stock',      'ic' => 'clipboard-data', 't' => 'Stock',   'd' => 'Existencias y alertas de reposición'],
        ['r' => 'inventario/compras',    'ic' => 'bag',        't' => 'Compras',     'd' => 'Ingresos de mercadería'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Inventario', 'icono' => 'box-seam',
        'desc' => 'Productos, proveedores, stock y compras.', 'subs' => $subs], 'Inventario');
}

// ---------- Productos ----------
function inventario_productos(): void
{
    requiere_modulo('inventario');
    $rows = fetch_all("SELECT * FROM vw_producto_stock ORDER BY nombre");
    view('inventario/productos', ['rows' => $rows], 'Productos');
}

function inventario_producto_form(): void
{
    requiere_modulo('inventario');
    $id = (int)get('id', 0);
    $p = $id ? fetch_one("SELECT * FROM producto WHERE id_producto=?", [$id]) : null;
    if ($id && !$p) { flash('Producto no encontrado.', 'error'); redirect('index.php?r=inventario/productos'); }
    $cats = fetch_all("SELECT * FROM categoria_producto ORDER BY nombre");
    view('inventario/producto_form', ['p' => $p, 'cats' => $cats], $id ? 'Editar producto' : 'Nuevo producto');
}

function inventario_producto_guardar(): void
{
    requiere_modulo('inventario');
    $id = (int)post('id_producto', 0);
    $d = [
        'id_categoria' => (int)post('id_categoria', 0),
        'nombre'       => trim((string)post('nombre', '')),
        'descripcion'  => trim((string)post('descripcion', '')) ?: null,
        'unidad_medida' => trim((string)post('unidad_medida', 'unidad')) ?: 'unidad',
        'stock_minimo' => (float)post('stock_minimo', 0),
        'precio_costo' => (float)post('precio_costo', 0),
        'precio_venta' => (float)post('precio_venta', 0),
        'tasa_iva'     => (int)post('tasa_iva', 10),
    ];
    if ($d['nombre'] === '' || $d['id_categoria'] <= 0) {
        flash('Nombre y categoría son obligatorios.', 'error');
        redirect('index.php?r=inventario/producto_form' . ($id ? '&id=' . $id : ''));
    }
    if ($id) {
        q("UPDATE producto SET id_categoria=:id_categoria,nombre=:nombre,descripcion=:descripcion,unidad_medida=:unidad_medida,
             stock_minimo=:stock_minimo,precio_costo=:precio_costo,precio_venta=:precio_venta,tasa_iva=:tasa_iva
           WHERE id_producto=:id", $d + ['id' => $id]);
        auditar('MODIFICACION', 'Inventario', 'producto', $id, $d['nombre']);
        flash('Producto actualizado.');
    } else {
        q("INSERT INTO producto (id_categoria,nombre,descripcion,unidad_medida,stock_minimo,precio_costo,precio_venta,tasa_iva)
           VALUES (:id_categoria,:nombre,:descripcion,:unidad_medida,:stock_minimo,:precio_costo,:precio_venta,:tasa_iva)", $d);
        auditar('ALTA', 'Inventario', 'producto', (int)db()->lastInsertId(), $d['nombre']);
        flash('Producto creado.');
    }
    redirect('index.php?r=inventario/productos');
}

function inventario_producto_baja(): void
{
    requiere_modulo('inventario');
    q("UPDATE producto SET activo = 1 - activo WHERE id_producto=?", [(int)post('id_producto', 0)]);
    flash('Estado del producto actualizado.');
    redirect('index.php?r=inventario/productos');
}

// ---------- Ajuste de stock (usa el procedimiento de la BD) ----------
function inventario_ajuste(): void
{
    requiere_modulo('inventario');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $u = usuario_actual();
        try {
            q("CALL sp_registrar_movimiento_inventario(?,?,?,?,?,?,?)", [
                (int)post('id_producto', 0), $u['id'], (int)post('id_tipo_movimiento', 0),
                (float)post('cantidad', 0), (float)post('precio_unitario', 0),
                trim((string)post('referencia', '')) ?: null, trim((string)post('observaciones', '')) ?: null,
            ]);
            flash('Movimiento de stock registrado.');
        } catch (PDOException $ex) {
            flash('No se pudo registrar el movimiento: ' . $ex->getMessage(), 'error');
        }
        redirect('index.php?r=inventario/stock');
    }
    $prods = fetch_all("SELECT id_producto, nombre FROM producto WHERE activo=1 ORDER BY nombre");
    $tipos = fetch_all("SELECT * FROM tipo_movimiento_inventario ORDER BY nombre");
    view('inventario/ajuste', ['prods' => $prods, 'tipos' => $tipos], 'Ajuste de stock');
}

// ---------- Stock ----------
function inventario_stock(): void
{
    requiere_modulo('inventario');
    $rows = fetch_all("SELECT * FROM vw_producto_stock WHERE activo=1 ORDER BY nombre");
    $bajo = fetch_all("SELECT * FROM vw_producto_bajo_stock ORDER BY faltante DESC");
    view('inventario/stock', ['rows' => $rows, 'bajo' => $bajo], 'Stock');
}

// ---------- Proveedores ----------
function inventario_proveedores(): void
{
    requiere_modulo('inventario');
    $rows = fetch_all("SELECT p.*, fn_proveedor_saldo(p.id_proveedor) AS saldo FROM proveedor p ORDER BY nombre");
    view('inventario/proveedores', ['rows' => $rows], 'Proveedores');
}

function inventario_proveedor_form(): void
{
    requiere_modulo('inventario');
    $id = (int)get('id', 0);
    $p = $id ? fetch_one("SELECT * FROM proveedor WHERE id_proveedor=?", [$id]) : null;
    if ($id && !$p) { flash('Proveedor no encontrado.', 'error'); redirect('index.php?r=inventario/proveedores'); }
    view('inventario/proveedor_form', ['p' => $p], $id ? 'Editar proveedor' : 'Nuevo proveedor');
}

function inventario_proveedor_guardar(): void
{
    requiere_modulo('inventario');
    $id = (int)post('id_proveedor', 0);
    $d = [
        'nombre'   => trim((string)post('nombre', '')),
        'contacto' => trim((string)post('contacto', '')) ?: null,
        'ruc'      => trim((string)post('ruc', '')) ?: null,
        'telefono' => trim((string)post('telefono', '')) ?: null,
        'email'    => trim((string)post('email', '')) ?: null,
        'direccion' => trim((string)post('direccion', '')) ?: null,
    ];
    if ($d['nombre'] === '') { flash('El nombre es obligatorio.', 'error'); redirect('index.php?r=inventario/proveedor_form' . ($id ? '&id=' . $id : '')); }
    try {
        if ($id) {
            q("UPDATE proveedor SET nombre=:nombre,contacto=:contacto,ruc=:ruc,telefono=:telefono,email=:email,direccion=:direccion
               WHERE id_proveedor=:id", $d + ['id' => $id]);
            flash('Proveedor actualizado.');
        } else {
            q("INSERT INTO proveedor (nombre,contacto,ruc,telefono,email,direccion)
               VALUES (:nombre,:contacto,:ruc,:telefono,:email,:direccion)", $d);
            flash('Proveedor creado.');
        }
    } catch (PDOException $ex) {
        flash('No se pudo guardar (¿RUC duplicado?).', 'error');
        redirect('index.php?r=inventario/proveedor_form' . ($id ? '&id=' . $id : ''));
    }
    redirect('index.php?r=inventario/proveedores');
}

function inventario_proveedor_baja(): void
{
    requiere_modulo('inventario');
    q("UPDATE proveedor SET activo = 1 - activo WHERE id_proveedor=?", [(int)post('id_proveedor', 0)]);
    flash('Estado del proveedor actualizado.');
    redirect('index.php?r=inventario/proveedores');
}

// ---------- Compras ----------
function inventario_compras(): void
{
    requiere_modulo('inventario');
    $rows = fetch_all("SELECT * FROM vw_compra_resumen ORDER BY fecha DESC LIMIT 200");
    view('inventario/compras', ['rows' => $rows], 'Compras');
}

function inventario_compra_form(): void
{
    requiere_modulo('inventario');
    $proveedores = fetch_all("SELECT id_proveedor, nombre FROM proveedor WHERE activo=1 ORDER BY nombre");
    $categorias  = fetch_all("SELECT id_categoria, nombre FROM categoria_producto ORDER BY nombre");
    $condiciones = fetch_all("SELECT * FROM condicion_venta ORDER BY id_condicion_venta");
    $productos   = fetch_all("SELECT nombre FROM producto WHERE activo=1 ORDER BY nombre");
    view('inventario/compra_form', [
        'proveedores' => $proveedores, 'categorias' => $categorias,
        'condiciones' => $condiciones, 'productos' => $productos,
    ], 'Nueva compra');
}

function inventario_compra_guardar(): void
{
    requiere_modulo('inventario');
    $u = usuario_actual();
    $id_proveedor  = (int)post('id_proveedor', 0);
    $id_condicion  = (int)post('id_condicion_venta', 1) ?: 1;
    $nro_factura   = trim((string)post('nro_factura_proveedor', '')) ?: null;
    $obs           = trim((string)post('observaciones', '')) ?: null;
    $cat_nuevos    = (int)post('id_categoria_nuevos', 0);

    $nombres   = (array)post('nombre', []);
    $cantidades = (array)post('cantidad', []);
    $precios   = (array)post('precio', []);

    if (!$id_proveedor) { flash('Elegí un proveedor.', 'error'); redirect('index.php?r=inventario/compra_form'); }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        // Cabecera en estado Pendiente
        $pdo->prepare(
            "INSERT INTO compra (id_proveedor,id_usuario,id_estado_compra,id_condicion_venta,nro_factura_proveedor,observaciones)
             VALUES (?,?,1,?,?,?)"
        )->execute([$id_proveedor, $u['id'], $id_condicion, $nro_factura, $obs]);
        $id_compra = (int)$pdo->lastInsertId();

        $buscar = $pdo->prepare("SELECT id_producto FROM producto WHERE nombre = ? LIMIT 1");
        $crear  = $pdo->prepare(
            "INSERT INTO producto (id_categoria,nombre,unidad_medida,precio_costo,precio_venta,tasa_iva)
             VALUES (?,?, 'unidad', ?, ?, 10)"
        );
        $detalle = $pdo->prepare("INSERT INTO detalle_compra (id_compra,id_producto,cantidad,precio_unitario) VALUES (?,?,?,?)");

        $lineas = 0; $creados = 0;
        foreach ($nombres as $i => $nom) {
            $nom = trim((string)$nom);
            $cant = (float)($cantidades[$i] ?? 0);
            $prec = (float)($precios[$i] ?? 0);
            if ($nom === '' || $cant <= 0) continue;

            // Verifica si el producto existe; si no, lo crea automáticamente
            $buscar->execute([$nom]);
            $idp = $buscar->fetchColumn();
            if (!$idp) {
                $cat = $cat_nuevos ?: (int)fetch_val("SELECT MIN(id_categoria) FROM categoria_producto");
                $crear->execute([$cat, $nom, $prec, $prec]);
                $idp = (int)$pdo->lastInsertId();
                $creados++;
            }
            $detalle->execute([$id_compra, (int)$idp, $cant, $prec]);
            $lineas++;
        }

        if ($lineas === 0) {
            $pdo->rollBack();
            flash('Agregá al menos un producto con cantidad.', 'error');
            redirect('index.php?r=inventario/compra_form');
        }

        // Confirmar: genera los movimientos de inventario (suma stock) y actualiza costo
        $st = $pdo->prepare("CALL sp_confirmar_compra(?,?)");
        $st->execute([$id_compra, $u['id']]);
        $st->closeCursor();
        $pdo->commit();

        auditar('COMPRA', 'Inventario', 'compra', $id_compra,
            "$lineas producto(s), $creados nuevo(s)");
        flash("Compra registrada: stock actualizado" . ($creados ? " ($creados producto/s nuevo/s creado/s)" : "") . ".");
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('No se pudo registrar la compra: ' . $ex->getMessage(), 'error');
        redirect('index.php?r=inventario/compra_form');
    }
    redirect('index.php?r=inventario/compras');
}
