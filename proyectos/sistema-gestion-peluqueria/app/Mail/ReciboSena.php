<?php

declare(strict_types=1);

namespace App\Mail;

use Dompdf\Dompdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El recibo de la seña, con la confirmación de la cita, para la clienta.
 *
 * **Es lo que cierra el círculo de la seña** (7.127.0, pedido del usuario:
 * «con el pago de la seña la clienta debe recibir un recibo con el aviso de
 * confirmación de cita»). Ella transfirió desde su casa y avisó por el
 * portal; hasta que el salón confirmaba, lo único que tenía era la pantalla
 * diciendo «sin confirmar». Cuando alguien del mostrador confirma, le llega
 * esto: cuánto recibimos, por qué cita, qué queda por pagar, y que el
 * horario **ya es suyo**.
 *
 * **El recibo va en el cuerpo Y en PDF.** En el cuerpo, para leerlo de una
 * en el teléfono; en PDF porque un recibo es algo que se guarda y se
 * muestra, y el cuerpo de un correo no se presenta en ningún lado. El PDF lo
 * dibuja Dompdf con la misma plantilla que el cuerpo, así los dos dicen lo
 * mismo.
 *
 * **No es un comprobante fiscal, y lo dice.** El comprobante se emite al
 * terminar la atención, con su timbrado; esto es la constancia de que la
 * seña entró.
 */
class ReciboSena extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $r  Lo que arma `Sena::recibo()`
     */
    public function __construct(public array $r) {}

    public function envelope(): Envelope
    {
        $cita = fecha($this->r['cita']->fecha_hora, 'd/m');

        return new Envelope(
            subject: ($this->r['confirmada']
                    ? 'Recibimos tu seña: tu cita del ' . $cita . ' queda confirmada'
                    : 'Recibimos tu seña por la cita del ' . $cita)
                . ' · ' . $this->r['salon']
        );
    }

    public function content(): Content
    {
        return new Content(view: 'correo.recibo_sena', with: ['r' => $this->r]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $r = $this->r;

        return [
            Attachment::fromData(static function () use ($r): string {
                $pdf = new Dompdf();
                $pdf->loadHtml(view('correo.recibo_sena_pdf', ['r' => $r])->render(), 'UTF-8');
                $pdf->setPaper('A5');
                $pdf->render();

                return (string) $pdf->output();
            }, 'recibo-sena-' . (int) $r['cita']->id_cita . '.pdf')->withMime('application/pdf'),
        ];
    }
}
