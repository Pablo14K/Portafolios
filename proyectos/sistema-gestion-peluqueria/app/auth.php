<?php
// =====================================================================
//  Autenticación y control de acceso por roles
//  Roles (según BD): 1 Propietaria · 2 Gerente · 3 Asistente · 4 Cliente
// =====================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ROL_PROPIETARIA = 1;
const ROL_GERENTE     = 2;
const ROL_ASISTENTE   = 3;
const ROL_CLIENTE     = 4;

function intentar_login(string $usuario, string $password): bool
{
    // Permite iniciar sesión con username o email
    $u = fetch_one(
        'SELECT u.*, r.nombre AS rol_nombre, r.es_personal
           FROM usuario u JOIN rol r ON r.id_rol = u.id_rol
          WHERE (u.username = :u1 OR u.email = :u2) AND u.activo = 1
          LIMIT 1',
        ['u1' => $usuario, 'u2' => $usuario]
    );
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['uid']      = (int)$u['id_usuario'];
    $_SESSION['nombre']   = $u['nombre'] . ' ' . $u['apellido'];
    $_SESSION['rol']      = (int)$u['id_rol'];
    $_SESSION['rol_nom']  = $u['rol_nombre'];

    // Si es cliente, vinculamos su ficha de cliente para el portal
    if ((int)$u['id_rol'] === ROL_CLIENTE) {
        $_SESSION['id_cliente'] = fetch_val(
            'SELECT id_cliente FROM cliente WHERE id_usuario = ? LIMIT 1',
            [(int)$u['id_usuario']]
        ) ?: null;
    }
    auditar('LOGIN', 'Seguridad', 'usuario', (int)$u['id_usuario'], 'Inicio de sesión');
    return true;
}

// Establece la sesión a partir de un id de usuario (login biométrico / verificación)
function iniciar_sesion_por_id(int $idUsuario): bool
{
    $u = fetch_one(
        'SELECT u.*, r.nombre AS rol_nombre FROM usuario u JOIN rol r ON r.id_rol = u.id_rol
          WHERE u.id_usuario = ? AND u.activo = 1 LIMIT 1',
        [$idUsuario]
    );
    if (!$u) return false;
    session_regenerate_id(true);
    $_SESSION['uid']     = (int)$u['id_usuario'];
    $_SESSION['nombre']  = $u['nombre'] . ' ' . $u['apellido'];
    $_SESSION['rol']     = (int)$u['id_rol'];
    $_SESSION['rol_nom'] = $u['rol_nombre'];
    if ((int)$u['id_rol'] === ROL_CLIENTE) {
        $_SESSION['id_cliente'] = fetch_val('SELECT id_cliente FROM cliente WHERE id_usuario = ? LIMIT 1', [$idUsuario]) ?: null;
    }
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function usuario_actual(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    return [
        'id'     => $_SESSION['uid'],
        'nombre' => $_SESSION['nombre'],
        'rol'    => $_SESSION['rol'],
        'rol_nom' => $_SESSION['rol_nom'],
    ];
}

function esta_logueado(): bool
{
    return !empty($_SESSION['uid']);
}

function es_cliente(): bool
{
    return (int)($_SESSION['rol'] ?? 0) === ROL_CLIENTE;
}

function es_personal(): bool
{
    return esta_logueado() && !es_cliente();
}

// Exige sesión iniciada; si no, va al login
function requiere_login(): void
{
    if (!esta_logueado()) {
        redirect('index.php?r=auth/login');
    }
}

// Exige que el rol esté dentro de la lista permitida
function requiere_rol(array $roles): void
{
    requiere_login();
    if (!in_array((int)$_SESSION['rol'], $roles, true)) {
        http_response_code(403);
        exit('<div style="font-family:sans-serif;padding:2rem">No tenés permiso para acceder a esta sección.</div>');
    }
}

// Solo personal (bloquea al cliente en el panel de gestión)
function requiere_personal(): void
{
    requiere_login();
    if (es_cliente()) {
        redirect('index.php?r=portal/index');
    }
}

// Módulos del panel de gestión (clave => etiqueta) para la matriz de roles
function modulos_sistema(): array
{
    return [
        'citas'         => 'Citas y agenda',
        'clientes'      => 'Clientes',
        'servicios'     => 'Servicios',
        'inventario'    => 'Inventario',
        'facturacion'   => 'Facturación y caja',
        'reportes'      => 'Reportes',
        'personal'      => 'Personal',
        'configuracion' => 'Configuración',
    ];
}

// ¿El rol tiene habilitado ese módulo? La Propietaria siempre (superadmin).
function rol_puede(int $rol, string $modulo): bool
{
    if ($rol === ROL_PROPIETARIA) return true;
    return (int)fetch_val("SELECT COUNT(*) FROM rol_modulo WHERE id_rol=? AND modulo=?", [$rol, $modulo]) > 0;
}

// Exige que el usuario actual (personal) tenga habilitado el módulo
function requiere_modulo(string $modulo): void
{
    requiere_personal();
    if (!rol_puede((int)$_SESSION['rol'], $modulo)) {
        http_response_code(403);
        exit('<div style="font-family:sans-serif;padding:2rem">Tu rol no tiene acceso a este módulo.</div>');
    }
}

// Solo clientes (portal). Además exige que tengan ficha de cliente vinculada.
function requiere_cliente(): int
{
    requiere_login();
    if (!es_cliente()) {
        redirect('index.php?r=dashboard/index');
    }
    $idc = (int)($_SESSION['id_cliente'] ?? 0);
    if (!$idc) {
        http_response_code(403);
        exit('<div style="font-family:sans-serif;padding:2rem">Tu usuario no está vinculado a una ficha de cliente. Contactá al salón.</div>');
    }
    return $idc;
}
