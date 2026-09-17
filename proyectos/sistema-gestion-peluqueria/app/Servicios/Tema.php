<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * La paleta del sistema, derivada de UN solo color.
 *
 * El salón elige un color —de un catálogo con nombre, o el suyo— y el resto se
 * calcula: el fondo, las tarjetas, el texto, los bordes, los tintes y las
 * barras oscuras. Es la idea de la maqueta que dio el usuario, «los demás
 * colores se ajustan solos», y la razón es la misma por la que la identidad
 * vive en variables: **con trece selectores sueltos lo normal es terminar con
 * un fondo de un color y los bordes de otro**, y nadie tiene por qué saber que
 * un contorno de control pide 3:1. Por eso desde la 7.125.0 el color es lo
 * ÚNICO que se elige: el «ajuste fino» de los trece tokens a mano —que la
 * 7.124.0 conservó de la versión anterior— se retiró por pedido del usuario.
 *
 * ## Cómo se deriva
 *
 * Del color elegido se toman el **tono** y la **saturación**, y cada token
 * tiene su **luminosidad de destino** y un **tope de saturación** — eso es
 * `reglas()`. Los destinos no se inventaron: son los del verde agua de la
 * identidad, medidos uno por uno, así que poniendo el verde agua se vuelve a
 * algo casi idéntico y cualquier otro color cae en la misma escala.
 *
 * **El verde agua exacto es un caso aparte, a propósito.** Ninguna fórmula
 * reproduce la paleta al píxel —su tono se mueve un poco entre los extremos—
 * así que `PRIMARIO` devuelve los valores literales de `app.css`: elegir
 * «Verde agua» tiene que dejar el sistema **exactamente** como se entrega, no
 * parecido.
 *
 * ## Por qué las reglas salen de acá y no están escritas en el JS
 *
 * La pantalla de Ajustes dibuja una vista previa en vivo, así que el
 * navegador también tiene que saber derivar. En vez de escribir la tabla dos
 * veces —que es el error que este proyecto ya tiene anotado en el espejo de la
 * agenda—, **la tabla viaja al navegador** (`data-ap-reglas`) y el JS sólo
 * repite las quince líneas de conversión HSL, que son matemática y no
 * criterio. Y lo que se GUARDA lo calcula siempre el servidor.
 */
class Tema
{
    /** El verde agua de la identidad: el que se entrega. */
    public const PRIMARIO = '#1A6B5F';

    /**
     * Los tamaños de letra: `clave => [rótulo, porcentaje del tamaño base]`.
     *
     * Escalan el documento ENTERO (`html{font-size}`), y funciona porque
     * `app.css` no tiene una sola medida en píxeles: crecen juntos el texto,
     * los botones, los altos de fila y los espacios.
     */
    public const LETRAS = [
        'normal' => ['Normal', '100%'],
        'grande' => ['Grande', '112.5%'],
        'muy_grande' => ['Muy grande', '125%'],
    ];

    /**
     * Los tipos de letra: `clave => [rótulo, pila de fuentes]`.
     *
     * **Son pilas del sistema, sin descargar nada**: este proyecto no trae
     * fuentes y no las va a traer por CDN —una fuente que no carga deja la
     * pantalla en blanco un segundo—. «Del sistema» es la de `app.css`, así
     * que su pila va vacía y el layout no escribe nada; las otras dos son las
     * que cualquier computadora y cualquier teléfono tienen: una más ancha y
     * abierta, para quien lee mejor con letra grande y separada, y una con
     * serifas, para quien viene de leer en papel.
     */
    public const FUENTES = [
        'sistema' => ['Del sistema', ''],
        'amplia' => ['Amplia', 'Verdana, Tahoma, "DejaVu Sans", sans-serif'],
        'clasica' => ['Clásica', 'Georgia, "Times New Roman", serif'],
    ];

