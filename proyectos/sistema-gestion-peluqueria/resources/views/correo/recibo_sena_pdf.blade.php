{{-- El recibo de la seña en PDF, adjunto al correo. Lo dibuja Dompdf, que no
     lee `app.css` ni variables CSS: los colores van escritos, como en el PDF
     de la factura del portal. El contenido es el mismo partial que el cuerpo
     del correo. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0F4C43; font-size: 12px; }
        .cab { background: #0F4C43; color: #FFFFFF; padding: 10px 14px; margin: 0 0 14px; }
        .cab .salon { color: #7AC3B7; font-weight: bold; font-size: 15px; }
        .cab .que { font-size: 11px; color: #E1FAF5; margin-top: 2px; }
        h1 { font-size: 16px; font-weight: normal; margin: 0 0 10px; }
    </style>
</head>
<body>
    <div class="cab">
        <div class="salon">{{ $r['salon'] }}</div>
        <div class="que">Recibo de seña · cita Nº {{ (int) $r['cita']->id_cita }} · emitido el {{ fecha(ahora_bd(), 'd/m/Y H:i') }}</div>
    </div>

    <h1>{{ $r['confirmada'] ? 'Tu cita quedó confirmada' : 'Recibimos tu seña' }}</h1>

    @include('correo._recibo_sena_cuerpo', ['r' => $r])
</body>
</html>
