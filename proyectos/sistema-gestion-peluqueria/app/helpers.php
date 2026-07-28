<?php
// =====================================================================
//  Funciones de apoyo (escape, flash, CSRF, formato, redirección)
// =====================================================================
declare(strict_types=1);

// Escape para HTML
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Formato de dinero (guaraníes: sin decimales, separador de miles con punto)
function money($n): string
{
    return MONEDA . ' ' . number_format((float)$n, 0, ',', '.');
}

// Formato de cantidad: sin decimales si es un número entero (12 en vez de 12,00);
// con decimales solo cuando el producto se vende o consume fraccionado (0,5).
function cant($n): string
{
    $v = (float)$n;
    if (abs($v - round($v)) < 0.005) {
        return number_format($v, 0, ',', '.');
    }
    return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
}

// Formato de fecha/hora legible
function fecha($dt, string $fmt = 'd/m/Y H:i'): string
{
    if (!$dt) return '';
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    return $ts ? date($fmt, $ts) : '';
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

// --- Mensajes flash -------------------------------------------------------
function flash(string $msg, string $tipo = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'tipo' => $tipo];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) return '';
    $map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $map[$f['tipo']] ?? 'secondary';
        $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
            . e($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// --- CSRF -----------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $ok = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        || hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '');
    if (!$ok) {
        http_response_code(419);
        exit('Token de seguridad inválido. Volvé atrás y reintentá.');
    }
}

// Entrada segura desde POST/GET
function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}
function get(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

// Registra una acción en la tabla auditoria (el trigger de la BD no cubre esto).
// Solo registra si hay un usuario en sesión (id_usuario es NOT NULL en la tabla).
function auditar(string $accion, string $modulo, string $tabla, ?int $idRegistro = null, ?string $detalle = null): void
{
    if (empty($_SESSION['uid'])) return;
    try {
        q("INSERT INTO auditoria (id_usuario, accion, modulo, tabla_afectada, id_registro, detalle)
           VALUES (?,?,?,?,?,?)",
          [(int)$_SESSION['uid'], $accion, $modulo, $tabla, $idRegistro, $detalle]);
    } catch (PDOException $e) {
        // La auditoría nunca debe romper la operación principal
    }
}

// Badge de estado con color acorde
function estado_badge(string $estado): string
{
    $map = [
        'Programada' => 'prog', 'Reprogramada' => 'prog', 'En proceso' => 'proc',
        'Atendida' => 'ok', 'Confirmada' => 'ok', 'Emitida' => 'ok', 'Registrado' => 'ok',
        'Cancelada' => 'no', 'Ausente' => 'no', 'Anulada' => 'no', 'Anulado' => 'no',
        'Pendiente' => 'warn', 'Abierta' => 'ok', 'Cerrada' => 'muted',
    ];
    $k = $map[$estado] ?? 'muted';
    return '<span class="badge-estado e-' . $k . '">' . e($estado) . '</span>';
}
