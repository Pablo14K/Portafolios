<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function facturacion_index(): void
{
    requiere_modulo('facturacion');
    $subs = [
        ['r' => 'facturacion/facturas', 'ic' => 'receipt',    't' => 'Facturas',  'd' => 'Comprobantes emitidos'],
        ['r' => 'facturacion/cobros',   'ic' => 'cash-coin',  't' => 'Cobros',    'd' => 'Pagos recibidos de clientes'],
        ['r' => 'facturacion/caja',     'ic' => 'safe',       't' => 'Caja',      'd' => 'Apertura, cierre y saldo'],
        ['r' => 'facturacion/pagos',    'ic' => 'wallet2',    't' => 'Pagos al personal', 'd' => 'Comisiones y liquidaciones'],
        ['r' => 'facturacion/proveedores','ic' => 'truck',    't' => 'Pagos a proveedores', 'd' => 'Cuentas por pagar de compras'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Facturación y caja', 'icono' => 'cash-stack',
        'desc' => 'Facturas, cobros, caja y pagos al personal.', 'subs' => $subs], 'Facturación');
}

function facturacion_facturas(): void
{
    requiere_modulo('facturacion');
    $rows = fetch_all("SELECT * FROM vw_factura_resumen ORDER BY fecha_emision DESC LIMIT 200");
    $metodos = fetch_all("SELECT id_metodo_pago, nombre FROM metodo_pago ORDER BY id_metodo_pago");
    view('facturacion/facturas', ['rows' => $rows, 'metodos' => $metodos], 'Facturas');
}

// Lista de citas atendidas todavía sin factura
function facturacion_emitir(): void
{
    requiere_modulo('facturacion');
    $citas = fetch_all(
        "SELECT c.id_cita, c.id_cliente, c.fecha_hora,
                CONCAT(cl.nombre,' ',cl.apellido) AS cliente,
                (SELECT GROUP_CONCAT(s.nombre SEPARATOR ', ') FROM cita_servicio cs JOIN servicio s ON s.id_servicio=cs.id_servicio WHERE cs.id_cita=c.id_cita) AS servicios,
                (SELECT COALESCE(SUM(s.precio),0) FROM cita_servicio cs JOIN servicio s ON s.id_servicio=cs.id_servicio WHERE cs.id_cita=c.id_cita) AS total
           FROM cita c JOIN cliente cl ON cl.id_cliente=c.id_cliente
          WHERE c.id_estado_cita=4
            AND NOT EXISTS (SELECT 1 FROM factura f WHERE f.id_cita=c.id_cita)
          ORDER BY c.fecha_hora DESC LIMIT 100"
    );
    view('facturacion/emitir', ['citas' => $citas], 'Emitir factura');
}

function facturacion_emitir_guardar(): void
{
    requiere_modulo('facturacion');
    $u = usuario_actual();
    $id_cita = (int)post('id_cita', 0);
    $id_cliente = (int)post('id_cliente', 0);
    if (!$id_cita || !$id_cliente) { flash('Datos de la cita inválidos.', 'error'); redirect('index.php?r=facturacion/emitir'); }

    $pdo = db();
    try {
        $st = $pdo->prepare("CALL sp_emitir_factura(?,?,?,?,?, @f)");
        $st->execute([$id_cliente, $id_cita, $u['id'], 1, 1]);  // tipo 1=Factura, condición 1=Contado
        $st->closeCursor();
        $idf = (int)$pdo->query("SELECT @f")->fetchColumn();
        auditar('EMISION', 'Facturacion', 'factura', $idf, 'Factura de la cita #' . $id_cita);
        flash('Factura emitida correctamente.');
    } catch (PDOException $ex) {
        $msg = strpos($ex->getMessage(), 'timbrado') !== false ? 'No hay timbrado vigente para la factura.' : 'No se pudo emitir la factura.';
        flash($msg, 'error');
        redirect('index.php?r=facturacion/emitir');
    }
    redirect('index.php?r=facturacion/facturas');
}