    /**
     * Las paletas con nombre.
     *
     * `clave => [nombre, color, palabras por las que se busca]`. El nombre es
     * lo que se lee en pantalla; las palabras existen para que «violeta»
     * encuentre a Lavanda, que es como busca quien no sabe cómo se llama el
     * color que quiere.
     */
    public static function catalogo(): array
    {
        return [
            'agua' => ['Verde agua', self::PRIMARIO, 'verde turquesa'],
            'jade' => ['Jade', '#25766A', 'verde'],
            'bosque' => ['Bosque', '#275740', 'verde oscuro'],
            'salvia' => ['Salvia', '#4F6F52', 'verde'],
            'oliva' => ['Oliva', '#646A3A', 'verde'],
            'azul' => ['Azul sereno', '#356C98', 'azul'],
            'oceano' => ['Océano', '#245A78', 'azul'],
            'cielo' => ['Cielo', '#426FA6', 'azul'],
            'indigo' => ['Índigo', '#4F5692', 'azul violeta'],
            'lavanda' => ['Lavanda', '#71518B', 'violeta morado lila'],
            'ciruela' => ['Ciruela', '#704D77', 'violeta morado'],
            'malva' => ['Malva', '#8C618A', 'violeta rosa'],
            'rosa' => ['Rosa', '#98556A', 'rosado'],
            'cobre' => ['Cobre', '#9B5739', 'naranja marron'],
            'arena' => ['Arena', '#816044', 'beige marron'],
            'cafe' => ['Café', '#735743', 'marron'],
            'piedra' => ['Piedra', '#6C6962', 'gris'],
            'grafito' => ['Grafito', '#4B5358', 'gris negro'],
        ];
    }

    /**
     * Para cada token: `[tope de saturación, luminosidad de destino]`.
     *
     * El tope está para que un color muy saturado no deje el fondo de la
     * pantalla fosforescente: el fondo admite hasta 36 % de saturación y el
     * texto hasta 50 %, aunque el color elegido tenga 90 %.
     *
     * Dos tokens no son una mezcla sino una decisión, y van marcados:
     * `'primario'` es el color tal cual lo eligió el salón —no se lo corrige— y
     * `'sobre:x'` es **el texto que se lee encima de x**, blanco o el fondo
     * oscuro según cuál contraste más.
     */
    public static function reglas(): array
    {
        return [
            // --- Tema claro -------------------------------------------------
            'fondo' => [0.36, 0.974],
            'superficie' => [0.38, 0.94],
            'texto' => [0.50, 0.17],
            'texto_tenue' => [0.25, 0.29],
            'borde' => [0.42, 0.62],
            'borde_fuerte' => [0.25, 0.29],
            // El contorno de un control pide 3:1 sobre la tarjeta y la tarjeta
            // es clarísima, así que este va más oscuro que el resto de los
            // bordes. Medido contra las dieciocho paletas y dos extremos: el
            // peor caso es el oliva, con 3,1:1. Con [0.24, 0.47] daba 2,9:1 —
            // o sea un campo vacío que no se distingue del panel.
            'borde_control' => [0.28, 0.44],
            'placeholder' => [0.25, 0.38],
            'acento' => 'primario',
            'acento_hover' => [0.62, 0.17],
            'acento_claro' => [0.42, 0.62],
            'sobre_acento' => 'sobre:acento',
            'acento_suave' => [0.35, 0.79],
            'acento_texto' => [0.50, 0.17],
            'acento_tinte' => [0.33, 0.86],
            'acento_enfasis' => [0.60, 0.26],

            // --- Las barras, que son oscuras en los dos temas ---------------
            'sup_oscura' => [0.60, 0.17],
            'sup_oscura_2' => [0.60, 0.26],
            'sup_oscura_borde' => [0.25, 0.29],
            'sobre_oscura' => [0.45, 0.93],
            'sobre_oscura_tenue' => [0.33, 0.86],
            'acento_sobre_oscura' => [0.42, 0.62],

            // --- Tema oscuro ------------------------------------------------
            'fondo_oscuro' => [0.36, 0.08],
            'superficie_oscuro' => [0.38, 0.13],
            'texto_oscuro' => [0.45, 0.93],
            'texto_tenue_oscuro' => [0.30, 0.70],
            'borde_oscuro' => [0.35, 0.26],
            'borde_fuerte_oscuro' => [0.24, 0.47],
            'borde_control_oscuro' => [0.24, 0.47],
            'placeholder_oscuro' => [0.21, 0.53],
            'acento_oscuro' => [0.42, 0.62],
            'acento_hover_oscuro' => [0.35, 0.79],
            'acento_claro_oscuro' => [0.42, 0.62],
            'sobre_acento_oscuro' => 'sobre:acento_oscuro',
            'acento_suave_oscuro' => [0.38, 0.20],
            'acento_texto_oscuro' => [0.42, 0.62],
            'acento_tinte_oscuro' => [0.38, 0.16],
            'acento_enfasis_oscuro' => [0.42, 0.62],

            // --- Las barras, un paso más profundas en el tema oscuro --------
            'sup_oscura_d' => [0.50, 0.11],
            'sup_oscura_2_d' => [0.60, 0.17],
            'sup_oscura_borde_d' => [0.60, 0.26],
            'sobre_oscura_tenue_d' => [0.30, 0.70],
        ];
    }

