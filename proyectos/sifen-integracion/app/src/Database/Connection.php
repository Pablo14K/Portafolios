<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Conexion PDO a MySQL. Es usada por repositorios, scripts y pantalla web para acceder a facturacion_sifen.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Database;

use PDO;
use Throwable;

/**
 * Comentario de codigo: clase Connection. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class Connection
{
    private static bool $schemaChecked = false;

    /**
     * Crea una conexión PDO con opciones seguras para la demo.
     * Aplica una sola vez por proceso las migraciones idempotentes
     * necesarias para cancelaciones (Manual SIFEN cap. 11).
     */
    public static function make(array $config): PDO
    {
        $pdo = new PDO(
            $config['dsn'],
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        if (!self::$schemaChecked) {
            self::$schemaChecked = true;
            try {
                SchemaUpgrader::ensureInvoiceCancellationSchema($pdo);
                SchemaUpgrader::ensureManualSendSchema($pdo);
            } catch (Throwable $e) {
                // Si la BD aún no tiene la tabla `invoices` (instalación nueva sin
                // base_de_datos_completa.sql) no rompemos el arranque: la primera
                // query real fallará con un mensaje más claro para el usuario.
                error_log('[SchemaUpgrader] ' . $e->getMessage());
            }
        }

        return $pdo;
    }
}
