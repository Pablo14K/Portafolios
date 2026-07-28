<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function citas_index(): void
{
    requiere_modulo('citas');
    $subs = [
        ['r' => 'citas/agenda',    'ic' => 'calendar-week', 't' => 'Agenda',      'd' => 'Citas del día y próximas'],
        ['r' => 'citas/form',      'ic' => 'calendar-plus', 't' => 'Nueva cita',  'd' => 'Agendar con control de disponibilidad'],
        ['r' => 'citas/ausencias', 'ic' => 'calendar-x',    't' => 'Excepciones', 'd' => 'Feriados, licencias y bloqueos'],
    ];
    view('modulo_landing', ['titulo_mod' => 'Citas y agenda', 'icono' => 'calendar-event',
        'desc' => 'Agenda, nuevas citas y excepciones de disponibilidad.', 'subs' => $subs], 'Citas');
}

function citas_agenda(): void
{
    requiere_modulo('citas');
    $dia = (string)get('dia', date('Y-m-d'));
    $rows = fetch_all(
        "SELECT * FROM vw_agenda_citas WHERE DATE(fecha_hora)=? ORDER BY fecha_hora", [$dia]
    );
    view('citas/agenda', ['rows' => $rows, 'dia' => $dia], 'Agenda');
}

function citas_form(): void
{
    requiere_modulo('citas');
    $clientes = fetch_all("SELECT id_cliente, nombre, apellido FROM cliente WHERE activo=1 ORDER BY apellido, nombre");
    $profs = fetch_all("SELECT id_usuario, nombre, apellido FROM usuario WHERE activo=1 AND id_rol IN (1,2,3) ORDER BY nombre");
    $servicios = fetch_all("SELECT id_servicio, nombre, precio, duracion_min FROM servicio WHERE activo=1 ORDER BY nombre");
    view('citas/form', ['clientes' => $clientes, 'profs' => $profs, 'servicios' => $servicios], 'Nueva cita');
}

function citas_guardar(): void
{
    requiere_modulo('citas');
    $id_cliente = (int)post('id_cliente', 0);
    $id_usuario = (int)post('id_usuario', 0);
    $fecha = trim((string)post('fecha_hora', ''));
    $servicios = array_map('intval', (array)post('servicios', []));
    $obs = trim((string)post('observaciones', '')) ?: null;

    if (!$id_cliente || !$id_usuario || $fecha === '' || !$servicios) {
        flash('Elegí cliente, profesional, al menos un servicio y la fecha/hora.', 'error');
        redirect('index.php?r=citas/form');
    }
    $fecha = str_replace('T', ' ', $fecha);

    // Duración = suma de los servicios elegidos
    $in = implode(',', array_fill(0, count($servicios), '?'));
    $dur = (int)fetch_val("SELECT COALESCE(SUM(duracion_min),0) FROM servicio WHERE id_servicio IN ($in)", $servicios);
    if ($dur <= 0) $dur = 30;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("CALL sp_agendar_cita(?,?,?,?,?, @nueva_cita)");
        $st->execute([$id_cliente, $id_usuario, $fecha, $dur, $obs]);
        $st->closeCursor();
        $id_cita = (int)$pdo->query("SELECT @nueva_cita")->fetchColumn();

        $ins = $pdo->prepare("INSERT INTO cita_servicio (id_cita, id_servicio) VALUES (?,?)");
        foreach ($servicios as $sid) { $ins->execute([$id_cita, $sid]); }
        $pdo->commit();
        auditar('ALTA', 'Citas', 'cita', $id_cita, 'Cita agendada para ' . $fecha);
        flash('Cita agendada correctamente.');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $ex->getMessage();
        // El procedimiento lanza un mensaje claro si el profesional no está disponible
        $amable = strpos($msg, 'disponible') !== false ? 'El profesional no está disponible en ese horario.' : 'No se pudo agendar la cita.';
        flash($amable, 'error');
        redirect('index.php?r=citas/form');
    }
    redirect('index.php?r=citas/agenda&dia=' . substr($fecha, 0, 10));
}

function citas_estado(): void
{
    requiere_modulo('citas');
    $id = (int)post('id_cita', 0);
    $estado = (int)post('id_estado_cita', 0);
    if (in_array($estado, [4,5,6], true)) {   // Atendida, En proceso, Ausente
        q("UPDATE cita SET id_estado_cita=? WHERE id_cita=?", [$estado, $id]);
        flash('Estado de la cita actualizado.');
    }
    redirect('index.php?r=citas/agenda&dia=' . (string)post('dia', date('Y-m-d')));
}

