<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/index')) ?>"><i class="bi bi-arrow-left"></i> Servicios</a>
    <h1 class="mt-1">Catálogo de servicios</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=servicios/form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo servicio</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Servicio</th><th>Categoría</th><th>Precio</th><th>Duración</th><th>IVA</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted-warm py-4">Sin servicios.</td></tr><?php endif; ?>
      <?php foreach ($rows as $s): ?>
        <tr>
          <td><?= e($s['nombre']) ?></td>
          <td class="text-muted-warm"><?= e($s['categoria']) ?></td>
          <td><?= money($s['precio']) ?></td>
          <td><?= (int)$s['duracion_min'] ?> min</td>
          <td><?= (int)$s['tasa_iva'] ?>%</td>
          <td><?= $s['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=servicios/form&id=' . $s['id_servicio'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=servicios/baja')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id_servicio" value="<?= (int)$s['id_servicio'] ?>">
              <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
