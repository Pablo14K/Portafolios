<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Restablecer contraseña · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="spg-login-wrap">
  <form class="spg-login" method="post" action="<?= e(base_url('index.php?r=auth/recuperar_codigo')) ?>">
    <?= csrf_field() ?>
    <div class="logo-big"><i class="bi bi-shield-lock"></i></div>
    <h1 class="text-center" style="font-size:1.25rem;font-weight:500;margin-bottom:.2rem;">Nueva contraseña</h1>
    <p class="text-center text-muted-warm" style="font-size:.85rem;margin-bottom:1.2rem;">Código enviado a <strong><?= e($email) ?></strong></p>

    <?php if (!empty($error)): ?><div class="alert alert-danger py-2" style="font-size:.85rem;"><?= e($error) ?></div><?php endif; ?>

    <div class="mb-2"><label class="form-label">Código</label>
      <input type="text" name="codigo" class="form-control text-center" inputmode="numeric" maxlength="6" placeholder="000000" required></div>
    <div class="mb-2"><label class="form-label">Nueva contraseña</label>
      <input type="password" name="nueva" class="form-control" required minlength="6"></div>
    <div class="mb-3"><label class="form-label">Repetir contraseña</label>
      <input type="password" name="nueva2" class="form-control" required></div>
    <button class="btn btn-oro w-100 py-2" type="submit">Restablecer</button>
    <p class="text-center mt-3 mb-0" style="font-size:.85rem;color:var(--gris-oscuro)">
      <a class="link-oro" href="<?= e(base_url('index.php?r=auth/login')) ?>">Volver al login</a>
    </p>
  </form>
</div>
</body></html>
