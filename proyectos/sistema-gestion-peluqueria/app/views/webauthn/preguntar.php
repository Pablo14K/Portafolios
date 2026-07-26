<?php $csrf = csrf_token(); ?>
<div class="spg-login-wrap" style="min-height:70vh">
  <div class="spg-login" style="text-align:center">
    <div class="logo-big"><i class="bi bi-fingerprint"></i></div>
    <h1 style="font-size:1.2rem;font-weight:500;margin-bottom:.4rem;">Iniciar sesión con tu huella</h1>
    <p class="text-muted-warm" style="font-size:.9rem;margin-bottom:1.2rem;">
      Tu dispositivo permite desbloqueo biométrico. ¿Querés usar tu <strong>huella</strong> para entrar más rápido la próxima vez?
    </p>

    <div id="bioCargando" class="text-muted-warm" style="font-size:.85rem">Comprobando tu dispositivo…</div>

    <div id="bioAcciones" style="display:none">
      <button id="btnActivar" class="btn btn-oro w-100 py-2 mb-2"><i class="bi bi-fingerprint"></i> Sí, activar huella</button>
      <button id="btnAhoraNo" class="btn btn-outline-neutro w-100">Ahora no</button>
      <p class="text-muted-warm mt-3 mb-0" style="font-size:.78rem">Podés cambiarlo cuando quieras desde <strong>Mi cuenta</strong>.</p>
    </div>

    <div id="bioError" class="alert alert-warning mt-3 py-2" style="display:none;font-size:.82rem"></div>
  </div>
</div>

<script src="<?= e(base_url('assets/js/webauthn.js')) ?>"></script>
<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;
  var home = <?= json_encode(base_url('index.php?r=' . $home)) ?>;
  var username = <?= json_encode($username) ?>, email = <?= json_encode($email) ?>;
  var urls = {
    options: <?= json_encode(base_url('index.php?r=webauthn/reg_options')) ?>,
    verify:  <?= json_encode(base_url('index.php?r=webauthn/register')) ?>
  };
  var marcar = <?= json_encode(base_url('index.php?r=webauthn/marcar_preguntado')) ?>;

  function irHome() { window.location.href = home; }
  function saltar() {
    fetch(marcar, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: '_csrf=' + encodeURIComponent(csrf) })
      .finally(irHome);
  }

  SPGBio.available().then(function (ok) {
    document.getElementById('bioCargando').style.display = 'none';
    if (!ok) { saltar(); return; }   // dispositivo sin biometría: no molestar
    document.getElementById('bioAcciones').style.display = 'block';
  });

  document.getElementById('btnAhoraNo').addEventListener('click', saltar);
  document.getElementById('btnActivar').addEventListener('click', function () {
    var btn = this; btn.disabled = true; btn.innerHTML = 'Esperando tu huella…';
    SPGBio.register(urls, csrf).then(function (res) {
      if (res.ok) { SPGBio.recordar(res.username || username, res.email || email); irHome(); }
      else { throw new Error(res.error || 'No se pudo activar.'); }
    }).catch(function (e) {
      btn.disabled = false; btn.innerHTML = '<i class="bi bi-fingerprint"></i> Sí, activar huella';
      var box = document.getElementById('bioError'); box.style.display = 'block';
      box.textContent = 'No se pudo activar la huella: ' + e.message;
    });
  });
})();
</script>
