<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/index')) ?>"><i class="bi bi-arrow-left"></i> Inventario</a>
    <h1 class="mt-1">Stock</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=inventario/ajuste')) ?>"><i class="bi bi-arrow-left-right"></i> Registrar movimiento</a>
</div>

<?php if ($bajo): ?>
<div class="spg-panel mb-3" style="border-color:var(--rojo)">
  <h2 style="font-size:1rem;font-weight:500;color:var(--rojo);margin-bottom:.7rem;"><i class="bi bi-exclamation-triangle"></i> Productos por reponer</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Stock</th><th>Mínimo</th><th>Faltante</th></tr></thead>
      <tbody>
      <?php foreach ($bajo as $b): ?>
        <tr><td><?= e($b['nombre']) ?></td><td class="text-muted-warm"><?= e($b['categoria']) ?></td>
          <td><span class="badge-estado e-no"><?= e(cant($b['stock_actual'])) ?></span></td>
          <td class="text-muted-warm"><?= e(cant($b['stock_minimo'])) ?></td>
          <td><?= e(cant($b['faltante'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="spg-panel">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.7rem;">Existencias</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Unidad</th><th>Stock actual</th><th>Mínimo</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin productos.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= e($r['nombre']) ?></td><td class="text-muted-warm"><?= e($r['categoria']) ?></td>
          <td class="text-muted-warm"><?= e($r['unidad_medida']) ?></td>
          <td><?= e(cant($r['stock_actual'])) ?></td><td class="text-muted-warm"><?= e(cant($r['stock_minimo'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
