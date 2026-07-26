<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
  <h1 class="mt-1">Datos del local</h1>
  <div class="sub">Estos datos identifican al emisor en los comprobantes.</div>
</div>
<div class="spg-panel" style="max-width:680px">
  <form method="post" action="<?= e(base_url('index.php?r=configuracion/local')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label">Nombre / Razón social *</label>
        <input class="form-control" name="nombre" required value="<?= e($s['nombre'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">RUC</label>
        <input class="form-control" name="ruc" value="<?= e($s['ruc'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Teléfono</label>
        <input class="form-control" name="telefono" value="<?= e($s['telefono'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Ciudad</label>
        <input class="form-control" name="ciudad" value="<?= e($s['ciudad'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Dirección</label>
        <input class="form-control" name="direccion" value="<?= e($s['direccion'] ?? '') ?>"></div>
    </div>
    <div class="mt-4"><button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button></div>
  </form>
</div>
