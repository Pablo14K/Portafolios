<?php
declare(strict_types=1);

namespace App\Service;

use App\Repository\EmitterRepository;
use App\Repository\InvoiceEventRepository;
use App\Repository\InvoiceRepository;
use RuntimeException;

/**
 * Orquesta la cancelación de un DE aprobado por SIFEN (Manual cap. 11).
 *
 * Flujo:
 *   1. Verifica que la factura esté APROBADA y dentro del plazo legal
 *      (48 h FE, 168 h NCE/NDE/NRE/AFE).
 *   2. Llama al microservicio Node (SifenBridge::cancel) que firma y envía el evento.
 *   3. Si SIFEN responde código 0600, persiste:
 *        - invoices.fe_estado='CANCELADO' + motivo + idEvento + trackId
 *        - invoice_events (historial completo)
 *      Si SIFEN rechaza, registra el evento como RECHAZADO sin tocar la factura.
 */
final class CancellationService
{
    private const TIPO_DOC_FE = 1;

    public function __construct(
        private InvoiceRepository      $invoices,
        private InvoiceEventRepository $events,
        private EmitterRepository      $emitters,
        private SifenBridge            $sifenBridge,
    ) {}

    public function cancelByInvoiceId(int $invoiceId, string $motivo): array
    {
        $invoice = $this->invoices->getInvoiceComplete($invoiceId);
        if ($invoice === null) {
            throw new RuntimeException("Factura #$invoiceId no existe.");
        }
        return $this->cancel($invoice, $motivo);
    }

    public function cancelByCdc(string $cdc, string $motivo): array
    {
        $invoice = $this->invoices->findByCdc($cdc);
        if ($invoice === null) {
            throw new RuntimeException("No existe factura con CDC $cdc.");
        }
        return $this->cancel($invoice, $motivo);
    }

