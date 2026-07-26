<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function portal_index(): void
{
    $idc = requiere_cliente();
    $proxima = fetch_one(
        "SELECT v.* FROM vw_agenda_citas v
           JOIN cita c ON c.id_cita = v.id_cita
          WHERE c.id_cliente=? AND v.fecha_hora >= NOW() AND v.estado NOT IN ('Cancelada','Ausente')
          ORDER BY v.fecha_hora LIMIT 1", [$idc]
    );
    view('portal/index', ['proxima' => $proxima], 'Mi portal');
}

function portal_reservar(): void
{
    requiere_cliente();
    $profs = fetch_all("SELECT id_usuario, nombre, apellido FROM usuario WHERE activo=1 AND id_rol IN (1,2,3) ORDER BY nombre");
    $servicios = fetch_all("SELECT id_servicio, nombre, precio, duracion_min FROM servicio WHERE activo=1 ORDER BY nombre");
    view('portal/reservar', ['profs' => $profs, 'servicios' => $servicios], 'Reservar cita');
}

function portal_guardar_reserva(): void
{
    $idc = requiere_cliente();
    $id_usuario = (int)post('id_usuario', 0);
    $fecha = str_replace('T', ' ', trim((string)post('fecha_hora', '')));
    $servicios = array_map('intval', (array)post('servicios', []));
    $obs = trim((string)post('observaciones', '')) ?: null;

    if (!$id_usuario || $fecha === '' || !$servicios) {
        flash('Elegí profesional, al menos un servicio y la fecha/hora.', 'error');
        redirect('index.php?r=portal/reservar');
    }
    $in = implode(',', array_fill(0, count($servicios), '?'));
    $dur = (int)fetch_val("SELECT COALESCE(SUM(duracion_min),0) FROM servicio WHERE id_servicio IN ($in)", $servicios);
    if ($dur <= 0) $dur = 30;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("CALL sp_agendar_cita(?,?,?,?,?, @nc)");
        $st->execute([$idc, $id_usuario, $fecha, $dur, $obs]);
        $st->closeCursor();
        $id_cita = (int)$pdo->query("SELECT @nc")->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO cita_servicio (id_cita,id_servicio) VALUES (?,?)");
        foreach ($servicios as $sid) { $ins->execute([$id_cita, $sid]); }
        $pdo->commit();
        flash('¡Tu cita fue reservada!');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = strpos($ex->getMessage(), 'disponible') !== false ? 'Ese horario no está disponible. Probá otro.' : 'No se pudo reservar la cita.';
        flash($msg, 'error');
        redirect('index.php?r=portal/reservar');
    }
    redirect('index.php?r=portal/citas');
}

function portal_citas(): void
{
    $idc = requiere_cliente();
    $prox = fetch_all(
        "SELECT v.* FROM vw_agenda_citas v JOIN cita c ON c.id_cita=v.id_cita
          WHERE c.id_cliente=? AND v.fecha_hora >= NOW() ORDER BY v.fecha_hora", [$idc]
    );
    $pasadas = fetch_all(
        "SELECT v.* FROM vw_agenda_citas v JOIN cita c ON c.id_cita=v.id_cita
          WHERE c.id_cliente=? AND v.fecha_hora < NOW() ORDER BY v.fecha_hora DESC LIMIT 50", [$idc]
    );
    view('portal/citas', ['prox' => $prox, 'pasadas' => $pasadas], 'Mis citas');
}

function portal_cancelar(): void
{
    $idc = requiere_cliente();
    $id = (int)post('id_cita', 0);
    // Verificamos que la cita sea del cliente
    $ok = fetch_val("SELECT COUNT(*) FROM cita WHERE id_cita=? AND id_cliente=?", [$id, $idc]);
    if (!$ok) { flash('No podés cancelar esa cita.', 'error'); redirect('index.php?r=portal/citas'); }
    try { q("CALL sp_cancelar_cita(?)", [$id]); flash('Tu cita fue cancelada.'); }
    catch (PDOException $ex) { flash('No se pudo cancelar la cita.', 'error'); }
    redirect('index.php?r=portal/citas');
}

function portal_promociones(): void
{
    $idc = requiere_cliente();
    $fid = fetch_one("SELECT * FROM vw_cliente_fidelizacion WHERE id_cliente=?", [$idc]);
    $promos = fetch_all(
        "SELECT * FROM descuento
          WHERE activo=1 AND (fecha_inicio IS NULL OR fecha_inicio<=CURDATE())
                        AND (fecha_fin IS NULL OR fecha_fin>=CURDATE())
          ORDER BY nombre"
    );
    view('portal/promociones', ['fid' => $fid, 'promos' => $promos], 'Promociones');
}

function portal_valoraciones(): void
{
    $idc = requiere_cliente();
    // Citas atendidas sin calificar
    $pendientes = fetch_all(
        "SELECT c.id_cita, c.fecha_hora,
                (SELECT GROUP_CONCAT(s.nombre SEPARATOR ', ') FROM cita_servicio cs JOIN servicio s ON s.id_servicio=cs.id_servicio WHERE cs.id_cita=c.id_cita) AS servicios
           FROM cita c
          WHERE c.id_cliente=? AND c.id_estado_cita=4
            AND NOT EXISTS (SELECT 1 FROM calificacion cal WHERE cal.id_cita=c.id_cita)
          ORDER BY c.fecha_hora DESC", [$idc]
    );
    $hechas = fetch_all(
        "SELECT cal.*, c.fecha_hora FROM calificacion cal JOIN cita c ON c.id_cita=cal.id_cita
          WHERE c.id_cliente=? ORDER BY cal.fecha DESC", [$idc]
    );
    view('portal/valoraciones', ['pendientes' => $pendientes, 'hechas' => $hechas], 'Valoraciones');
}

function portal_calificar(): void
{
    $idc = requiere_cliente();
    $id_cita = (int)post('id_cita', 0);
    $puntaje = (int)post('puntaje', 0);
    $comentario = trim((string)post('comentario', '')) ?: null;
    $ok = fetch_val("SELECT COUNT(*) FROM cita WHERE id_cita=? AND id_cliente=? AND id_estado_cita=4", [$id_cita, $idc]);
    if (!$ok || $puntaje < 1 || $puntaje > 5) { flash('No se pudo registrar la valoración.', 'error'); redirect('index.php?r=portal/valoraciones'); }
    try {
        q("INSERT INTO calificacion (id_cita,puntaje,comentario) VALUES (?,?,?)", [$id_cita, $puntaje, $comentario]);
        flash('¡Gracias por tu valoración!');
    } catch (PDOException $ex) { flash('Esa cita ya fue calificada.', 'error'); }
    redirect('index.php?r=portal/valoraciones');
}
