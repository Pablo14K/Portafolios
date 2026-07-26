<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar contraseña · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="spg-login-wrap">
  <form class="spg-login" method="post" action="<?= e(base_url('index.php?r=auth/recuperar')) ?>">
    <?= csrf_field() ?>
    <div class="logo-big"><i class="bi bi-key"></i></div>
    <h1 class="text-center" style="font-size:1.25rem;font-weight:500;margin-bottom:.2rem;">Recuperar contraseña</h1>
    <p class="text-center text-muted-warm" style="font-size:.85rem;margin-bottom:1.2rem;">Ingresá tu email y te enviaremos un código.</p>

    <?php if (!empty($enviado)): ?>
      <div class="alert alert-info py-2" style="font-size:.85rem;">Si el email está registrado, te enviamos un código. Revisá tu bandeja.</div>
    <?php endif; ?>

    <div class="mb-3">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" autofocus required>
    </div>
    <button class="btn btn-oro w-100 py-2" type="submit">Enviar código</button>
    <p class="text-center mt-3 mb-0" style="font-size:.85rem;color:var(--gris-oscuro)">
      <a class="link-oro" href="<?= e(base_url('index.php?r=auth/login')) ?>">Volver al login</a>
    </p>
  </form>
</div>
</body></html>
