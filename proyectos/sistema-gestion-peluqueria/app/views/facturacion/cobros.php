<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
  <h1 class="mt-1">Cobros</h1>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Método</th><th>Monto</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin cobros registrados.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr><td><?= e(fecha($c['fecha'])) ?></td><td><?= e($c['cliente'] ?: '—') ?></td>
          <td class="text-muted-warm"><?= e($c['metodo']) ?></td><td><?= money($c['monto']) ?></td>
          <td><?= estado_badge($c['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
