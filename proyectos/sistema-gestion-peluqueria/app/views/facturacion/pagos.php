<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
  <h1 class="mt-1">Pagos al personal</h1>
  <div class="sub">Liquidaciones por comisión de servicios realizados.</div>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Beneficiario</th><th>Período</th><th>Servicios</th><th>Monto</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin pagos registrados.</td></tr><?php endif; ?>
      <?php foreach ($rows as $p): ?>
        <tr><td><?= e(fecha($p['fecha'])) ?></td><td><?= e($p['beneficiario']) ?></td>
          <td class="text-muted-warm"><?= e($p['periodo']) ?></td><td><?= (int)$p['servicios'] ?></td>
          <td><?= money($p['monto']) ?></td><td><?= estado_badge($p['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
