<?php $id = $u['id_usuario'] ?? 0; ?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=personal/usuarios')) ?>"><i class="bi bi-arrow-left"></i> Usuarios</a>
  <h1 class="mt-1"><?= $id ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
</div>
<div class="spg-panel" style="max-width:720px">
  <form method="post" action="<?= e(base_url('index.php?r=personal/usuario_guardar')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id_usuario" value="<?= (int)$id ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($u['nombre'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Apellido *</label>
        <input class="form-control" name="apellido" required value="<?= e($u['apellido'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Usuario *</label>
        <input class="form-control" name="username" required value="<?= e($u['username'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Cédula</label>
        <input class="form-control" name="cedula" value="<?= e($u['cedula'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Teléfono</label>
        <input class="form-control" name="telefono" value="<?= e($u['telefono'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Email *</label>
        <input type="email" class="form-control" name="email" required value="<?= e($u['email'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label">Rol *</label>
        <select class="form-select" name="id_rol" required>
          <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id_rol'] ?>" <?= (($u['id_rol'] ?? 3) == $r['id_rol']) ? 'selected' : '' ?>><?= e($r['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label">Sucursal</label>
        <select class="form-select" name="id_sucursal">
          <option value="">—</option>
          <?php foreach ($sucursales as $sc): ?><option value="<?= (int)$sc['id_sucursal'] ?>" <?= (($u['id_sucursal'] ?? 0) == $sc['id_sucursal']) ? 'selected' : '' ?>><?= e($sc['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label">Contraseña <?= $id ? '<span class="text-muted-warm" style="font-weight:400">(dejar vacío para no cambiar)</span>' : '*' ?></label>
        <input type="password" class="form-control" name="password" <?= $id ? '' : 'required' ?>></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=personal/usuarios')) ?>">Cancelar</a>
    </div>
  </form>
</div>
