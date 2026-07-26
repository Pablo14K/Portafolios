<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=portal/index')) ?>"><i class="bi bi-arrow-left"></i> Mi portal</a>
  <h1 class="mt-1">Reservar cita</h1>
  <div class="sub">Elegí el servicio, el profesional y el horario.</div>
</div>
<div class="spg-panel" style="max-width:640px">
  <form method="post" action="<?= e(base_url('index.php?r=portal/guardar_reserva')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Profesional *</label>
        <select class="form-select" name="id_usuario" required><option value="">—</option>
          <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id_usuario'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Fecha y hora *</label>
        <input type="datetime-local" class="form-control" name="fecha_hora" required value="<?= e(date('Y-m-d\TH:i')) ?>"></div>
      <div class="col-12"><label class="form-label">Servicios *</label>
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
      <div class="col-12"><label class="form-label">Comentario (opcional)</label>
        <textarea class="form-control" name="observaciones" rows="2"></textarea></div>
    </div>
    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-calendar-check"></i> Reservar</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=portal/index')) ?>">Cancelar</a>
    </div>
  </form>
</div>