    /**
     * Qué variable CSS escribe cada token, en el tema claro.
     *
     * Vive acá y no adentro del layout porque lo leen **tres** lugares: el
     * layout, que las escribe; la guardia que comprueba que el verde agua de
     * `Tema` siga siendo el de `app.css`; y quien tenga que agregar un token.
     * Escrito en cada uno, el día que se renombre una variable quedan dos
     * apuntando al vacío y ninguna da error.
     */
    public static function variables(): array
    {
        return [
            'fondo' => ['--fondo'],
            'superficie' => ['--superficie'],
            'texto' => ['--texto'],
            'texto_tenue' => ['--texto-tenue'],
            'borde' => ['--borde'],
            'borde_fuerte' => ['--borde-fuerte'],
            'borde_control' => ['--borde-control'],
            'placeholder' => ['--placeholder'],
            'acento' => ['--acento'],
            'acento_hover' => ['--acento-oscuro'],
            'acento_claro' => ['--acento-claro'],
            'sobre_acento' => ['--sobre-acento'],
            'acento_suave' => ['--acento-suave'],
            'acento_texto' => ['--acento-texto'],
            'acento_tinte' => ['--acento-tinte'],
            'acento_enfasis' => ['--acento-enfasis'],
            'sup_oscura' => ['--sup-oscura'],
            'sup_oscura_2' => ['--sup-oscura-2'],
            'sup_oscura_borde' => ['--sup-oscura-borde'],
            'sobre_oscura' => ['--sobre-oscura'],
            'sobre_oscura_tenue' => ['--sobre-oscura-tenue'],
            'acento_sobre_oscura' => ['--acento-sobre-oscura'],
        ];
    }

    /**
     * Las mismas variables en el tema oscuro, que salen de otros tokens.
     *
     * Las dos `--bs-*` de acá no tienen gemela en el tema claro: `app.css` las
     * declara con un valor literal en el bloque oscuro —no con un `var()`— así
     * que sin escribirlas se quedarían en verde agua mientras el resto cambia.
     */
    public static function variablesOscuro(): array
    {
        return [
            'fondo_oscuro' => ['--fondo'],
            'superficie_oscuro' => ['--superficie'],
            'texto_oscuro' => ['--texto'],
            'texto_tenue_oscuro' => ['--texto-tenue'],
            'borde_oscuro' => ['--borde'],
            'borde_fuerte_oscuro' => ['--borde-fuerte'],
            'borde_control_oscuro' => ['--borde-control'],
            'placeholder_oscuro' => ['--placeholder'],
            'acento_oscuro' => ['--acento'],
            'acento_hover_oscuro' => ['--acento-oscuro'],
            'acento_claro_oscuro' => ['--acento-claro'],
            'sobre_acento_oscuro' => ['--sobre-acento'],
            'acento_suave_oscuro' => ['--acento-suave', '--bs-secondary-bg'],
            'acento_texto_oscuro' => ['--acento-texto'],
            'acento_tinte_oscuro' => ['--acento-tinte', '--bs-tertiary-bg'],
            'acento_enfasis_oscuro' => ['--acento-enfasis'],
            'sup_oscura_d' => ['--sup-oscura'],
            'sup_oscura_2_d' => ['--sup-oscura-2'],
            'sup_oscura_borde_d' => ['--sup-oscura-borde'],
            'sobre_oscura_tenue_d' => ['--sobre-oscura-tenue'],
        ];
    }

