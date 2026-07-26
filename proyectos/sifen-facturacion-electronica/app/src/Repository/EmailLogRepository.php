<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Repositorio de auditoria de emails. Guarda evidencia del transporte usado y archivo .eml generado.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Repository;

use PDO;

/**
 * Comentario de codigo: clase EmailLogRepository. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class EmailLogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Guarda una evidencia mínima del correo enviado.
     */
    public function add(int $invoiceId, string $to, string $subject, string $transport, ?string $artifact): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_log (invoice_id, destinatario, asunto, transport, artefacto)
             VALUES (:invoice_id, :to, :subject, :transport, :artifact)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'to' => $to,
            'subject' => $subject,
            'transport' => $transport,
            'artifact' => $artifact,
        ]);
    }

    /**
     * ¿Este comprobante ya fue enviado antes? Se usa como red de seguridad
     * anti-doble-envío al reanudar: si el correo (misma factura + destinatario +
     * asunto) ya figura en email_log, no se reenvía.
     */
    public function wasSent(int $invoiceId, string $to, string $subject): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM email_log
              WHERE invoice_id = :inv AND destinatario = :to AND asunto = :subject
              LIMIT 1'
        );
        $stmt->execute(['inv' => $invoiceId, 'to' => $to, 'subject' => $subject]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Comentario de codigo: Devuelve todos los registros necesarios para la vista administrativa.
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM email_log ORDER BY id ASC');
        return $stmt->fetchAll() ?: [];
    }
}
