<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/index')) ?>"><i class="bi bi-arrow-left"></i> Servicios</a>
    <h1 class="mt-1">Descuentos y promociones</h1>
    <div class="sub">Los descuentos de nivel se aplican automáticamente según la fidelización del cliente.</div>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=servicios/descuento_form')) ?>"><i class="bi bi-plus-lg"></i> Nuevo descuento</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Nombre</th><th>Tipo</th><th>Valor</th><th>Vigencia</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin descuentos.</td></tr><?php endif; ?>
      <?php foreach ($rows as $d): ?>
        <tr>
          <td><?= e($d['nombre']) ?><?php if ($d['descripcion']): ?><div class="text-muted-warm" style="font-size:.8rem"><?= e($d['descripcion']) ?></div><?php endif; ?></td>
          <td><?= e($d['tipo']) ?></td>
          <td><?= $d['tipo'] === 'PORCENTAJE' ? e($d['valor']) . '%' : money($d['valor']) ?></td>
          <td class="text-muted-warm"><?= $d['fecha_inicio'] ? e(fecha($d['fecha_inicio'], 'd/m/Y')) . ' → ' . ($d['fecha_fin'] ? e(fecha($d['fecha_fin'], 'd/m/Y')) : 'sin fin') : 'Permanente' ?></td>
          <td><?= $d['activo'] ? '<span class="badge-estado e-ok">Activo</span>' : '<span class="badge-estado e-muted">Inactivo</span>' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=servicios/descuento_form&id=' . $d['id_descuento'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" action="<?= e(base_url('index.php?r=servicios/descuento_baja')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id_descuento" value="<?= (int)$d['id_descuento'] ?>">
              <button class="btn btn-sm btn-outline-neutro"><i class="bi bi-toggle-on"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
