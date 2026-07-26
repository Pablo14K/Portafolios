<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crear cuenta · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="spg-login-wrap">
  <form class="spg-login" method="post" action="<?= e(base_url('index.php?r=auth/registro')) ?>" style="max-width:440px">
    <?= csrf_field() ?>
    <div class="logo-big"><i class="bi bi-scissors"></i></div>
    <h1 class="text-center" style="font-size:1.25rem;font-weight:500;margin-bottom:.2rem;">Crear cuenta</h1>
    <p class="text-center text-muted-warm" style="font-size:.85rem;margin-bottom:1.3rem;">Registrate para reservar tus citas online</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger py-2" style="font-size:.85rem;"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row g-2">
      <div class="col-6"><label class="form-label">Nombre *</label>
        <input class="form-control" name="nombre" required value="<?= e($old['nombre'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label">Apellido *</label>
        <input class="form-control" name="apellido" required value="<?= e($old['apellido'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Email *</label>
        <input type="email" class="form-control" name="email" required value="<?= e($old['email'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label">Teléfono</label>
        <input class="form-control" name="telefono" value="<?= e($old['telefono'] ?? '') ?>"></div>
      <div class="col-6"><label class="form-label">Usuario *</label>
        <input class="form-control" name="username" required value="<?= e($old['username'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Contraseña *</label>
        <div class="input-group">
          <input type="password" name="password" id="pass" class="form-control" required minlength="6">
          <button class="btn btn-outline-neutro" type="button" id="togglePass" tabindex="-1" aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye" id="eyeIcon"></i></button>
        </div>
        <div class="form-text" style="font-size:.75rem">Mínimo 6 caracteres.</div></div>
      <div class="col-12"><label class="form-label">Repetir contraseña *</label>
        <div class="input-group">
          <input type="password" name="password2" id="pass2" class="form-control" required>
          <button class="btn btn-outline-neutro" type="button" id="togglePass2" tabindex="-1" aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye" id="eyeIcon2"></i></button>
        </div></div>
    </div>

    <button class="btn btn-oro w-100 py-2 mt-4" type="submit">Crear cuenta</button>
    <p class="text-center mt-3 mb-0" style="font-size:.85rem;color:var(--gris-oscuro)">
      ¿Ya tenés cuenta? <a class="link-oro" href="<?= e(base_url('index.php?r=auth/login')) ?>">Iniciá sesión</a>
    </p>
  </form>
</div>
<script>
  function toggle(btnId, inputId, iconId) {
    var btn = document.getElementById(btnId), inp = document.getElementById(inputId), ic = document.getElementById(iconId);
    btn.addEventListener('click', function () {
      var ver = inp.type === 'password';
      inp.type = ver ? 'text' : 'password';
      ic.className = ver ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  }
  toggle('togglePass', 'pass', 'eyeIcon');
  toggle('togglePass2', 'pass2', 'eyeIcon2');
</script>
</body></html>
