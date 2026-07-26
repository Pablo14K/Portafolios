<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Historial de eventos SIFEN (cancelación / inutilización) por factura.
 * Manual SIFEN cap. 11. La trazabilidad se conserva por 5 años.
 */
final class InvoiceEventRepository
{
    public function __construct(private PDO $pdo) {}

    public function record(
        int $invoiceId,
        string $tipo,
        string $cdcAfectado,
        string $idEvento,
        string $motivo,
        ?string $xmlPath,
        array $respuesta
    ): int {
        $estado = !empty($respuesta['ok']) ? 'APROBADO' : 'RECHAZADO';
        $codigo = (string)($respuesta['codigo'] ?? $respuesta['respuesta']['codigo'] ?? '');
        $track  = (string)($respuesta['trackId'] ?? $respuesta['respuesta']['trackId'] ?? '');

        $stmt = $this->pdo->prepare(
            'INSERT INTO invoice_events
                (invoice_id, tipo, cdc_afectado, id_evento, motivo,
                 xml_evento_path, respuesta_sifen, estado_sifen, codigo_respuesta, track_id)
             VALUES
                (:iid, :tipo, :cdc, :idev, :mot,
                 :xml, :resp, :est, :cod, :tr)'
        );
        $stmt->execute([
            'iid'  => $invoiceId,
            'tipo' => $tipo,
            'cdc'  => $cdcAfectado,
            'idev' => $idEvento,
            'mot'  => $motivo,
            'xml'  => $xmlPath,
            'resp' => json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'est'  => $estado,
            'cod'  => $codigo !== '' ? $codigo : null,
            'tr'   => $track !== '' ? $track : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function listByInvoice(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice_events WHERE invoice_id=:id ORDER BY id DESC');
        $stmt->execute(['id' => $invoiceId]);
        return $stmt->fetchAll() ?: [];
    }
}
