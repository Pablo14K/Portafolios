<div class="spg-page-head d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
    <h1 class="mt-1">Facturas</h1>
  </div>
  <a class="btn btn-oro" href="<?= e(base_url('index.php?r=facturacion/emitir')) ?>"><i class="bi bi-plus-lg"></i> Emitir factura</a>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Nº</th><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Total</th><th>Cobrado</th><th>Saldo</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted-warm py-4">Sin facturas emitidas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $f): $cobrable = ($f['estado'] === 'Emitida' && (float)$f['saldo'] > 0 && (int)$f['signo'] === 1); ?>
        <tr>
          <td><?= e($f['nro_comprobante']) ?></td>
          <td><?= e(fecha($f['fecha_emision'])) ?></td>
          <td><?= e($f['cliente']) ?></td>
          <td class="text-muted-warm"><?= e($f['tipo_comprobante']) ?></td>
          <td><?= money($f['total']) ?></td>
          <td><?= money($f['cobrado']) ?></td>
          <td><?= (float)$f['saldo'] > 0 ? '<span class="badge-estado e-warn">' . money($f['saldo']) . '</span>' : money(0) ?></td>
          <td><?= estado_badge($f['estado']) ?></td>
          <td class="text-end">
            <?php if ($cobrable): ?>
              <button class="btn btn-sm btn-oro" data-bs-toggle="modal" data-bs-target="#cob<?= (int)$f['id_factura'] ?>"><i class="bi bi-cash-coin"></i> Cobrar</button>
            <?php else: ?><span class="text-muted-warm" style="font-size:.8rem">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($rows as $f): if (!($f['estado'] === 'Emitida' && (float)$f['saldo'] > 0 && (int)$f['signo'] === 1)) continue; ?>
<div class="modal fade" id="cob<?= (int)$f['id_factura'] ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= e(base_url('index.php?r=facturacion/cobrar')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id_factura" value="<?= (int)$f['id_factura'] ?>">
      <div class="modal-header"><h5 class="modal-title" style="font-size:1rem">Cobrar <?= e($f['nro_comprobante']) ?> · <?= e($f['cliente']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted-warm mb-2" style="font-size:.85rem">Saldo pendiente: <strong style="color:var(--oro-oscuro)"><?= money($f['saldo']) ?></strong></p>
        <div class="mb-2"><label class="form-label">Método de pago</label>
          <select class="form-select" name="id_metodo_pago">
            <?php foreach ($metodos as $m): ?><option value="<?= (int)$m['id_metodo_pago'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Monto</label>
          <input type="number" min="1" step="1" class="form-control" name="monto" value="<?= (int)$f['saldo'] ?>" required></div>
        <div class="mb-1"><label class="form-label">Referencia (opcional)</label>
          <input class="form-control" name="referencia" placeholder="N° de transacción, etc."></div>
      </div>
      <div class="modal-footer"><button class="btn btn-oro">Registrar cobro</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
