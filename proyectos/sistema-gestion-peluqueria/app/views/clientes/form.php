<?php $id = $c['id_cliente'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=clientes/lista')) ?>"><i class="bi bi-arrow-left"></i> Clientes</a>
  <h1 class="mt-1"><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1>
</div>

<div class="spg-panel" style="max-width:720px">
  <form method="post" action="<?= e(base_url('index.php?r=clientes/guardar')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id_cliente" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($c['nombre'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Apellido *</label>
        <input class="form-control" name="apellido" required value="<?= e($c['apellido'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Cédula</label>
        <input class="form-control" name="cedula" value="<?= e($c['cedula'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">RUC</label>
        <input class="form-control" name="ruc" value="<?= e($c['ruc'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Teléfono</label>
        <input class="form-control" name="telefono" value="<?= e($c['telefono'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" value="<?= e($c['email'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Fecha de nacimiento</label>
        <input type="date" class="form-control" name="fecha_nacimiento" value="<?= e($c['fecha_nacimiento'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Observaciones</label>
        <textarea class="form-control" name="observaciones" rows="2"><?= e($c['observaciones'] ?? '') ?></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=clientes/lista')) ?>">Cancelar</a>
    </div>
  </form>
</div>
