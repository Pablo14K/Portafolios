<?php
declare(strict_types=1);
require_once __DIR__ . '/../view.php';

function wa_json($data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function wa_payload(): array
{
    $raw = (string)($_POST['payload'] ?? '');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function wa_ruta_home(): string
{
    return es_cliente() ? 'portal/index' : 'dashboard/index';
}

// --------- Pregunta única tras el login ---------
function webauthn_preguntar(): void
{
    requiere_login();
    $uid = (int)$_SESSION['uid'];
    $tiene = (int)fetch_val("SELECT COUNT(*) FROM credencial_webauthn WHERE id_usuario=?", [$uid]);
    $pregunt = (int)(fetch_val("SELECT biometrico_pregunt FROM preferencia_usuario WHERE id_usuario=?", [$uid]) ?: 0);
    if ($tiene || $pregunt) {
        redirect('index.php?r=' . wa_ruta_home());
    }
    $email = (string)fetch_val("SELECT email FROM usuario WHERE id_usuario=?", [$uid]);
    $username = (string)fetch_val("SELECT username FROM usuario WHERE id_usuario=?", [$uid]);
    view('webauthn/preguntar', ['home' => wa_ruta_home(), 'email' => $email, 'username' => $username], 'Activar huella');
}

function webauthn_marcar_preguntado(): void
{
    requiere_login();
    $uid = (int)$_SESSION['uid'];
    q("INSERT INTO preferencia_usuario (id_usuario, biometrico_pregunt) VALUES (?,1)
       ON DUPLICATE KEY UPDATE biometrico_pregunt=1", [$uid]);
    wa_json(['ok' => true]);
}

// --------- Registro de credencial (activar huella) ---------
function webauthn_reg_options(): void
{
    requiere_login();
    $uid = (int)$_SESSION['uid'];
    $u = fetch_one("SELECT username, nombre, apellido, email FROM usuario WHERE id_usuario=?", [$uid]);
    $ch = webauthn_nuevo_challenge();
    $existentes = fetch_all("SELECT credential_id FROM credencial_webauthn WHERE id_usuario=?", [$uid]);
    $exclude = array_map(fn($r) => ['type' => 'public-key', 'id' => $r['credential_id']], $existentes);

    wa_json(['ok' => true, 'publicKey' => [
        'challenge' => $ch,
        'rp'   => ['name' => APP_NAME, 'id' => webauthn_rp_id()],
        'user' => ['id' => b64url_encode('u' . $uid), 'name' => $u['email'] ?: $u['username'], 'displayName' => $u['nombre'] . ' ' . $u['apellido']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
        'timeout' => 60000,
        'attestation' => 'none',
        'excludeCredentials' => $exclude,
    ]]);
}

function webauthn_register(): void
{
    requiere_login();
    $uid = (int)$_SESSION['uid'];
    $p = wa_payload();
    try {
        [$credId, $pem] = webauthn_verificar_registro((string)($p['clientDataJSON'] ?? ''), (string)($p['attestationObject'] ?? ''));
        q("INSERT INTO credencial_webauthn (id_usuario, credential_id, public_key, etiqueta) VALUES (?,?,?,?)",
          [$uid, $credId, $pem, 'Dispositivo']);
        q("INSERT INTO preferencia_usuario (id_usuario, biometrico_activo, biometrico_pregunt) VALUES (?,1,1)
           ON DUPLICATE KEY UPDATE biometrico_activo=1, biometrico_pregunt=1", [$uid]);
        auditar('BIOMETRICO_ALTA', 'Seguridad', 'credencial_webauthn', $uid, 'Registró login con huella');
        $email = (string)fetch_val("SELECT email FROM usuario WHERE id_usuario=?", [$uid]);
        $username = (string)fetch_val("SELECT username FROM usuario WHERE id_usuario=?", [$uid]);
        wa_json(['ok' => true, 'email' => $email, 'username' => $username]);
    } catch (Throwable $ex) {
        wa_json(['ok' => false, 'error' => $ex->getMessage()]);
    }
}

function webauthn_desactivar(): void
{
    requiere_login();
    $uid = (int)$_SESSION['uid'];
    q("DELETE FROM credencial_webauthn WHERE id_usuario=?", [$uid]);
    q("INSERT INTO preferencia_usuario (id_usuario, biometrico_activo, biometrico_pregunt) VALUES (?,0,1)
       ON DUPLICATE KEY UPDATE biometrico_activo=0", [$uid]);
    auditar('BIOMETRICO_BAJA', 'Seguridad', 'credencial_webauthn', $uid, 'Desactivó login con huella');
    wa_json(['ok' => true]);
}

// --------- Login con huella (público) ---------
function webauthn_auth_options(): void
{
    $p = wa_payload();
    $login = trim((string)($p['login'] ?? ''));
    if ($login === '') wa_json(['ok' => false, 'error' => 'Falta el usuario.']);

    $u = fetch_one("SELECT id_usuario FROM usuario WHERE (username=? OR email=?) AND activo=1 LIMIT 1", [$login, $login]);
    if (!$u) wa_json(['ok' => false, 'error' => 'Sin credenciales.']);

    $creds = fetch_all("SELECT credential_id FROM credencial_webauthn WHERE id_usuario=?", [(int)$u['id_usuario']]);
    if (!$creds) wa_json(['ok' => false, 'error' => 'Sin credenciales.']);

    $ch = webauthn_nuevo_challenge();
    wa_json(['ok' => true, 'publicKey' => [
        'challenge' => $ch,
        'timeout' => 60000,
        'rpId' => webauthn_rp_id(),
        'userVerification' => 'required',
        'allowCredentials' => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']], $creds),
    ]]);
}

function webauthn_login(): void
{
    $p = wa_payload();
    $credId = (string)($p['credentialId'] ?? '');
    $cred = fetch_one("SELECT id_credencial, id_usuario, public_key FROM credencial_webauthn WHERE credential_id=?", [$credId]);
    if (!$cred) wa_json(['ok' => false, 'error' => 'Credencial desconocida.']);

    $ok = webauthn_verificar_asercion(
        (string)($p['clientDataJSON'] ?? ''),
        (string)($p['authenticatorData'] ?? ''),
        (string)($p['signature'] ?? ''),
        (string)$cred['public_key']
    );
    if (!$ok) wa_json(['ok' => false, 'error' => 'No se pudo validar la huella.']);

    if (!iniciar_sesion_por_id((int)$cred['id_usuario'])) {
        wa_json(['ok' => false, 'error' => 'La cuenta no está activa.']);
    }
    auditar('LOGIN_BIOMETRICO', 'Seguridad', 'usuario', (int)$cred['id_usuario'], 'Inicio de sesión con huella');
    wa_json(['ok' => true, 'redirect' => base_url('index.php?r=' . wa_ruta_home())]);
}
