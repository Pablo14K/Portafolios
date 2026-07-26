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
    requiere_rol([ROL_PROPIETARIA, ROL_GERENTE]);
    $rows = fetch_all("SELECT * FROM vw_pago_personal_resumen ORDER BY fecha DESC LIMIT 200");
    view('facturacion/pagos', ['rows' => $rows], 'Pagos al personal');
}
