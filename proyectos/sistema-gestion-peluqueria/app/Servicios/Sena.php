<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Mail\ReciboSena;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * El desglose de la seña de una cita: cuánto pide cada servicio y por qué.
 *
 * **«Seña Gs. 210.000» sin explicación no se puede comprobar.** La clienta no
 * sabe de dónde sale ese número —si es de un servicio o de tres, ni qué
 * porcentaje se le aplicó— y quien confirma el pago en el mostrador tampoco:
 * los dos ven un total y tienen que creerle.
 *
 * El total sigue saliendo de `fn_cita_sena_requerida`, que es la autoridad y la
 * que hace cumplir el tope. Acá se arma **el mismo cálculo abierto por
 * servicio**, para poder mostrarlo; si alguna vez cambia el criterio de la
 * función, hay que cambiarlo también acá.
 */
class Sena
{
    /**
     * Una fila por servicio de la cita, más el total.
     *
     * **Lo canjeado no pide seña y aparece igual**, marcado: sacarlo de la
     * lista dejaría un total que no cierra con los servicios que se ven.
     *
     * @return array{filas: array<int, object>, total: float, lista: float,
     *               descuento: float, promo: ?string, nivel: ?string}
     */
    public static function desglose(int $idCita): array
    {
        $filas = DB::select(
            "SELECT s.nombre,
                    s.precio,
                    s.sena_porcentaje,
                    -- El canje ya está pagado con puntos: cobrar una garantía
                    -- por algo que la clienta no va a pagar no tiene sentido.
                    (SELECT COUNT(*) FROM canje cj
                      WHERE cj.id_cita = cs.id_cita AND cj.id_servicio = cs.id_servicio) AS canjeado,
                    CASE
                        WHEN (SELECT COUNT(*) FROM canje cj
                               WHERE cj.id_cita = cs.id_cita AND cj.id_servicio = cs.id_servicio) > 0 THEN 0
                        WHEN s.sena_porcentaje IS NULL THEN 0
                        ELSE ROUND(s.precio * s.sena_porcentaje / 100)
                    END AS sena
               FROM cita_servicio cs
               JOIN servicio s ON s.id_servicio = cs.id_servicio
              WHERE cs.id_cita = ?
              ORDER BY s.nombre", [$idCita]
        );

        // **El total sale de la base, no de sumar las filas.** Es la que manda:
        // si las dos se separaran, el número que se muestra dejaría de ser el
        // que el sistema exige.
        $total = (float) DB::scalar('SELECT fn_cita_sena_requerida(?)', [$idCita]);

        $lista = 0.0;
        foreach ($filas as $f) {
            $lista += (float) $f->precio;
        }

        // El descuento del nivel de esta clienta: es el único de los de nivel
        // que le corresponde, los otros son de niveles que no tiene.
        $idNivel = (int) DB::scalar(
            'SELECT fn_cliente_descuento(c.id_cliente) FROM cita c WHERE c.id_cita = ?', [$idCita]);

        // **De dónde sale el descuento, y ahora pueden ser VARIOS.** Un total
        // más bajo sin explicación se lee como un error de la pantalla, y quien
        // cobra no puede defenderlo si la clienta pregunta.
        //
        // Desde que el descuento se calcula por servicio, nombrar uno solo sería
        // mentir por omisión: con una promo del 5 % en el corte y otra del 3 %
        // en el lavado, el número sale de las dos. Se listan las que de verdad
        // aportaron — la que gana en cada renglón.
        $aporta = DB::select(
            "SELECT d.nombre,
                    (SELECT COUNT(*) FROM nivel n WHERE n.id_descuento = d.id_descuento) AS es_nivel
               FROM descuento d
              WHERE d.activo = 1
                AND EXISTS (
                    SELECT 1 FROM cita_servicio cs
                      JOIN servicio s ON s.id_servicio = cs.id_servicio
                     WHERE cs.id_cita = :c1
                       AND NOT EXISTS (SELECT 1 FROM canje cj
                                        WHERE cj.id_cita = cs.id_cita
                                          AND cj.id_servicio = cs.id_servicio)
                       -- Este descuento le aplica a ese servicio…
                       AND (NOT EXISTS (SELECT 1 FROM servicio_descuento sd
                                         WHERE sd.id_descuento = d.id_descuento)
                            OR EXISTS (SELECT 1 FROM servicio_descuento sd
                                        WHERE sd.id_descuento = d.id_descuento
                                          AND sd.id_servicio = cs.id_servicio))
                       AND fn_descuento_monto(d.id_descuento, s.precio) > 0
                       -- …y es el mejor que le aplica: si no, no aportó nada.
                       AND fn_descuento_monto(d.id_descuento, s.precio) >= ALL (
                             SELECT fn_descuento_monto(d2.id_descuento, s.precio)
                               FROM descuento d2
                              WHERE d2.activo = 1
                                AND (d2.id_descuento = :niv2
                                     OR NOT EXISTS (SELECT 1 FROM nivel n2
                                                     WHERE n2.id_descuento = d2.id_descuento))
                                AND (NOT EXISTS (SELECT 1 FROM servicio_descuento sd2
                                                  WHERE sd2.id_descuento = d2.id_descuento)
                                     OR EXISTS (SELECT 1 FROM servicio_descuento sd2
                                                 WHERE sd2.id_descuento = d2.id_descuento
                                                   AND sd2.id_servicio = cs.id_servicio))))
                AND (d.id_descuento = :niv1
                     OR NOT EXISTS (SELECT 1 FROM nivel n WHERE n.id_descuento = d.id_descuento))
              ORDER BY d.nombre",
            ['c1' => $idCita, 'niv1' => $idNivel, 'niv2' => $idNivel]
        );

        $nombreNivel = (string) DB::scalar(
            'SELECT n.nombre FROM cita c JOIN cliente cl ON cl.id_cliente = c.id_cliente
               JOIN nivel n ON n.id_nivel = fn_cliente_nivel(cl.id_cliente)
              WHERE c.id_cita = ?', [$idCita]);

        $promos = [];
        $porNivel = false;
        foreach ($aporta as $a) {
            if ((int) $a->es_nivel > 0) {
                $porNivel = true;
            } else {
                $promos[] = (string) $a->nombre;
            }
        }

        return [
            'filas' => $filas,
            'total' => $total,
            'lista' => $lista,
            'descuento' => (float) DB::scalar('SELECT fn_cita_descuento_total(?, ?)', [$idCita, $idNivel]),
            'promo' => $promos ? implode('» y «', $promos) : null,
            'nivel' => $porNivel ? $nombreNivel : null,
        ];
    }

    // -----------------------------------------------------------------
    //  El recibo de la seña, para la clienta
    // -----------------------------------------------------------------

    /**
     * Lo que dice el recibo: la cita, lo que entró, lo que queda, y si con
     * eso el horario ya es suyo.
     *
     * **`confirmada` sale de comparar lo señado contra lo que el salón pide**
     * (`fn_cita_sena_requerida`), que es exactamente lo que la agenda mira
     * para dibujar «sin confirmar»: si los dos usaran otra cuenta, el correo
     * diría «confirmada» y la fila seguiría en ámbar.
     *
     * @param  list<int>  $idsCobros  Los cobros de ESTA seña, para el detalle
     * @return array<string, mixed>|null  null si la cita no existe
     */
    public static function recibo(int $idCita, array $idsCobros): ?array
    {
        $cita = DB::selectOne(
            "SELECT c.id_cita, c.id_cliente, c.fecha_hora, c.personas, c.para_otra_persona, c.nombre_para,
                    CONCAT(pe.nombre, ' ', pe.apellido) AS cliente, pe.email,
                    CONCAT(pu.nombre, ' ', pu.apellido) AS profesional,
                    su.nombre AS sucursal, su.direccion, su.telefono,
                    fn_cita_total(c.id_cita) AS total,
                    fn_cita_sena_requerida(c.id_cita) AS requerida,
                    fn_cita_sena(c.id_cita) AS senado,
                    fn_cita_duracion(c.id_cita) AS duracion
               FROM cita c
               JOIN cliente cl ON cl.id_cliente = c.id_cliente
               JOIN persona pe ON pe.id_persona = cl.id_persona
               JOIN usuario u ON u.id_usuario = c.id_usuario
               JOIN persona pu ON pu.id_persona = u.id_persona
               JOIN sucursal su ON su.id_sucursal = c.id_sucursal
              WHERE c.id_cita = ?", [$idCita]
        );
        if (! $cita) {
            return null;
        }

        $ids = array_values(array_filter(array_map('intval', $idsCobros)));
        $cobros = $ids
            ? DB::select(
                'SELECT co.id_cobro, co.fecha, co.monto, mp.nombre AS metodo
                   FROM cobro co JOIN metodo_pago mp ON mp.id_metodo_pago = co.id_metodo_pago
                  WHERE co.id_cobro IN (' . implode(',', $ids) . ') AND co.id_estado_cobro = 1
                  ORDER BY co.id_cobro')
            : [];

        $recibido = 0.0;
        foreach ($cobros as $co) {
            $recibido += (float) $co->monto;
        }

        return [
            'salon' => Config::nombreSalon(),
            'cita' => $cita,
            'servicios' => DB::select(
                'SELECT s.nombre, s.precio FROM cita_servicio cs
                   JOIN servicio s ON s.id_servicio = cs.id_servicio
                  WHERE cs.id_cita = ? ORDER BY s.nombre', [$idCita]
            ),
            'cobros' => $cobros,
            'recibido' => $recibido,
            'senado' => (float) $cita->senado,
            'requerida' => (float) $cita->requerida,
            'total' => (float) $cita->total,
            'saldo' => max((float) $cita->total - (float) $cita->senado, 0.0),
            'confirmada' => (float) $cita->requerida <= 0 || (float) $cita->senado + 0.005 >= (float) $cita->requerida,
            // El enlace del correo: reprogramar o cancelar sin sesión, como en
            // el recordatorio. Es lo que la clienta va a querer hacer si algo
            // del recibo no coincide.
            'url' => Notificaciones::urlReprogramar(Notificaciones::tokenDeCita($idCita)),
        ];
    }

    /**
     * Manda el recibo. Devuelve si salió, a dónde, y si no, por qué.
     *
     * **Va después de registrar la seña y no atado a eso**: si el correo
     * falla, la seña ya entró y la cita ya está confirmada — es la regla de
     * siempre, la del comprobante al emitir. Lo que no puede pasar es que la
     * clienta se quede sin enterarse en silencio: si el envío falla, el aviso
     * de confirmación **queda en la cola de `notificacion`** y sale por el
     * despachador de cada diez minutos, sin el recibo adjunto pero con la
     * noticia que importa. Si salió, se anota como ENVIADA en esa misma cola,
     * para que el registro de lo que se le mandó a cada clienta esté entero.
     *
     * @param  list<int>  $idsCobros
     * @return array{ok: bool, email: string, motivo: string}
     */
    public static function mandarRecibo(int $idCita, array $idsCobros): array
    {
        $r = self::recibo($idCita, $idsCobros);
        if (! $r) {
            return ['ok' => false, 'email' => '', 'motivo' => 'la cita no existe'];
        }

        $email = trim((string) ($r['cita']->email ?? ''));
        $aviso = ($r['confirmada'] ? 'Recibimos tu seña de ' : 'Recibimos ')
            . money($r['recibido']) . ($r['confirmada'] ? '' : ' de seña')
            . ' por tu cita del ' . fecha($r['cita']->fecha_hora, 'd/m/Y') . ' a las '
            . fecha($r['cita']->fecha_hora, 'H:i')
            . ($r['confirmada']
                ? ': el horario queda confirmado.'
                : '. Para confirmar el horario faltan ' . money($r['requerida'] - $r['senado']) . '.')
            . ($r['saldo'] > 0 ? ' Al terminar la atención quedan ' . money($r['saldo']) . ' por pagar.' : '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'email' => '', 'motivo' => 'la clienta no tiene un correo cargado'];
        }

        try {
            Mail::to($email)->send(new ReciboSena($r));
        } catch (Throwable $e) {
            Log::error('Recibo de seña de la cita ' . $idCita . ' a ' . $email . ': ' . $e->getMessage());
            // La noticia sale igual por la cola, sin el adjunto.
            Notificaciones::crear(2, (int) $r['cita']->id_cliente, $idCita, $aviso);

            return ['ok' => false, 'email' => $email,
                    'motivo' => 'el correo no salió ahora; el aviso queda en la cola y sale solo, sin el recibo adjunto'];
        }

        try {
            DB::insert(
                "INSERT INTO notificacion (id_tipo_notificacion, id_cliente, id_cita, canal, mensaje, estado, fecha_envio)
                 VALUES (2, ?, ?, 'EMAIL', ?, 'ENVIADA', NOW())",
                [(int) $r['cita']->id_cliente, $idCita, mb_substr($aviso, 0, 300)]
            );
        } catch (Throwable $e) {
            report($e);
        }

        return ['ok' => true, 'email' => $email, 'motivo' => ''];
    }
}
