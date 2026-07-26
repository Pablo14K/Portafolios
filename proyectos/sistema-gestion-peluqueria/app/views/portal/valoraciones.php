<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=portal/index')) ?>"><i class="bi bi-arrow-left"></i> Mi portal</a>
  <h1 class="mt-1">Valoraciones</h1>
  <div class="sub">Calificá los servicios que ya recibiste.</div>
</div>

<div class="spg-panel mb-3">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Pendientes de calificar</h2>
  <?php if (!$pendientes): ?><p class="text-muted-warm mb-0">No tenés servicios pendientes de calificar.</p><?php endif; ?>
  <?php foreach ($pendientes as $c): ?>
    <form method="post" action="<?= e(base_url('index.php?r=portal/calificar')) ?>" class="py-2" style="border-bottom:1px solid var(--gris-calido)">
      <?= csrf_field() ?><input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <strong><?= e($c['servicios'] ?: 'Servicio') ?></strong>
          <div class="text-muted-warm" style="font-size:.83rem"><?= e(fecha($c['fecha_hora'])) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <select class="form-select form-select-sm" name="puntaje" style="width:auto" required>
            <option value="">Puntaje</option>
            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
          </select>
          <input class="form-control form-control-sm" name="comentario" placeholder="Comentario (opcional)" style="max-width:220px">
          <button class="btn btn-oro btn-sm">Enviar</button>
        </div>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<div class="spg-panel">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Tus valoraciones</h2>
  <?php if (!$hechas): ?><p class="text-muted-warm mb-0">Todavía no dejaste valoraciones.</p><?php endif; ?>
  <?php foreach ($hechas as $h): ?>
    <div class="py-2" style="border-bottom:1px solid var(--gris-calido)">
      <span style="color:var(--oro-oscuro)"><?= str_repeat('★', (int)$h['puntaje']) . str_repeat('☆', 5 - (int)$h['puntaje']) ?></span>
      <span class="text-muted-warm ms-2" style="font-size:.83rem"><?= e(fecha($h['fecha'])) ?></span>
      <?php if ($h['comentario']): ?><div style="font-size:.9rem"><?= e($h['comentario']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
