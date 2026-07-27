<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/index')) ?>"><i class="bi bi-arrow-left"></i> Inventario</a>
    <h1 class="mt-1">Productos</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=inventario/producto_form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo producto</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Stock</th><th>Mínimo</th><th>Costo</th><th>Venta</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted-warm py-4">Sin productos.</td></tr><?php endif; ?>
      <?php foreach ($rows as $p): $bajo = (float)$p['stock_actual'] <= (float)$p['stock_minimo']; ?>
        <tr>
          <td><?= e($p['nombre']) ?> <span class="text-muted-warm" style="font-size:.8rem">(<?= e($p['unidad_medida']) ?>)</span></td>
          <td class="text-muted-warm"><?= e($p['categoria']) ?></td>
          <td><?= $bajo && $p['activo'] ? '<span class="badge-estado e-no">' . e(cant($p['stock_actual'])) . '</span>' : e(cant($p['stock_actual'])) ?></td>
          <td class="text-muted-warm"><?= e(cant($p['stock_minimo'])) ?></td>
          <td><?= money($p['precio_costo']) ?></td>
          <td><?= money($p['precio_venta']) ?></td>
          <td><?= $p['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/producto_form&id=' . $p['id_producto'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=inventario/producto_baja')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id_producto" value="<?= (int)$p['id_producto'] ?>">
              <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
