<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=citas/index')) ?>"><i class="bi bi-arrow-left"></i> Citas</a>
  <h1 class="mt-1">Excepciones de agenda</h1>
  <div class="sub">Feriados, vacaciones, licencias y bloqueos. Dejá el profesional vacío para que aplique a todo el salón.</div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Registrar excepción</h2>
      <form method="post" action="<?= e(base_url('index.php?r=citas/ausencias')) ?>">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Profesional</label>
          <select class="form-select" name="id_usuario"><option value="">Todo el salón</option>
            <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id_usuario'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Tipo *</label>
          <select class="form-select" name="id_tipo_ausencia" required><option value="">—</option>
            <?php foreach ($tipos as $t): ?><option value="<?= (int)$t['id_tipo_ausencia'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Desde *</label><input type="datetime-local" class="form-control" name="fecha_inicio" required></div>
        <div class="mb-2"><label class="form-label">Hasta *</label><input type="datetime-local" class="form-control" name="fecha_fin" required></div>
        <div class="mb-3"><label class="form-label">Motivo</label><input class="form-control" name="motivo"></div>
        <button class="btn btn-oro w-100"><i class="bi bi-plus-lg"></i> Registrar</button>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="spg-panel">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Quién</th><th>Tipo</th><th>Desde</th><th>Hasta</th><th>Motivo</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin excepciones.</td></tr><?php endif; ?>
          <?php foreach ($rows as $a): ?>
            <tr><td><?= e($a['quien']) ?></td><td><span class="badge-estado e-prog"><?= e($a['tipo']) ?></span></td>
              <td><?= e(fecha($a['fecha_inicio'])) ?></td><td><?= e(fecha($a['fecha_fin'])) ?></td>
              <td class="text-muted-warm"><?= e($a['motivo'] ?: '—') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
