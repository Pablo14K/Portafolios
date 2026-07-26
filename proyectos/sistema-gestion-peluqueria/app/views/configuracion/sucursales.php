<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
    <h1 class="mt-1">Sucursales</h1>
    <div class="sub">Locales del negocio. Los datos de cada sucursal identifican al emisor en la facturación.</div>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=configuracion/sucursal_form')) ?>"><i class="bi bi-plus-lg"></i> Nueva sucursal</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Nombre</th><th>RUC</th><th>Ciudad</th><th>Teléfono</th><th>Usuarios</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr>
          <td><?= e($s['nombre']) ?></td>
          <td><?= e($s['ruc'] ?: '—') ?></td>
          <td class="text-muted-warm"><?= e($s['ciudad'] ?: '—') ?></td>
          <td><?= e($s['telefono'] ?: '—') ?></td>
          <td><?= (int)$s['usuarios'] ?></td>
          <td><?= $s['activo'] ? '<span class="badge-estado e-ok">Activa</span>' : '<span class="badge-estado e-muted">Inactiva</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=configuracion/sucursal_form&id=' . $s['id_sucursal'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=configuracion/sucursal_baja')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id_sucursal" value="<?= (int)$s['id_sucursal'] ?>">
              <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
