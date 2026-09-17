@extends('layout.app')

@section('titulo', 'Ajustes')

@php
    use App\Servicios\Tema;

    $apC = $tema['tokens'];
    $apNombrePaleta = $tema['paleta'] && isset($catalogo[$tema['paleta']])
        ? $catalogo[$tema['paleta']][0]
        : 'Color propio';

    /* Con qué token se pinta cada variable de la vista previa, en claro y en
       oscuro. Son propias (`--pv-*`) y no las del sistema a propósito: la previa
       tiene que poder mostrar una paleta que TODAVÍA NO se guardó — leyendo las
       de la página mostraría siempre la de ahora, o sea nada.

       **El mapa viaja al navegador** (`data-ap-previa-mapa`) en vez de estar
       escrito también en `app.js`: escrito dos veces, la previa y lo que se
       guarda se desfasan y nadie lo nota hasta que alguien mira. */
    $apMapaPrevia = [
        '--pv-fondo' => ['fondo', 'fondo_oscuro'],
        '--pv-superficie' => ['superficie', 'superficie_oscuro'],
        '--pv-texto' => ['texto', 'texto_oscuro'],
        '--pv-tenue' => ['texto_tenue', 'texto_tenue_oscuro'],
        '--pv-borde' => ['borde', 'borde_oscuro'],
        '--pv-acento' => ['acento', 'acento_oscuro'],
        '--pv-sobre-acento' => ['sobre_acento', 'sobre_acento_oscuro'],
        '--pv-suave' => ['acento_suave', 'acento_suave_oscuro'],
        '--pv-acento-texto' => ['acento_texto', 'acento_texto_oscuro'],
        '--pv-barra' => ['sup_oscura', 'sup_oscura_d'],
        '--pv-sobre-barra' => ['sobre_oscura', 'sobre_oscura'],
        '--pv-acento-barra' => ['acento_sobre_oscura', 'acento_sobre_oscura'],
    ];

    /* El tamaño de letra guardado ya escala la página entera, previa incluida.
       Para que la previa mida lo que va a medir de verdad, su tamaño es el
       ELEGIDO dividido por el que está puesto: con los dos iguales, 1rem. */
    $apPct = fn (string $k): float => (float) rtrim(Tema::LETRAS[$k][1] ?? '100%', '%');
    $apLetraBase = $apPct($tema['letra']);
    $apLetraElegida = (string) old('letra', $tema['letra']);
    $apFuenteElegida = (string) old('fuente', $tema['fuente']);

    $apPrevia = function (bool $oscuro) use ($apC, $apMapaPrevia, $apPct, $apLetraBase, $apLetraElegida, $apFuenteElegida) {
        $css = '';
        foreach ($apMapaPrevia as $var => $par) {
            $css .= $var . ':' . ($apC[$par[$oscuro ? 1 : 0]] ?? '#000000') . ';';
        }
        $pila = Tema::FUENTES[$apFuenteElegida][1] ?? '';

        return $css . '--pv-letra:' . round($apPct($apLetraElegida) / $apLetraBase, 4) . 'rem;'
            . '--pv-fuente:' . ($pila !== '' ? $pila : 'inherit');
    };

    /* Las clases de la muestra «Aa» van escritas ENTERAS, no armadas con la
       clave: `AndamiajeTest` comprueba que toda clase del CSS aparezca en algún
       marcado, y una interpolada no aparece — el día que se renombre, la regla
       queda apuntando al vacío sin que nada dé error. */
    $apAa = ['normal' => 'sgp-ap-aa-chico', 'grande' => 'sgp-ap-aa-grande', 'muy_grande' => 'sgp-ap-aa-mayor'];
@endphp

