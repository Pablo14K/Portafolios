<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=personal/index')) ?>"><i class="bi bi-arrow-left"></i> Personal</a>
    <h1 class="mt-1">Comisiones</h1>
    <div class="sub">Porcentaje o monto que percibe cada profesional por servicio.</div>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=personal/comision_form')) ?>"><i class="bi bi-plus-lg"></i> Nueva comisión</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Profesional</th><th>Servicio</th><th>Tipo</th><th>Valor</th><th>Vigente desde</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin comisiones cargadas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr><td><?= e($c['profesional']) ?></td><td class="text-muted-warm"><?= e($c['servicio']) ?></td>
          <td><?= e($c['tipo']) ?></td><td><?= $c['tipo'] === 'PORCENTAJE' ? e($c['valor']) . '%' : money($c['valor']) ?></td>
          <td><?= e(fecha($c['vigente_desde'], 'd/m/Y')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
