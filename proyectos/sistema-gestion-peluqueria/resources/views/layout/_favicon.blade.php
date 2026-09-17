{{--
    El ícono de la pestaña del navegador.

    **Es el logo que cargó el salón**, el mismo que se ve en la barra: quien
    tiene varias pestañas abiertas reconoce la del sistema por ahí, y una
    genérica lo obliga a leer el título.

    Sin logo cargado va la tijera de la identidad, dibujada como SVG embebido y
    no como archivo: no hay paso de compilación en este proyecto y un `.ico`
    suelto en `public/` es una cosa más que mantener al día con la paleta.
    Los colores van escritos a mano —el verde de `--acento` (#1A6B5F) y la tijera
    blanca— porque un `var()` de CSS no llega adentro de un `data:` URI. Va la
    pastilla entera y no la tijera suelta: el oro de antes se leía sobre una
    pestaña clara y sobre una oscura, y el verde oscuro solo, sobre la oscura,
    no se ve.

    Va en un partial y no copiado en cada `<head>` por el motivo de siempre: son
    siete pantallas con cabecera propia, y copiado se desfasan.
--}}
@php $sgpIco = \App\Servicios\Config::logo(); @endphp
@if ($sgpIco)
    <link rel="icon" href="{{ $sgpIco }}">
    <link rel="apple-touch-icon" href="{{ $sgpIco }}">
@else
    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
        . '<rect width="16" height="16" rx="3.5" fill="#1A6B5F"/>'
        . '<g fill="#FFFFFF" transform="translate(1.6 1.2) scale(.8)">'
        . '<path d="M3.5 3.5a2 2 0 1 1 2.7 1.87l1.8 2.4 1.8-2.4A2 2 0 1 1 12.5 3.5a2 2 0 0 1-3.1 1.66'
        . 'L7.6 7.53l4.3 5.72a.5.5 0 0 1-.8.6L8 9.2l-3.1 4.14a.5.5 0 1 1-.8-.6l4.3-5.72-1.8-2.37'
        . 'A2 2 0 0 1 3.5 3.5Zm1 0a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm6 0a1 1 0 1 0 2 0 1 1 0 0 0-2 0Z"/>'
        . '</g></svg>'
    ) }}">
@endif