function facturacion_cobrar(): void
{
    requiere_modulo('facturacion');
    $u = usuario_actual();
    $id_factura = (int)post('id_factura', 0);
    $id_metodo  = (int)post('id_metodo_pago', 1) ?: 1;
    $monto      = (float)post('monto', 0);
    $ref        = trim((string)post('referencia', '')) ?: null;
    if ($monto <= 0) { flash('Ingresá un monto válido.', 'error'); redirect('index.php?r=facturacion/facturas'); }
    try {
        $st = db()->prepare("CALL sp_registrar_cobro(?,?,?,?,?, @c)");
        $st->execute([$id_factura, $id_metodo, $u['id'], $monto, $ref]);
        $st->closeCursor();
        auditar('COBRO', 'Facturacion', 'factura', $id_factura, 'Cobro ' . money($monto));
        flash('Cobro registrado.');
    } catch (PDOException $ex) {
        $msg = $ex->getMessage();
        $amable = strpos($msg, 'saldo') !== false ? 'El monto supera el saldo pendiente.' : 'No se pudo registrar el cobro.';
        flash($amable, 'error');
    }
    redirect('index.php?r=facturacion/facturas');
}

function facturacion_cobros(): void
{
    requiere_modulo('facturacion');
    $rows = fetch_all(
        "SELECT co.id_cobro, co.fecha, co.monto, mp.nombre AS metodo, ec.nombre AS estado,
                CONCAT(cl.nombre,' ',cl.apellido) AS cliente
           FROM cobro co
           JOIN metodo_pago mp   ON mp.id_metodo_pago = co.id_metodo_pago
           JOIN estado_cobro ec  ON ec.id_estado_cobro = co.id_estado_cobro
           LEFT JOIN factura f    ON f.id_factura = co.id_factura
           LEFT JOIN cliente cl   ON cl.id_cliente = f.id_cliente
          ORDER BY co.fecha DESC LIMIT 200"
    );
    view('facturacion/cobros', ['rows' => $rows], 'Cobros');
}

function facturacion_caja(): void
{
    requiere_modulo('facturacion');
    $rows = fetch_all("SELECT * FROM vw_caja_resumen ORDER BY fecha_apertura DESC LIMIT 60");
    $abierta = fetch_one("SELECT * FROM vw_caja_resumen WHERE estado='Abierta' ORDER BY fecha_apertura DESC LIMIT 1");
    view('facturacion/caja', ['rows' => $rows, 'abierta' => $abierta], 'Caja');
}

function facturacion_abrir_caja(): void
{
    requiere_modulo('facturacion');
    $u = usuario_actual();
    $ya = fetch_val("SELECT COUNT(*) FROM caja WHERE id_estado_caja=1");
    if ($ya) { flash('Ya hay una caja abierta. Cerrala antes de abrir otra.', 'warning'); redirect('index.php?r=facturacion/caja'); }
    try {
        $st = db()->prepare("CALL sp_abrir_caja(?,?, @c)");
        $st->execute([$u['id'], (float)post('monto_inicial', 0)]);
        $st->closeCursor();
        flash('Caja abierta.');
    } catch (PDOException $ex) { flash('No se pudo abrir la caja.', 'error'); }
    redirect('index.php?r=facturacion/caja');
}

function facturacion_cerrar_caja(): void
{
    requiere_modulo('facturacion');
    try { q("CALL sp_cerrar_caja(?)", [(int)post('id_caja', 0)]); flash('Caja cerrada.'); }
    catch (PDOException $ex) { flash('No se pudo cerrar la caja.', 'error'); }
    redirect('index.php?r=facturacion/caja');
}

function facturacion_pagos(): void
{
    requiere_modulo('facturacion');
    $rows = fetch_all("SELECT * FROM vw_pago_personal_resumen ORDER BY fecha DESC LIMIT 200");
    $profs = fetch_all(
        "SELECT u.id_usuario, u.nombre, u.apellido,
                (SELECT COUNT(*) FROM servicio_realizado sr
                  LEFT JOIN detalle_pago_personal d ON d.id_servicio_realizado = sr.id_servicio_realizado
                  WHERE sr.id_usuario = u.id_usuario AND d.id_detalle_pago IS NULL) AS pendientes
           FROM usuario u WHERE u.activo=1 AND u.id_rol IN (1,2,3) ORDER BY u.nombre"
    );
    view('facturacion/pagos', ['rows' => $rows, 'profs' => $profs], 'Pagos al personal');
}

