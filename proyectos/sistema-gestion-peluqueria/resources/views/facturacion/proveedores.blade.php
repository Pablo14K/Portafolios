@extends('layout.app')

@section('titulo', 'Pagos a proveedores')

@section('contenido')
    <x-encabezado sub="Las compras confirmadas que todavía se deben. <strong>Un pago en efectivo no puede superar lo que hay en el cajón</strong>; los pagos por banco o transferencia no se frenan, porque no salen de ahí." />

    {{-- **Sin caja abierta se paga igual por banco** (7.121.0): la transferencia
         sale de la cuenta bancaria, que es su propia caja. Lo que la caja
         cerrada impide es el pago en efectivo, y el aviso lo dice así. --}}
    @if (! $caja)
        <div class="alert alert-warning">
            La caja está cerrada: se puede pagar <strong>por transferencia</strong> —sale de la cuenta
            bancaria del local de la compra—, pero para pagar en efectivo hay que abrirla primero.
        </div>
    @endif

    <div class="sgp-panel mb-3">
        <h2 class="sgp-form-titulo mb-2"><i class="bi bi-cash-stack"></i> Cuentas por pagar</h2>
        <div class="table-responsive sgp-tabla-movil">
            <table class="table align-middle mb-0">
                <thead>
                    <tr><th>Proveedor</th><th>Compra</th><th>Vencimiento</th>
                        <th class="text-end sgp-movil-oculto">Total</th><th class="text-end">Saldo</th><th class="text-end">Pagar</th></tr>
                </thead>
                <tbody>
                    @forelse ($cuentas as $c)
                        <tr>
                            <td class="sgp-movil-titulo" data-label="Proveedor">{{ $c->proveedor }}</td>
                            <td class="text-muted-warm" data-label="Compra">
                                {{ fecha($c->fecha, 'd/m/Y') }}
                                @if ($c->nro_factura_proveedor ?? null) · {{ $c->nro_factura_proveedor }} @endif
                                {{-- **A crédito y en cuántas cuotas** (7.127.0): la fila decía
                                     el total y nada de cómo se pactó pagarlo, así que una
                                     compra en tres cuotas se leía —y se pagaba— como una sola. --}}
                                <div class="sgp-pago-condicion" style="font-size:.8rem">
                                    @if ((int) $c->cuotas > 0)
                                        {{ $c->condicion }} en {{ (int) $c->cuotas }} cuota{{ (int) $c->cuotas === 1 ? '' : 's' }}
                                        @if ($c->cuota_pendiente)
                                            · va la <strong>{{ (int) $c->cuota_pendiente }}ª</strong>
                                        @endif
                                    @elseif ((int) $c->dias_credito > 0)
                                        {{ $c->condicion }} a {{ (int) $c->dias_credito }} días
                                    @else
                                        {{ $c->condicion }}
                                    @endif
                                </div>
                            </td>
                            <td data-label="Vencimiento">
                                @if ($c->vencida)
                                    <span class="badge-estado e-no">vencida</span>
                                @endif
                                <span class="text-muted-warm">
                                    {{ $c->vencimiento ? fecha($c->vencimiento, 'd/m/Y') : '—' }}</span>
                            </td>
                            <td class="text-end sgp-movil-oculto" data-label="Total">
                                {{ money($c->total) }}
                                @if ((float) $c->descuento > 0)
                                    <div class="text-muted-warm" style="font-size:.78rem"
                                         title="Renglones {{ money($c->bruto) }} menos el descuento de la factura">
                                        con {{ money($c->descuento) }} de descuento</div>
                                @endif
                            </td>
                            <td class="text-end" data-label="Saldo">
                                <strong class="txt-no">{{ money($c->saldo) }}</strong>
                                @if ($c->cuota_pendiente && $c->cuota_falta !== null && (float) $c->cuota_falta < (float) $c->saldo)
                                    <div class="text-muted-warm" style="font-size:.78rem">
                                        cuota: {{ money($c->cuota_falta) }}</div>
                                @endif
                                @if ((float) $c->acreditado > 0)
                                    <div class="text-muted-warm" style="font-size:.78rem">
                                        nota de crédito: {{ money($c->acreditado) }}</div>
                                @endif
                            </td>
                            <td class="text-end sgp-movil-acciones">
                                @if ($caja || ($bancosPorCompra[$c->id_compra] ?? []))
                                    <button class="btn btn-sm btn-acento" data-bs-toggle="modal"
                                            data-bs-target="#modalPago{{ $c->id_compra }}">
                                        <i class="bi bi-cash-coin"></i> Pagar</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="sgp-vacio">
                                    <i class="bi bi-check-circle"></i>
                                    <div class="t">No hay deudas pendientes con proveedores.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="sgp-panel">
        <h2 class="sgp-form-titulo mb-2"><i class="bi bi-clock-history"></i> Pagos registrados</h2>
        <div class="table-responsive sgp-tabla-movil">
            <table class="table align-middle mb-0">
                <thead>
                    <tr><th>Fecha</th><th>Proveedor</th>
                        <th class="text-end">Monto</th><th>Estado</th><th class="text-end"></th></tr>
                </thead>
                <tbody>
                    @forelse ($pagos as $p)
                        <tr>
                            <td class="sgp-movil-titulo" data-label="Fecha">{{ fecha($p->fecha) }}</td>
                            <td data-label="Proveedor">{{ $p->proveedor }}</td>
                            <td class="text-end" data-label="Monto">{{ money($p->monto) }}</td>
                            <td data-label="Estado">{!! estado_badge($p->estado) !!}</td>
                            <td class="text-end sgp-movil-acciones" style="white-space:nowrap">
                                <button class="sgp-btn-detalle" data-bs-toggle="collapse"
                                        data-bs-target="#detPagP{{ $p->id_pago_proveedor }}" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i> Detalle
                                </button>
                                @if ($p->estado !== 'Anulado')
                                    <button class="btn btn-sm btn-outline-neutro" title="Anular"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalAnPago{{ $p->id_pago_proveedor }}">
                                        <i class="bi bi-x-circle"></i></button>
                                @endif
                            </td>
                        </tr>
                        <tr class="sgp-fila-detalle">
                            <td colspan="5">
                                <div class="collapse" id="detPagP{{ $p->id_pago_proveedor }}">
                                    <div class="sgp-det-cuerpo">
                                        <div class="sgp-det-grid">
                                            {{-- **Qué compra pagó.** El pago SÍ queda ligado a la
                                                 compra —`sp_pagar_compra` escribe el detalle— pero acá
                                                 no se veía: con el mismo proveedor repetido no había
                                                 forma de saber cuál de las cuatro compras se pagó.
                                                 Un pago puede cubrir varias, y por eso salen todas. --}}
                                            <div>
                                                <dt>Compra que pagó</dt>
                                                <dd>
                                                    {{ $p->compras ?: '—' }}
                                                    {{-- **El papel que llega después del pago.** La compra
                                                         saldada ya no está en «Cuentas por pagar», así que
                                                         éste es el único lugar desde donde se la puede
                                                         alcanzar. --}}
                                                    @if ($p->compra_sin_factura && $p->estado !== 'Anulado')
                                                        <button type="button" class="btn btn-sm btn-rapido mt-1"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modalNroFac{{ $p->compra_sin_factura }}">
                                                            <i class="bi bi-receipt"></i> Cargar la factura</button>
                                                    @endif
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>Medio</dt>
                                                <dd>{{ $p->metodo }}</dd>
                                            </div>
                                            <div>
                                                <dt>Referencia</dt>
                                                <dd>{{ $p->referencia ?: '—' }}</dd>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted-warm py-3">Todavía no hay pagos registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Cargar el número de la factura de una compra ya pagada. --}}
    @php $yaPuesto = []; @endphp
    @foreach ($pagos as $p)
        @if ($p->compra_sin_factura && ! in_array($p->compra_sin_factura, $yaPuesto, true))
            @php $yaPuesto[] = $p->compra_sin_factura; @endphp
            <div class="modal fade" id="modalNroFac{{ $p->compra_sin_factura }}" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <form method="post" action="{{ route('inventario.compra.factura') }}" class="modal-content">
                        @csrf
                        <input type="hidden" name="id_compra" value="{{ $p->compra_sin_factura }}">
                        <input type="hidden" name="desde" value="pagos">
                        <div class="modal-header">
                            <h5 class="modal-title" style="font-size:1rem">
                                <i class="bi bi-receipt"></i> Factura del proveedor</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted-warm" style="font-size:.86rem">
                                {{ $p->proveedor }} · {{ $p->compras }}
                            </p>
                            <label class="form-label" for="nf{{ $p->compra_sin_factura }}">Número</label><x-ayuda>Es el número del papel que entregó el proveedor. Queda pegado a la compra, así que el pago y la factura se pueden rastrear juntos.</x-ayuda>
                            <input class="form-control" id="nf{{ $p->compra_sin_factura }}"
                                   name="nro_factura_proveedor" required maxlength="30"
                                   placeholder="001-001-0001234">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-neutro" data-bs-dismiss="modal">Cancelar</button>
                            <button class="btn btn-acento"><i class="bi bi-check2"></i> Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach

    {{-- Un modal de pago por cuenta pendiente. Con la caja cerrada se dibuja
         igual si el local de la compra tiene cuenta bancaria: el pago por
         transferencia no necesita el cajón (7.121.0). --}}
    @foreach ($cuentas as $c)
        @if ($caja || ($bancosPorCompra[$c->id_compra] ?? []))
            <div class="modal fade" id="modalPago{{ $c->id_compra }}" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" action="{{ route('facturacion.pagar_proveedor') }}">
                            @csrf
                            <input type="hidden" name="id_compra" value="{{ $c->id_compra }}">
                            <div class="modal-header">
                                <h5 class="modal-title" style="font-size:1rem">
                                    Pagar a {{ $c->proveedor }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                {{-- **El número de la factura del proveedor se carga
                                     acá si todavía no está.** El papel casi siempre
                                     llega con el pago, y una vez saldada la compra
                                     desaparece de esta lista: desde ahí ya no había
                                     dónde vincularlo. --}}
                                @unless (trim((string) ($c->nro_factura_proveedor ?? '')) !== '')
                                    <div class="mb-3">
                                        <label class="form-label" for="nfp{{ $c->id_compra }}">
                                            Nº de factura del proveedor
                                            <span class="text-muted-warm">(opcional)</span></label>
                                        <input class="form-control" id="nfp{{ $c->id_compra }}"
                                               name="nro_factura_proveedor" maxlength="30"
                                               placeholder="001-001-0001234">
                                        <x-ayuda>Si el papel vino con el pago, cargalo ahora: después la compra sale de esta lista.</x-ayuda>
                                    </div>
                                @endunless

                                {{-- **La cuenta de la factura, entera** (7.127.0). El modal decía
                                     «saldo: X» y nada más: ni que la compra era a crédito, ni
                                     cuánto era la cuota, ni si la factura traía descuento. Es el
                                     reflejo de la factura del proveedor, y quien paga tiene que
                                     poder cotejarla con el papel. --}}
                                @php
                                    $sgpCuotas = $cuotasPorCompra[$c->id_compra] ?? [];
                                    $sgpIva = $ivaPorCompra[$c->id_compra] ?? null;
                                    $sgpPendiente = null;
                                    foreach ($sgpCuotas as $sgpCu) {
                                        if ($sgpCu->estado !== 'pagada') { $sgpPendiente = $sgpCu; break; }
                                    }
                                    // Lo que se propone: la cuota que vence, no el saldo entero.
                                    $sgpPropuesto = $sgpPendiente ? (float) $sgpPendiente->falta : (float) $c->saldo;
                                @endphp
                                <table class="table table-sm mb-2 sgp-pago-cuenta" style="font-size:.86rem">
                                    <tbody>
                                        @if ((float) $c->descuento > 0)
                                            <tr><td class="text-muted-warm">Renglones</td><td class="text-end">{{ money($c->bruto) }}</td></tr>
                                            <tr><td class="text-muted-warm">Descuento de la factura</td><td class="text-end">− {{ money($c->descuento) }}</td></tr>
                                        @endif
                                        <tr><td>Total de la factura</td><td class="text-end"><strong>{{ money($c->total) }}</strong></td></tr>
                                        @if ($sgpIva)
                                            <tr><td class="text-muted-warm">IVA incluido
                                                @if ((float) $sgpIva->iva_10 > 0 && (float) $sgpIva->iva_5 > 0)
                                                    (10 %: {{ money($sgpIva->iva_10) }} · 5 %: {{ money($sgpIva->iva_5) }})
                                                @elseif ((float) $sgpIva->iva_5 > 0 && (float) $sgpIva->iva_10 <= 0)
                                                    (5 %)
                                                @endif
                                                </td>
                                                <td class="text-end text-muted-warm">{{ money((float) $sgpIva->iva_10 + (float) $sgpIva->iva_5) }}</td></tr>
                                        @endif
                                        @if ((float) $c->pagado > 0)
                                            <tr><td class="text-muted-warm">Ya pagado</td><td class="text-end">− {{ money($c->pagado) }}</td></tr>
                                        @endif
                                        @if ((float) $c->acreditado > 0)
                                            <tr><td class="text-muted-warm">Notas de crédito del proveedor</td><td class="text-end">− {{ money($c->acreditado) }}</td></tr>
                                        @endif
                                        <tr><td><strong>Saldo</strong></td><td class="text-end"><strong class="txt-acento">{{ money($c->saldo) }}</strong></td></tr>
                                    </tbody>
                                </table>

                                @if ($sgpCuotas)
                                    <div class="mb-3 sgp-pago-cuotas" style="font-size:.84rem">
                                        <div class="text-muted-warm mb-1">
                                            <i class="bi bi-calendar2-week"></i> {{ $c->condicion }} en {{ count($sgpCuotas) }} cuotas
                                        </div>
                                        @foreach ($sgpCuotas as $sgpCu)
                                            <div class="d-flex justify-content-between gap-2 {{ $sgpCu->estado === 'pagada' ? 'text-muted-warm' : '' }}">
                                                <span>
                                                    {{ (int) $sgpCu->nro_cuota }}ª · vence {{ fecha($sgpCu->fecha_vencimiento, 'd/m/Y') }}
                                                    @if ($sgpCu->estado === 'pagada')
                                                        <span class="badge-estado e-ok">pagada</span>
                                                    @elseif ($sgpCu->vencida)
                                                        <span class="badge-estado e-no">vencida</span>
                                                    @elseif ($sgpCu->estado === 'parcial')
                                                        <span class="badge-estado e-warn">falta {{ money($sgpCu->falta) }}</span>
                                                    @elseif ($sgpPendiente && (int) $sgpCu->nro_cuota === (int) $sgpPendiente->nro_cuota)
                                                        <span class="badge-estado e-warn">la que sigue</span>
                                                    @endif
                                                </span>
                                                <span>{{ money($sgpCu->monto) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <p class="mb-3" style="font-size:.82rem">
                                    @if ($sgpPendiente)
                                        Viene propuesta la <strong>{{ (int) $sgpPendiente->nro_cuota }}ª cuota</strong>
                                        ({{ money($sgpPendiente->falta) }}{{ $sgpPendiente->estado === 'parcial' ? ', lo que le falta' : '' }}).
                                        Podés pagar más —lo de más va a la cuota siguiente— o menos: lo que quede sigue pendiente.
                                    @else
                                        {{-- **El pago parcial ya se podía y no se decía.** --}}
                                        Viene propuesto el saldo entero. Podés pagar menos: lo que quede
                                        sigue como saldo pendiente de esta compra.
                                    @endif
                                </p>
                                {{-- **De qué cajón sale la plata.** Con dos abiertos,
                                     tomar «el último» deja el egreso en el arqueo de
                                     otra persona y se descubre al cerrar. Son los del
                                     local DE LA COMPRA, que es de donde sale. --}}
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label">Medio de pago</label>
                                        {{-- El `data-tipo` lo lee app.js: con efectivo pregunta
                                             de qué caja sale, con transferencia de qué cuenta
                                             (7.121.0). La transferencia es una opción como
                                             cualquier otra y descuenta de la cuenta elegida;
                                             no hace falta caja abierta para pagar por banco. --}}
                                        <select class="form-select" name="id_metodo_pago" required>
                                            @foreach ($metodos as $m)
                                                <option value="{{ $m->id_metodo_pago }}"
                                                        data-tipo="{{ $m->tipo }}">{{ $m->nombre }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Monto</label>
                                        <div class="input-group">
                                            <span class="input-group-text">{{ config('sgp.moneda') }}</span>
                                            <input class="form-control input-miles" name="monto" data-min="0"
                                                   data-max="{{ (int) $c->saldo }}"
                                                   value="{{ monto_input($sgpPropuesto) }}" required>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-3">
                                        {{-- **De qué caja sale el efectivo.** Con dos abiertas,
                                             tomar «el último» deja el egreso en el arqueo de
                                             otra persona y se descubre al cerrar. Son los del
                                             local DE LA COMPRA, que es de donde sale. --}}
                                        <div data-caja-bloque>
                                        @include('facturacion._caja_elegir', [
                                            'cajas' => $cajasPorCompra[$c->id_compra] ?? [],
                                            'uid' => 'Prov' . $c->id_compra,
                                            'rotulo' => '¿De qué caja sale la plata?',
                                            'ayuda' => 'El egreso entra al arqueo de esa caja. Son las abiertas en '
                                                . $c->sucursal . ', que es el local de la compra.',
                                        ])
                                        </div>

                                        {{-- **Y si NO sale del cajón, de qué cuenta sale.** Una
                                             transferencia no toca la caja —por eso `fn_caja_saldo`
                                             no la resta—: descuenta de la cuenta bancaria del
                                             local de la compra, por lo mismo que los cajones. --}}
                                        @include('facturacion._cuenta_elegir', [
                                            'cuentas' => $bancosPorCompra[$c->id_compra] ?? [],
                                            'uid' => 'Prov' . $c->id_compra,
                                        ])
                                    </div>
                                    <div class="col-12">
                                        {{-- **No es la factura del proveedor.** Se leía
                                             como que el sistema la pedía dos veces: acá
                                             va el comprobante de ESTE pago —el número
                                             que devuelve el banco al transferir, o el
                                             recibo que firma el proveedor—, que es lo
                                             que permite rastrearlo el día que reclamen
                                             que no se pagó. En efectivo casi nunca hay
                                             ninguno, y por eso es opcional. --}}
                                        <label class="form-label" for="refp{{ $c->id_compra }}">
                                            Comprobante de este pago
                                            <span class="text-muted-warm">(opcional)</span></label>
                                        <input class="form-control" id="refp{{ $c->id_compra }}"
                                               name="referencia" maxlength="60"
                                               placeholder="Nº de transferencia, recibo…">
                                        <x-ayuda>Es el respaldo de la salida de plata, no la factura del proveedor: esa es la de arriba y se carga una sola vez.</x-ayuda>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-neutro" data-bs-dismiss="modal">Cancelar</button>
                                <button class="btn btn-acento">Registrar el pago</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    @foreach ($pagos as $p)
        @continue ($p->estado === 'Anulado')
        <div class="modal fade" id="modalAnPago{{ $p->id_pago_proveedor }}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" action="{{ route('facturacion.anular_pago_proveedor') }}">
                        @csrf
                        <input type="hidden" name="id_pago_proveedor" value="{{ $p->id_pago_proveedor }}">
                        <div class="modal-header">
                            <h5 class="modal-title" style="font-size:1rem">
                                Anular el pago de {{ money($p->monto) }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted-warm" style="font-size:.85rem">
                                El saldo de la compra vuelve a subir y el egreso deja de descontarse de la caja.
                            </p>
                            <label class="form-label" for="motPp{{ $p->id_pago_proveedor }}">Motivo *</label>
                            <input class="form-control" id="motPp{{ $p->id_pago_proveedor }}"
                                   name="motivo" required maxlength="200">
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
