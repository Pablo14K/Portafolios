{{-- Aviso para el equipo del salón, no para la clienta. Estilos en línea: los
     clientes de correo descartan las hojas de estilo. --}}
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#F2FBF9;font-family:Segoe UI,Arial,sans-serif;color:#0F4C43">
    <div style="max-width:520px;margin:24px auto;background:#FFFFFF;border-radius:12px;overflow:hidden;
                border:1px solid #CEEAE5">

        <div style="background:#0F4C43;padding:18px 24px">
            <span style="color:#7AC3B7;font-size:1.05rem;font-weight:bold">{{ config('app.name') }}</span>
            <span style="color:#CEEAE5;font-size:.78rem;float:right;padding-top:4px">aviso interno</span>
        </div>

        <div style="padding:24px">
            <h1 style="font-size:18px;font-weight:500;margin:0 0 14px">{{ $titulo }}</h1>

            @if ($paraQuien)
                <p style="margin:0 0 10px">Hola {{ $paraQuien }},</p>
            @endif

            <p style="margin:0 0 10px">{{ $mensaje }}</p>

            @if ($url)
                <p style="text-align:center;margin:22px 0">
                    <a href="{{ $url }}" style="background:#1A6B5F;color:#FFFFFF;text-decoration:none;
                       padding:11px 20px;border-radius:8px;font-weight:bold;display:inline-block">
                        {{ $textoBoton }}</a>
                </p>

                <p style="color:#375B59;font-size:12px">
                    Si el botón no funciona, copiá este enlace:<br>{{ $url }}
                </p>
            @endif

            <p style="color:#375B59;font-size:13px">
                Te llega porque tu rol administra esa parte del sistema. No es un aviso para la clienta.
            </p>
        </div>

        <div style="background:#F2FBF9;padding:12px 24px;color:#375B59;font-size:11px;text-align:center">
            {{ config('app.name') }} · Luque, Paraguay
        </div>
    </div>
</body>
</html>
