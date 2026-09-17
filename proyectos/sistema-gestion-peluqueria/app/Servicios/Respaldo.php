<?php

declare(strict_types=1);

namespace App\Servicios;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El papel que respalda plata: el comprobante de un gasto, la nota de
 * crédito de un proveedor.
 *
 * **Fuera de `public/`**, igual que el comprobante de la seña: es
 * documentación de dinero y no tiene por qué quedar colgando de una URL que
 * alguien adivine. Se sirve desde el sistema, con la sesión y el permiso ya
 * comprobados.
 *
 * Vivía como método privado de `FacturacionController` (7.47.0); al
 * necesitarlo también Inventario —la nota de crédito de la compra, 7.127.0—
 * se muda acá, porque copiado se desfasa: uno aceptaría 3 MB y el otro 2, o
 * uno miraría el contenido y el otro la extensión.
 */
final class Respaldo
{
    /** Dónde van, relativo a `storage/app`. */
    private const CARPETA = 'respaldos';

    /** Tope del archivo: una foto de un papel no pesa más. */
    private const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Guarda el archivo y devuelve su nombre, o false si no sirve.
     *
     * **Se mira el contenido, no la extensión**, que la elige quien sube el
     * archivo. Se acepta la foto —que es como lo manda casi todo el mundo— y
     * el PDF, que es como lo dan algunos bancos y proveedores.
     */
    public static function guardar(mixed $archivo, string $prefijo): string|false
    {
        if (! $archivo || ! $archivo->isValid() || $archivo->getSize() > self::MAX_BYTES) {
            return false;
        }

        $info = @getimagesize($archivo->getRealPath());
        $tipos = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        $esPdf = str_starts_with((string) @file_get_contents($archivo->getRealPath(), false, null, 0, 5), '%PDF-');

        if (! $esPdf && (! $info || ! isset($tipos[$info[2]]))) {
            return false;
        }

        $nombre = $prefijo . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.'
            . ($esPdf ? 'pdf' : $tipos[$info[2]]);
        try {
            $archivo->move(storage_path('app/' . self::CARPETA), $nombre);
        } catch (Throwable $e) {
            Log::error('No se pudo guardar el respaldo: ' . $e->getMessage());

            return false;
        }

        return $nombre;
    }

    /**
     * La ruta en disco de un respaldo guardado, o null si no está.
     *
     * El nombre lo pone el sistema, pero se comprueba igual: si algún día lo
     * pusiera otra cosa, un `../` acá serviría cualquier archivo del disco.
     */
    public static function ruta(?string $nombre): ?string
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '' || $nombre !== basename($nombre)) {
            return null;
        }

        $ruta = storage_path('app/' . self::CARPETA . '/' . $nombre);

        return is_file($ruta) ? $ruta : null;
    }
}
