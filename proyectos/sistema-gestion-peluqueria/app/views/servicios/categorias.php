<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/index')) ?>"><i class="bi bi-arrow-left"></i> Servicios</a>
  <h1 class="mt-1">Categorías de servicio</h1>
  <div class="sub">Podés agregar, renombrar o eliminar categorías.</div>
</div>
<div class="row g-3">
  <div class="col-md-5">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Agregar categoría</h2>
      <form method="post" action="<?= e(base_url('index.php?r=servicios/categorias')) ?>">
        <?= csrf_field() ?>
        <div class="input-group">
          <input class="form-control" name="nombre" placeholder="Ej: Depilación" required>
          <button class="btn btn-oro"><i class="bi bi-plus-lg"></i></button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-md-7">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Categorías existentes</h2>
      <?php if (!$rows): ?><p class="text-muted-warm mb-0">Sin categorías.</p><?php endif; ?>
      <?php foreach ($rows as $r): $id = (int)$r['id_categoria_servicio']; ?>
        <div class="d-flex align-items-center gap-1 mb-2">
          <form method="post" action="<?= e(base_url('index.php?r=servicios/categoria_editar')) ?>" class="d-flex gap-1 flex-grow-1">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input class="form-control form-control-sm" name="nombre" value="<?= e($r['nombre']) ?>" required>
            <button class="btn btn-sm btn-outline-neutro" title="Guardar cambios"><i class="bi bi-check-lg"></i></button>
          </form>
          <form method="post" action="<?= e(base_url('index.php?r=servicios/categoria_borrar')) ?>"
                onsubmit="return confirm('¿Eliminar esta categoría?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-cancelar" title="Eliminar"><i class="bi bi-trash"></i></button>
          </form>
          <span class="text-muted-warm" style="font-size:.75rem;min-width:80px;text-align:right"><?= (int)$r['usos'] ?> servicio(s)</span>
        </div>
      <?php endforeach; ?>
      <p class="text-muted-warm mt-2 mb-0" style="font-size:.75rem">No se pueden eliminar categorías que tengan servicios asociados.</p>
    </div>
  </div>
</div>
