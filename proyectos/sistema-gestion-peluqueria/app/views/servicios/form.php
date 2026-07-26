<?php $id = $s['id_servicio'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/lista')) ?>"><i class="bi bi-arrow-left"></i> Catálogo</a>
  <h1 class="mt-1"><?= $id ? 'Editar servicio' : 'Nuevo servicio' ?></h1>
</div>
<div class="spg-panel" style="max-width:680px">
  <form method="post" action="<?= e(base_url('index.php?r=servicios/guardar')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id_servicio" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($s['nombre'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Categoría *</label>
        <select class="form-select" name="id_categoria_servicio" required>
          <option value="">—</option>
          <?php foreach ($cats as $ct): ?>
            <option value="<?= (int)$ct['id_categoria_servicio'] ?>" <?= (($s['id_categoria_servicio'] ?? 0) == $ct['id_categoria_servicio']) ? 'selected' : '' ?>><?= e($ct['nombre']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label">Precio (Gs.)</label>
        <input type="number" min="0" step="1" class="form-control" name="precio" value="<?= e($s['precio'] ?? 0) ?>"></div>
      <div class="col-md-4"><label class="form-label">Duración (min)</label>
        <input type="number" min="0" step="5" class="form-control" name="duracion_min" value="<?= e($s['duracion_min'] ?? 0) ?>"></div>
      <div class="col-md-4"><label class="form-label">IVA</label>
        <select class="form-select" name="tasa_iva">
          <?php foreach ([10,5,0] as $iva): ?>
            <option value="<?= $iva ?>" <?= (($s['tasa_iva'] ?? 10) == $iva) ? 'selected' : '' ?>><?= $iva ?>%</option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-12"><label class="form-label">Descripción</label>
        <textarea class="form-control" name="descripcion" rows="2"><?= e($s['descripcion'] ?? '') ?></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=servicios/lista')) ?>">Cancelar</a>
    </div>
  </form>
</div>