// Liquida al profesional los servicios realizados que todavía no se le pagaron
function facturacion_pagar_personal(): void
{
    requiere_modulo('facturacion');
    $u = usuario_actual();
    $idProf = (int)post('id_usuario', 0);
    $periodo = trim((string)post('periodo', '')) ?: date('m/Y');
    if (!$idProf) { flash('Elegí un profesional.', 'error'); redirect('index.php?r=facturacion/pagos'); }

    $pend = (int)fetch_val(
        "SELECT COUNT(*) FROM servicio_realizado sr
          LEFT JOIN detalle_pago_personal d ON d.id_servicio_realizado = sr.id_servicio_realizado
         WHERE sr.id_usuario=? AND d.id_detalle_pago IS NULL", [$idProf]
    );
    if (!$pend) {
        flash('Ese profesional no tiene servicios pendientes de liquidar.', 'warning');
        redirect('index.php?r=facturacion/pagos');
    }
    try {
        $st = db()->prepare("CALL sp_registrar_pago_personal(?,?,?, @p)");
        $st->execute([$idProf, $u['id'], $periodo]);
        $st->closeCursor();
        $idPago = (int)db()->query("SELECT @p")->fetchColumn();
        auditar('PAGO_PERSONAL', 'Facturacion', 'pago_personal', $idPago, "Liquidación $periodo ($pend servicios)");
        flash('Pago al profesional registrado.');
    } catch (PDOException $ex) {
        flash('No se pudo registrar el pago: ' . $ex->getMessage(), 'error');
    }
    redirect('index.php?r=facturacion/pagos');
}

// ---------- Pagos a proveedores ----------
function facturacion_proveedores(): void
{
    requiere_modulo('facturacion');
    $cuentas = fetch_all("SELECT * FROM vw_cuenta_proveedor WHERE saldo > 0 ORDER BY vencida DESC, vencimiento");
    // El monto no se guarda: se calcula con la función de la base (modelo 3FN)
    $pagos = fetch_all(
        "SELECT pp.fecha, pp.referencia,
                fn_pago_proveedor_monto(pp.id_pago_proveedor) AS monto,
                pr.nombre AS proveedor, mp.nombre AS metodo, ep.nombre AS estado
           FROM pago_proveedor pp
           JOIN proveedor pr ON pr.id_proveedor = pp.id_proveedor
           JOIN metodo_pago mp ON mp.id_metodo_pago = pp.id_metodo_pago
           JOIN estado_pago_proveedor ep ON ep.id_estado_pago_proveedor = pp.id_estado_pago_proveedor
          ORDER BY pp.fecha DESC LIMIT 100"
    );
    $metodos = fetch_all("SELECT id_metodo_pago, nombre FROM metodo_pago ORDER BY id_metodo_pago");
    view('facturacion/proveedores', ['cuentas' => $cuentas, 'pagos' => $pagos, 'metodos' => $metodos], 'Pagos a proveedores');
}

function facturacion_pagar_proveedor(): void
{
    requiere_modulo('facturacion');
    $u = usuario_actual();
    $idCompra = (int)post('id_compra', 0);
    $idMetodo = (int)post('id_metodo_pago', 1) ?: 1;
    $monto    = (float)post('monto', 0);
    $ref      = trim((string)post('referencia', '')) ?: null;
    if ($monto <= 0) { flash('Ingresá un monto válido.', 'error'); redirect('index.php?r=facturacion/proveedores'); }
    try {
        $st = db()->prepare("CALL sp_pagar_compra(?,?,?,?,?, @p)");
        $st->execute([$idCompra, $idMetodo, $u['id'], $monto, $ref]);
        $st->closeCursor();
        auditar('PAGO_PROVEEDOR', 'Facturacion', 'compra', $idCompra, 'Pago ' . money($monto));
        flash('Pago al proveedor registrado. Si hay una caja abierta, el egreso queda reflejado en ella.');
    } catch (PDOException $ex) {
        $msg = $ex->getMessage();
        $amable = strpos($msg, 'saldo') !== false ? 'El monto supera el saldo pendiente de la compra.'
                : (strpos($msg, 'confirmada') !== false ? 'Solo se pueden pagar compras confirmadas.' : 'No se pudo registrar el pago.');
        flash($amable, 'error');
    }
    redirect('index.php?r=facturacion/proveedores');
}
