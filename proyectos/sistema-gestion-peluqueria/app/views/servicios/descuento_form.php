<?php $id = $d['id_descuento'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/descuentos')) ?>"><i class="bi bi-arrow-left"></i> Descuentos</a>
  <h1 class="mt-1"><?= $id ? 'Editar descuento' : 'Nuevo descuento' ?></h1>
</div>
<div class="spg-panel" style="max-width:640px">
  <form method="post" action="<?= e(base_url('index.php?r=servicios/descuento_guardar')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id_descuento" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($d['nombre'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Tipo *</label>
        <select class="form-select" name="tipo">
          <option value="PORCENTAJE" <?= (($d['tipo'] ?? '') === 'PORCENTAJE') ? 'selected' : '' ?>>Porcentaje</option>
          <option value="MONTO" <?= (($d['tipo'] ?? '') === 'MONTO') ? 'selected' : '' ?>>Monto fijo</option>
        </select></div>
      <div class="col-md-4"><label class="form-label">Valor *</label>
        <input type="number" min="0" step="0.01" class="form-control" name="valor" value="<?= e($d['valor'] ?? 0) ?>"></div>
      <div class="col-md-4"><label class="form-label">Vigente desde</label>
        <input type="date" class="form-control" name="fecha_inicio" value="<?= e($d['fecha_inicio'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Vigente hasta</label>
        <input type="date" class="form-control" name="fecha_fin" value="<?= e($d['fecha_fin'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Descripción</label>
        <textarea class="form-control" name="descripcion" rows="2"><?= e($d['descripcion'] ?? '') ?></textarea></div>
    </div>
    <div class="form-text mt-2" style="font-size:.78rem">Dejá las fechas vacías para un descuento permanente.</div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=servicios/descuentos')) ?>">Cancelar</a>
    </div>
  </form>
</div>
