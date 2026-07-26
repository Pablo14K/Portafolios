<?php // Espera: $titulo_mod, $icono, $desc, $subs[] (r, ic, t, d) ?>
<div class="spg-page-head d-flex justify-content-between align-items-end">
  <div>
    <a class="spg-back" href="<?= e(base_url('index.php?r=dashboard/index')) ?>"><i class="bi bi-arrow-left"></i> Panel</a>
    <h1 class="mt-1"><i class="bi bi-<?= e($icono) ?>"></i> <?= e($titulo_mod) ?></h1>
    <div class="sub"><?= e($desc) ?></div>
  </div>
</div>

<div class="spg-cards">
  <?php foreach ($subs as $s): ?>
    <a class="spg-card" href="<?= e(base_url('index.php?r=' . $s['r'])) ?>">
      <div class="ic"><i class="bi bi-<?= e($s['ic']) ?>"></i></div>
      <h3><?= e($s['t']) ?></h3>
      <p><?= e($s['d']) ?></p>
    </a>
  <?php endforeach; ?>
</div>
