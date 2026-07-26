<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificar cuenta · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="spg-login-wrap">
  <div class="spg-login">
    <div class="logo-big"><i class="bi bi-envelope-check"></i></div>
    <h1 class="text-center" style="font-size:1.25rem;font-weight:500;margin-bottom:.2rem;">Verificá tu cuenta</h1>
    <p class="text-center text-muted-warm" style="font-size:.85rem;margin-bottom:1.2rem;">Ingresá el código que enviamos a<br><strong><?= e($email) ?></strong></p>
    <?= flash_render() ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger py-2" style="font-size:.85rem;"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="<?= e(base_url('index.php?r=auth/verificar')) ?>">
      <?= csrf_field() ?>
      <div class="mb-3">
        <input type="text" name="codigo" class="form-control text-center" style="font-size:1.4rem;letter-spacing:.4rem"
               inputmode="numeric" maxlength="6" placeholder="000000" autofocus required>
      </div>
      <button class="btn btn-oro w-100 py-2" type="submit">Verificar</button>
    </form>
    <form method="post" action="<?= e(base_url('index.php?r=auth/verificar')) ?>" class="mt-2 text-center">
      <?= csrf_field() ?><input type="hidden" name="reenviar" value="1">
      <button class="btn btn-link link-oro" style="font-size:.85rem;text-decoration:none">Reenviar código</button>
    </form>
  </div>
</div>
</body></html>
