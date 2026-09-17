<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Fachada del motor SIFEN. Desde la migración a PHP puro (cPanel) delega TODO en clases PHP nativas
 *           (SifenEmitter para emitir; respuestas mock/PHP para eventos). YA NO usa el microservicio Node.
 * Donde se usa: lo usan PipelineService (emisión) y CancellationService (cancelación). Mantiene el mismo contrato
 *               público que tenía la versión que hablaba con Node, para no tocar a sus consumidores.
 * Nota: ver docs/DOCUMENTACION_TECNICA_COMPLETA.md y GUIA_COMENTARIOS_CODIGO.md.
 */

namespace App\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * SifenBridge v3 — motor SIFEN 100% PHP (sin Node).
 *
 * Conserva la misma interfaz pública que la versión Node (emit / consultarDE /
 * cancel / inutilizar) para que PipelineService y CancellationService no cambien;
 * por dentro delega en SifenEmitter y, para eventos, responde en PHP (mock) o
 * marca claramente lo que falta para el modo real.
 */
final class SifenBridge
{
    private string $mode;

    /**
     * @param array       $config  El array 'sifen' de config.php.
     * @param string|null $certDir Compat. histórica (ya no se usa; el cert sale de $config).
     */
    public function __construct(private array $config, ?string $certDir = null)
    {
        $this->mode = (string)($config['mode'] ?? 'mock');
    }

    /**
     * Emite el documento electrónico usando el motor PHP nativo (SifenEmitter).
     * Devuelve el mismo contrato que antes consumía PipelineService.
     */
    public function emit(array $payload): array
    {
        $response = (new SifenEmitter($this->config))->emit($payload);

        if (!($response['ok'] ?? false)) {
            $msg = (string)($response['respuesta']['message'] ?? 'SIFEN rechazó el documento.');
            throw new RuntimeException('SIFEN: ' . $msg);
        }

        return $response;
    }

    /**
     * Verifica que el motor PHP está operativo (reemplaza al health-check del Node).
     */
    public function healthCheck(): array
    {
        return ['ok' => true, 'engine' => 'php', 'mode' => $this->mode];
    }

    /**
     * Consulta el estado de un DE por CDC. En modo mock devuelve APROBADO (no hay WS).
     * En test/prod la consulta real al WS siConsDE aún no está portada a PHP nativo:
     * se devuelve DESCONOCIDO para no bloquear (SIFEN validará al recibir el evento).
     */
    public function consultarDE(string $cdc): array
    {
        if ($this->mode === 'mock') {
            return ['ok' => true, 'estado' => 'APROBADO', 'codigo' => '', 'cdc' => $cdc, 'modo' => 'mock'];
        }
        // TODO(modo real): implementar siConsDE en PHP (SoapSifenClient) cuando haya certificado.
        return ['ok' => true, 'estado' => 'DESCONOCIDO', 'codigo' => '', 'cdc' => $cdc];
    }

    /**
     * Cancela un DE (evento tipo 1, Manual SIFEN cap. 11). En modo mock simula la
     * aprobación (cód. 0600) sin enviar nada. En test/prod la construcción + firma +
     * envío del evento todavía no está portada a PHP nativo (queda como TODO para
     * cuando exista certificado de homologación).
     */
    public function cancel(array $emitter, string $cdc, string $motivo): array
    {
        if ($this->mode === 'mock') {
            $idEvento = $this->mockIdEvento();
            return [
                'ok'              => true,
                'idEvento'        => $idEvento,
                'evento_xml_path' => '',
                'codigo'          => '0600',
                'trackId'         => 'MOCK-' . (new DateTimeImmutable())->format('YmdHis'),
                'respuesta'       => [
                    'ok'      => true,
                    'codigo'  => '0600',
                    'trackId' => 'MOCK-' . (new DateTimeImmutable())->format('YmdHis'),
                    'mensaje' => 'Evento de cancelación procesado en modo mock. Sin valor fiscal.',
                ],
            ];
        }

        throw new RuntimeException(
            'La cancelación contra SIFEN real aún no está implementada en el motor PHP nativo. '
            . 'Pendiente para cuando se cuente con el certificado de homologación.'
        );
    }

    /**
     * Inutiliza un rango de numeración (evento tipo 2). Mismo criterio que cancel().
     */
    public function inutilizar(array $emitter, array $datos): array
    {
        if ($this->mode === 'mock') {
            return [
                'ok'        => true,
                'idEvento'  => $this->mockIdEvento(),
                'codigo'    => '0600',
                'respuesta' => ['ok' => true, 'codigo' => '0600', 'mensaje' => 'Inutilización mock. Sin valor fiscal.'],
            ];
        }

        throw new RuntimeException(
            'La inutilización contra SIFEN real aún no está implementada en el motor PHP nativo.'
        );
    }

    /**
     * Genera un idEvento numérico simulado (15 dígitos) para registrar el evento mock.
     */
    private function mockIdEvento(): string
    {
        return (new DateTimeImmutable())->format('YmdHis') . (string)random_int(0, 9);
    }
}
