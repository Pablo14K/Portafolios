<?php $u = usuario_actual(); ?>
<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=personal/index')) ?>"><i class="bi bi-arrow-left"></i> Personal</a>
    <h1 class="mt-1">Usuarios</h1>
  </div>
  <?php if ((int)$u['rol'] === ROL_PROPIETARIA): ?>
    <a class="btn btn-oro" href="<?= e(base_url('index.php?r=personal/usuario_form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo usuario</a>
  <?php endif; ?>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Nombre</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin usuarios.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['nombre'] . ' ' . $r['apellido']) ?></td>
          <td class="text-muted-warm"><?= e($r['username']) ?></td>
          <td class="text-muted-warm"><?= e($r['email']) ?></td>
          <td><span class="badge-estado e-prog"><?= e($r['rol']) ?></span></td>
          <td><?= $r['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <?php if ((int)$u['rol'] === ROL_PROPIETARIA): ?>
              <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=personal/usuario_form&id=' . $r['id_usuario'])) ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" action="<?= e(base_url('index.php?r=personal/usuario_baja')) ?>" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="id_usuario" value="<?= (int)$r['id_usuario'] ?>">
                <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
              </form>
            <?php else: ?><span class="text-muted-warm" style="font-size:.8rem">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
