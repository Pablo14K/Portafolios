<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
  <h1 class="mt-1">Auditoría</h1>
  <div class="sub">Últimas acciones registradas por los triggers de la base de datos.</div>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Tabla</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin registros de auditoría.</td></tr><?php endif; ?>
      <?php foreach ($rows as $a): ?>
        <tr><td><?= e(fecha($a['fecha_hora'])) ?></td><td><?= e($a['usuario']) ?></td>
          <td><span class="badge-estado e-prog"><?= e($a['accion']) ?></span></td>
          <td class="text-muted-warm"><?= e($a['modulo']) ?></td><td class="text-muted-warm"><?= e($a['tabla_afectada']) ?></td>
          <td class="text-muted-warm"><?= e($a['detalle'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
