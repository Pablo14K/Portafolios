<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="spg-login-wrap">
  <!-- Panel biométrico: aparece si el navegador tiene una huella activada para este equipo -->
  <div class="spg-login" id="bioPanel" style="display:none;text-align:center">
    <div class="logo-big"><i class="bi bi-scissors"></i></div>
    <h1 style="font-size:1.2rem;font-weight:500;margin-bottom:.2rem;"><?= e(APP_NAME) ?></h1>
    <p class="text-muted-warm" style="font-size:.85rem;margin-bottom:1.1rem;">Ingresá con tu huella</p>
    <div style="font-size:.95rem;margin-bottom:1rem"><i class="bi bi-person-circle"></i> <span id="bioEmail"></span></div>
    <button id="bioBtn" class="btn btn-oro" style="width:74px;height:74px;border-radius:50%;font-size:2rem" aria-label="Entrar con huella"><i class="bi bi-fingerprint"></i></button>
    <div id="bioMsg" class="text-muted-warm mt-2" style="font-size:.8rem">Tocá para entrar</div>
    <p class="mt-3 mb-0"><a href="#" id="usarClave" class="link-oro" style="font-size:.85rem">Usar contraseña</a></p>
  </div>

  <form class="spg-login" id="formLogin" method="post" action="<?= e(base_url('index.php?r=auth/login')) ?>">
    <?= csrf_field() ?>
    <div class="logo-big"><i class="bi bi-scissors"></i></div>
    <h1 class="text-center" style="font-size:1.25rem;font-weight:500;margin-bottom:.2rem;"><?= e(APP_NAME) ?></h1>
    <p class="text-center text-muted-warm" style="font-size:.85rem;margin-bottom:1.3rem;">Sistema de gestión</p>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger py-2" style="font-size:.85rem;"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="mb-3">
      <label class="form-label">Usuario o email</label>
      <input type="text" name="usuario" class="form-control" autofocus required value="<?= e(post('usuario', '')) ?>">
    </div>
    <div class="mb-4">
      <label class="form-label">Contraseña</label>
      <div class="input-group">
        <input type="password" name="password" id="pass" class="form-control" required>
        <button class="btn btn-outline-neutro" type="button" id="togglePass" tabindex="-1" aria-label="Mostrar u ocultar contraseña">
          <i class="bi bi-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>
    <button class="btn btn-oro w-100 py-2" type="submit">Ingresar</button>

    <p class="text-center mt-3 mb-1" style="font-size:.85rem;">
      <a class="link-oro" href="<?= e(base_url('index.php?r=auth/recuperar')) ?>">¿Olvidaste tu contraseña?</a>
    </p>
    <p class="text-center mb-0" style="font-size:.85rem;color:var(--gris-oscuro)">
      ¿Sos cliente nuevo?
      <a class="link-oro" href="<?= e(base_url('index.php?r=auth/registro')) ?>">Creá tu cuenta</a>
    </p>
  </form>
</div>
<script>
  (function () {
    var btn = document.getElementById('togglePass'),
        pass = document.getElementById('pass'),
        icon = document.getElementById('eyeIcon');
    btn.addEventListener('click', function () {
      var ver = pass.type === 'password';
      pass.type = ver ? 'text' : 'password';
      icon.className = ver ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  })();
</script>
<script src="<?= e(base_url('assets/js/webauthn.js')) ?>"></script>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var urls = {
    options: <?= json_encode(base_url('index.php?r=webauthn/auth_options')) ?>,
    verify:  <?= json_encode(base_url('index.php?r=webauthn/login')) ?>
  };
  var saved = SPGBio.guardado();
  var panel = document.getElementById('bioPanel'), formL = document.getElementById('formLogin');

  function mostrarClave() { panel.style.display = 'none'; formL.style.display = 'block'; }

  if (saved && saved.login) {
    SPGBio.available().then(function (ok) {
      if (!ok) { mostrarClave(); return; }
      document.getElementById('bioEmail').textContent = saved.email || saved.login;
      formL.style.display = 'none';
      panel.style.display = 'block';
    });
  }

  document.getElementById('usarClave').addEventListener('click', function (e) { e.preventDefault(); mostrarClave(); });
  document.getElementById('bioBtn').addEventListener('click', function () {
    var msg = document.getElementById('bioMsg'); msg.textContent = 'Esperando tu huella…';
    SPGBio.login(urls, saved.login, csrf).then(function (res) {
      if (res.ok) { window.location.href = res.redirect; }
      else { throw new Error(res.error || 'No se pudo validar.'); }
    }).catch(function (e) {
      msg.textContent = 'No se pudo entrar con huella. Probá con contraseña.';
    });
  });
})();
</script>
</body></html>