    private function cancel(array $invoice, string $motivo): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5)   throw new RuntimeException('Motivo: mínimo 5 caracteres (Manual cap. 11.6.1).');
        if (mb_strlen($motivo) > 500) throw new RuntimeException('Motivo: máximo 500 caracteres (Manual cap. 11.6.1).');

        $cdc = (string)($invoice['fe_cdc'] ?? '');
        if ($cdc === '' || strlen($cdc) !== 44) {
            throw new RuntimeException('La factura no tiene CDC válido. Solo se cancelan DE aprobados.');
        }

        $estadoActual = strtoupper((string)($invoice['fe_estado'] ?? ''));
        if ($estadoActual === 'CANCELADO') {
            throw new RuntimeException('La factura ya está cancelada.');
        }
        if (!in_array($estadoActual, ['APROBADO', 'EMITIDA', 'AUTORIZADO', 'ACEPTADO'], true)) {
            throw new RuntimeException("Solo se cancelan facturas APROBADAS. Estado actual: $estadoActual");
        }

        $this->assertWithinDeadline($invoice);

        $emitter = $this->emitters->getEmitter();
        if (!$emitter) {
            throw new RuntimeException('No existe configuración del emisor en emitter_settings.');
        }

        // Pre-validación SIFEN (Manual cap. 11.6.1 cód. 4000/4001):
        // verificamos que el DE existe y está APROBADO antes de generar el evento.
        // Solo aplica fuera de modo mock; en mock la consultaDE es simulada.
        $this->assertSifenAprobado($cdc);

        $resp = $this->sifenBridge->cancel($emitter, $cdc, $motivo);

        $idEvento = (string)($resp['idEvento'] ?? '');
        $xmlPath  = (string)($resp['evento_xml_path'] ?? '');
        $aprobado = !empty($resp['ok']) && !empty(($resp['respuesta']['ok'] ?? $resp['ok']));
        $codigo   = (string)($resp['respuesta']['codigo'] ?? $resp['codigo'] ?? '');
        $trackId  = (string)($resp['respuesta']['trackId'] ?? $resp['trackId'] ?? '');

        $eventId = $this->events->record(
            (int)$invoice['id'],
            'CANCELACION',
            $cdc,
            $idEvento ?: 'SIN-ID',
            $motivo,
            $xmlPath ?: null,
            $resp,
        );

        if ($aprobado || $codigo === '0600') {
            $this->invoices->markCancelled((int)$invoice['id'], $motivo, $idEvento, $trackId ?: null);
            return [
                'ok'        => true,
                'invoice_id'=> (int)$invoice['id'],
                'cdc'       => $cdc,
                'idEvento'  => $idEvento,
                'codigo'    => $codigo ?: '0600',
                'event_row' => $eventId,
                'mensaje'   => 'Cancelación registrada en SIFEN.',
            ];
        }

        $msg = (string)($resp['respuesta']['mensaje'] ?? $resp['error'] ?? 'SIFEN rechazó la cancelación.');
        return [
            'ok'        => false,
            'invoice_id'=> (int)$invoice['id'],
            'cdc'       => $cdc,
            'idEvento'  => $idEvento,
            'codigo'    => $codigo,
            'event_row' => $eventId,
            'mensaje'   => $msg,
        ];
    }

    /**
     * Consulta el estado del DE en SIFEN antes de cancelar. Si el DE no existe
     * o no está aprobado, evitamos enviar un evento que SIFEN va a rechazar
     * (códigos 4000/4001) y damos un mensaje claro al usuario.
     *
     * En modo mock la consultaDE devuelve siempre APROBADO, así que esta
     * validación es no-op en desarrollo local.
     */
    private function assertSifenAprobado(string $cdc): void
    {
        try {
            $resp = $this->sifenBridge->consultarDE($cdc);
        } catch (\Throwable $e) {
            // Si la consulta falla por red u otro motivo, no bloqueamos la cancelación:
            // SIFEN devolverá 4000/4001 si corresponde y eso queda registrado en invoice_events.
            return;
        }

        $estado = strtoupper((string)($resp['estado'] ?? ''));
        $codigo = (string)($resp['codigo'] ?? '');

        // ERROR_PARSE indica que xml2js no pudo leer la respuesta — no concluyente, no bloqueamos.
        if ($estado === 'ERROR_PARSE' || $estado === 'DESCONOCIDO' || $estado === '') return;

        if (str_contains($estado, 'APROBADO') || str_contains($estado, 'ACEPTADO')) return;

        if (str_contains($estado, 'CANCEL')) {
            throw new RuntimeException("SIFEN reporta el DE $cdc como CANCELADO. No se puede cancelar dos veces (cód. 4005).");
        }
        if (str_contains($estado, 'INEXISTENTE') || $codigo === '4000') {
            throw new RuntimeException("SIFEN no encuentra el DE con CDC $cdc (cód. 4000). Verificá que fue aprobado.");
        }
        throw new RuntimeException("SIFEN reporta el DE en estado '$estado' (cód. $codigo). Solo se cancelan DE APROBADOS (cód. 4001).");
    }

    /**
     * Plazo legal según Tabla J del Manual SIFEN v150:
     *   FE                       → 48 h
     *   NCE / NDE / NRE / AFE    → 168 h (7 días)
     * Se cuenta desde fecha_firma (proxy de "aprobación SIFEN" en el sistema local).
     */
    private function assertWithinDeadline(array $invoice): void
    {
        $referencia = $invoice['fecha_firma'] ?: $invoice['updated_at'] ?: $invoice['created_at'] ?: null;
        if (!$referencia) return; // Sin referencia, no bloqueamos por plazo.

        $tipoDoc = (int)($invoice['tipo_documento'] ?? self::TIPO_DOC_FE);
        $limiteHs = $tipoDoc === self::TIPO_DOC_FE ? 48 : 168;

        $aprobado = strtotime((string)$referencia);
        if ($aprobado === false) return;

        $horas = (time() - $aprobado) / 3600;
        if ($horas > $limiteHs) {
            throw new RuntimeException(sprintf(
                'Plazo de cancelación vencido: pasaron %.1f h (límite %d h). Manual cap. 11.6.1 cód. 4002.',
                $horas, $limiteHs
            ));
        }
    }
}
