<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=clientes/lista')) ?>"><i class="bi bi-arrow-left"></i> Clientes</a>
  <h1 class="mt-1"><?= e($c['nombre'] . ' ' . $c['apellido']) ?></h1>
  <div class="sub"><?= e($c['telefono'] ?: 'Sin teléfono') ?> · <?= e($c['email'] ?: 'Sin email') ?></div>
</div>

<?php if ($fid): ?>
<div class="spg-metrics">
  <div class="spg-metric"><div class="lbl">Nivel</div><div class="val oro"><?= e($fid['nivel'] ?: 'Bronce') ?></div></div>
  <div class="spg-metric"><div class="lbl">Visitas</div><div class="val"><?= (int)$fid['visitas'] ?></div></div>
  <div class="spg-metric"><div class="lbl">Puntos</div><div class="val"><?= (int)$fid['puntos'] ?></div></div>
  <div class="spg-metric"><div class="lbl">Descuento</div><div class="val" style="font-size:1rem"><?= e($fid['descuento_del_nivel'] ?: '—') ?></div></div>
</div>
<?php endif; ?>

<div class="spg-panel mt-2">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Historial de servicios</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Servicio</th><th>Profesional</th><th>Comprobante</th><th>Puntaje</th></tr></thead>
      <tbody>
      <?php if (!$hist): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin servicios registrados.</td></tr><?php endif; ?>
      <?php foreach ($hist as $h): ?>
        <tr>
          <td><?= e(fecha($h['fecha_hora'])) ?></td>
          <td><?= e($h['servicio']) ?></td>
          <td><?= e($h['profesional']) ?></td>
          <td class="text-muted-warm"><?= e($h['nro_comprobante'] ?: '—') ?></td>
          <td><?= $h['puntaje'] ? str_repeat('★', (int)$h['puntaje']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
