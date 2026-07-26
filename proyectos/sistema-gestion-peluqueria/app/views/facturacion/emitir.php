<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=facturacion/facturas')) ?>"><i class="bi bi-arrow-left"></i> Facturas</a>
  <h1 class="mt-1">Emitir factura</h1>
  <div class="sub">Citas atendidas pendientes de facturar. El comprobante se numera con el timbrado vigente.</div>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Servicios</th><th>Total</th><th class="text-end">Acción</th></tr></thead>
      <tbody>
      <?php if (!$citas): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">No hay citas atendidas pendientes de facturar.</td></tr><?php endif; ?>
      <?php foreach ($citas as $c): ?>
        <tr>
          <td><?= e(fecha($c['fecha_hora'])) ?></td>
          <td><?= e($c['cliente']) ?></td>
          <td class="text-muted-warm"><?= e($c['servicios'] ?: '—') ?></td>
          <td><?= money($c['total']) ?></td>
          <td class="text-end">
            <form method="post" action="<?= e(base_url('index.php?r=facturacion/emitir_guardar')) ?>" onsubmit="return confirm('¿Emitir la factura de esta cita?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id_cita" value="<?= (int)$c['id_cita'] ?>">
              <input type="hidden" name="id_cliente" value="<?= (int)$c['id_cliente'] ?>">
              <button class="btn btn-sm btn-oro"><i class="bi bi-receipt"></i> Emitir</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
