<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
  <h1 class="mt-1">Pagos al personal</h1>
  <div class="sub">Liquidación por comisión de los servicios realizados.</div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Registrar liquidación</h2>
      <form method="post" action="<?= e(base_url('index.php?r=facturacion/pagar_personal')) ?>">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Profesional *</label>
          <select class="form-select" name="id_usuario" required>
            <option value="">—</option>
            <?php foreach ($profs as $p): ?>
              <option value="<?= (int)$p['id_usuario'] ?>" <?= !$p['pendientes'] ? 'disabled' : '' ?>>
                <?= e($p['nombre'] . ' ' . $p['apellido']) ?><?= $p['pendientes'] ? ' (' . (int)$p['pendientes'] . ' pendiente/s)' : ' (sin pendientes)' ?>
              </option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label">Período</label>
          <input class="form-control" name="periodo" value="<?= e(date('m/Y')) ?>" placeholder="07/2026"></div>
        <button class="btn btn-oro w-100"><i class="bi bi-cash-coin"></i> Liquidar</button>
      </form>
      <p class="text-muted-warm mt-3 mb-0" style="font-size:.78rem">
        Se liquidan los servicios realizados que todavía no fueron pagados, según la comisión vigente del profesional.
        Los servicios se registran en <strong>Citas → Registrar atención</strong>.
      </p>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="spg-panel">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Fecha</th><th>Beneficiario</th><th>Período</th><th>Servicios</th><th>Monto</th><th>Estado</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin pagos registrados.</td></tr><?php endif; ?>
          <?php foreach ($rows as $p): ?>
            <tr><td><?= e(fecha($p['fecha'])) ?></td><td><?= e($p['beneficiario']) ?></td>
              <td class="text-muted-warm"><?= e($p['periodo']) ?></td><td><?= (int)$p['servicios'] ?></td>
              <td><?= money($p['monto']) ?></td><td><?= estado_badge($p['estado']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