    /**
     * La paleta entera a partir de un color.
     *
     * Devuelve los 42 tokens en mayúsculas. Un color inválido cae en el verde
     * agua: esto lo llama el layout en cada petición y no es lugar para
     * reventar.
     */
    public static function paleta(string $primario): array
    {
        $primario = self::normalizar($primario);
        if ($primario === '') {
            $primario = self::PRIMARIO;
        }
        if ($primario === self::PRIMARIO) {
            return self::identidad();
        }

        [$h, $s] = self::hsl($primario);
        $tokens = [];

        foreach (self::reglas() as $token => $regla) {
            if ($regla === 'primario') {
                $tokens[$token] = $primario;

                continue;
            }
            if (is_string($regla)) {
                continue;   // los «sobre:» se resuelven abajo, ya con todo puesto
            }
            [$tope, $luz] = $regla;
            $tokens[$token] = self::deHsl($h, min($s, $tope), $luz);
        }

        foreach (self::reglas() as $token => $regla) {
            if (is_string($regla) && str_starts_with($regla, 'sobre:')) {
                $sobre = $tokens[substr($regla, 6)] ?? $primario;
                $tokens[$token] = self::mejorTexto($sobre, $tokens['fondo_oscuro']);
            }
        }

        return $tokens;
    }

    /**
     * Los valores literales del verde agua, tal como están en `app.css`.
     *
     * No es una copia por comodidad: es la garantía de que elegir «Verde agua»
     * —o restablecer— deja el sistema **exactamente** como se entrega. Una
     * fórmula lo dejaría parecido, y «parecido» sobre la identidad del sistema
     * no alcanza.
     */
    private static function identidad(): array
    {
        return [
            'fondo' => '#F2FBF9', 'superficie' => '#E1FAF5', 'texto' => '#0F4C43',
            'texto_tenue' => '#375B59', 'borde' => '#7AC3B7', 'borde_fuerte' => '#375B59',
            'borde_control' => '#5E918A', 'placeholder' => '#4A776F',
            'acento' => '#1A6B5F', 'acento_hover' => '#0F4C43', 'acento_claro' => '#7AC3B7',
            'sobre_acento' => '#FFFFFF', 'acento_suave' => '#B6DFD8', 'acento_texto' => '#0F4C43',
            'acento_tinte' => '#CEEAE5', 'acento_enfasis' => '#1A6B5F',

            'sup_oscura' => '#0F4C43', 'sup_oscura_2' => '#1A6B5F', 'sup_oscura_borde' => '#375B59',
            'sobre_oscura' => '#E1FAF5', 'sobre_oscura_tenue' => '#CEEAE5',
            'acento_sobre_oscura' => '#7AC3B7',

            'fondo_oscuro' => '#0B1F1C', 'superficie_oscuro' => '#12302B', 'texto_oscuro' => '#E1FAF5',
            'texto_tenue_oscuro' => '#9CC9C1', 'borde_oscuro' => '#2C5A53',
            'borde_fuerte_oscuro' => '#5E918A', 'borde_control_oscuro' => '#5E918A',
            'placeholder_oscuro' => '#6F9E97',
            'acento_oscuro' => '#7AC3B7', 'acento_hover_oscuro' => '#B6DFD8',
            'acento_claro_oscuro' => '#7AC3B7', 'sobre_acento_oscuro' => '#0B1F1C',
            'acento_suave_oscuro' => '#1E4A43', 'acento_texto_oscuro' => '#7AC3B7',
            'acento_tinte_oscuro' => '#173A35', 'acento_enfasis_oscuro' => '#7AC3B7',

            'sup_oscura_d' => '#0A2D28', 'sup_oscura_2_d' => '#0F4C43',
            'sup_oscura_borde_d' => '#1A6B5F', 'sobre_oscura_tenue_d' => '#9CC9C1',
        ];
    }

