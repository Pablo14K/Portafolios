<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=clientes/index')) ?>"><i class="bi bi-arrow-left"></i> Clientes</a>
  <h1 class="mt-1">Fidelización</h1>
  <div class="sub">Nivel, visitas y puntos calculados automáticamente por la base de datos.</div>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Cliente</th><th>Teléfono</th><th>Visitas</th><th>Puntos</th><th>Nivel</th><th>Descuento</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted-warm py-4">Sin datos.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['cliente']) ?></td>
          <td class="text-muted-warm"><?= e($r['telefono'] ?: '—') ?></td>
          <td><?= (int)$r['visitas'] ?></td>
          <td><?= (int)$r['puntos'] ?></td>
          <td><span class="badge-estado e-prog"><?= e($r['nivel'] ?: 'Bronce') ?></span></td>
          <td class="text-muted-warm"><?= e($r['descuento_del_nivel'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
