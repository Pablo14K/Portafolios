<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=clientes/index')) ?>"><i class="bi bi-arrow-left"></i> Clientes</a>
  <h1 class="mt-1">Valoraciones</h1>
  <div class="sub">Promedio general: <strong class="text-oro" style="color:var(--oro-oscuro)"><?= $prom ? e($prom) . ' ★' : 'sin datos' ?></strong></div>
</div>
<div class="spg-panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Profesional</th><th>Puntaje</th><th>Comentario</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted-warm py-4">Sin valoraciones.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(fecha($r['fecha'])) ?></td>
          <td><?= e($r['cliente']) ?></td>
          <td><?= e($r['profesional']) ?></td>
          <td style="color:var(--oro-oscuro)"><?= str_repeat('★', (int)$r['puntaje']) . str_repeat('☆', 5 - (int)$r['puntaje']) ?></td>
          <td class="text-muted-warm"><?= e($r['comentario'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
