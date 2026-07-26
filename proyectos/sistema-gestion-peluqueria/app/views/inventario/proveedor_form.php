<?php $id = $p['id_proveedor'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/proveedores')) ?>"><i class="bi bi-arrow-left"></i> Proveedores</a>
  <h1 class="mt-1"><?= $id ? 'Editar proveedor' : 'Nuevo proveedor' ?></h1>
</div>
<div class="spg-panel" style="max-width:680px">
  <form method="post" action="<?= e(base_url('index.php?r=inventario/proveedor_guardar')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id_proveedor" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nombre / Razón social *</label>
        <input class="form-control" name="nombre" required value="<?= e($p['nombre'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">RUC</label>
        <input class="form-control" name="ruc" value="<?= e($p['ruc'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Contacto</label>
        <input class="form-control" name="contacto" value="<?= e($p['contacto'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Teléfono</label>
        <input class="form-control" name="telefono" value="<?= e($p['telefono'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Email</label>
        <input type="email" class="form-control" name="email" value="<?= e($p['email'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Dirección</label>
        <input class="form-control" name="direccion" value="<?= e($p['direccion'] ?? '') ?>"></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/proveedores')) ?>">Cancelar</a>
    </div>
  </form>
</div>
