<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/index')) ?>"><i class="bi bi-arrow-left"></i> Inventario</a>
    <h1 class="mt-1">Compras</h1>
    <div class="sub">Registrar una compra suma stock automáticamente (y crea el producto si no existe).</div>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=inventario/compra_form')) ?>"><i class="bi bi-plus-lg"></i> Nueva compra</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Proveedor</th><th>Registró</th><th>Total</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin compras registradas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr><td><?= e(fecha($c['fecha'])) ?></td><td><?= e($c['proveedor']) ?></td>
          <td class="text-muted-warm"><?= e($c['registro']) ?></td>
          <td><?= money($c['total']) ?></td><td><?= estado_badge($c['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
