<?php $id = $p['id_producto'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/productos')) ?>"><i class="bi bi-arrow-left"></i> Productos</a>
  <h1 class="mt-1"><?= $id ? 'Editar producto' : 'Nuevo producto' ?></h1>
</div>
<div class="spg-panel" style="max-width:720px">
  <form method="post" action="<?= e(base_url('index.php?r=inventario/producto_guardar')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id_producto" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($p['nombre'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Categoría *</label>
        <select class="form-select" name="id_categoria" required><option value="">—</option>
          <?php foreach ($cats as $ct): ?><option value="<?= (int)$ct['id_categoria'] ?>" <?= (($p['id_categoria'] ?? 0) == $ct['id_categoria']) ? 'selected' : '' ?>><?= e($ct['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label">Unidad</label>
        <input class="form-control" name="unidad_medida" value="<?= e($p['unidad_medida'] ?? 'unidad') ?>"></div>
      <div class="col-md-4"><label class="form-label">Stock mínimo</label>
        <input type="number" min="0" step="0.01" class="form-control" name="stock_minimo" value="<?= e($p['stock_minimo'] ?? 0) ?>"></div>
      <div class="col-md-4"><label class="form-label">IVA</label>
        <select class="form-select" name="tasa_iva"><?php foreach ([10,5,0] as $iva): ?><option value="<?= $iva ?>" <?= (($p['tasa_iva'] ?? 10) == $iva) ? 'selected' : '' ?>><?= $iva ?>%</option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Precio costo (Gs.)</label>
        <input type="number" min="0" step="1" class="form-control" name="precio_costo" value="<?= e($p['precio_costo'] ?? 0) ?>"></div>
      <div class="col-md-6"><label class="form-label">Precio venta (Gs.)</label>
        <input type="number" min="0" step="1" class="form-control" name="precio_venta" value="<?= e($p['precio_venta'] ?? 0) ?>"></div>
      <div class="col-12"><label class="form-label">Descripción</label>
        <textarea class="form-control" name="descripcion" rows="2"><?= e($p['descripcion'] ?? '') ?></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/productos')) ?>">Cancelar</a>
    </div>
  </form>
</div>
