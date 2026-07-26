<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=citas/agenda')) ?>"><i class="bi bi-arrow-left"></i> Agenda</a>
  <h1 class="mt-1">Nueva cita</h1>
  <div class="sub">La disponibilidad del profesional se valida automáticamente al agendar.</div>
</div>
<div class="spg-panel" style="max-width:720px">
  <form method="post" action="<?= e(base_url('index.php?r=citas/guardar')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Cliente *</label>
        <select class="form-select" name="id_cliente" required><option value="">—</option>
          <?php foreach ($clientes as $c): ?><option value="<?= (int)$c['id_cliente'] ?>"><?= e($c['apellido'] . ', ' . $c['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Profesional *</label>
        <select class="form-select" name="id_usuario" required><option value="">—</option>
          <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id_usuario'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Fecha y hora *</label>
        <input type="datetime-local" class="form-control" name="fecha_hora" required value="<?= e(date('Y-m-d\TH:i')) ?>"></div>
      <div class="col-12"><label class="form-label">Servicios * <span class="text-muted-warm" style="font-weight:400">(la duración se suma automáticamente)</span></label>
        <div class="row g-2">
        <?php foreach ($servicios as $s): ?>
          <div class="col-md-6">
            <label class="d-flex align-items-center gap-2 p-2" style="border:1px solid var(--gris-calido);border-radius:8px;cursor:pointer">
              <input class="form-check-input mt-0" type="checkbox" name="servicios[]" value="<?= (int)$s['id_servicio'] ?>">
              <span><?= e($s['nombre']) ?> <span class="text-muted-warm" style="font-size:.8rem">· <?= (int)$s['duracion_min'] ?>min · <?= money($s['precio']) ?></span></span>
            </label>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <div class="col-12"><label class="form-label">Observaciones</label>
        <textarea class="form-control" name="observaciones" rows="2"></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-calendar-check"></i> Agendar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=citas/agenda')) ?>">Cancelar</a>
    </div>
  </form>
</div>
