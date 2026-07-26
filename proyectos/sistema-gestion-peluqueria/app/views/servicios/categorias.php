<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=servicios/index')) ?>"><i class="bi bi-arrow-left"></i> Servicios</a>
  <h1 class="mt-1">Categorías de servicio</h1>
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
      <table class="table align-middle mb-0">
        <thead><tr><th>Categoría</th><th>Servicios</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['nombre']) ?></td><td class="text-muted-warm"><?= (int)$r['usos'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