function citas_cancelar(): void
{
    requiere_modulo('citas');
    $id = (int)post('id_cita', 0);
    try { q("CALL sp_cancelar_cita(?)", [$id]); auditar('CANCELACION', 'Citas', 'cita', $id, 'Cita cancelada'); flash('Cita cancelada.'); }
    catch (PDOException $ex) { flash('No se pudo cancelar la cita.', 'error'); }
    redirect('index.php?r=citas/agenda&dia=' . (string)post('dia', date('Y-m-d')));
}

function citas_reprogramar(): void
{
    requiere_modulo('citas');
    $id = (int)post('id_cita', 0);
    $nueva = str_replace('T', ' ', trim((string)post('nueva_fecha', '')));
    if ($nueva === '') { flash('Elegí la nueva fecha y hora.', 'error'); redirect('index.php?r=citas/agenda'); }
    try { q("CALL sp_reprogramar_cita(?,?)", [$id, $nueva]); flash('Cita reprogramada.'); }
    catch (PDOException $ex) {
        $msg = strpos($ex->getMessage(), 'disponible') !== false ? 'El profesional no está disponible en el nuevo horario.' : 'No se pudo reprogramar.';
        flash($msg, 'error');
    }
    redirect('index.php?r=citas/agenda&dia=' . substr($nueva, 0, 10));
}

// ---------- Registrar la atención de una cita ----------
// Aquí se anota qué servicios se hicieron y qué productos se gastaron.
// El consumo descuenta el stock automáticamente (trigger de la base).
function citas_atender(): void
{
    requiere_modulo('citas');
    $id = (int)get('id', 0);
    $cita = fetch_one(
        "SELECT c.*, CONCAT(cl.nombre,' ',cl.apellido) AS cliente, CONCAT(u.nombre,' ',u.apellido) AS profesional
           FROM cita c JOIN cliente cl ON cl.id_cliente=c.id_cliente JOIN usuario u ON u.id_usuario=c.id_usuario
          WHERE c.id_cita=?", [$id]
    );
    if (!$cita) { flash('Cita no encontrada.', 'error'); redirect('index.php?r=citas/agenda'); }

    $servicios = fetch_all(
        "SELECT s.id_servicio, s.nombre, s.precio,
                (SELECT COUNT(*) FROM servicio_realizado sr WHERE sr.id_cita=cs.id_cita AND sr.id_servicio=cs.id_servicio) AS ya
           FROM cita_servicio cs JOIN servicio s ON s.id_servicio=cs.id_servicio
          WHERE cs.id_cita=? ORDER BY s.nombre", [$id]
    );
    $productos = fetch_all("SELECT id_producto, nombre, unidad_medida FROM producto WHERE activo=1 ORDER BY nombre");
    $usados = fetch_all(
        "SELECT p.nombre, pu.cantidad, p.unidad_medida
           FROM producto_utilizado pu
           JOIN producto p ON p.id_producto=pu.id_producto
           JOIN servicio_realizado sr ON sr.id_servicio_realizado=pu.id_servicio_realizado
          WHERE sr.id_cita=?", [$id]
    );
    view('citas/atender', ['cita' => $cita, 'servicios' => $servicios, 'productos' => $productos, 'usados' => $usados], 'Registrar atención');
}

