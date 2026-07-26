<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Servicio SMTP/email. Envia correos reales o genera .eml de evidencia segun configuracion.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Service;

use App\Repository\EmailLogRepository;
use App\Support\FileStore;
use RuntimeException;

/**
 * Comentario de codigo: clase MailService. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class MailService
{
    public function __construct(
        private array $config,
        private EmailLogRepository $emailLogRepository
    ) {
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function send(array $job): void
    {
        $transport = (string) ($this->config['transport'] ?? 'smtp');
        $emailDir = rtrim((string) $this->config['email_dir'], '/');
        FileStore::ensureDir($emailDir);

        $fromEmail = (string) ($this->config['from_email'] ?? '');
        if ($fromEmail === '') {
            throw new RuntimeException('MAIL_FROM_EMAIL no está configurado.');
        }

        // Resolvemos cada adjunto de forma portable: si la ruta guardada en la BD no
        // existe (proyecto movido / BD restaurada en otro server), FileStore la
        // reconstruye desde storage/ por nombre de archivo.
        $attachments = array_values(array_filter(array_map(
            static fn (string $p): string => $p === '' ? '' : FileStore::resolveStorage($p),
            [
                (string) ($job['adjunto_1'] ?? ''),
                (string) ($job['adjunto_2'] ?? ''),
                (string) ($job['adjunto_3'] ?? ''),
            ]
        )));

        $message = $this->buildMimeMessage(
            fromEmail: $fromEmail,
            fromName: (string) $this->config['from_name'],
            toEmail: (string) $job['cliente_email'],
            subject: (string) $job['asunto'],
            htmlBody: (string) $job['cuerpo_html'],
            attachments: $attachments
        );

        $artifact = $emailDir . '/email_' . $job['id'] . '.eml';
        file_put_contents($artifact, $message);

        if ($transport === 'smtp') {
            $this->sendViaSmtp((string) $job['cliente_email'], $fromEmail, $message);
        } elseif ($transport !== 'file') {
            throw new RuntimeException('Transporte de correo no soportado: ' . $transport);
        }

        $this->emailLogRepository->add(
            (int) $job['invoice_id'],
            (string) $job['cliente_email'],
            (string) $job['asunto'],
            $transport,
            $artifact
        );
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    private function buildMimeMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody,
        array $attachments
    ): string {
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'Date: ' . date(DATE_RFC2822),
        ];

        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $htmlBody;

        foreach ($attachments as $attachment) {
            if ($attachment === '' || !is_file($attachment)) {
                continue;
            }
            $filename = basename($attachment);
            $mime = $this->detectMime($filename);
            $content = chunk_split(base64_encode((string) file_get_contents($attachment)));

            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $mime . '; name="' . $filename . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
            $parts[] = '';
            $parts[] = $content;
        }

        $parts[] = '--' . $boundary . '--';
        $parts[] = '';

        return implode("\r\n", array_merge($headers, [''], $parts));
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    private function detectMime(string $filename): string
    {
        $lower = strtolower($filename);
        return match (true) {
            str_ends_with($lower, '.pdf') => 'application/pdf',
            str_ends_with($lower, '.xml') => 'application/xml',
            str_ends_with($lower, '.png') => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    private function sendViaSmtp(string $toEmail, string $fromEmail, string $message): void
    {
        $host = (string) $this->config['host'];
        $port = (int) $this->config['port'];
        $encryption = strtolower((string) ($this->config['encryption'] ?? ''));
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $socket = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            // No se pudo siquiera abrir el socket: típicamente NO hay internet o el
            // host SMTP no responde. Es reintentable → SUSPENDIDO (no ERROR).
            throw new MailConnectionException('No se pudo abrir conexión SMTP: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 20);

        // Si el socket se queda sin datos por timeout o la conexión se corta a mitad
        // (se fue el internet durante el diálogo SMTP), lo tratamos como error de RED
        // reintentable lanzando MailConnectionException, no como rechazo permanente.
        $read = static function ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new MailConnectionException('Timeout esperando respuesta del servidor SMTP (conexión perdida).');
            }
            if ($data === '') {
                throw new MailConnectionException('El servidor SMTP cerró la conexión sin responder (conexión perdida).');
            }
            return $data;
        };

        $write = static function ($socket, string $command): void {
            fwrite($socket, $command . "\r\n");
        };

        $expect = static function (array $allowedCodes, string $response): void {
            $code = (int) substr($response, 0, 3);
            if (!in_array($code, $allowedCodes, true)) {
                throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($response));
            }
        };

        $expect([220], $read($socket));
        $write($socket, 'EHLO localhost');
        $expect([250], $read($socket));

        if ($encryption === 'tls') {
            $write($socket, 'STARTTLS');
            $expect([220], $read($socket));
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                // Fallo en la negociación TLS: casi siempre es de red/transporte → reintentable.
                throw new MailConnectionException('No se pudo activar STARTTLS (conexión TLS fallida).');
            }
            $write($socket, 'EHLO localhost');
            $expect([250], $read($socket));
        }

        if ($username !== '') {
            $write($socket, 'AUTH LOGIN');
            $expect([334], $read($socket));
            $write($socket, base64_encode($username));
            $expect([334], $read($socket));
            $write($socket, base64_encode($password));
            $expect([235], $read($socket));
        }

        $write($socket, 'MAIL FROM:<' . $fromEmail . '>');
        $expect([250], $read($socket));
        $write($socket, 'RCPT TO:<' . $toEmail . '>');
        $expect([250, 251], $read($socket));
        $write($socket, 'DATA');
        $expect([354], $read($socket));
        fwrite($socket, $message . "\r\n.\r\n");
        $expect([250], $read($socket));
        $write($socket, 'QUIT');
        fclose($socket);
    }
}
