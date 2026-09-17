{{--
    El comprobante en papel, para Dompdf.

    Los colores van escritos y no como `var(--acento)`: Dompdf no lee `app.css` ni
    resuelve variables CSS, así que una hoja de estilos compartida saldría en
    negro sobre blanco. Son los mismos valores de la identidad — el verde agua
    del texto y de la regla bajo el encabezado, y el rojo semántico del sello
    de acreditada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $f->nro }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #0F4C43; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .tenue { color: #375B59; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { text-align: left; border-bottom: 2px solid #1A6B5F; padding: 5px 4px; font-size: 10px; }
        td { padding: 5px 4px; border-bottom: 1px solid #CEEAE5; }
        .der { text-align: right; }
        .tot { font-size: 14px; font-weight: bold; }
        .sello { border: 2px solid #993535; color: #993535; padding: 6px 10px;
                 font-weight: bold; margin: 0 0 10px; }
    </style>
</head>
<body>
    @include('portal._factura_cuerpo', ['papel' => true])

    <p class="tenue" style="margin-top:24px">
        Comprobante emitido por {{ $salon }}. Documento de respaldo del cobro.
    </p>
</body>
</html>
