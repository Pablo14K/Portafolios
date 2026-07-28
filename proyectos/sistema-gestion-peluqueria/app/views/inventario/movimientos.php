<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/index')) ?>"><i class="bi bi-arrow-left"></i> Inventario</a>
    <h1 class="mt-1">Movimientos de stock</h1>
    <div class="sub">Todas las entradas y salidas. El stock de un producto es la suma de estos movimientos.</div>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=inventario/ajuste')) ?>"><i class="bi bi-plus-lg"></i> Registrar movimiento</a>
</div>

<div class="spg-panel">
  <form class="mb-3 d-flex align-items-center gap-2 flex-wrap" method="get" action="<?= e(base_url('index.php')) ?>">
    <input type="hidden" name="r" value="inventario/movimientos">
    <label class="form-label mb-0">Producto</label>
    <select name="producto" class="form-select" style="max-width:300px" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($prods as $p): ?>
        <option value="<?= (int)$p['id_producto'] ?>" <?= $idp == $p['id_producto'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($prod): ?>
      <span class="ms-2">Stock actual: <strong style="color:var(--oro-oscuro)"><?= e(cant($prod['stock'])) ?></strong> <span class="text-muted-warm"><?= e($prod['unidad_medida']) ?></span></span>
    <?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Referencia</th><th>Registró</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin movimientos registrados.</td></tr><?php endif; ?>
      <?php foreach ($rows as $m): $entra = ($m['signo'] === 'E'); ?>
        <tr>
          <td><?= e(fecha($m['fecha'])) ?></td>
          <td><?= e($m['producto']) ?></td>
          <td><?= e($m['tipo']) ?></td>
          <td>
            <?php if ($entra): ?>
              <span class="badge-estado e-ok">+ <?= e(cant($m['cantidad'])) ?></span>
            <?php else: ?>
              <span class="badge-estado e-no">− <?= e(cant($m['cantidad'])) ?></span>
            <?php endif; ?>
            <span class="text-muted-warm" style="font-size:.78rem"><?= e($m['unidad_medida']) ?></span>
          </td>
          <td class="text-muted-warm"><?= e($m['referencia'] ?: '—') ?></td>
          <td class="text-muted-warm"><?= e($m['usuario']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted-warm mt-3 mb-0" style="font-size:.78rem">
    Las salidas por <strong>consumo en servicio</strong> se generan solas al registrar la atención de una cita
    (Citas → botón <i class="bi bi-check2-square"></i>), y las entradas por <strong>compra</strong> al registrar una compra.
  </p>
</div>
