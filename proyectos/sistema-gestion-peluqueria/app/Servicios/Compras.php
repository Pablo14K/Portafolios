<?php

declare(strict_types=1);

namespace App\Servicios;

use Illuminate\Support\Facades\DB;

/**
 * Lo que dos pantallas necesitan saber de una compra: la ficha de Inventario
 * y el pago de Tesorería. Escrito en cada una se desfasa —una diría «pagada»
 * donde la otra propone cobrar la cuota—.
 */
final class Compras
{
    /**
     * Las cuotas de una compra, con cuánto cubrió cada una lo ya pagado (y lo
     * acreditado por notas de crédito).
     *
     * **Qué cuota cubre cada pago NO se guarda: se deduce por orden**, como se
     * paga una deuda —lo que entra va a la cuota más vieja—, que es lo mismo
     * que hace `fn_compra_cuota_pendiente` en la base. Guardarlo sería una
     * columna derivada, y además obligaría a repartir a mano un pago que
     * cubre una cuota y media.
     *
     * Cada cuota vuelve con `cubierto`, `falta`, `estado` (pagada · parcial ·
     * pendiente) y `vencida`.
     *
     * @return list<object>
     */
    public static function cuotas(int $idCompra): array
    {
        $cuotas = DB::select(
            'SELECT nro_cuota, fecha_vencimiento, monto FROM compra_cuota WHERE id_compra = ? ORDER BY nro_cuota',
            [$idCompra]
        );
        if (! $cuotas) {
            return [];
        }

        $cubierto = (float) DB::scalar('SELECT fn_compra_pagado(?) + fn_compra_acreditado(?)', [$idCompra, $idCompra]);
        $hoy = ahora_bd('Y-m-d');
        foreach ($cuotas as $c) {
            $aplica = max(0.0, min((float) $c->monto, $cubierto));
            $cubierto -= $aplica;
            $c->cubierto = $aplica;
            $c->falta = round((float) $c->monto - $aplica, 2);
            $c->estado = $c->falta <= 0.005 ? 'pagada' : ($aplica > 0 ? 'parcial' : 'pendiente');
            $c->vencida = $c->estado !== 'pagada' && (string) $c->fecha_vencimiento < $hoy;
        }

        return $cuotas;
    }
}
