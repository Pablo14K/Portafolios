<?php $u = usuario_actual(); $nombre = explode(' ', $u['nombre'])[0]; ?>
<div class="spg-page-head">
  <h1>Hola, <?= e($nombre) ?></h1>
  <div class="sub">Tu próxima cita y todo lo tuyo, en un solo lugar.</div>
</div>

<?php if ($proxima): ?>
<div class="spg-panel mb-3" style="border-color:var(--oro)">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3">
      <div style="width:52px;height:52px;border-radius:10px;background:var(--blanco-hueso);display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1">
        <span style="font-size:1.1rem;font-weight:500;color:var(--oro-oscuro)"><?= e(fecha($proxima['fecha_hora'], 'd')) ?></span>
        <span style="font-size:.65rem;color:var(--gris-oscuro);text-transform:uppercase"><?= e(fecha($proxima['fecha_hora'], 'M')) ?></span>
      </div>
      <div>
        <div style="font-weight:500"><?= e($proxima['servicios'] ?: 'Cita') ?></div>
        <div class="text-muted-warm" style="font-size:.85rem"><?= e(fecha($proxima['fecha_hora'], 'H:i')) ?> · con <?= e($proxima['profesional']) ?></div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <form method="post" action="<?= e(base_url('index.php?r=portal/cancelar')) ?>" onsubmit="return confirm('¿Cancelar tu cita?')">
        <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$proxima['id_cita'] ?>">
        <button class="btn btn-cancelar btn-sm"><i class="bi bi-x-lg"></i> Cancelar</button>
      </form>
      <a class="btn btn-oro btn-sm" href="<?= e(base_url('index.php?r=portal/reservar')) ?>">Reservar nueva</a>
    </div>
  </div>
</div>
<?php else: ?>
<div class="spg-panel mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <span class="text-muted-warm">No tenés citas próximas.</span>
  <a class="btn btn-oro btn-sm" href="<?= e(base_url('index.php?r=portal/reservar')) ?>">Reservar una cita</a>
</div>
<?php endif; ?>

<div class="spg-cards">
  <a class="spg-card" href="<?= e(base_url('index.php?r=portal/reservar')) ?>">
    <div class="ic"><i class="bi bi-calendar-plus"></i></div><h3>Reservar cita</h3><p>Elegí servicio, día y horario disponible.</p></a>
  <a class="spg-card" href="<?= e(base_url('index.php?r=portal/citas')) ?>">
    <div class="ic"><i class="bi bi-clock-history"></i></div><h3>Mis citas</h3><p>Próximas y pasadas · Reprogramar o cancelar.</p></a>
  <a class="spg-card" href="<?= e(base_url('index.php?r=portal/promociones')) ?>">
    <div class="ic"><i class="bi bi-percent"></i></div><h3>Promociones</h3><p>Descuentos y beneficios de tu nivel.</p></a>
  <a class="spg-card" href="<?= e(base_url('index.php?r=portal/valoraciones')) ?>">
    <div class="ic"><i class="bi bi-star"></i></div><h3>Valoraciones</h3><p>Calificá los servicios que recibiste.</p></a>
</div>
