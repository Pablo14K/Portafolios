<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
  <h1 class="mt-1">Catálogos</h1>
</div>
<div class="row g-3">
  <div class="col-md-4">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;">Categorías de producto</h2>
      <form method="post" action="<?= e(base_url('index.php?r=configuracion/catalogos')) ?>" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="tipo" value="producto">
        <div class="input-group"><input class="form-control" name="nombre" placeholder="Nueva categoría" required><button class="btn btn-oro"><i class="bi bi-plus-lg"></i></button></div>
      </form>
      <ul class="list-group list-group-flush">
        <?php foreach ($cat_prod as $c): ?><li class="list-group-item px-0"><?= e($c['nombre']) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-md-4">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;">Categorías de servicio</h2>
      <form method="post" action="<?= e(base_url('index.php?r=configuracion/catalogos')) ?>" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="tipo" value="servicio">
        <div class="input-group"><input class="form-control" name="nombre" placeholder="Nueva categoría" required><button class="btn btn-oro"><i class="bi bi-plus-lg"></i></button></div>
      </form>
      <ul class="list-group list-group-flush">
        <?php foreach ($cat_serv as $c): ?><li class="list-group-item px-0"><?= e($c['nombre']) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-md-4">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;">Niveles de fidelización</h2>
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Nivel</th><th>Visitas</th><th>Descuento</th></tr></thead>
        <tbody>
        <?php foreach ($niveles as $n): ?>
          <tr><td><?= e($n['nombre']) ?></td><td><?= (int)$n['visitas_minimas'] ?></td><td class="text-muted-warm"><?= e($n['descuento'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
