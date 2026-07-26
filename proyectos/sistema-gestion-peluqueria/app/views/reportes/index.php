<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=dashboard/index')) ?>"><i class="bi bi-arrow-left"></i> Panel</a>
  <h1 class="mt-1">Reportes</h1>
  <div class="sub">Indicadores del mes <?= e(fecha($mes . '-01', 'm/Y')) ?>.</div>
</div>

<div class="spg-metrics">
  <div class="spg-metric"><div class="lbl">Ingresos del mes</div><div class="val oro"><?= money($ingresos_mes) ?></div></div>
  <div class="spg-metric"><div class="lbl">Citas del mes</div><div class="val"><?= (int)$citas_mes ?></div></div>
  <div class="spg-metric"><div class="lbl">Ausencias del mes</div><div class="val"><?= (int)$ausencias_mes ?></div></div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="spg-panel h-100">
      <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Servicios más solicitados</h2>
      <table class="table align-middle mb-0">
        <thead><tr><th>Servicio</th><th>Categoría</th><th>Veces</th><th>Ingreso</th></tr></thead>
        <tbody>
        <?php if (!$servicios): ?><tr><td colspan="4" class="text-center text-muted-warm py-3">Sin datos.</td></tr><?php endif; ?>
        <?php foreach ($servicios as $s): ?>
          <tr><td><?= e($s['servicio']) ?></td><td class="text-muted-warm"><?= e($s['categoria']) ?></td>
            <td><?= (int)$s['veces_realizado'] ?></td><td><?= money($s['ingreso_generado']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="spg-panel h-100">
      <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Demanda por hora</h2>
      <?php if (!$demanda): ?><p class="text-muted-warm">Sin citas registradas.</p><?php endif; ?>
      <?php foreach ($demanda as $d): $pct = $maxDemanda ? round((int)$d['citas'] / $maxDemanda * 100) : 0; ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span style="width:52px;font-size:.82rem;color:var(--gris-oscuro)"><?= str_pad((string)$d['hora'], 2, '0', STR_PAD_LEFT) ?>:00</span>
          <div style="flex:1;background:var(--blanco-hueso);border-radius:6px;height:18px;overflow:hidden">
            <div style="width:<?= $pct ?>%;background:var(--oro);height:100%"></div>
          </div>
          <span style="width:30px;text-align:right;font-size:.82rem"><?= (int)$d['citas'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="spg-panel mt-3">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Cuenta corriente con proveedores</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Proveedor</th><th>Fecha</th><th>Vencimiento</th><th>Total</th><th>Saldo</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$prov): ?><tr><td colspan="6" class="text-center text-muted-warm py-3">Sin saldos pendientes.</td></tr><?php endif; ?>
      <?php foreach ($prov as $p): ?>
        <tr><td><?= e($p['proveedor']) ?></td><td><?= e(fecha($p['fecha'], 'd/m/Y')) ?></td>
          <td><?= e(fecha($p['vencimiento'], 'd/m/Y')) ?></td><td><?= money($p['total']) ?></td>
          <td><?= money($p['saldo']) ?></td>
          <td><?= (int)$p['vencida'] ? '<span class="badge-estado e-no">Vencida</span>' : '<span class="badge-estado e-warn">Pendiente</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
