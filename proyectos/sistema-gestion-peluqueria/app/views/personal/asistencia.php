<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=personal/index')) ?>"><i class="bi bi-arrow-left"></i> Personal</a>
  <h1 class="mt-1">Asistencia</h1>
  <div class="sub">Registro manual de entrada y salida por turno (no requiere lector biométrico).</div>
</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="spg-panel" <?= $editar ? 'style="border-color:var(--oro)"' : '' ?>>
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">
        <?= $editar ? 'Editar asistencia' : 'Registrar asistencia' ?>
      </h2>
      <?php if ($editar): ?>
        <p class="text-muted-warm mb-2" style="font-size:.83rem">
          <?= e($editar['profesional']) ?> · <?= e(fecha($editar['fecha'], 'd/m/Y')) ?>
        </p>
      <?php endif; ?>
      <?php if (!$turnos && !$editar): ?>
        <p class="text-muted-warm mb-0" style="font-size:.85rem">Primero cargá turnos en <a class="link-oro" href="<?= e(base_url('index.php?r=personal/turnos')) ?>">Turnos</a>.</p>
      <?php else: ?>
      <form method="post" action="<?= e(base_url('index.php?r=personal/asistencia')) ?>">
        <?= csrf_field() ?>
        <?php if ($editar): ?>
          <input type="hidden" name="id_turno" value="<?= (int)$editar['id_turno'] ?>">
        <?php else: ?>
          <div class="mb-2"><label class="form-label">Turno *</label>
            <select class="form-select" name="id_turno" required><option value="">—</option>
              <?php foreach ($turnos as $t): ?>
                <option value="<?= (int)$t['id_turno'] ?>"><?= e(fecha($t['fecha'], 'd/m') . ' · ' . $t['profesional'] . ' · ' . substr($t['hora_inicio'], 0, 5) . '-' . substr($t['hora_fin'], 0, 5)) ?><?= $t['tiene_asistencia'] ? ' (ya registrado)' : '' ?></option>
              <?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Entrada</label>
            <input type="time" class="form-control" name="hora_entrada" value="<?= e($editar ? substr((string)$editar['hora_entrada'], 0, 5) : '') ?>"></div>
          <div class="col-6"><label class="form-label">Salida</label>
            <input type="time" class="form-control" name="hora_salida" value="<?= e($editar ? substr((string)$editar['hora_salida'], 0, 5) : '') ?>"></div>
        </div>
        <div class="mb-2 mt-2"><label class="form-label">Horas extras</label>
          <input type="number" min="0" step="0.5" class="form-control" name="horas_extras" value="<?= e($editar ? (float)$editar['horas_extras'] : 0) ?>"></div>
        <div class="mb-2"><label class="form-label">Motivo de ausencia</label>
          <input class="form-control" name="motivo_ausencia" placeholder="Si faltó, indicá el motivo" value="<?= e($editar['motivo_ausencia'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Observaciones</label>
          <input class="form-control" name="observaciones" value="<?= e($editar['observaciones'] ?? '') ?>"></div>
        <button class="btn btn-oro w-100"><i class="bi bi-check-lg"></i> <?= $editar ? 'Guardar cambios' : 'Registrar' ?></button>
        <?php if ($editar): ?>
          <a class="btn btn-outline-neutro w-100 mt-2" href="<?= e(base_url('index.php?r=personal/asistencia')) ?>">Cancelar</a>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="spg-panel">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Fecha</th><th>Profesional</th><th>Entrada</th><th>Salida</th><th>Hs. extras</th><th>Observaciones</th><th class="text-end">Acción</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted-warm py-4">Sin registros de asistencia.</td></tr><?php endif; ?>
          <?php foreach ($rows as $a): ?>
            <tr><td><?= e(fecha($a['fecha'], 'd/m/Y')) ?></td><td><?= e($a['profesional']) ?></td>
              <td><?= $a['hora_entrada'] ? e(substr($a['hora_entrada'], 0, 5)) : '—' ?></td>
              <td><?= $a['hora_salida'] ? e(substr($a['hora_salida'], 0, 5)) : '—' ?></td>
              <td><?= e(cant($a['horas_extras'])) ?></td>
              <td class="text-muted-warm"><?= e($a['observaciones'] ?: ($a['motivo_ausencia'] ? 'Ausente: ' . $a['motivo_ausencia'] : '—')) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-neutro" href="<?= e(base_url('index.php?r=personal/asistencia&editar=' . $a['id_turno'])) ?>" title="Editar"><i class="bi bi-pencil"></i></a>
              </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
