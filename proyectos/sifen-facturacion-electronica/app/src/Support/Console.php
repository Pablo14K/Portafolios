<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Utilidad de consola. Formatea salida para scripts CLI.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Support;

/**
 * Comentario de codigo: clase Console. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class Console
{
    public static function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public static function title(string $message): void
    {
        self::line('');
        self::line('==== ' . $message . ' ====');
    }
}
