<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=personal/comisiones')) ?>"><i class="bi bi-arrow-left"></i> Comisiones</a>
  <h1 class="mt-1">Nueva comisión</h1>
  <div class="sub">Dejá el servicio en “Todos” para que aplique a cualquier servicio del profesional.</div>
</div>
<div class="spg-panel" style="max-width:620px">
  <form method="post" action="<?= e(base_url('index.php?r=personal/comision_guardar')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Profesional *</label>
        <select class="form-select" name="id_usuario" required><option value="">—</option>
          <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id_usuario'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Servicio</label>
        <select class="form-select" name="id_servicio"><option value="">Todos los servicios</option>
          <?php foreach ($servicios as $s): ?><option value="<?= (int)$s['id_servicio'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Tipo *</label>
        <select class="form-select" name="tipo"><option value="PORCENTAJE">Porcentaje</option><option value="MONTO">Monto fijo</option></select></div>
      <div class="col-md-4"><label class="form-label">Valor *</label>
        <input type="number" min="0" step="0.01" class="form-control" name="valor" required></div>
      <div class="col-md-4"><label class="form-label">Vigente desde *</label>
        <input type="date" class="form-control" name="vigente_desde" required value="<?= e(date('Y-m-d')) ?>"></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=personal/comisiones')) ?>">Cancelar</a>
    </div>
  </form>
</div>
