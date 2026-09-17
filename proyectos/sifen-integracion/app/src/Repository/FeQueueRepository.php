<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Repositorio de cola FE. Controla trabajos de emision electronica, CDC, XML, KuDE y errores.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Repository;

use PDO;

/**
 * Comentario de codigo: clase FeQueueRepository. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class FeQueueRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Inserta una factura en cola para emisión electrónica.
     */
    public function enqueue(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO fe_queue (invoice_id) VALUES (:invoice_id)');
        $stmt->execute(['invoice_id' => $invoiceId]);
    }

    /**
     * Comentario de codigo: Obtiene trabajos pendientes para que el worker los procese.
     */
    public function getPending(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM fe_queue WHERE estado = 'PENDIENTE' ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Toma de forma ATÓMICA el siguiente trabajo a generar (más antiguo en PENDIENTE)
     * y lo deja en PROCESANDO antes de devolverlo. Permite la generación "uno por uno"
     * desde el botón sin worker, evitando que dos peticiones tomen el mismo job.
     *
     * @return array|null la fila del job reclamado, o null si no hay pendientes.
     */
    public function claimNext(): ?array
    {
        for ($intento = 0; $intento < 5; $intento++) {
            $candidato = $this->pdo->query(
                "SELECT id FROM fe_queue WHERE estado = 'PENDIENTE' ORDER BY id ASC LIMIT 1"
            )->fetchColumn();

            if ($candidato === false) {
                return null;
            }

            // No incrementamos intentos aquí: processFeJob() lo hace en su markProcessing().
            $upd = $this->pdo->prepare(
                "UPDATE fe_queue SET estado = 'PROCESANDO', fecha_proceso = NOW()
                  WHERE id = :id AND estado = 'PENDIENTE'"
            );
            $upd->execute(['id' => $candidato]);

            if ($upd->rowCount() === 1) {
                $row = $this->pdo->prepare('SELECT * FROM fe_queue WHERE id = :id');
                $row->execute(['id' => $candidato]);
                return $row->fetch() ?: null;
            }
        }
        return null;
    }

    /**
     * Cantidad de facturas en cola esperando que se genere su comprobante (PENDIENTE).
     */
    public function countPending(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM fe_queue WHERE estado = 'PENDIENTE'")->fetchColumn();
    }

    /**
     * Guarda el payload que se va a enviar. Esto ayuda a auditar y depurar.
     */
    public function markProcessing(int $jobId, array $payload): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fe_queue
             SET estado = :estado,
                 fecha_proceso = NOW(),
                 intentos = intentos + 1,
                 payload_json = :payload
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'estado' => 'PROCESANDO',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Comentario de codigo: Marca el proceso como aprobado y persiste los archivos generados.
     */
    public function markApproved(int $jobId, string $cdc, string $xmlPath, string $kudePath, string $qrText, array $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fe_queue
             SET estado = :estado,
                 cdc = :cdc,
                 xml_path = :xml,
                 kude_path = :kude,
                 qr_text = :qr,
                 sifen_track_id = :track,
                 sifen_respuesta = :respuesta,
                 fecha_aprobacion = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'estado' => 'APROBADO',
            'cdc' => $cdc,
            'xml' => $xmlPath,
            'kude' => $kudePath,
            'qr' => $qrText,
            'track' => (string) ($response['track_id'] ?? ''),
            'respuesta' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Comentario de codigo: Registra el error para que pueda revisarse desde la interfaz o logs.
     */
    public function markError(int $jobId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fe_queue
             SET estado = :estado,
                 ultimo_error = :error
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'estado' => 'ERROR',
            'error' => $error,
        ]);
    }

    /**
     * Comentario de codigo: Devuelve todos los registros necesarios para la vista administrativa.
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM fe_queue ORDER BY id ASC');
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Recupera jobs marcados PROCESANDO que llevan más de N segundos sin avanzar
     * (worker caído / reinicio del servidor) y los devuelve a la cola PENDIENTE
     * incrementando el contador de intentos. Sin esto, los jobs quedan atascados
     * indefinidamente y la factura nunca se emite.
     */
    public function resetStuckJobs(int $secondsThreshold = 600): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fe_queue
                SET estado = 'PENDIENTE',
                    ultimo_error = CONCAT(
                        IFNULL(ultimo_error,''),
                        IF(IFNULL(ultimo_error,'')='','','\n'),
                        '[auto-reset ', NOW(), '] worker no completó el job en ',
                        :sec, 's; reencolado.'
                    )
              WHERE estado = 'PROCESANDO'
                AND fecha_proceso IS NOT NULL
                AND fecha_proceso < (NOW() - INTERVAL :sec2 SECOND)"
        );
        $stmt->execute(['sec' => $secondsThreshold, 'sec2' => $secondsThreshold]);
        return $stmt->rowCount();
    }
}
