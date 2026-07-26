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
