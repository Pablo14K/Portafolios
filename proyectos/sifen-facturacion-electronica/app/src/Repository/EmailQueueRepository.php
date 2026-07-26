<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Repositorio de cola de emails. Crea, consulta y actualiza trabajos PENDIENTE/ENVIADO/ERROR.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Repository;

use PDO;

/**
 * Comentario de codigo: clase EmailQueueRepository. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class EmailQueueRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Comentario de codigo: Registra un trabajo pendiente en la cola correspondiente.
     */
    public function enqueue(
        int $invoiceId,
        string $email,
        string $type,
        string $subject,
        string $bodyHtml,
        ?string $attachment1,
        ?string $attachment2,
        ?string $attachment3 = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue (
                invoice_id, cliente_email, tipo, asunto, cuerpo_html, adjunto_1, adjunto_2, adjunto_3
             ) VALUES (
                :invoice_id, :email, :tipo, :asunto, :cuerpo_html, :adjunto_1, :adjunto_2, :adjunto_3
             )'
        );

        $stmt->execute([
            'invoice_id' => $invoiceId,
            'email' => $email,
            'tipo' => $type,
            'asunto' => $subject,
            'cuerpo_html' => $bodyHtml,
            'adjunto_1' => $attachment1,
            'adjunto_2' => $attachment2,
            'adjunto_3' => $attachment3,
        ]);
    }

    /**
     * Comentario de codigo: Obtiene trabajos pendientes para que el worker los procese.
     */
    public function getPending(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM email_queue WHERE estado = 'PENDIENTE' ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Toma de forma ATÓMICA el siguiente correo a enviar (el más antiguo en
     * PENDIENTE o SUSPENDIDO) y lo deja en ENVIANDO antes de devolverlo.
     *
     * Esto es lo que permite el envío "uno por uno" del botón manual y evita
     * el doble-envío si dos pestañas/peticiones corren a la vez: solo una logra
     * el UPDATE condicional (rowCount === 1); la otra reintenta con el siguiente.
     * Incluye SUSPENDIDO para que "Reanudar" retome lo que quedó tras un corte.
     *
     * @return array|null la fila del correo reclamado, o null si no hay nada que enviar.
     */
    public function claimNext(): ?array
    {
        for ($intento = 0; $intento < 5; $intento++) {
            $candidato = $this->pdo->query(
                "SELECT id FROM email_queue
                  WHERE estado IN ('PENDIENTE','SUSPENDIDO')
                  ORDER BY id ASC LIMIT 1"
            )->fetchColumn();

            if ($candidato === false) {
                return null; // No quedan correos por enviar.
            }

            $upd = $this->pdo->prepare(
                "UPDATE email_queue
                    SET estado = 'ENVIANDO', intentos = intentos + 1
                  WHERE id = :id AND estado IN ('PENDIENTE','SUSPENDIDO')"
            );
            $upd->execute(['id' => $candidato]);

            if ($upd->rowCount() === 1) {
                $row = $this->pdo->prepare('SELECT * FROM email_queue WHERE id = :id');
                $row->execute(['id' => $candidato]);
                return $row->fetch() ?: null;
            }
            // Otra petición se lo llevó entre el SELECT y el UPDATE → probar el siguiente.
        }
        return null;
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function markSending(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE email_queue SET estado = 'ENVIANDO', intentos = intentos + 1 WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function markSent(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE email_queue SET estado = 'ENVIADO', sent_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * Comentario de codigo: Registra el error para que pueda revisarse desde la interfaz o logs.
     */
    public function markError(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE email_queue SET estado = 'ERROR', ultimo_error = :error WHERE id = :id");
        $stmt->execute(['id' => $id, 'error' => $error]);
    }

    /**
     * Marca el correo como SUSPENDIDO: hubo un corte de conexión (sin internet)
     * a mitad del envío. NO es un error permanente — al reanudar, claimNext() lo
     * vuelve a tomar y se reintenta. Así no se pierde información ni se duplica.
     */
    public function markSuspended(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue SET estado = 'SUSPENDIDO', ultimo_error = :error WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'error' => $error]);
    }

    /**
     * Marca el correo como ENVIADO sin reenviarlo (idempotencia): se usa cuando se
     * detecta que ese comprobante YA figura en email_log (p. ej. el proceso se cortó
     * justo después de enviar pero antes de poder registrar el ENVIADO).
     */
    public function markSentDedup(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
                SET estado = 'ENVIADO', sent_at = NOW(),
                    ultimo_error = '[idempotencia] ya figuraba enviado en email_log; no se reenvió.'
              WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Recupera correos que quedaron colgados en ENVIANDO (el navegador se cerró o
     * la petición murió a mitad del envío) y los devuelve a PENDIENTE para reanudar.
     * Gemelo de FeQueueRepository::resetStuckJobs. Usa updated_at como marca de
     * "última vez que se tocó la fila".
     *
     * @return int cantidad de correos reencolados.
     */
    public function resetStuckSending(int $secondsThreshold = 120): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE email_queue
                SET estado = 'PENDIENTE',
                    ultimo_error = CONCAT(
                        IFNULL(ultimo_error,''),
                        IF(IFNULL(ultimo_error,'')='','','\n'),
                        '[auto-reset ', NOW(), '] envío no completado; reencolado.'
                    )
              WHERE estado = 'ENVIANDO'
                AND updated_at < (NOW() - INTERVAL :sec SECOND)"
        );
        $stmt->execute(['sec' => $secondsThreshold]);
        return $stmt->rowCount();
    }

    /**
     * Devuelve la cantidad de correos por cada estado. Alimenta los contadores del
     * panel de envío (pendientes / suspendidos / enviados / error).
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $rows = $this->pdo->query(
            "SELECT estado, COUNT(*) AS n FROM email_queue GROUP BY estado"
        )->fetchAll() ?: [];
        $out = ['PENDIENTE' => 0, 'SUSPENDIDO' => 0, 'ENVIANDO' => 0, 'ENVIADO' => 0, 'ERROR' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['estado']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Comentario de codigo: Devuelve todos los registros necesarios para la vista administrativa.
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM email_queue ORDER BY id ASC');
        return $stmt->fetchAll() ?: [];
    }
}
