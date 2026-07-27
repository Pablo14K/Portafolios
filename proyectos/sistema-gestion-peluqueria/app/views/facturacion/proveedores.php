<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/index')) ?>"><i class="bi bi-arrow-left"></i> Facturación</a>
  <h1 class="mt-1">Pagos a proveedores</h1>
  <div class="sub">Compras confirmadas con saldo pendiente. El pago se descuenta de la caja abierta.</div>
</div>

<div class="spg-panel mb-3">
  <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Cuentas por pagar</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Proveedor</th><th>Fecha</th><th>Vencimiento</th><th>Total</th><th>Pagado</th><th>Saldo</th><th class="text-end">Acción</th></tr></thead>
      <tbody>
      <?php if (!$cuentas): ?><tr><td colspan="7" class="text-center text-muted-warm py-4">No hay saldos pendientes con proveedores.</td></tr><?php endif; ?>
      <?php foreach ($cuentas as $c): ?>
        <tr>
          <td><?= e($c['proveedor']) ?><?php if ($c['nro_factura_proveedor']): ?><div class="text-muted-warm" style="font-size:.78rem">Fact. <?= e($c['nro_factura_proveedor']) ?></div><?php endif; ?></td>
          <td><?= e(fecha($c['fecha'], 'd/m/Y')) ?></td>
          <td><?= e(fecha($c['vencimiento'], 'd/m/Y')) ?></td>
          <td><?= money($c['total']) ?></td>
          <td><?= money($c['pagado']) ?></td>
          <td><?= (int)$c['vencida'] ? '<span class="badge-estado e-no">' . money($c['saldo']) . '</span>' : '<span class="badge-estado e-warn">' . money($c['saldo']) . '</span>' ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-oro" data-bs-toggle="modal" data-bs-target="#pp<?= (int)$c['id_compra'] ?>"><i class="bi bi-cash-coin"></i> Pagar</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="spg-panel">
  <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Pagos realizados</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Proveedor</th><th>Método</th><th>Monto</th><th>Referencia</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$pagos): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin pagos registrados.</td></tr><?php endif; ?>
      <?php foreach ($pagos as $p): ?>
        <tr><td><?= e(fecha($p['fecha'])) ?></td><td><?= e($p['proveedor']) ?></td>
          <td class="text-muted-warm"><?= e($p['metodo']) ?></td><td><?= money($p['monto']) ?></td>
          <td class="text-muted-warm"><?= e($p['referencia'] ?: '—') ?></td><td><?= estado_badge($p['estado']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($cuentas as $c): ?>
<div class="modal fade" id="pp<?= (int)$c['id_compra'] ?>" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= e(base_url('index.php?r=facturacion/pagar_proveedor')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id_compra" value="<?= (int)$c['id_compra'] ?>">
      <div class="modal-header"><h5 class="modal-title" style="font-size:1rem">Pagar a <?= e($c['proveedor']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted-warm mb-2" style="font-size:.85rem">Saldo pendiente: <strong style="color:var(--oro-oscuro)"><?= money($c['saldo']) ?></strong></p>
        <div class="mb-2"><label class="form-label">Método de pago</label>
          <select class="form-select" name="id_metodo_pago">
            <?php foreach ($metodos as $m): ?><option value="<?= (int)$m['id_metodo_pago'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Monto</label>
          <input type="number" min="1" step="1" class="form-control" name="monto" value="<?= (int)$c['saldo'] ?>" required></div>
        <div class="mb-1"><label class="form-label">Referencia (opcional)</label>
          <input class="form-control" name="referencia" placeholder="N° de transferencia, recibo, etc."></div>
      </div>
      <div class="modal-footer"><button class="btn btn-oro">Registrar pago</button></div>
    </form>
  </div></div>
</div>
<?php endforeach; ?>
