@extends('layout.app')

@section('titulo', 'Sucursales')

@section('contenido')
    <x-encabezado
        sub="Los locales del salón. El RUC y la dirección de la sucursal son los que se imprimen en el comprobante."
        :accion="['ruta' => 'seguridad.sucursal_form', 't' => 'Nueva sucursal', 'ic' => 'plus-lg']" />

    {{-- El nombre, el logo, los colores y la letra del SISTEMA vivieron acá
         arriba desde la 7.36.1 hasta la 7.124.0, y sobrecargaban la lista de
         locales con algo que no es de ningún local. Desde la 7.125.0 tienen su
         propia pantalla: Configuración → Ajustes. --}}
    <div class="sgp-panel">
        <div class="table-responsive sgp-tabla-movil">
            <table class="table align-middle mb-0">
                <thead>
                    <tr><th>Nombre</th><th>Teléfono</th>
                        <th class="text-end">Personal</th><th>Estado</th><th class="text-end">Acciones</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $s)
                        <tr>
                            <td class="sgp-movil-titulo" data-label="Nombre">{{ $s->nombre }}</td>
                            <td data-label="Teléfono">{{ $s->telefono ?: '—' }}</td>
                            <td class="text-end" data-label="Personal">{{ (int) $s->personal }}</td>
                            <td data-label="Estado">
                                @if ($s->activo)
                                    <span class="badge-estado e-ok">Activa</span>
                                @else
                                    <span class="badge-estado e-muted">Inactiva</span>
                                @endif
                            </td>
                            <td class="text-end sgp-movil-acciones" style="white-space:nowrap">
                                <button class="sgp-btn-detalle" data-bs-toggle="collapse"
                                        data-bs-target="#detSuc{{ $s->id_sucursal }}" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i> Detalle
                                </button>
                                <a class="btn btn-sm btn-outline-neutro" title="Editar"
                                   href="{{ route('seguridad.sucursal_form', $s->id_sucursal) }}">
                                    <i class="bi bi-pencil"></i></a>
                                <form method="post" action="{{ route('seguridad.sucursal.baja') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="id_sucursal" value="{{ $s->id_sucursal }}">
                                    <button class="btn btn-sm btn-outline-neutro"
                                            title="{{ $s->activo ? 'Desactivar' : 'Activar' }}"
                                            data-confirmar="¿{{ $s->activo ? 'Desactivar' : 'Activar' }} «{{ $s->nombre }}»?">
                                        <i class="bi bi-toggle-{{ $s->activo ? 'on' : 'off' }}"></i></button>
                                </form>
                            </td>
                        </tr>
                        <tr class="sgp-fila-detalle">
                            <td colspan="5">
                                <div class="collapse" id="detSuc{{ $s->id_sucursal }}">
                                    <div class="sgp-det-cuerpo">
                                        <div class="sgp-det-grid">
                                            <div>
                                                <dt>RUC</dt>
                                                <dd>{{ $s->ruc ?: '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt>Ciudad</dt>
                                                <dd>{{ $s->ciudad ?: '—' }}</dd>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="sgp-vacio">
                                    <i class="bi bi-shop"></i>
                                    <div class="t">No hay sucursales cargadas.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
