@extends('layout.app')

@section('titulo', 'Detalle de compra')

@section('contenido')
    <div class="sgp-page-head">
        <a class="sgp-back" href="{{ route('inventario.compras') }}"><i class="bi bi-arrow-left"></i> Compras</a>
        <h1 class="mt-1">Compra a {{ $compra->proveedor }}</h1>
        <div class="sub">
            {{ fecha($compra->fecha) }}
            @if ($compra->nro_factura_proveedor) · factura {{ $compra->nro_factura_proveedor }} @endif
            {{-- `@endif` pegado a una palabra no lo compila Blade (lleva `\B`
                 delante de la arroba): las directivas van con espacio. --}}
            · {{ $compra->condicion }}
            @if ($cuotas)
                en {{ count($cuotas) }} cuota{{ count($cuotas) === 1 ? '' : 's' }}
            @elseif ((int) $compra->dias_credito > 0)
                a {{ (int) $compra->dias_credito }} días
            @endif
        </div>
    </div>

    {{-- **El número de factura se puede cargar después.** El papel no siempre
         llega con la mercadería: se recibe el pedido, se paga, y la factura
         aparece días más tarde. Pidiéndolo sólo al registrar la compra, o se
         inventaba uno o quedaba en blanco para siempre. --}}
    <div class="sgp-panel mb-3">
        <form method="post" action="{{ route('inventario.compra.factura') }}"
              class="d-flex gap-2 align-items-end flex-wrap">
            @csrf
            <input type="hidden" name="id_compra" value="{{ $compra->id_compra }}">
            <div>
                <label class="form-label mb-1" for="nroFac">
                    <i class="bi bi-receipt"></i> Factura del proveedor
                </label>
                <input class="form-control form-control-sm" id="nroFac" name="nro_factura_proveedor"
                       data-solo="documento" inputmode="numeric" maxlength="30"
                       placeholder="001-001-0001234" style="min-width:200px"
                       list="facturasProveedor"
                       value="{{ $compra->nro_factura_proveedor }}">
                {{-- **Las que ya se anotaron al pagarle a este proveedor.** La
                     referencia de un pago suele ser el número del papel, así
                     que se ofrece como sugerencia — no se completa sola,
                     porque una referencia puede ser también un nº de
                     operación del banco. --}}
                <datalist id="facturasProveedor">
                    @foreach ($facturasSugeridas ?? [] as $ref)
                        <option value="{{ $ref }}"></option>
                    @endforeach
                </datalist>
            </div>
            <button class="btn btn-sm btn-rapido"><i class="bi bi-check-lg"></i>
                {{ $compra->nro_factura_proveedor ? 'Corregir' : 'Anotar' }}</button>
            @unless ($compra->nro_factura_proveedor)
                <span class="text-muted-warm" style="font-size:.82rem">
                    Todavía sin factura: anotala cuando el proveedor la entregue.
                </span>
            @endunless
        </form>
    </div>

    <div class="sgp-metrics mb-3">
        <div class="sgp-metric">
            <div class="lbl">Total</div>
            <div class="val acento">{{ money($compra->total) }}</div>
            @if ((float) $compra->descuento > 0)
                <div class="text-muted-warm" style="font-size:.78rem">
                    renglones {{ money($compra->bruto) }} · descuento {{ money($compra->descuento) }}</div>
            @endif
        </div>
        <div class="sgp-metric">
            <div class="lbl">Saldo</div>
            <div class="val {{ (float) $compra->saldo > 0 ? '' : 'txt-ok' }}">{{ money($compra->saldo) }}</div>
            @if ((float) $compra->acreditado > 0)
                <div class="text-muted-warm" style="font-size:.78rem">
                    pagado {{ money($compra->pagado) }} · en notas de crédito {{ money($compra->acreditado) }}</div>
            @endif
        </div>
        <div class="sgp-metric">
            <div class="lbl">Vencimiento</div>
            <div class="val" style="font-size:1rem">
                {{ $compra->vencimiento ? fecha($compra->vencimiento, 'd/m/Y') : '—' }}
            </div>
        </div>
        <div class="sgp-metric">
            <div class="lbl">Estado</div>
            <div class="val" style="font-size:1rem">{!! estado_badge($compra->estado) !!}</div>
        </div>
    </div>

    <div class="sgp-panel">
        <div class="table-responsive sgp-tabla-movil">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Producto</th><th>Categoría</th><th class="text-end">Cantidad</th>
                        <th class="text-end">Precio</th><th class="text-end">IVA</th><th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lineas as $l)
                        <tr>
                            <td class="sgp-movil-titulo" data-label="Producto">{{ $l->nombre }}</td>
                            <td class="text-muted-warm" data-label="Categoría">{{ $l->categoria }}</td>
                            <td class="text-end" data-label="Cantidad">{{ cant($l->cantidad) }} {{ $l->unidad_medida }}</td>
                            <td class="text-end" data-label="Precio">{{ money($l->precio_unitario) }}</td>
                            <td class="text-end text-muted-warm" data-label="IVA">
                                {{ (int) $l->tasa_iva > 0 ? (int) $l->tasa_iva . ' %' : 'exenta' }}</td>
                            <td class="text-end" data-label="Subtotal">{{ money($l->total_linea) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                {{-- **El pie dice lo que dice la factura del proveedor** (7.127.0):
                     los renglones, su descuento, el total, y el IVA que viene
                     incluido en el precio —desglosado por tasa, con el descuento
                     repartido proporcional, igual que en el comprobante de venta—.
                     Con un solo renglón de IVA no se abre por tasa: sería repetir
                     el mismo número dos veces. --}}
                <tfoot>
                    @if ((float) $compra->descuento > 0)
                        <tr>
                            <td colspan="5" class="text-end text-muted-warm">Renglones</td>
                            <td class="text-end text-muted-warm">{{ money($compra->bruto) }}</td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end text-muted-warm">Descuento de la factura</td>
                            <td class="text-end text-muted-warm">− {{ money($compra->descuento) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="5" class="text-end"><strong>Total</strong></td>
                        <td class="text-end"><strong class="txt-acento">{{ money($compra->total) }}</strong></td>
                    </tr>
                    @if ($impuestos)
                        @php
                            $sgpIvaTot = (float) $impuestos->iva_10 + (float) $impuestos->iva_5;
                            $sgpTasas = ((float) $impuestos->gravado_10 > 0 ? 1 : 0)
                                + ((float) $impuestos->gravado_5 > 0 ? 1 : 0)
                                + ((float) $impuestos->exentas > 0 ? 1 : 0);
                        @endphp
                        <tr class="sgp-compra-iva">
                            <td colspan="5" class="text-end text-muted-warm" style="font-size:.85rem">
                                IVA incluido
                                @if ($sgpTasas > 1)
                                    <span class="text-muted-warm">
                                        @if ((float) $impuestos->gravado_10 > 0) · 10 % sobre {{ money($impuestos->gravado_10) }}: {{ money($impuestos->iva_10) }} @endif
                                        @if ((float) $impuestos->gravado_5 > 0) · 5 % sobre {{ money($impuestos->gravado_5) }}: {{ money($impuestos->iva_5) }} @endif
                                        @if ((float) $impuestos->exentas > 0) · exentas {{ money($impuestos->exentas) }} @endif
                                    </span>
                                @endif
                            </td>
                            <td class="text-end text-muted-warm" style="font-size:.85rem">{{ money($sgpIvaTot) }}</td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>
    </div>

    {{-- **Las cuotas, con lo que le falta a cada una** (7.127.0). Se cargaban
         al registrar la compra y no se veían más: la ficha decía el saldo y
         nada de cómo estaba repartido, así que para saber cuál vence y cuánto
         había que ir a la base. Qué cuota cubre cada pago **se deduce por
         orden** —lo que entra va a la más vieja—, igual que
         `fn_compra_cuota_pendiente`. --}}
    @if ($cuotas)
        <div class="sgp-panel mt-3 sgp-compra-cuotas">
            <h2 class="sgp-form-titulo mb-2"><i class="bi bi-calendar2-week"></i> Cuotas</h2>
            <div class="table-responsive sgp-tabla-movil">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Cuota</th><th>Vence</th><th class="text-end">Monto</th>
                        <th class="text-end">Cubierto</th><th class="text-end">Falta</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($cuotas as $cu)
                            <tr class="{{ $cu->estado === 'pagada' ? 'text-muted-warm' : '' }}">
                                <td class="sgp-movil-titulo" data-label="Cuota">{{ (int) $cu->nro_cuota }}ª</td>
                                <td data-label="Vence">{{ fecha($cu->fecha_vencimiento, 'd/m/Y') }}</td>
                                <td class="text-end" data-label="Monto">{{ money($cu->monto) }}</td>
                                <td class="text-end" data-label="Cubierto">{{ money($cu->cubierto) }}</td>
                                <td class="text-end" data-label="Falta">{{ $cu->falta > 0 ? money($cu->falta) : '—' }}</td>
                                <td data-label="Estado">
                                    @if ($cu->estado === 'pagada')
                                        <span class="badge-estado e-ok">pagada</span>
                                    @elseif ($cu->vencida)
                                        <span class="badge-estado e-no">vencida</span>
                                    @elseif ($cu->estado === 'parcial')
                                        <span class="badge-estado e-warn">en parte</span>
                                    @elseif ((int) $cu->nro_cuota === (int) $compra->cuota_pendiente)
                                        <span class="badge-estado e-warn">la que sigue</span>
                                    @else
                                        <span class="badge-estado e-muted">pendiente</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- **Lo que ya se pagó de esta compra.**

         El pago queda ligado en `detalle_pago_proveedor` desde siempre y no se
         veía por ningún lado: la compra decía cuánto debe y no de dónde salía
         ese saldo, así que para saber si un pago entró había que ir a Tesorería
         y buscarlo entre todos los del proveedor.

         **El monto que se muestra es `monto_aplicado`, no el del pago**: un
         pago puede cubrir varias compras, y poner el total acá diría que a
         esta se le aplicó más de lo que se le aplicó. --}}
    <div class="sgp-panel mt-3">
        <h2 class="sgp-form-titulo mb-2"><i class="bi bi-cash-stack"></i> Pagos de esta compra</h2>

        @if ($pagos)
            <div class="table-responsive sgp-tabla-movil">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th>
                        <th class="text-end">Aplicado</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($pagos as $p)
                            <tr class="{{ $p->estado === 'Anulado' ? 'text-muted-warm' : '' }}">
                                <td class="sgp-movil-titulo" data-label="Fecha">{{ fecha($p->fecha) }}</td>
                                <td data-label="Medio">{{ $p->metodo }}</td>
                                <td class="text-muted-warm" data-label="Referencia">{{ $p->referencia ?: '—' }}</td>
                                <td class="text-end" data-label="Aplicado">{{ money($p->monto) }}</td>
                                <td data-label="Estado">{!! estado_badge($p->estado) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" class="text-end">Pagado</th>
                            <th class="text-end">
                                @php
                                    $pagado = 0;
                                    foreach ($pagos as $p) {
                                        if ($p->estado !== 'Anulado') { $pagado += (float) $p->monto; }
                                    }
                                @endphp
                                {{ money($pagado) }}
                            </th>
                            <th></th>
                        </tr>
                        @if ((float) $compra->acreditado > 0)
                            <tr>
                                <th colspan="3" class="text-end">En notas de crédito</th>
                                <th class="text-end">{{ money($compra->acreditado) }}</th>
                                <th></th>
                            </tr>
                        @endif
                        <tr>
                            <th colspan="3" class="text-end">Saldo</th>
                            <th class="text-end {{ (float) $compra->saldo > 0 ? 'txt-no' : 'txt-ok' }}">
                                {{ (float) $compra->saldo > 0 ? money($compra->saldo) : 'saldada' }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <p class="text-muted-warm mb-0" style="font-size:.86rem">
                Todavía no se pagó nada de esta compra.
                @if ((float) $compra->saldo > 0)
                    Se paga desde <a href="{{ route('facturacion.proveedores') }}">Tesorería → Pagos a proveedores</a>.
                @elseif ((float) $compra->acreditado > 0)
                    Quedó saldada con la nota de crédito del proveedor.
                @endif
            </p>
        @endif
    </div>

    {{-- **La nota de crédito del proveedor** (7.127.0, pedido del usuario:
         «una compra en sí es una factura, por lo que debe poder adjuntar su
         nota de crédito correspondiente»). Es el documento con el que el
         proveedor descuenta lo que cobró de más, lo devuelto o un descuento
         posterior. **Baja el saldo como un pago, pero no es un pago**: no
         salió de ninguna caja, así que no toca ningún arqueo — por eso va
         en la ficha de la compra y no en Tesorería. Se anula, no se borra. --}}
    <div class="sgp-panel mt-3 sgp-compra-notas">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="sgp-form-titulo mb-0"><i class="bi bi-receipt-cutoff"></i> Notas de crédito del proveedor<x-ayuda>Cuando el proveedor cobró de más, se le devolvió mercadería o hizo un descuento después, emite una nota de crédito sobre su factura. Se registra acá y baja lo que se le debe. No mueve stock: si hubo devolución, la salida va por Inventario → Movimientos.</x-ayuda></h2>
            @if ((int) $compra->id_estado_compra === 2 && (float) $compra->saldo > 0)
                <button class="btn btn-sm btn-rapido" type="button" data-bs-toggle="modal" data-bs-target="#modalNotaCompra">
                    <i class="bi bi-plus-lg"></i> Registrar una nota de crédito</button>
            @endif
        </div>

        @if ($notas)
            <div class="table-responsive sgp-tabla-movil">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Nota</th><th>Fecha</th><th>Motivo</th>
                        <th class="text-end">Monto</th><th>Documento</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($notas as $n)
                            <tr class="{{ (int) $n->activo ? '' : 'text-muted-warm' }}">
                                <td class="sgp-movil-titulo" data-label="Nota">
                                    {{ $n->nro_documento }}
                                    @unless ((int) $n->activo)
                                        <span class="badge-estado e-no" title="{{ $n->anulado_motivo }}">anulada</span>
                                    @endunless
                                </td>
                                <td data-label="Fecha">{{ fecha($n->fecha, 'd/m/Y') }}</td>
                                <td data-label="Motivo" class="text-muted-warm" style="font-size:.85rem">
                                    {{ $n->motivo }}
                                    <div style="font-size:.78rem">cargó {{ $n->cargo }} el {{ fecha($n->creado_en, 'd/m/Y') }}</div>
                                </td>
                                <td class="text-end" data-label="Monto">{{ money($n->monto) }}</td>
                                <td data-label="Documento">
                                    @if ($n->archivo)
                                        <a class="btn btn-sm btn-outline-neutro" target="_blank"
                                           href="{{ route('inventario.compra.nota_credito.archivo', ['id' => $n->id_nota]) }}">
                                            <i class="bi bi-paperclip"></i> Ver</a>
                                    @else
                                        <span class="text-muted-warm" style="font-size:.82rem">sin adjunto</span>
                                    @endif
                                </td>
                                <td class="text-end sgp-movil-acciones">
                                    @if ((int) $n->activo)
                                        <button class="btn btn-sm btn-outline-neutro sgp-btn-ico" type="button" title="Anular"
                                                data-bs-toggle="modal" data-bs-target="#modalAnNota{{ $n->id_nota }}">
                                            <i class="bi bi-x-circle"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted-warm mb-0" style="font-size:.86rem">Esta compra no tiene notas de crédito.</p>
        @endif
    </div>

    {{-- Los modales van FUERA de los paneles con tabla (7.87.4) --}}
    @if ((int) $compra->id_estado_compra === 2 && (float) $compra->saldo > 0)
        <div class="modal fade" id="modalNotaCompra" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" action="{{ route('inventario.compra.nota_credito') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="id_compra" value="{{ $compra->id_compra }}">
                        <div class="modal-header">
                            <h5 class="modal-title" style="font-size:1rem">Nota de crédito de {{ $compra->proveedor }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3" style="font-size:.88rem">
                                Sobre la factura {{ $compra->nro_factura_proveedor ?: 'de esta compra' }},
                                que hoy debe <strong class="txt-acento">{{ money($compra->saldo) }}</strong>.
                                La nota no puede pasarse de eso: por más sería un crédito a favor
                                que el sistema no aplica a otras compras.
                            </p>
                            <div class="row g-2">
                                <div class="col-7">
                                    <label class="form-label" for="ncNro">Nº de la nota *</label>
                                    <input class="form-control" id="ncNro" name="nro_documento" required
                                           data-solo="documento" inputmode="numeric" maxlength="30"
                                           placeholder="001-001-0000123" value="{{ old('nro_documento') }}">
                                </div>
                                <div class="col-5">
                                    <label class="form-label" for="ncFecha">Fecha *</label>
                                    <input class="form-control" id="ncFecha" name="fecha" type="date" required
                                           max="{{ ahora_bd('Y-m-d') }}" value="{{ old('fecha', ahora_bd('Y-m-d')) }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="ncMonto">Monto *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">{{ config('sgp.moneda') }}</span>
                                        <input class="form-control input-miles" id="ncMonto" name="monto" required
                                               data-min="0" data-max="{{ (int) $compra->saldo }}"
                                               value="{{ old('monto') }}">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="ncMotivo">Por qué la emitió *</label>
                                    <textarea class="form-control" id="ncMotivo" name="motivo" rows="2" required
                                              minlength="10" maxlength="300"
                                              placeholder="Devolvimos 2 cajas de guantes rotas / cobró de más el flete…">{{ old('motivo') }}</textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="ncArchivo">La nota, escaneada o en foto</label>
                                    <input class="form-control" id="ncArchivo" type="file" name="archivo"
                                           accept="image/png,image/jpeg,image/webp,application/pdf">
                                    <x-ayuda>PNG, JPG, WEBP o PDF, hasta 3 MB. Es el respaldo de que el proveedor descontó de verdad; si todavía no llegó, se puede registrar sin él.</x-ayuda>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-neutro" data-bs-dismiss="modal">Cancelar</button>
                            <button class="btn btn-acento">Registrar la nota</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @foreach ($notas as $n)
        @continue (! (int) $n->activo)
        <div class="modal fade" id="modalAnNota{{ $n->id_nota }}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" action="{{ route('inventario.compra.nota_credito.anular') }}">
                        @csrf
                        <input type="hidden" name="id_nota" value="{{ $n->id_nota }}">
                        <div class="modal-header">
                            <h5 class="modal-title" style="font-size:1rem">Anular la nota {{ $n->nro_documento }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p style="font-size:.88rem">La compra vuelve a deber {{ money($n->monto) }}. La nota queda
                                en la ficha, marcada como anulada y con el motivo.</p>
                            <label class="form-label" for="anNota{{ $n->id_nota }}">Motivo *</label>
                            <textarea class="form-control" id="anNota{{ $n->id_nota }}" name="motivo" rows="2"
                                      required minlength="10" maxlength="200"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-neutro" data-bs-dismiss="modal">Cancelar</button>
                            <button class="btn btn-acento">Anular</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection
