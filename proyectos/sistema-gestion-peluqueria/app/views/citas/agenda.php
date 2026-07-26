<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=citas/index')) ?>"><i class="bi bi-arrow-left"></i> Citas</a>
    <h1 class="mt-1">Agenda</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=citas/form')) ?>"><i class="bi bi-plus-lg"></i> Nueva cita</a>
</div>

<div class="spg-panel">
  <form class="mb-3 d-flex align-items-center gap-2" method="get" action="<?= e(base_url('index.php')) ?>">
    <input type="hidden" name="r" value="citas/agenda">
    <label class="form-label mb-0">Día</label>
    <input type="date" name="dia" class="form-control" style="max-width:190px" value="<?= e($dia) ?>" onchange="this.form.submit()">
    <span class="text-muted-warm ms-2"><?= count($rows) ?> cita(s)</span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Hora</th><th>Cliente</th><th>Profesional</th><th>Servicios</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">No hay citas este día.</td></tr><?php endif; ?>
      <?php foreach ($rows as $c): $fin = ($c['estado'] === 'Cancelada' || $c['estado'] === 'Atendida' || $c['estado'] === 'Ausente'); ?>
        <tr>
          <td><strong><?= e(fecha($c['fecha_hora'], 'H:i')) ?></strong><div class="text-muted-warm" style="font-size:.78rem"><?= (int)$c['duracion_min'] ?> min</div></td>
          <td><?= e($c['cliente']) ?><div class="text-muted-warm" style="font-size:.78rem"><?= e($c['telefono'] ?: '') ?></div></td>
          <td><?= e($c['profesional']) ?></td>
          <td class="text-muted-warm"><?= e($c['servicios'] ?: '—') ?></td>
          <td><?= estado_badge($c['estado']) ?></td>
          <td class="text-end" style="white-space:nowrap">
            <?php if (!$fin): ?>
              <form method="post" action="<?= e(base_url('index.php?r=citas/estado')) ?>" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>"><input type="hidden" name="dia" value="<?= e($dia) ?>">
                <button name="id_estado_cita" value="5" class="btn btn-sm btn-outline-neutro" title="En proceso"><i class="bi bi-play"></i></button>
                <button name="id_estado_cita" value="4" class="btn btn-sm btn-outline-neutro" title="Atendida"><i class="bi bi-check2"></i></button>
                <button name="id_estado_cita" value="6" class="btn btn-sm btn-outline-neutro" title="Ausente"><i class="bi bi-person-x"></i></button>
              </form>
              <button class="btn btn-sm btn-outline-neutro" title="Reprogramar" data-bs-toggle="modal" data-bs-target="#rep<?= (int)$c['id_cita'] ?>"><i class="bi bi-clock"></i></button>
              <form method="post" action="<?= e(base_url('index.php?r=citas/cancelar')) ?>" class="d-inline" onsubmit="return confirm('¿Cancelar esta cita?')">
                <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>"><input type="hidden" name="dia" value="<?= e($dia) ?>">
                <button class="btn btn-sm btn-cancelar" title="Cancelar"><i class="bi bi-x-lg"></i></button>
              </form>
            <?php else: ?><span class="text-muted-warm" style="font-size:.8rem">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($rows as $c): if (in_array($c['estado'], ['Cancelada','Atendida','Ausente'], true)) continue; ?>
<div class="modal fade" id="rep<?= (int)$c['id_cita'] ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= e(base_url('index.php?r=citas/reprogramar')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>">
      <div class="modal-header"><h5 class="modal-title" style="font-size:1rem">Reprogramar cita de <?= e($c['cliente']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label">Nueva fecha y hora</label>
        <input type="datetime-local" name="nueva_fecha" class="form-control" required value="<?= e(date('Y-m-d\TH:i', strtotime($c['fecha_hora']))) ?>">
      </div>
      <div class="modal-footer"><button class="btn btn-oro">Reprogramar</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
