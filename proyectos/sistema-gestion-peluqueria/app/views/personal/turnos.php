<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=personal/index')) ?>"><i class="bi bi-arrow-left"></i> Personal</a>
  <h1 class="mt-1">Turnos laborales</h1>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Registrar turno</h2>
      <form method="post" action="<?= e(base_url('index.php?r=personal/turnos')) ?>">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Profesional *</label>
          <select class="form-select" name="id_usuario" required><option value="">—</option>
            <?php foreach ($profs as $p): ?><option value="<?= (int)$p['id_usuario'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Fecha *</label><input type="date" class="form-control" name="fecha" required></div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Inicio *</label><input type="time" class="form-control" name="hora_inicio" required></div>
          <div class="col-6"><label class="form-label">Fin *</label><input type="time" class="form-control" name="hora_fin" required></div>
        </div>
        <button class="btn btn-oro w-100 mt-3"><i class="bi bi-plus-lg"></i> Registrar</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="spg-panel">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Fecha</th><th>Profesional</th><th>Inicio</th><th>Fin</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted-warm py-4">Sin turnos.</td></tr><?php endif; ?>
          <?php foreach ($rows as $t): ?>
            <tr><td><?= e(fecha($t['fecha'], 'd/m/Y')) ?></td><td><?= e($t['profesional']) ?></td>
              <td><?= e(substr($t['hora_inicio'], 0, 5)) ?></td><td><?= e(substr($t['hora_fin'], 0, 5)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
