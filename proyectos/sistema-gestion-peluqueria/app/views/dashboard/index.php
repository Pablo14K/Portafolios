<?php $u = usuario_actual(); ?>
<div class="spg-page-head">
  <h1>Panel principal</h1>
  <div class="sub">Hola, <?= e($u['nombre']) ?>. Elegí un módulo para entrar a sus submódulos.</div>
</div>

<div class="spg-metrics mt-3">
  <div class="spg-metric"><div class="lbl">Citas de hoy</div><div class="val"><?= (int)$m['citas_hoy'] ?></div></div>
  <div class="spg-metric"><div class="lbl">Clientes activos</div><div class="val"><?= (int)$m['clientes'] ?></div></div>
  <div class="spg-metric"><div class="lbl">Productos bajo stock</div><div class="val"><?= (int)$m['bajo_stock'] ?></div></div>
  <div class="spg-metric"><div class="lbl">Ingresos de hoy</div><div class="val oro"><?= money($m['ingresos_hoy']) ?></div></div>
</div>

<div class="spg-cards">
  <?php foreach (menu_modulos() as $mod): ?>
    <?php if (!rol_puede((int)$u['rol'], $mod['mod'])) continue; ?>
    <a class="spg-card <?= !empty($mod['dark']) ? 'dark' : '' ?>" href="<?= e(base_url('index.php?r=' . $mod['r'])) ?>">
      <div class="ic"><i class="bi bi-<?= e($mod['ic']) ?>"></i></div>
      <h3><?= e($mod['titulo']) ?></h3>
      <p><?= e($mod['sub']) ?></p>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($proximas): ?>
<div class="spg-panel mt-2">
  <h2 style="font-size:1rem;font-weight:500;margin-bottom:.8rem;">Próximas citas</h2>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Profesional</th><th>Servicios</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($proximas as $c): ?>
        <tr>
          <td><?= e(fecha($c['fecha_hora'])) ?></td>
          <td><?= e($c['cliente']) ?></td>
          <td><?= e($c['profesional']) ?></td>
          <td class="text-muted-warm"><?= e($c['servicios'] ?: '—') ?></td>
          <td><?= estado_badge($c['estado']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
