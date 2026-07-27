<?php
// Bloque reutilizable: lista editable de categorías
function cat_bloque(string $titulo, string $tipo, array $items, string $campoId): void
{
    ?>
    <div class="spg-panel h-100">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;"><?= e($titulo) ?></h2>
      <form method="post" action="<?= e(base_url('index.php?r=configuracion/catalogos')) ?>" class="mb-3">
        <?= csrf_field() ?><input type="hidden" name="tipo" value="<?= e($tipo) ?>">
        <div class="input-group">
          <input class="form-control" name="nombre" placeholder="Nueva categoría" required>
          <button class="btn btn-oro" title="Agregar"><i class="bi bi-plus-lg"></i></button>
        </div>
      </form>

      <?php foreach ($items as $c): $id = (int)$c[$campoId]; ?>
        <div class="d-flex align-items-center gap-1 mb-2">
          <form method="post" action="<?= e(base_url('index.php?r=configuracion/catalogo_editar')) ?>" class="d-flex gap-1 flex-grow-1">
            <?= csrf_field() ?>
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input class="form-control form-control-sm" name="nombre" value="<?= e($c['nombre']) ?>" required>
            <button class="btn btn-sm btn-outline-neutro" title="Guardar cambios"><i class="bi bi-check-lg"></i></button>
          </form>
          <form method="post" action="<?= e(base_url('index.php?r=configuracion/catalogo_borrar')) ?>"
                onsubmit="return confirm('¿Eliminar la categoría <?= e(addslashes($c['nombre'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-cancelar" title="<?= (int)$c['usos'] ? 'En uso por ' . (int)$c['usos'] : 'Eliminar' ?>"><i class="bi bi-trash"></i></button>
          </form>
          <span class="text-muted-warm" style="font-size:.72rem;min-width:26px;text-align:right"><?= (int)$c['usos'] ?></span>
        </div>
      <?php endforeach; ?>
      <p class="text-muted-warm mb-0" style="font-size:.73rem">El número indica cuántos registros usan la categoría. No se pueden eliminar las que están en uso.</p>
    </div>
    <?php
}
?>
<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
  <h1 class="mt-1">Catálogos</h1>
  <div class="sub">Podés agregar, renombrar o eliminar categorías.</div>
</div>

<div class="row g-3">
  <div class="col-md-4"><?php cat_bloque('Categorías de producto', 'producto', $cat_prod, 'id_categoria'); ?></div>
  <div class="col-md-4"><?php cat_bloque('Categorías de servicio', 'servicio', $cat_serv, 'id_categoria_servicio'); ?></div>
  <div class="col-md-4">
    <div class="spg-panel h-100">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;">Niveles de fidelización</h2>
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Nivel</th><th>Visitas</th><th>Descuento</th></tr></thead>
        <tbody>
        <?php foreach ($niveles as $n): ?>
          <tr><td><?= e($n['nombre']) ?></td><td><?= (int)$n['visitas_minimas'] ?></td><td class="text-muted-warm"><?= e($n['descuento'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted-warm mt-2 mb-0" style="font-size:.73rem">Los niveles se asignan solos según las visitas del cliente. Los porcentajes se editan en Servicios → Descuentos.</p>
    </div>
  </div>
</div>
