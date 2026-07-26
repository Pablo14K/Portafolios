<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function dashboard_index(): void
{
    requiere_personal();

    $hoy = date('Y-m-d');
    $m = [
        'citas_hoy' => (int)fetch_val(
            "SELECT COUNT(*) FROM cita WHERE DATE(fecha_hora)=? AND id_estado_cita NOT IN (3,6)", [$hoy]
        ),
        'clientes' => (int)fetch_val("SELECT COUNT(*) FROM cliente WHERE activo=1"),
        'bajo_stock' => (int)fetch_val("SELECT COUNT(*) FROM vw_producto_bajo_stock"),
        'ingresos_hoy' => (float)fetch_val(
            "SELECT COALESCE(SUM(monto),0) FROM cobro WHERE DATE(fecha)=? AND id_estado_cobro=1", [$hoy]
        ),
    ];

    $proximas = fetch_all(
        "SELECT * FROM vw_agenda_citas
          WHERE fecha_hora >= NOW() AND estado NOT IN ('Cancelada','Ausente')
          ORDER BY fecha_hora LIMIT 6"
    );

    view('dashboard/index', ['m' => $m, 'proximas' => $proximas], 'Panel principal');
}
