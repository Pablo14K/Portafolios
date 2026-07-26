<?php
// Variables esperadas: $titulo (string), $activo (string, opcional)
$u = usuario_actual();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo ?? APP_NAME) ?> · <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body>
<header class="spg-topbar">
  <a class="spg-brand" href="<?= e(base_url('index.php?r=' . (es_cliente() ? 'portal/index' : 'dashboard/index'))) ?>">
    <span class="spg-logo"><i class="bi bi-scissors"></i></span>
    <span class="spg-brand-name"><?= e(APP_NAME) ?></span>
  </a>
  <?php if ($u): ?>
  <div class="spg-user">
    <a class="spg-user-link" href="<?= e(base_url('index.php?r=cuenta/index')) ?>" title="Mi cuenta"><i class="bi bi-person-circle"></i> <span class="spg-user-nombre"><?= e($u['nombre']) ?></span></a>
    <span class="spg-rol-chip"><?= e($u['rol_nom']) ?></span>
    <a class="spg-user-link" href="<?= e(base_url('index.php?r=auth/logout')) ?>" title="Cerrar sesión"><i class="bi bi-box-arrow-right"></i></a>
  </div>
  <?php endif; ?>
</header>
<main class="container py-2">
  <?= flash_render() ?>
