<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function reportes_index(): void
{
    requiere_modulo('reportes');

    $servicios = fetch_all("SELECT * FROM vw_servicios_mas_solicitados LIMIT 12");
    $demanda   = fetch_all("SELECT * FROM vw_demanda_por_hora");

    $mes = date('Y-m');
    $ingresos_mes = (float)fetch_val(
        "SELECT COALESCE(SUM(monto),0) FROM cobro WHERE id_estado_cobro=1 AND DATE_FORMAT(fecha,'%Y-%m')=?", [$mes]
    );
    $citas_mes = (int)fetch_val(
        "SELECT COUNT(*) FROM cita WHERE DATE_FORMAT(fecha_hora,'%Y-%m')=?", [$mes]
    );
    $ausencias_mes = (int)fetch_val(
        "SELECT COUNT(*) FROM cita WHERE id_estado_cita=6 AND DATE_FORMAT(fecha_hora,'%Y-%m')=?", [$mes]
    );
    $prov = fetch_all("SELECT * FROM vw_cuenta_proveedor WHERE saldo > 0 ORDER BY vencida DESC, vencimiento LIMIT 20");

    // Máximo para dibujar las barras de demanda
    $maxDemanda = 0;
    foreach ($demanda as $d) { $maxDemanda = max($maxDemanda, (int)$d['citas']); }

    view('reportes/index', [
        'servicios' => $servicios, 'demanda' => $demanda, 'maxDemanda' => $maxDemanda,
        'ingresos_mes' => $ingresos_mes, 'citas_mes' => $citas_mes, 'ausencias_mes' => $ausencias_mes,
        'prov' => $prov, 'mes' => $mes,
    ], 'Reportes');
}