@section('contenido')
    {{-- **Es UNO para todo el sistema, no uno por sucursal**, y por eso tiene su
         propia pantalla: hasta la 7.124.0 vivía arriba de la lista de locales y
         la sobrecargaba. La clienta entra por un único portal y ve una sola
         marca; quien atiende ve la misma trabaje donde trabaje.

         La forma es la de la maqueta que dio el usuario: a la izquierda lo que
         se decide, en cuatro desplegables con un título que dice para qué es
         cada uno; a la derecha CÓMO VA A QUEDAR, mediana; y un solo botón al
         pie. --}}
    <x-encabezado sub="Cómo se presenta y cómo se ve el sistema. Es uno para todo el salón: lo ven el equipo y las clientas, en todas las pantallas y de una." />

    <div class="sgp-panel">
        {{-- **Un solo formulario y un solo POST.** El logo se quita y la
             apariencia se restablece con los suyos, al pie de la pantalla: un
             `<form>` no se anida adentro de otro, así que sus botones los
             alcanzan con el atributo `form`. --}}
        <form id="formAjustes" method="post" action="{{ route('seguridad.ajustes.guardar') }}"
              enctype="multipart/form-data" data-ap
              data-ap-reglas="{{ json_encode($reglas) }}"
              data-ap-catalogo="{{ json_encode(array_map(fn ($p) => $p[1], $catalogo)) }}"
              data-ap-previa-mapa="{{ json_encode($apMapaPrevia) }}"
              {{-- El verde agua no sale de la fórmula: son los valores literales
                   de `app.css`, para que elegirlo deje el sistema EXACTAMENTE
                   como se entrega. El navegador los necesita por lo mismo. --}}
              data-ap-identidad="{{ json_encode(Tema::paleta(Tema::PRIMARIO)) }}"
              data-ap-primario-base="{{ Tema::PRIMARIO }}"
              data-ap-letras="{{ json_encode(array_map(fn ($l) => (float) rtrim($l[1], '%'), Tema::LETRAS)) }}"
              data-ap-letra-base="{{ $apLetraBase }}"
              data-ap-fuentes="{{ json_encode(array_map(fn ($f) => $f[1], Tema::FUENTES)) }}">
            @csrf

            <div class="sgp-ap-layout">
                <div class="sgp-ap-col">

                    {{-- ----------------------------------------------------------
                         Identidad: el nombre y el logo. Se pliega porque se carga
                         una vez.

                         OJO: el nombre va SIN `required` en el marcado. Un campo
                         obligatorio adentro de un `<details>` cerrado está en
                         `display:none`, y ahí el navegador se niega a enviar el
                         formulario **sin decir nada** — es el defecto de la 7.67.0.
                         `app.js` se lo pone al abrirlo y se lo saca al cerrarlo, y
                         el servidor lo vuelve a comprobar, que es el control de
                         verdad.
                         ---------------------------------------------------------- --}}
                    <details class="sgp-ap-det" data-ap-det>
                        <summary><i class="bi bi-shop"></i> Identidad del sistema</summary>
                        <div class="sgp-ap-det-cuerpo">
                            <div class="sgp-ap-campos">
                                <div>
                                    <label class="form-label" for="nombre_salon">Nombre del salón *</label>
                                    <input class="form-control" id="nombre_salon" name="nombre_salon"
                                           data-ap-obligatorio data-ap-nombre-campo
                                           maxlength="60" value="{{ old('nombre_salon', $nombreSalon) }}">
                                </div>
                                <div>
                                    <label class="form-label" for="logo">Logo</label><x-ayuda>PNG, JPG o WEBP, hasta 512 KB. Si no subís nada, queda el que está.</x-ayuda>
                                    <input type="file" class="form-control" id="logo" name="logo"
                                           accept="image/png,image/jpeg,image/webp">
                                </div>
                            </div>

                            @if ($logo)
                                <div class="d-flex align-items-center gap-3 mt-3">
                                    <img src="{{ $logo }}" alt="Logo del salón"
                                         style="height:40px;width:auto;border-radius:6px;background:var(--sup-oscura);padding:4px">
                                    <span class="text-muted-warm" style="font-size:.82rem">Logo actual</span>
                                    <button class="btn btn-sm btn-outline-neutro" type="submit" form="formQuitarLogo"
                                            data-confirmar="¿Quitar el logo y volver al ícono por defecto?">
                                        <i class="bi bi-trash"></i> Quitar</button>
                                </div>
                            @endif
                        </div>
                    </details>

                    {{-- ----------------------------------------------------------
                         Apariencia: UN color, y de él sale el resto. Abre
                         desplegado porque es lo que se toca seguido y lo que la
                         previa de al lado sigue.
                         ---------------------------------------------------------- --}}
                    <details class="sgp-ap-det" data-ap-det open>
                        <summary><i class="bi bi-palette"></i> Apariencia</summary>
                        <div class="sgp-ap-det-cuerpo">
                            <div class="sgp-ap-fila">
                                <span class="sgp-ap-muestras" data-ap-muestras aria-hidden="true">
                                    <span style="background:{{ $apC['superficie'] }}"></span>
                                    <span style="background:{{ $apC['borde'] }}"></span>
                                    <span style="background:{{ $apC['acento'] }}"></span>
                                </span>
                                <span class="sgp-ap-nombre" data-ap-nombre>{{ $apNombrePaleta }}</span>
                                {{-- Arranca escondido y lo muestra `app.js`: sin JavaScript
                                     no hay nada que plegar, así que un botón que abre lo
                                     que ya está abierto sólo confunde. --}}
                                <button type="button" class="btn btn-sm btn-outline-neutro" data-ap-cambiar
                                        aria-controls="apPicker" aria-expanded="true" hidden>
                                    <i class="bi bi-palette"></i> Cambiar</button>
                            </div>

                            {{-- El elegidor. **Arranca ABIERTO en el marcado y lo pliega el
                                 JS**: sin `app.js` se ven las dieciocho paletas, se elige una
                                 y se guarda igual — son etiquetas de radio, no botones. --}}
                            <div class="sgp-ap-picker" id="apPicker">
                                <label class="form-label" for="apBuscar" data-ap-buscar-rot hidden>Buscar un color</label>
                                <input class="form-control mb-1" type="search" id="apBuscar" data-ap-buscar hidden
                                       placeholder="Por ejemplo, verde" autocomplete="off">

                                <div class="sgp-ap-grid" data-ap-grid>
                                    @foreach ($catalogo as $clave => $p)
                                        @php $pal = Tema::paleta($p[1]); @endphp
                                        <label class="sgp-ap-paleta" data-ap-opcion
                                               data-ap-busca="{{ $p[0] . ' ' . $p[2] }}">
                                            <input type="radio" name="paleta" value="{{ $clave }}"
                                                   @checked(old('paleta', $tema['paleta']) === $clave)>
                                            <span class="sgp-ap-tiras" aria-hidden="true">
                                                <i style="background:{{ $pal['superficie'] }}"></i>
                                                <i style="background:{{ $pal['borde'] }}"></i>
                                                <i style="background:{{ $pal['acento'] }}"></i>
                                            </span>
                                            <span class="sgp-ap-paleta-nom">{{ $p[0] }}
                                                <span class="sgp-ap-tick" aria-hidden="true">✓</span></span>
                                        </label>
                                    @endforeach
                                    <div class="sgp-ap-vacio" data-ap-vacio hidden>No encontramos ese color.</div>
                                </div>

                                <nav class="sgp-ap-pag" data-ap-pag hidden aria-label="Más colores">
                                    <button type="button" class="btn btn-sm btn-outline-neutro" data-ap-antes>
                                        <i class="bi bi-chevron-left"></i> Anterior</button>
                                    <span data-ap-pagina aria-live="polite"></span>
                                    <button type="button" class="btn btn-sm btn-outline-neutro" data-ap-luego>
                                        Siguiente <i class="bi bi-chevron-right"></i></button>
                                </nav>

                                <div class="sgp-ap-propio">
                                    <label class="mb-0" for="apPropioR">
                                        <input class="form-check-input me-1" type="radio" name="paleta" value=""
                                               id="apPropioR" data-ap-propio-radio
                                               @checked(old('paleta', $tema['paleta']) === null)>
                                        Color principal del salón
                                    </label>
                                    <input type="color" class="form-control form-control-color" name="color_primario"
                                           id="apPropio" data-ap-propio aria-label="Color propio del salón"
                                           value="{{ old('color_primario', $tema['primario']) }}">
                                </div>
                            </div>
                        </div>
                    </details>

                    {{-- ----------------------------------------------------------
                         Datos fiscales: lo que sale impreso en la factura
                         electrónica. Va debajo de Identidad porque ambos bloques
                         describen al salón antes de elegir su apariencia.
                         ---------------------------------------------------------- --}}
                    <details class="sgp-ap-det" data-ap-det>
                        <summary><i class="bi bi-receipt"></i> Datos fiscales</summary>
                        <div class="sgp-ap-det-cuerpo">
                            <div class="sgp-ap-campos">
                                <div>
                                    <label class="form-label" for="actividad_cod">Código de actividad</label><x-ayuda>El de la DNIT, el mismo del RUC. Sale impreso en la factura electrónica.</x-ayuda>
                                    <input class="form-control" id="actividad_cod" name="actividad_cod"
                                           data-solo="numeros" inputmode="numeric" maxlength="10"
                                           value="{{ old('actividad_cod', $actividad['cod']) }}" placeholder="96021">
                                </div>
                                <div class="sgp-ap-ancho">
                                    <label class="form-label" for="actividad_desc">Actividad económica</label><x-ayuda campo="actividad_desc" />
                                    <input class="form-control" id="actividad_desc" name="actividad_desc" maxlength="120"
                                           value="{{ old('actividad_desc', $actividad['desc']) }}"
                                           placeholder="PELUQUERIA Y OTROS TRATAMIENTOS DE BELLEZA">
                                </div>
                                <div class="sgp-ap-ancho">
                                    <label class="form-label" for="email_fiscal">Correo con el que facturás</label><x-ayuda>Va impreso en la factura; no es a donde llegan los avisos del sistema.</x-ayuda>
                                    <input class="form-control" id="email_fiscal" name="email_fiscal" type="email"
                                           maxlength="120" value="{{ old('email_fiscal', $emailFiscal) }}">
                                </div>
                            </div>
                        </div>
                    </details>

                    {{-- ----------------------------------------------------------
                         Texto: cuánto mide y con qué letra. Las dos cosas escalan
                         y cambian la pantalla ENTERA —texto, botones, filas—, no
                         un renglón. Son etiquetas de radio: se eligen y se guardan
                         con el JavaScript apagado.
                         ---------------------------------------------------------- --}}
                    <details class="sgp-ap-det" data-ap-det>
                        <summary><i class="bi bi-fonts"></i> Texto</summary>
                        <div class="sgp-ap-det-cuerpo">
                            <div class="sgp-ap-grupo">
                                <h3>Tamaño</h3>
                                <div class="sgp-ap-opciones">
                                    @foreach (Tema::LETRAS as $clave => $l)
                                        <label class="sgp-ap-opcion">
                                            <input type="radio" name="letra" value="{{ $clave }}" data-ap-letra="{{ $clave }}"
                                                   @checked($apLetraElegida === $clave)>
                                            <span class="{{ $apAa[$clave] ?? 'sgp-ap-aa-chico' }}" aria-hidden="true">Aa</span> {{ $l[0] }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="sgp-ap-grupo mb-0">
                                <h3>Tipo de letra</h3>
                                <div class="sgp-ap-opciones">
                                    @foreach (Tema::FUENTES as $clave => $f)
                                        <label class="sgp-ap-opcion" @if ($f[1] !== '') style="font-family:{{ $f[1] }}" @endif>
                                            <input type="radio" name="fuente" value="{{ $clave }}" data-ap-fuente="{{ $clave }}"
                                                   @checked($apFuenteElegida === $clave)>
                                            <span class="sgp-ap-aa-grande" aria-hidden="true">Aa</span> {{ $f[0] }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </details>

                </div>

                {{-- ------------------------------------------------------------
                     La vista previa. Sin JavaScript muestra lo que HAY guardado,
                     que ya es una respuesta; con JavaScript sigue a lo que se
                     toca. Es mediana a propósito: a media pantalla se comía la
                     computadora, y lo que hay que mirar cabe en 300 px.

                     Los dos botones de arriba **NO guardan el tema**: sólo cambian
                     la previa. El claro u oscuro lo elige cada persona en Mi
                     cuenta, y así queda; acá hace falta poder mirar cómo cae el
                     color en los dos. Van escondidos porque sin `app.js` no hay
                     nada que alternar.
                     ------------------------------------------------------------ --}}
                <div class="sgp-ap-col sgp-ap-lado">
                    <div class="sgp-ap-previa-caja">
                        <div class="sgp-ap-previa-cab">
                            <span class="sgp-ap-previa-rotulo">Vista previa</span>
                            <span class="sgp-ap-modos" data-ap-modos hidden role="group" aria-label="Mirar la previa en">
                                <button type="button" class="sgp-ap-modo" data-ap-modo="claro" aria-pressed="true"
                                        title="Pantalla clara"><i class="bi bi-sun"></i></button>
                                <button type="button" class="sgp-ap-modo" data-ap-modo="oscuro" aria-pressed="false"
                                        title="Pantalla oscura"><i class="bi bi-moon"></i></button>
                            </span>
                        </div>
                        <section class="sgp-ap-previa" data-ap-previa style="{{ $apPrevia(false) }}"
                                 aria-label="Ejemplo de cómo va a verse la agenda">
                            <header class="sgp-ap-previa-barra">
                                <span class="sgp-ap-previa-logo">
                                    @if ($logo)
                                        <img src="{{ $logo }}" alt="">
                                    @else
                                        <i class="bi bi-scissors" aria-hidden="true"></i>
                                    @endif
                                </span>
                                <span class="sgp-ap-previa-nombre" data-ap-previa-nombre>{{ $nombreSalon }}</span>
                            </header>
                            <div class="sgp-ap-previa-cuerpo">
                                <div class="sgp-ap-previa-fecha">Hoy · {{ fecha_larga(ahora_bd()) }}</div>
                                <div class="sgp-ap-previa-titulo">Agenda del día</div>

                                <div class="sgp-ap-cita">
                                    <span class="sgp-ap-hora">09:00</span>
                                    <div>
                                        <div class="sgp-ap-persona">Ana López</div>
                                        <div class="sgp-ap-servicio">Corte de dama y brushing</div>
                                        <span class="sgp-ap-ok">✓ Confirmada</span>
                                    </div>
                                </div>
                                <div class="sgp-ap-cita">
                                    <span class="sgp-ap-hora">10:30</span>
                                    <div>
                                        <div class="sgp-ap-persona">Camila Vera</div>
                                        <div class="sgp-ap-servicio">Coloración completa</div>
                                        <span class="sgp-ap-ok">✓ Confirmada</span>
                                    </div>
                                </div>

                                <div class="sgp-ap-boton">+ Nueva cita</div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <div class="sgp-ap-pie">
                @if ($personalizado)
                    <button type="submit" form="formRestablecerTema" class="btn btn-outline-neutro"
                            data-confirmar="¿Volver al verde agua y a la letra de fábrica?">
                        <i class="bi bi-arrow-counterclockwise"></i> Volver a los de fábrica</button>
                @endif
                <button type="reset" class="btn btn-outline-neutro" data-ap-deshacer>
                    <i class="bi bi-x-lg"></i> Deshacer</button>
                <button class="btn btn-acento"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </form>
    </div>

    {{-- Los dos POST que no pueden vivir adentro del formulario grande. --}}
    <form id="formQuitarLogo" method="post" action="{{ route('seguridad.ajustes.logo.quitar') }}" class="d-none">
        @csrf
    </form>
    <form id="formRestablecerTema" method="post" action="{{ route('seguridad.ajustes.restablecer') }}" class="d-none">
        @csrf
    </form>
@endsection
