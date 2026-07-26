<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=portal/index')) ?>"><i class="bi bi-arrow-left"></i> Mi portal</a>
  <h1 class="mt-1">Promociones</h1>
</div>

<?php if ($fid): ?>
<div class="spg-panel mb-3" style="border-color:var(--oro)">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <div class="ic" style="width:44px;height:44px;border-radius:10px;background:var(--blanco-hueso);display:flex;align-items:center;justify-content:center;color:var(--oro-oscuro);font-size:1.3rem"><i class="bi bi-award"></i></div>
    <div>
      <div style="font-weight:500">Tu nivel: <?= e($fid['nivel'] ?: 'Bronce') ?></div>
      <div class="text-muted-warm" style="font-size:.85rem"><?= (int)$fid['visitas'] ?> visitas · <?= (int)$fid['puntos'] ?> puntos · Beneficio: <?= e($fid['descuento_del_nivel'] ?: 'sin descuento aún') ?></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="spg-cards">
  <?php if (!$promos): ?>
    <div class="spg-panel"><p class="text-muted-warm mb-0">No hay promociones activas por el momento.</p></div>
  <?php endif; ?>
  <?php foreach ($promos as $p): ?>
    <div class="spg-card" style="cursor:default">
      <div class="ic"><i class="bi bi-percent" style="color:var(--oro-oscuro)"></i></div>
      <h3><?= e($p['nombre']) ?></h3>
      <p><?= e($p['descripcion'] ?: '') ?><br><strong style="color:var(--oro-oscuro)"><?= $p['tipo'] === 'PORCENTAJE' ? e($p['valor']) . '% off' : money($p['valor']) . ' de descuento' ?></strong></p>
    </div>
  <?php endforeach; ?>
</div>