    // ---------------------------------------------------------------------
    // Color: lo mínimo para derivar y para medir contraste.
    // Son las mismas quince líneas que repite `app.js` para la vista previa.
    // ---------------------------------------------------------------------

    /** `#a1b2c3` va a `#A1B2C3`. Cadena vacía si no es un color. */
    public static function normalizar(string $hex): string
    {
        $hex = strtoupper(trim($hex));

        return preg_match('/^#[0-9A-F]{6}$/', $hex) ? $hex : '';
    }

    /** Los tres canales en 0..1. */
    public static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
    }

    /** De `#1A6B5F` a `26,107,95`, que es lo que piden las variables `--bs-*-rgb`. */
    public static function csv(string $hex): string
    {
        return hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2)) . ',' . hexdec(substr($hex, 5, 2));
    }

    /** `[tono 0..360, saturación 0..1, luminosidad 0..1]`. */
    public static function hsl(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        $l = ($max + $min) / 2;
        $h = 0.0;
        $s = 0.0;

        if ($d > 0.0) {
            $s = $d / (1 - abs(2 * $l - 1));
            if ($max === $r) {
                $h = fmod(($g - $b) / $d, 6);
            } elseif ($max === $g) {
                $h = ($b - $r) / $d + 2;
            } else {
                $h = ($r - $g) / $d + 4;
            }
            $h = fmod($h * 60 + 360, 360);
        }

        return [$h, $s, $l];
    }

    /** El camino de vuelta. */
    public static function deHsl(float $h, float $s, float $l): string
    {
        $a = $s * min($l, 1 - $l);
        $f = static function (float $n) use ($h, $l, $a): string {
            $k = fmod($n + $h / 30, 12);
            $v = $l - $a * max(-1, min($k - 3, 9 - $k, 1));

            return str_pad(strtoupper(dechex((int) round($v * 255))), 2, '0', STR_PAD_LEFT);
        };

        return '#' . $f(0) . $f(8) . $f(4);
    }

    /** La luminancia relativa de la WCAG. */
    public static function luminancia(string $hex): float
    {
        $c = array_map(
            static fn (float $v): float => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4,
            self::rgb($hex)
        );

        return $c[0] * 0.2126 + $c[1] * 0.7152 + $c[2] * 0.0722;
    }

    /** Cuántos a uno contrastan dos colores. */
    public static function contraste(string $a, string $b): float
    {
        $x = self::luminancia($a);
        $y = self::luminancia($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /**
     * Qué se lee encima de un fondo: el blanco o el oscuro de la propia paleta.
     *
     * Es lo que hace que la polaridad se acomode sola. El verde agua es oscuro
     * y lleva texto blanco; si el salón elige un color claro, el texto de los
     * botones pasa a ser oscuro **sin que nadie tenga que darse cuenta**, que
     * es justo lo que la 7.123.0 tuvo que corregir a mano en cuarenta lugares.
     */
    public static function mejorTexto(string $fondo, string $oscuro = '#0B1F1C'): string
    {
        return self::contraste('#FFFFFF', $fondo) >= self::contraste($oscuro, $fondo)
            ? '#FFFFFF'
            : $oscuro;
    }
}
