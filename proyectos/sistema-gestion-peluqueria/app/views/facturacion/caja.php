<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
  <h1 class="mt-1">Caja</h1>
</div>

<div class="row g-3 mb-1">
  <div class="col-md-5">
    <div class="spg-panel">
      <?php if ($abierta): ?>
        <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.5rem;">Caja abierta</h2>
        <div class="spg-metric mb-2" style="border:none;padding:.4rem 0"><div class="lbl">Saldo actual</div><div class="val oro"><?= money($abierta['saldo']) ?></div></div>
        <p class="text-muted-warm" style="font-size:.85rem">Responsable: <?= e($abierta['responsable']) ?><br>Apertura: <?= e(fecha($abierta['fecha_apertura'])) ?></p>
        <form method="post" action="<?= e(base_url('index.php?r=facturacion/cerrar_caja')) ?>" onsubmit="return confirm('¿Cerrar la caja?')">
          <?= csrf_field() ?><input type="hidden" name="id_caja" value="<?= (int)$abierta['id_caja'] ?>">
          <button class="btn btn-outline-neutro w-100"><i class="bi bi-lock"></i> Cerrar caja</button>
        </form>
      <?php else: ?>
        <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Abrir caja</h2>
        <form method="post" action="<?= e(base_url('index.php?r=facturacion/abrir_caja')) ?>">
          <?= csrf_field() ?>
          <label class="form-label">Monto inicial (Gs.)</label>
          <input type="number" min="0" step="1" class="form-control mb-3" name="monto_inicial" value="0">
          <button class="btn btn-oro w-100"><i class="bi bi-unlock"></i> Abrir caja</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-7">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Historial de cajas</h2>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Apertura</th><th>Responsable</th><th>Inicial</th><th>Cobros</th><th>Saldo</th><th>Estado</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin registros.</td></tr><?php endif; ?>
          <?php foreach ($rows as $c): ?>
            <tr><td><?= e(fecha($c['fecha_apertura'])) ?></td><td><?= e($c['responsable']) ?></td>
              <td><?= money($c['monto_inicial']) ?></td><td><?= money($c['cobros']) ?></td>
              <td><?= money($c['saldo']) ?></td><td><?= estado_badge($c['estado']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
