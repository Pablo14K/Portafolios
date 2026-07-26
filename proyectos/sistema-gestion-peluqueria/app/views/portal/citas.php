<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=portal/index')) ?>"><i class="bi bi-arrow-left"></i> Mi portal</a>
    <h1 class="mt-1">Mis citas</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=portal/reservar')) ?>"><i class="bi bi-plus-lg"></i> Reservar</a>
</div>

<div class="spg-panel mb-3">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Próximas</h2>
  <?php if (!$prox): ?><p class="text-muted-warm mb-0">No tenés citas próximas.</p><?php endif; ?>
  <?php foreach ($prox as $c): ?>
    <div class="d-flex align-items-center justify-content-between py-2 flex-wrap gap-2" style="border-bottom:1px solid var(--gris-calido)">
      <div>
        <strong><?= e(fecha($c['fecha_hora'])) ?></strong> · <?= e($c['servicios'] ?: 'Cita') ?>
        <div class="text-muted-warm" style="font-size:.83rem">con <?= e($c['profesional']) ?> · <?= estado_badge($c['estado']) ?></div>
      </div>
      <?php if (!in_array($c['estado'], ['Cancelada','Atendida','Ausente'], true)): ?>
        <form method="post" action="<?= e(base_url('index.php?r=portal/cancelar')) ?>" onsubmit="return confirm('¿Cancelar tu cita?')">
          <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>">
          <button class="btn btn-cancelar btn-sm"><i class="bi bi-x-lg"></i> Cancelar</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="spg-panel">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Historial</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Servicios</th><th>Profesional</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$pasadas): ?><tr><td colspan="4" class="text-center text-muted-warm py-3">Sin citas anteriores.</td></tr><?php endif; ?>
      <?php foreach ($pasadas as $c): ?>
        <tr><td><?= e(fecha($c['fecha_hora'])) ?></td><td><?= e($c['servicios'] ?: '—') ?></td>
          <td><?= e($c['profesional']) ?></td><td><?= estado_badge($c['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
