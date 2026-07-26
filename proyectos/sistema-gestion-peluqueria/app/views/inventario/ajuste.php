<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/stock')) ?>"><i class="bi bi-arrow-left"></i> Stock</a>
  <h1 class="mt-1">Registrar movimiento de stock</h1>
  <div class="sub">Entradas y salidas manuales: ajustes, mermas, inventario inicial, devoluciones.</div>
</div>
<div class="spg-panel" style="max-width:680px">
  <form method="post" action="<?= e(base_url('index.php?r=inventario/ajuste')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-7"><label class="form-label">Producto *</label>
        <select class="form-select" name="id_producto" required><option value="">—</option>
          <?php foreach ($prods as $p): ?><option value="<?= (int)$p['id_producto'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-5"><label class="form-label">Tipo de movimiento *</label>
        <select class="form-select" name="id_tipo_movimiento" required><option value="">—</option>
          <?php foreach ($tipos as $t): ?><option value="<?= (int)$t['id_tipo_movimiento'] ?>"><?= e($t['nombre']) ?> (<?= $t['signo'] === 'E' ? 'entrada' : 'salida' ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Cantidad *</label>
        <input type="number" min="0.01" step="0.01" class="form-control" name="cantidad" required></div>
      <div class="col-md-4"><label class="form-label">Precio unitario (Gs.)</label>
        <input type="number" min="0" step="1" class="form-control" name="precio_unitario" value="0"></div>
      <div class="col-md-4"><label class="form-label">Referencia</label>
        <input class="form-control" name="referencia" placeholder="Ej: Ajuste, N° remisión"></div>
      <div class="col-12"><label class="form-label">Observaciones</label>
        <textarea class="form-control" name="observaciones" rows="2"></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Registrar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/stock')) ?>">Cancelar</a>
    </div>
  </form>
</div>