function citas_atender_guardar(): void
{
    requiere_modulo('citas');
    $u = usuario_actual();
    $id_cita = (int)post('id_cita', 0);
    $realizados = array_map('intval', (array)post('servicios', []));
    $prodIds = (array)post('producto', []);
    $prodCant = (array)post('cantidad', []);
    $obs = trim((string)post('observaciones', '')) ?: null;

    $cita = fetch_one("SELECT id_usuario FROM cita WHERE id_cita=?", [$id_cita]);
    if (!$cita) { flash('Cita no encontrada.', 'error'); redirect('index.php?r=citas/agenda'); }
    // Solo se aceptan servicios que realmente estén agendados en esta cita
    $agendados = array_column(fetch_all("SELECT id_servicio FROM cita_servicio WHERE id_cita=?", [$id_cita]), 'id_servicio');
    $realizados = array_values(array_intersect($realizados, array_map('intval', $agendados)));

    if (!$realizados) {
        flash('Marcá al menos un servicio realizado.', 'error');
        redirect('index.php?r=citas/atender&id=' . $id_cita);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $existe = $pdo->prepare("SELECT id_servicio_realizado FROM servicio_realizado WHERE id_cita=? AND id_servicio=? LIMIT 1");
        $insSR = $pdo->prepare("INSERT INTO servicio_realizado (id_cita,id_servicio,id_usuario,observaciones) VALUES (?,?,?,?)");
        $insPU = $pdo->prepare("INSERT INTO producto_utilizado (id_servicio_realizado,id_producto,cantidad) VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)");

        $idsSR = [];
        foreach ($realizados as $sid) {
            $existe->execute([$id_cita, $sid]);
            $ya = $existe->fetchColumn();
            if ($ya) { $idsSR[] = (int)$ya; continue; }
            $insSR->execute([$id_cita, $sid, (int)$cita['id_usuario'], $obs]);
            $idsSR[] = (int)$pdo->lastInsertId();
        }

        // Los productos se imputan al primer servicio realizado de la cita
        $srPrincipal = $idsSR[0];
        $nProd = 0;
        foreach ($prodIds as $i => $pid) {
            $pid = (int)$pid;
            $c = (float)($prodCant[$i] ?? 0);
            if ($pid <= 0 || $c <= 0) continue;
            $insPU->execute([$srPrincipal, $pid, $c]);
            $nProd++;
        }

        $pdo->prepare("UPDATE cita SET id_estado_cita=4 WHERE id_cita=?")->execute([$id_cita]);
        $pdo->commit();

        auditar('ATENCION', 'Citas', 'servicio_realizado', $id_cita,
            count($idsSR) . ' servicio(s), ' . $nProd . ' producto(s) consumido(s)');
        flash('Atención registrada.' . ($nProd ? ' El stock de los productos usados fue descontado.' : ''));
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('No se pudo registrar la atención: ' . $ex->getMessage(), 'error');
        redirect('index.php?r=citas/atender&id=' . $id_cita);
    }
    redirect('index.php?r=citas/agenda&dia=' . (string)post('dia', date('Y-m-d')));
}

// ---------- Excepciones de agenda ----------
function citas_ausencias(): void
{
    requiere_modulo('citas');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $d = [
            'id_usuario' => ((int)post('id_usuario', 0)) ?: null,
            'id_tipo_ausencia' => (int)post('id_tipo_ausencia', 0),
            'fecha_inicio' => str_replace('T', ' ', trim((string)post('fecha_inicio', ''))),
            'fecha_fin'    => str_replace('T', ' ', trim((string)post('fecha_fin', ''))),
            'motivo'       => trim((string)post('motivo', '')) ?: null,
        ];
        if ($d['id_tipo_ausencia'] && $d['fecha_inicio'] && $d['fecha_fin']) {
            try {
                q("INSERT INTO ausencia_agenda (id_usuario,id_tipo_ausencia,fecha_inicio,fecha_fin,motivo)
                   VALUES (:id_usuario,:id_tipo_ausencia,:fecha_inicio,:fecha_fin,:motivo)", $d);
                flash('Excepción registrada.');
            } catch (PDOException $ex) { flash('Revisá las fechas (fin debe ser mayor al inicio).', 'error'); }
        } else {
            flash('Completá tipo y rango de fechas.', 'error');
        }
        redirect('index.php?r=citas/ausencias');
    }
    $rows = fetch_all(
        "SELECT a.*, ta.nombre AS tipo, COALESCE(CONCAT(u.nombre,' ',u.apellido),'Todo el salón') AS quien
           FROM ausencia_agenda a
           JOIN tipo_ausencia ta ON ta.id_tipo_ausencia = a.id_tipo_ausencia
           LEFT JOIN usuario u ON u.id_usuario = a.id_usuario
          WHERE a.activo=1 ORDER BY a.fecha_inicio DESC LIMIT 100"
    );
    $profs = fetch_all("SELECT id_usuario, nombre, apellido FROM usuario WHERE activo=1 AND id_rol IN (1,2,3) ORDER BY nombre");
    $tipos = fetch_all("SELECT * FROM tipo_ausencia ORDER BY nombre");
    view('citas/ausencias', ['rows' => $rows, 'profs' => $profs, 'tipos' => $tipos], 'Excepciones de agenda');
}
