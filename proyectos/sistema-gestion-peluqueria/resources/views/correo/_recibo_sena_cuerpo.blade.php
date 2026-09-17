{{-- El recibo de la seña: lo que comparten el cuerpo del correo y el PDF.

     **Un solo partial para los dos**, por lo de siempre: escritos aparte, la
     clienta termina con un correo que dice una cosa y un PDF que dice otra
     del mismo pago. Los estilos van en línea porque los clientes de correo
     descartan las hojas de estilo, y Dompdf no lee `app.css`.

     Recibe `$r`, lo que arma `Sena::recibo()`. --}}
@php
    $c = $r['cita'];
    $quien = $c->para_otra_persona && $c->nombre_para ? $c->nombre_para : $c->cliente;
@endphp

<p style="margin:0 0 14px;font-size:14px">
    Hola {{ $c->cliente }}:
    @if ($r['confirmada'])
        recibimos tu seña y <strong>tu cita queda confirmada</strong>. El horario ya es tuyo.
    @else
        recibimos {{ money($r['recibido']) }} de seña por tu cita. Para confirmar el horario
        faltan <strong>{{ money($r['requerida'] - $r['senado']) }}</strong>: hasta entonces la reserva
        sigue sin confirmar.
    @endif
</p>

<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:14px">
    <tr>
        <td style="padding:4px 0;color:#375B59;width:38%">Cita</td>
        <td style="padding:4px 0"><strong>{{ fecha($c->fecha_hora, 'd/m/Y') }}</strong> a las
            <strong>{{ fecha($c->fecha_hora, 'H:i') }}</strong></td>
    </tr>
    <tr>
        <td style="padding:4px 0;color:#375B59">Dónde</td>
        <td style="padding:4px 0">{{ $c->sucursal }}@if ($c->direccion) · {{ $c->direccion }}@endif</td>
    </tr>
    <tr>
        <td style="padding:4px 0;color:#375B59">Con</td>
        <td style="padding:4px 0">{{ $c->profesional }}</td>
    </tr>
    @if ($quien !== $c->cliente)
        <tr>
            <td style="padding:4px 0;color:#375B59">Para</td>
            <td style="padding:4px 0">{{ $quien }}</td>
        </tr>
    @endif
    @if ((int) $c->personas > 1)
        <tr>
            <td style="padding:4px 0;color:#375B59">Personas</td>
            <td style="padding:4px 0">{{ (int) $c->personas }}</td>
        </tr>
    @endif
</table>

<table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead>
        <tr>
            <th style="text-align:left;padding:6px 0;border-bottom:1px solid #CEEAE5;color:#375B59;font-weight:500">Servicio</th>
            <th style="text-align:right;padding:6px 0;border-bottom:1px solid #CEEAE5;color:#375B59;font-weight:500">Precio</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($r['servicios'] as $s)
            <tr>
                <td style="padding:5px 0;border-bottom:1px solid #E1FAF5">{{ $s->nombre }}</td>
                <td style="padding:5px 0;border-bottom:1px solid #E1FAF5;text-align:right">{{ money($s->precio) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:10px">
    <tr>
        <td style="padding:4px 0;color:#375B59">Total de la cita</td>
        <td style="padding:4px 0;text-align:right">{{ money($r['total']) }}</td>
    </tr>
    @foreach ($r['cobros'] as $co)
        <tr>
            <td style="padding:4px 0;color:#375B59">Seña recibida · {{ $co->metodo }} · {{ fecha($co->fecha, 'd/m/Y H:i') }}</td>
            <td style="padding:4px 0;text-align:right">− {{ money($co->monto) }}</td>
        </tr>
    @endforeach
    @if ($r['senado'] - $r['recibido'] > 0.005)
        <tr>
            <td style="padding:4px 0;color:#375B59">Señas anteriores</td>
            <td style="padding:4px 0;text-align:right">− {{ money($r['senado'] - $r['recibido']) }}</td>
        </tr>
    @endif
    <tr>
        <td style="padding:8px 0;font-weight:bold;font-size:16px;border-top:1px solid #CEEAE5">Queda por pagar al terminar</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold;font-size:16px;color:#1A6B5F;border-top:1px solid #CEEAE5">
            {{ money($r['saldo']) }}</td>
    </tr>
</table>

<p style="color:#375B59;font-size:12px;margin:18px 0 0">
    Este recibo es la constancia de que la seña entró. No es un comprobante fiscal: el comprobante
    se emite al terminar la atención, y ahí la seña se descuenta sola del total.
    La seña no se devuelve si no venís a la cita.
</p>
