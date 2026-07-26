<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

/**
 * Auto-migrador idempotente. Aplica las modificaciones de schema
 * necesarias para soportar cancelaciones (Manual SIFEN cap. 11) sin
 * obligar al usuario a correr SQL manualmente.
 *
 * Funciona con MySQL 8 (que soporta IF NOT EXISTS) y con MariaDB / MySQL
 * más viejos (que no lo soportan): primero intenta la versión moderna y,
 * si falla con código 1064, comprueba `INFORMATION_SCHEMA` antes de
 * agregar la columna o tabla. Es seguro ejecutarlo en cada arranque:
 * no hace nada si todo ya existe.
 */
final class SchemaUpgrader
{
    public static function ensureInvoiceCancellationSchema(PDO $pdo): void
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') return;

        $cols = [
            'cancelado_at'          => 'DATETIME NULL',
            'motivo_cancelacion'    => 'VARCHAR(500) NULL',
            'evento_cancelacion_id' => 'VARCHAR(20) NULL',
            'sifen_track_id'        => 'VARCHAR(50) NULL',
        ];
        foreach ($cols as $name => $definition) {
            self::ensureColumn($pdo, $database, 'invoices', $name, $definition);
        }

        // emitter_settings: sistema de facturación (A005) — 1=propio, 2=solución gratuita SIFEN
        self::ensureColumn($pdo, $database, 'emitter_settings', 'sistema_facturacion', "TINYINT NOT NULL DEFAULT 1");

        self::ensureInvoiceEventsTable($pdo, $database);
    }

    /**
     * Migraciones idempotentes para el ENVÍO MANUAL reanudable de comprobantes:
     *
     *  - email_queue.updated_at: marca de "última vez tocada", para detectar y
     *    reencolar correos colgados en ENVIANDO (resetStuckSending).
     *
     * El estado 'SUSPENDIDO' no requiere cambios de schema (la columna `estado` ya
     * es VARCHAR(20) y admite el literal). Seguro de ejecutar en cada arranque.
     */
    public static function ensureManualSendSchema(PDO $pdo): void
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') return;

        self::ensureColumn(
            $pdo,
            $database,
            'email_queue',
            'updated_at',
            'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }

    private static function ensureColumn(PDO $pdo, string $database, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col'
        );
        $stmt->execute(['db' => $database, 'tbl' => $table, 'col' => $column]);
        if ((int)$stmt->fetchColumn() > 0) return;

        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Si la columna se creó entre el SELECT y el ALTER (race condition con
            // otra request), 1060 = "Duplicate column name" → ignorar.
            if (!str_contains($e->getMessage(), '1060')) throw $e;
        }
    }

    private static function ensureInvoiceEventsTable(PDO $pdo, string $database): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl'
        );
        $stmt->execute(['db' => $database, 'tbl' => 'invoice_events']);
        if ((int)$stmt->fetchColumn() > 0) return;

        $pdo->exec(
            "CREATE TABLE invoice_events (
                id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_id        BIGINT NOT NULL,
                tipo              ENUM('CANCELACION','INUTILIZACION') NOT NULL,
                cdc_afectado      VARCHAR(44) NOT NULL,
                id_evento         VARCHAR(20) NOT NULL,
                motivo            TEXT NOT NULL,
                xml_evento_path   VARCHAR(500) NULL,
                respuesta_sifen   JSON NULL,
                estado_sifen      ENUM('PENDIENTE','APROBADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
                codigo_respuesta  VARCHAR(10) NULL,
                track_id          VARCHAR(50) NULL,
                created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_invoice (invoice_id),
                INDEX idx_cdc (cdc_afectado),
                INDEX idx_id_evento (id_evento),
                CONSTRAINT fk_invoice_events_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
