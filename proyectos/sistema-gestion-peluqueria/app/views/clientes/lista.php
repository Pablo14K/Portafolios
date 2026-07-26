<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=clientes/index')) ?>"><i class="bi bi-arrow-left"></i> Clientes</a>
    <h1 class="mt-1">Clientes</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=clientes/form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo cliente</a>
</div>

<div class="spg-panel">
  <form class="mb-3" method="get" action="<?= e(base_url('index.php')) ?>">
    <input type="hidden" name="r" value="clientes/lista">
    <div class="input-group" style="max-width:420px">
      <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, cédula o teléfono" value="<?= e($buscar) ?>">
      <button class="btn btn-outline-neutro"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Cliente</th><th>Cédula</th><th>Teléfono</th><th>Email</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$clientes): ?>
        <tr><td colspan="6" class="text-center text-muted-warm py-4">Sin resultados.</td></tr>
      <?php endif; ?>
      <?php foreach ($clientes as $c): ?>
        <tr>
          <td><?= e($c['apellido'] . ', ' . $c['nombre']) ?></td>
          <td><?= e($c['cedula'] ?: '—') ?></td>
          <td><?= e($c['telefono'] ?: '—') ?></td>
          <td class="text-muted-warm"><?= e($c['email'] ?: '—') ?></td>
          <td><?= $c['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=clientes/historial&id=' . $c['id_cliente'])) ?>" title="Historial"><i class="bi bi-clock-history"></i></a>
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=clientes/form&id=' . $c['id_cliente'])) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=clientes/baja')) ?>" class="d-inline" onsubmit="return confirm('¿Cambiar el estado de este cliente?')">
              <?= csrf_field() ?><input type="hidden" name="id_cliente" value="<?= (int)$c['id_cliente'] ?>">
              <button class="btn btn-sm btn-outline-neutro" title="Activar/Desactivar"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
