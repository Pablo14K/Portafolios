<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/index')) ?>"><i class="bi bi-arrow-left"></i> Inventario</a>
    <h1 class="mt-1">Proveedores</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=inventario/proveedor_form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo proveedor</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Proveedor</th><th>Contacto</th><th>RUC</th><th>Teléfono</th><th>Saldo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted-warm py-4">Sin proveedores.</td></tr><?php endif; ?>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td><?= e($p['nombre']) ?></td>
          <td class="text-muted-warm"><?= e($p['contacto'] ?: '—') ?></td>
          <td><?= e($p['ruc'] ?: '—') ?></td>
          <td><?= e($p['telefono'] ?: '—') ?></td>
          <td><?= (float)$p['saldo'] > 0 ? '<span class="badge-estado e-warn">' . money($p['saldo']) . '</span>' : money(0) ?></td>
          <td><?= $p['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/proveedor_form&id=' . $p['id_proveedor'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=inventario/proveedor_baja')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id_proveedor" value="<?= (int)$p['id_proveedor'] ?>">
              <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
