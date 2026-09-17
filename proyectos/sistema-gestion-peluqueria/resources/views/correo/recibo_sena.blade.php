{{-- El recibo de la seña, por correo, con el aviso de que la cita quedó
     confirmada. El contenido vive en `_recibo_sena_cuerpo`, que también
     dibuja el PDF adjunto: los dos tienen que decir lo mismo. --}}
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#F2FBF9;font-family:Segoe UI,Arial,sans-serif;color:#0F4C43">
    <div style="max-width:560px;margin:24px auto;background:#FFFFFF;border-radius:12px;overflow:hidden;
                border:1px solid #CEEAE5">

        <div style="background:#0F4C43;padding:18px 24px">
            <span style="color:#7AC3B7;font-size:1.05rem;font-weight:bold">{{ $r['salon'] }}</span>
        </div>

        <div style="padding:24px">
            <h1 style="font-size:18px;font-weight:500;margin:0 0 4px">
                {{ $r['confirmada'] ? 'Tu cita quedó confirmada' : 'Recibimos tu seña' }}
            </h1>
            <p style="margin:0 0 18px;color:#375B59;font-size:13px">
                Recibo de seña · cita Nº {{ (int) $r['cita']->id_cita }} · {{ fecha(ahora_bd(), 'd/m/Y H:i') }}
            </p>

            @include('correo._recibo_sena_cuerpo', ['r' => $r])

            <p style="margin:22px 0 0;text-align:center">
                <a href="{{ $r['url'] }}" style="background:#1A6B5F;color:#FFFFFF;text-decoration:none;
                   padding:11px 20px;border-radius:8px;font-weight:bold;display:inline-block">
                    Ver, reprogramar o cancelar mi cita</a>
            </p>
            <p style="color:#375B59;font-size:12px;margin:12px 0 0;text-align:center">
                Si el botón no funciona, copiá este enlace:<br>{{ $r['url'] }}
            </p>

            <p style="color:#375B59;font-size:12px;margin:22px 0 0;border-top:1px solid #CEEAE5;padding-top:14px">
                El recibo va también adjunto en PDF, por si tenés que mostrarlo. Si algo no coincide con lo
                que pagaste, escribinos y lo revisamos.
            </p>
        </div>
    </div>
</body>
</html>
