<?php
// =====================================================================
//  Render de vistas + definición del menú de módulos
// =====================================================================
declare(strict_types=1);

function view(string $vista, array $datos = [], string $titulo = APP_NAME): void
{
    extract($datos, EXTR_SKIP);
    require __DIR__ . '/views/layout/header.php';
    require __DIR__ . '/views/' . $vista . '.php';
    require __DIR__ . '/views/layout/footer.php';
}

// Tarjetas del panel de gestión. Cada una define los roles que la pueden ver.
// P=Propietaria(1) G=Gerente(2) A=Asistente(3)
function menu_modulos(): array
{
    return [
        ['mod' => 'citas',         'r' => 'citas/index',        'ic' => 'calendar-event', 'titulo' => 'Citas y agenda',    'sub' => 'Calendario · Nueva cita · Estados · Ausencias'],
        ['mod' => 'clientes',      'r' => 'clientes/index',     'ic' => 'people',         'titulo' => 'Clientes',          'sub' => 'Registro · Historial · Preferencias · Valoraciones'],
        ['mod' => 'servicios',     'r' => 'servicios/index',    'ic' => 'scissors',       'titulo' => 'Servicios',         'sub' => 'Catálogo · Categorías · Descuentos · Promos'],
        ['mod' => 'inventario',    'r' => 'inventario/index',   'ic' => 'box-seam',       'titulo' => 'Inventario',        'sub' => 'Productos · Proveedores · Stock · Compras'],
        ['mod' => 'facturacion',   'r' => 'facturacion/index',  'ic' => 'cash-stack',     'titulo' => 'Facturación y caja','sub' => 'Cobros · Facturas · Caja · Pagos'],
        ['mod' => 'reportes',      'r' => 'reportes/index',     'ic' => 'bar-chart',      'titulo' => 'Reportes',          'sub' => 'Servicios top · Demanda · Ingresos'],
        ['mod' => 'personal',      'r' => 'personal/index',     'ic' => 'person-badge',   'titulo' => 'Personal',          'sub' => 'Usuarios · Turnos · Comisiones · Asistencia'],
        ['mod' => 'configuracion', 'r' => 'configuracion/index','ic' => 'gear',           'titulo' => 'Configuración',     'sub' => 'Roles · Datos del local · Catálogos · Auditoría', 'dark' => true],
    ];
}
