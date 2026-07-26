<?php
// =====================================================================
//  Cliente SMTP mínimo en PHP puro (STARTTLS + AUTH LOGIN)
//  Suficiente para enviar los correos de verificación y recuperación
//  a través de Gmail (smtp.gmail.com:587) sin librerías externas.
// =====================================================================
declare(strict_types=1);

require_once __DIR__ . '/mail_config.php';

/**
 * Envía un correo HTML. Devuelve true si el servidor lo aceptó.
 * Si falla, deja el motivo en $error (por referencia).
 */
function enviar_correo(string $para, string $asunto, string $html, ?string &$error = null): bool
{
    $error = null;
    $host = MAIL_HOST;
    $port = MAIL_PORT;

    $fp = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 15);
    if (!$fp) {
        $error = "No se pudo conectar a $host:$port ($errstr)";
        return false;
    }
    stream_set_timeout($fp, 15);

    $leer = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // La última línea de una respuesta tiene un espacio tras el código
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        return [$code, trim($data)];
    };
    $decir = function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    try {
        [$c] = $leer();                       // saludo 220
        if ($c !== 220) throw new RuntimeException('Saludo SMTP inesperado.');

        $decir('EHLO localhost'); $leer();

        if (MAIL_ENCRYPTION === 'tls') {
            $decir('STARTTLS');
            [$c] = $leer();
            if ($c !== 220) throw new RuntimeException('STARTTLS rechazado.');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            }
            if (!stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new RuntimeException('No se pudo iniciar TLS.');
            }
            $decir('EHLO localhost'); $leer();
        }

        $decir('AUTH LOGIN');
        [$c] = $leer();
        if ($c !== 334) throw new RuntimeException('El servidor no aceptó AUTH LOGIN.');
        $decir(base64_encode(MAIL_USERNAME));
        [$c] = $leer();
        if ($c !== 334) throw new RuntimeException('Usuario rechazado.');
        $decir(base64_encode(MAIL_PASSWORD));
        [$c, $m] = $leer();
        if ($c !== 235) throw new RuntimeException('Autenticación fallida: ' . $m);

        $decir('MAIL FROM:<' . MAIL_FROM_EMAIL . '>');
        [$c] = $leer();
        if ($c !== 250) throw new RuntimeException('MAIL FROM rechazado.');

        $decir('RCPT TO:<' . $para . '>');
        [$c, $m] = $leer();
        if ($c !== 250 && $c !== 251) throw new RuntimeException('Destinatario rechazado: ' . $m);

        $decir('DATA');
        [$c] = $leer();
        if ($c !== 354) throw new RuntimeException('DATA rechazado.');

        $fromHeader = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM_EMAIL . '>';
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
        ];
        // Escapar líneas que empiezan con "." (transparencia SMTP)
        $cuerpo = chunk_split(base64_encode($html));
        $mensaje = implode("\r\n", $headers) . "\r\n\r\n" . $cuerpo . "\r\n.";
        $decir($mensaje);
        [$c, $m] = $leer();
        if ($c !== 250) throw new RuntimeException('El servidor no aceptó el mensaje: ' . $m);

        $decir('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        @fclose($fp);
        return false;
    }
}

// Plantilla HTML simple con la identidad visual
function plantilla_correo(string $titulo, string $cuerpoHtml): string
{
    return '<div style="font-family:Arial,sans-serif;background:#F7F5F2;padding:24px">'
        . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #E0DDD8;border-radius:12px;overflow:hidden">'
        . '<div style="background:#0D0D0D;padding:16px 22px;color:#C9A84C;font-size:18px;font-weight:bold">Peluquería Luque</div>'
        . '<div style="padding:22px;color:#2C2C2A">'
        . '<h2 style="color:#1A1A1A;font-size:18px;margin:0 0 12px">' . htmlspecialchars($titulo) . '</h2>'
        . $cuerpoHtml
        . '</div>'
        . '<div style="padding:14px 22px;color:#888;font-size:12px;border-top:1px solid #E0DDD8">Sistema de gestión · Peluquería Luque</div>'
        . '</div></div>';
}

// Genera un código numérico de 6 dígitos
function generar_codigo(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
