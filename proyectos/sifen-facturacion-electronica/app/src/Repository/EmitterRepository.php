<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Repositorio de datos del emisor. Lee emitter_settings para armar XML, KuDE y QR.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Repository;

use PDO;

/**
 * Comentario de codigo: clase EmitterRepository. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class EmitterRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Recupera la configuración fiscal del emisor.
     */
    public function getEmitter(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM emitter_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }
}
