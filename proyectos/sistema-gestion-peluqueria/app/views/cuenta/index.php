<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=' . (es_cliente() ? 'portal/index' : 'dashboard/index'))) ?>"><i class="bi bi-arrow-left"></i> Inicio</a>
  <h1 class="mt-1">Mi cuenta</h1>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Mis datos</h2>
      <table class="table table-sm align-middle mb-0">
        <tr><td class="text-muted-warm">Nombre</td><td><?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?></td></tr>
        <tr><td class="text-muted-warm">Usuario</td><td><?= e($perfil['username']) ?></td></tr>
        <tr><td class="text-muted-warm">Email</td><td><?= e($perfil['email']) ?></td></tr>
        <tr><td class="text-muted-warm">Teléfono</td><td><?= e($perfil['telefono'] ?: '—') ?></td></tr>
        <tr><td class="text-muted-warm">Rol</td><td><span class="badge-estado e-prog"><?= e($perfil['rol']) ?></span></td></tr>
      </table>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="spg-panel">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Cambiar contraseña</h2>
      <form method="post" action="<?= e(base_url('index.php?r=cuenta/password')) ?>" style="max-width:420px">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Contraseña actual</label>
          <div class="input-group"><input type="password" id="p0" class="form-control" name="actual" required>
            <button class="btn btn-outline-neutro" type="button" tabindex="-1" onclick="var e=document.getElementById('p0');e.type=e.type==='password'?'text':'password'"><i class="bi bi-eye"></i></button></div></div>
        <div class="mb-2"><label class="form-label">Nueva contraseña</label>
          <input type="password" class="form-control" name="nueva" required minlength="6">
          <div class="form-text" style="font-size:.75rem">Mínimo 6 caracteres.</div></div>
        <div class="mb-3"><label class="form-label">Repetir nueva contraseña</label>
          <input type="password" class="form-control" name="nueva2" required></div>
        <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Actualizar contraseña</button>
      </form>
    </div>

    <div class="spg-panel mt-3" id="bioCard">
      <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;"><i class="bi bi-fingerprint"></i> Inicio de sesión con huella</h2>
      <div id="bioNoSoporta" class="text-muted-warm" style="display:none;font-size:.85rem">Este dispositivo o navegador no permite el desbloqueo biométrico.</div>
      <div id="bioBox" style="display:none">
        <div class="form-check form-switch" style="font-size:1.05rem">
          <input class="form-check-input" type="checkbox" role="switch" id="bioSwitch" <?= !empty($bioActivo) ? 'checked' : '' ?>>
          <label class="form-check-label" for="bioSwitch" style="font-size:.9rem">
            <span id="bioEstado"><?= !empty($bioActivo) ? 'Activado' : 'Desactivado' ?></span>
          </label>
        </div>
        <p class="text-muted-warm mt-2 mb-0" style="font-size:.8rem">Con la huella activada, en este equipo podés entrar sin escribir la contraseña.</p>
        <div id="bioMsg2" class="mt-2" style="font-size:.82rem"></div>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(base_url('assets/js/webauthn.js')) ?>"></script>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var email = <?= json_encode($perfil['email']) ?>, username = <?= json_encode($perfil['username']) ?>;
  var regUrls = { options: <?= json_encode(base_url('index.php?r=webauthn/reg_options')) ?>, verify: <?= json_encode(base_url('index.php?r=webauthn/register')) ?> };
  var offUrl = <?= json_encode(base_url('index.php?r=webauthn/desactivar')) ?>;
  var sw = document.getElementById('bioSwitch'), estado = document.getElementById('bioEstado'), msg = document.getElementById('bioMsg2');

  SPGBio.available().then(function (ok) {
    document.getElementById(ok ? 'bioBox' : 'bioNoSoporta').style.display = 'block';
  });

  sw.addEventListener('change', function () {
    msg.textContent = '';
    if (sw.checked) {
      sw.disabled = true; msg.textContent = 'Esperando tu huella…';
      SPGBio.register(regUrls, csrf).then(function (res) {
        sw.disabled = false;
        if (res.ok) { estado.textContent = 'Activado'; SPGBio.recordar(res.username || username, res.email || email); msg.textContent = 'Listo, ya podés entrar con tu huella.'; }
        else { sw.checked = false; msg.textContent = 'No se pudo activar: ' + (res.error || ''); }
      }).catch(function (e) { sw.disabled = false; sw.checked = false; msg.textContent = 'No se pudo activar: ' + e.message; });
    } else {
      sw.disabled = true;
      fetch(offUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: '_csrf=' + encodeURIComponent(csrf) })
        .then(function (r) { return r.json(); }).then(function () {
          sw.disabled = false; estado.textContent = 'Desactivado'; SPGBio.olvidar(); msg.textContent = 'Login con huella desactivado.';
        });
    }
  });
})();
</script>
