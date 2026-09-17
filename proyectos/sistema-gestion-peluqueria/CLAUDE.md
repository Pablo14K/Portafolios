# SGP — Sistema de Gestión para Peluquería

Sistema web de gestión para una peluquería de Luque, Paraguay. TCC de Ingeniería en Informática.
**Laravel 13 + MariaDB 10.4.** Para levantarlo: `README.md`. Para publicarlo: `DESPLIEGUE.md` y
`ACTUALIZAR.md`. El porqué de cada versión: `HISTORIAL.md`.

> Se llama **SGP** (Sistema de Gestión para Peluquería). Tres cosas quedan a propósito con la
> grafía vieja `spg`: `/docker/spg` (la carpeta del panel de Hostinger), el repositorio
> `github.com/Pablo14K/SPG.git`, y `spg_migracion` en el historial. **El proyecto de Compose del
> servidor es `-p sgp`**: con otro nombre se crean volúmenes vacíos y la base y las fotos quedan
> huérfanas.

> El sistema nació sin framework y se migró a Laravel en la **6.0.0**. La migración cambió la
> arquitectura, no las reglas: la lógica sigue viviendo en la base.

## Para empezar: los comandos y dónde está cada cosa

*Esto es lo primero que lee Claude Code. Sus reglas mandan sobre cualquier costumbre del framework.*

Todo corre **dentro del contenedor** —`docker compose up -d`, la base en el 3307 y la aplicación
en el 8000—. No hay lint ni paso de compilación: Bootstrap viene por CDN y `public/assets/*.css`
y `*.js` se editan a mano.

| Para | Comando |
|---|---|
| La batería entera | `docker compose exec app php artisan test` |
| **Una sola prueba** | `docker compose exec app php artisan test --filter=nombre_del_metodo` |
| Un solo archivo | `docker compose exec app php artisan test tests/Feature/HuellaTest.php` |
| Comparar lo que corre contra lo que se entrega | `docker compose exec app php artisan sgp:diagnostico` |
| Lo que le falta cargar al salón | `docker compose exec app php artisan sgp:pendientes` |
| Entrar a la base | `docker compose exec bd mysql -uroot -proot peluqueria_test` |
| Regenerar el `.sql` que se entrega | ver *Solo hay DOS archivos `.sql`* |
| La vista previa del navegador de Claude Code | `preview_start` con `sgp` (`.claude/launch.json`): el PHP del host (`C:/php/php.exe`) en el **8002**, contra la base del contenedor por el 3307 — lo único que no corre en Docker |

- **Las pruebas corren contra `peluqueria_test`** y escriben ahí (revierten con
  `DatabaseTransactions`). `php artisan test` cuenta «240 passed, 2 skipped»: las dos salteadas
  son legítimas. Si esa base tiene otros datos —hoy trae la operación del servidor— la batería
  se corre contra una copia del mes simulado: ver *Las pruebas*.
- **Nunca `artisan migrate` ni `RefreshDatabase`**: el esquema tiene una sola fuente, el `.sql`.

| Necesitás | Está en |
|---|---|
| Llamar una rutina de la base | `app/Servicios/Bd.php`, y la tabla de *Regla número uno* dice cuál |
| Las 221 rutas, cada una con su middleware | `routes/web.php` |
| Los 33 permisos y su jerarquía | `config/permisos.php` + `App\Servicios\Permisos` |
| Menús, migas y tarjetas de módulo | `config/navegacion.php` + `App\Servicios\Navegacion` |
| Lo que decide el salón y no el código | `App\Servicios\Config` (tabla `configuracion`) |
| El esquema, única fuente, sin migraciones | `basededatos/peluqueria_bd(base).sql` |
| Los cambios de base que viajan al servidor | `basededatos/actualizaciones/` |
| Qué se rompió antes y cómo | `HISTORIAL.md` y *Los nueve errores que este proyecto se hace a sí mismo* |

**Qué es «terminado»**: pruebas en verde, `sgp:diagnostico` sin observaciones si se tocó la base,
la versión y la fecha en `config/sgp.php`, la fila en `HISTORIAL.md`, el commit con
`git commit -F archivo`, y decir al entregar si fue **sólo código** o **código y base** —con el
nombre del guion—.

## Regla número uno: la lógica de negocio vive en la base de datos

La base (`peluqueria_bd`) tiene **22 procedimientos, 51 funciones, 17 triggers y 18 vistas**,
más **93 restricciones `CHECK`**. Laravel **consume** esa lógica, no la reimplementa: nada de
reescribirla en Eloquent. Antes de escribir un cálculo en PHP, buscá si ya existe la rutina.

**El puente es `App\Servicios\Bd`**: resuelve los parámetros de salida (`Bd::idDe()`), cierra el
cursor después de un `CALL` —sin eso la consulta siguiente falla con *unbuffered queries*— y
las transacciones (`Bd::enTransaccion()`).

| Necesitás | Usá |
|---|---|
| Stock de un producto | `fn_producto_stock(id, sucursal)` — nunca se guarda; el catálogo es único y el stock de cada sucursal |
| Agendar una cita | `sp_agendar_cita(...)` — valida con `fn_verificar_disponibilidad` |
| Qué horarios hay libres | `App\Servicios\Agenda` — arma los huecos y le pregunta a la base cuáles sirven |
| Avisar/recordar al cliente | `App\Servicios\Notificaciones` — la cola de `notificacion` |
| Reprogramar / cancelar | `sp_reprogramar_cita`, `sp_cancelar_cita` |
| Emitir comprobante | `sp_emitir_factura(...)` — numera con `fn_timbrado_vigente(tipo, fecha, sucursal)` + `fn_siguiente_correlativo`; **el timbrado es el del local de la cita** |
| Qué se factura | **`cita_servicio`, no `servicio_realizado`** |
| Anular factura / cobro | `sp_anular_factura`, `sp_anular_cobro` — marcan estado, nunca borran |
| Nota de crédito | `sp_emitir_nota_credito(...)` — copia el detalle y numera con el timbrado del tipo 5 |
| Seña de reserva | `sp_registrar_sena(...)` — cobro atado a la cita, sin factura todavía |
| Revertir liquidación / anular pago a proveedor | `sp_revertir_pago_personal`, `sp_anular_pago_proveedor` |
| Sumar o descontar puntos | `sp_registrar_puntos(...)` — `chk_mp_tipo`: `ACUMULA` (+), `CANJE` (−), `AJUSTE` (≠0) |
| Totales de factura | `fn_factura_subtotal / _descuento / _total / _saldo` |
| Registrar cobro | `sp_registrar_cobro(...)` — una llamada por medio de pago |
| Detalle de tarjeta / cheque | `cobro_tarjeta`, `cobro_banco` — 1 a 1 con el cobro |
| Confirmar compra | `sp_confirmar_compra(...)` — genera los movimientos de stock |
| Movimiento de stock manual | `sp_registrar_movimiento_inventario(...)` |
| Saldo de caja | `fn_caja_saldo(id)` |
| Saldo de una cuenta bancaria | `fn_cuenta_saldo(id)` — el último arqueo (`arqueo_cuenta`) más lo movido desde entonces; NULL sin ningún arqueo |
| Lo que se le debe al proveedor | `fn_compra_saldo(id)` = `fn_compra_total` (renglones − `compra.descuento`) − `fn_compra_pagado` − `fn_compra_acreditado` (notas de crédito del proveedor, que **no son pagos**) |
| Qué cuota vence y cuánto le falta | `fn_compra_cuota_pendiente(id)` · `fn_compra_cuota_falta(id)` · `fn_compra_vencimiento(id)` — qué cuota cubre cada pago **se deduce por orden** |
| El IVA incluido en una compra | `vw_compra_impuestos` — por tasa, con el descuento repartido, como `vw_factura_impuestos` |
| Nivel / visitas / puntos del cliente | `fn_cliente_nivel`, `fn_cliente_visitas`, `fn_cliente_puntos` |
| Comisión de un servicio | `fn_comision_servicio(id_servicio_realizado)` |
| Quién trabaja tal día | `turno_laboral` ⋈ `turno_dia` ⋈ `usuario_turno` |
| Cuánto dura una cita | `fn_cita_duracion(id)` — la suma de los **turnos**; cada turno dura lo que el profesional que más tarda en él |
| Cuánto le toca a uno en esa cita | `fn_cita_duracion_de(id_cita, id_usuario)` — lo que le bloquea la agenda; **descuenta lo que ya cerró** (`cita_servicio.terminado_en`) |
| Desde cuándo le toca | `fn_cita_inicio_de(id_cita, id_usuario)` |
| Convertir 30 ml a stock | `consumo_a_stock()` / `stock_a_consumo()` de `app/Ayudas/formato.php` |
| La hora real del reloj | `ahora_bd()`, **nunca `date()`** — ver *La hora* |

Los triggers hacen cumplir reglas por su cuenta (`trg_movinv_bi` bloquea salidas sin stock,
`trg_factura_bi` valida el timbrado, `trg_produtil_ai` descuenta stock al registrar un consumo).
No hace falta duplicar esas validaciones, pero sí atrapar la `QueryException` y traducirla con
**`Bd::traducir($e, $mapa, $porDefecto)`**.

## Regla número dos: la base se mantiene normalizada, sin redundancia

**No se aceptan faltas a la 3FN ni datos repetidos.** El modelo se presenta como 3FN estricta.
Antes de agregar una columna, preguntate si ese dato ya vive en otro lado.

| Qué evitar | Cómo se resuelve acá |
|---|---|
| **Valores no atómicos** (1FN) | los días de un turno van en `turno_dia`, una fila por día, nunca `'LMXJVS'` en un `VARCHAR` |
| **Datos repetidos en varias tablas** | nombre, cédula, teléfono y email van **solo** en `persona`; `usuario`, `cliente` y `proveedor` la referencian |
| **Columnas derivadas guardadas** (3FN) | el stock, los totales y los saldos **no se guardan**: `fn_producto_stock`, `fn_factura_total`, `fn_caja_saldo` |

**Datos de personas: siempre `persona`.** Una entidad nueva con nombre y contacto se enlaza con
`id_persona`; se escribe con `Persona::guardar()` y `Persona::porDocumento()`, el único lugar que
toca esa tabla.

> Atomicidad y redundancia no son lo mismo: que `usuario` y `cliente` repitieran el nombre era
> redundancia de entidad, no una falta de 1FN. La 1FN se rompe cuando una columna guarda varios
> valores juntos.

## Versión del sistema

**Versionado semántico `X.Y.Z`, y sube en cada cambio.** Vive en `config/sgp.php`
(`sgp.version`) y se muestra en el pie de todas las pantallas.

| Dígito | Cuándo sube |
|---|---|
| **X** | cambio estructural que rompe la compatibilidad |
| **Y** | función o característica visual nueva, sin romper nada |
| **Z** | corrección: error, seguridad, falla técnica, documentación |

Al subir un dígito, los de la derecha vuelven a cero. Cuatro cosas en la misma tanda, siempre
juntas: `version` y `version_fecha` en `config/sgp.php`, una fila en `HISTORIAL.md` diciendo
**qué** cambió y **por qué**, y **el commit**.

### Al terminar un cambio, commitealo sin que haya que pedirlo

«Terminado» es: pruebas en verde, `sgp:diagnostico` sin observaciones si se tocó la base, y los
cuatro puntos de arriba. El mensaje sigue el formato del proyecto —mirá `git log`—:

```
X.Y.Z — qué cambió, en minúscula y en español

El porqué, no el qué: por qué estaba mal, qué se probó, qué se decidió no hacer.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```

- **Escribí el mensaje en un archivo y usá `git commit -F archivo`**: con `-m` un mensaje largo en
  español se corta o se le cuelan caracteres.
- Los commits van a `main`, que es la única rama. El usuario decide si abre una rama para algo.

### Al entregar, decí SI TOCÓ LA BASE y dá los comandos, sin más

**Si el commit tocó `basededatos/peluqueria_bd(base).sql`, tocó la base**:

```bash
git log --name-only --oneline -1 | grep -c "peluqueria_bd(base).sql"
```

El usuario quiere **sólo los bloques `bash`, en orden, según el caso**, sin explicaciones entre
medio. El resumen del cambio va aparte y corto.

**Caso A — sólo código:**

```bash
git push origin main
```

```bash
cd /tmp && rm -rf sgp-deploy && git clone https://github.com/Pablo14K/SPG.git sgp-deploy && cd sgp-deploy && docker compose -f docker-compose.produccion.yml -p sgp up -d --build
```

```bash
docker exec sgp_app grep -m1 "'version'" config/sgp.php
```

```bash
docker exec sgp_app php artisan sgp:diagnostico --produccion
```

**Caso B — código y base**, en este orden y no en otro (pedido del usuario): push, **el
respaldo primero**, después el despliegue, y recién ahí el guion:

```bash
git push origin main
```

```bash
mkdir -p /var/respaldos/sgp && docker exec sgp_bd sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --routines --triggers --events --single-transaction --default-character-set=utf8mb4 peluqueria_bd' > /var/respaldos/sgp/peluqueria_bd_$(date +%F_%H%M).sql
```

```bash
cd /tmp && rm -rf sgp-deploy && git clone https://github.com/Pablo14K/SPG.git sgp-deploy && cd sgp-deploy && docker compose -f docker-compose.produccion.yml -p sgp up -d --build
```

```bash
docker exec sgp_app grep -m1 "'version'" config/sgp.php
```

```bash
docker exec sgp_app sh -c 'mysql --skip-ssl -hbd -uroot -p"$DB_PASSWORD" --default-character-set=utf8mb4 peluqueria_bd < basededatos/actualizaciones/<el guion de esta versión>.sql'
```

```bash
docker exec sgp_app php artisan sgp:diagnostico --produccion
```

- **Si tocó la base, el guion tiene que existir** en `basededatos/actualizaciones/`, con la fecha
  y la versión en el nombre, re-ejecutable y sin tocar datos.
- **`down -v` nunca va en el servidor**: borra la operación del salón.
- Lo que sólo importa al desplegar (`DB_DATABASE`, credenciales del `.env` de producción, la
  contraseña de Gmail que quedó en el historial de git —`0de5fb6`, `e18367b`— y que rota el
  usuario) **se avisa al desplegar, no en cada tanda**. `sgp:diagnostico --produccion` lo cubre.
- **No hay avisos de mudanza** («esto ahora está en tal lado»): el sistema todavía no se entregó,
  así que no hay a quién avisarle. Los avisos que explican una consecuencia de HOY sí valen.

### Si al arreglar algo vas a dejar otra cosa sin funcionar, PREGUNTÁ ANTES

Apagar una función para resolver otro problema es decisión del usuario, no técnica. Pasó con el
correo: en la 6.4.0 se sacó la contraseña de Gmail del archivo versionado —motivo correcto— y
el sistema quedó en `MAIL_MAILER=log` **sin que nadie lo supiera durante meses**, con la pantalla
diciendo «te enviamos un código». La regla:

| Situación | Qué hacer |
|---|---|
| El arreglo apaga, limita o deja sin efecto algo que hoy anda | preguntar primero, diciendo qué se gana y qué se pierde |
| Hay una salida que conserva las dos cosas | proponerla — casi siempre existe |
| El usuario decide apagarla igual | hacerlo, **y dejar el apagado visible**: `sgp:diagnostico` y la pantalla lo dicen |

Una función apagada en silencio es indistinguible de una rota.

## El contenedor tiene que quedar al día, siempre

Lo que corre en Docker no se actualiza solo, y cuando se atrasa no avisa: falla después, cuando
alguien abre una pantalla.

| Si tocaste… | Hay que… |
|---|---|
| el esquema de la base | `docker compose down -v && docker compose up` — **sin el `-v` MariaDB no reimporta**: el guion de importación corre una sola vez, con el volumen vacío |
| `docker/php/env.docker` | `docker compose restart app` |
| `docker-compose.yml` o el `Dockerfile` | `docker compose up -d --build` |
| cualquier cosa, antes de dar algo por terminado | `docker compose exec app php artisan sgp:diagnostico` — compara lo que corre contra lo que se entrega |

**«Actualizar Docker» es `down -v` y volver a subir**, no reiniciar: es lo único que prueba que
lo que se entrega **carga**. El orden importa:

1. Volcar `peluqueria_test` a `basededatos/1mes_simulacion.sql` — `down -v` borra el volumen.
   (Hoy `peluqueria_test` trae la operación del servidor: el mes simulado se regenera desde la
   copia `peluqueria_sim`, ver *Las pruebas*.)
2. `docker compose down -v` · `docker compose up -d`.
3. `docker compose logs bd` tiene que decir «listo» por base.
4. **Contar contra lo de antes**: citas, facturas, cobros, clientas y la última fila de
   auditoría dan lo mismo, y `Coloración` viaja como `C3B3`.
5. `sgp:diagnostico` y abrir el portal.

**El volcado se copia con `docker compose cp`, nunca por una tubería de PowerShell**: PS 5.1
decodifica con la página de códigos de la consola y le agrega BOM.

**Antes de comprimir un ZIP**: `DB_DATABASE=peluqueria_test` en `docker/php/env.docker` (la copia
cargada), `1mes_simulacion.sql` regenerado desde la base que se transporta, la cuenta de correo
cargada en Seguridad → Correo del sistema (no viaja en ningún archivo), y `down -v && up` con los
conteos comparados. El ZIP se arma comprimiendo la carpeta, no con `git archive`: `secretos.env`
tiene que ir adentro.

## Convenciones al escribir código

- **Todo en español**: métodos, variables, comentarios y mensajes. `PascalCase`/`camelCase`
  como pide PSR-12, pero en español (`Agenda::motivoHuecoPerdido()`). Los mensajes le hablan de
  vos al usuario, en tono paraguayo.
- **Toda acción POST valida en el servidor**: leer con `$request`, encadenar `if/elseif` sobre
  `$error`, y `flash($error, 'error')` + `redirect()`. Lo del navegador (`required`, `pattern`)
  es ayuda, no garantía. Esconder un botón tampoco es el control.
- **Nunca `(float) $request->input(...)` para montos**: los campos de dinero llevan separador de
  miles; se parsean con `num()` (y `entero()` para cantidades enteras).
- **En las vistas, `{{ }}`**. `{!! !!}` sólo con HTML que armó el sistema.
- **`@csrf` en todo formulario**; el rechazo devuelve 419 con su pantalla en `errors/`.
- **Auditá lo que importa** con `Auditoria::registrar($accion, $modulo, $tabla, $id, $detalle)`,
  pero fijate si la base ya lo audita sola: `trg_factura_au`, `trg_cobro_au`,
  `trg_pagopersonal_au` y `trg_pagoproveedor_au` escriben en `auditoria` al anular o revertir. Ahí
  va `Auditoria::anotarMotivo($tabla, $id, $motivo)`, o quedan dos filas por la misma acción.
- **`catch (QueryException)` va primero, con `Bd::traducir()`.** `QueryException` hereda de
  `PDOException` → `RuntimeException`: un `catch (RuntimeException)` puesto para los avisos
  propios se come los errores de la base y deja inalcanzable el `catch (Throwable)` de abajo.
- **Un `catch` que no supo traducir el error lo registra con `Log::error()`** antes de contestar,
  y el mensaje avisa que el detalle quedó registrado. Sin eso el log queda vacío y hay que
  reproducir a mano.
- **Verificá pertenencia** (que la cita sea de ese cliente, que la factura no esté anulada) y no
  confíes en los campos ocultos: el cliente se toma de la cita, no del POST. Los `id` de catálogo
  se validan contra la base.
- **Nunca repitas un marcador con nombre en una consulta.** PDO prepara de verdad
  (`ATTR_EMULATE_PREPARES` en `false`) y MySQL no admite `:q` dos veces — tampoco en las partes de
  un UNION, que se preparan juntas: el sufijo va por fuente (`:cf_cobro`, `:cf_manual`). Para
  buscar en varias columnas está `Listado::likeVarias()`.
- **PHP toma `»` como parte de un nombre de variable**: `"«$t»"` es `$t»`, indefinida. Va `{$t}`.
- **`@if` pegado a una palabra no lo compila Blade** (su patrón lleva `\B` delante de la arroba):
  `días@endif` sale tal cual. Las directivas van con espacio o salto de línea antes.
- **Ojo con `e()` dentro de `{{ }}`**: escapa dos veces y un JSON en un atributo deja de parsear,
  sin dar error.
## Arquitectura

Laravel 13 sobre PHP 8.3, con **221 rutas declaradas una por una** en `routes/web.php` — nada de
`Route::resource`: lo que no está declarado no es alcanzable, y un método privado tampoco.

**Lo que NO se usa de Laravel, a propósito:**

| No se usa | Por qué |
|---|---|
| **Eloquent** para el negocio | la lógica vive en la base. `DB::select()` y `Bd::` |
| **Migraciones** | el esquema viene de `basededatos/peluqueria_bd(base).sql`; `database/migrations/` está vacío. `artisan migrate` crearía tablas de Laravel dentro de la base que se entrega |
| `database` como driver de sesión, caché y cola | van a **archivo** |
| Vite / Node | Bootstrap por CDN y `app.css` propio; no se compila nada |
| El *auth* de Laravel | las cuentas viven en `usuario` y la sesión la arma `App\Servicios\Sesion` |

```
app/
  Ayudas/formato.php       Funciones globales (composer «files»): money() monto_input() cant()
                           num() entero() fecha() fecha_larga() ahora_bd() recurso() flash()
                           estado_badge() ciudad_elegida() producto_fraccionado()
                           unidad_es_envase() consumo_a_stock() stock_a_consumo() unidad_consumo()
  Servicios/               La capa propia. Todo estático, sin estado.
    Bd.php                 El puente a las rutinas: idDe() enTransaccion() traducir()
    Agenda.php             Huecos, reparto entre profesionales, agendar con candado
    Permisos.php           Los 33 submódulos y su jerarquía
    Sesion.php             Ingreso y datos de la sesión
    Seguridad.php          Códigos de un solo uso (token_seguridad)
    WebAuthn.php           Huella en PHP puro (CBOR, COSE→PEM, OpenSSL)
    Facturacion.php        Emitir, cobrar, anular, nota de crédito, puntos
    Caja.php               Caja abierta y saldo
    Cuenta.php             La cuenta BANCARIA del salón: la caja del banco
    Movimientos.php        Las cuatro fuentes que mueven plata, del cajón y del banco
    Compras.php            Las cuotas de una compra con lo que cubrió cada una
    Respaldo.php           El papel que respalda plata, fuera de public/
    Persona.php            El único lugar que escribe en `persona`
    Notificaciones.php     Cola de avisos: ausencias, bajas, recordatorios, internos
    Calendario.php         Archivo .ics de la cita (hora flotante)
    Listado.php            Prototipo de listas: filtros(), paginacion(), exportar()
    Sucursales.php         La sucursal activa y el filtro por local
    Canje.php              El vale de puntos: canjear(), aplicarACita()
    Config.php             Lo que decide el salón y no el código (`configuracion`)
    Tema.php               La paleta derivada de UN color; tamaños y tipos de letra
    Imagen.php             Subir el logo o la foto de un servicio
    Borrador.php           No perder lo escrito al usar un alta rápida
    Sifen.php              Arma el TXT del comprobante y lo manda al Automatizador
    Pendientes.php         Qué le falta CARGAR al salón
    Alertas.php            Qué está pasando AHORA: la campanita
    Navegacion.php         Migas, módulos y catálogo de pantallas
    Auditoria.php          registrar() registrarComo() anotarMotivo()
    Contacto.php           Centro de Ayuda y Soporte
    Acompanantes.php       Quiénes vienen con la clienta (`cita_acompanante`)
    Alergias.php           Las alergias de CADA persona de la cita
    Asistencia.php         Fichaje, franja del turno y faltas sin aviso
    Ayuda.php              El diccionario de `config/ayudas.php`
    CitasVencidas.php      Cierra la Atrasada de más de un día y la que no se presentó
    Sena.php               Cuánta seña pide el salón, su desglose, y el recibo
    Pagos.php              Los tipos de alias del SIPAP
    Perfil.php             La foto de perfil de quien está en sesión, o sus iniciales
  Http/Controllers/        Uno por módulo, más Auth, Cuenta, Panel, Portal, CitaToken, Sucursal,
                           Vivo (la huella de actualización), Alertas, CuentaBancaria y Webauthn.
                           Seguridad es la excepción: SeguridadController tiene el landing y las
                           pantallas están en PersonalController (usuarios, turnos, comisiones,
                           asistencia) y ConfiguracionController (sucursales, roles, contacto,
                           auditoría, ajustes)
  Http/Middleware/         ExigeSesion · ExigePersonal · ExigeModulo · ExigeAdmin
  Mail/                    AvisoCita · AvisoInterno · CodigoSeguridad · ComprobanteCliente · ReciboSena
  Console/Commands/        sgp:diagnostico · sgp:pendientes · sgp:notificaciones
config/
  sgp.php                  Versión, puntos, agenda, timbrado, sesión
  navegacion.php           Los cinco niveles de navegación, en un solo lugar
  permisos.php             Los 33 submódulos
  ayudas.php               Qué se carga en cada campo
  sifen.php                La integración con el Automatizador
resources/views/
  layout/app.blade.php     Encabezado, barra de módulos y pie: envuelve todo
  components/              <x-encabezado> <x-filtros> <x-paginacion> <x-landing> <x-cobro-lineas>
                           <x-ayuda> <x-servicio-tarjeta> <x-ciudad> <x-avatar>
  <modulo>/                Una carpeta por módulo; reportes/ tiene un partial por informe
routes/web.php             Las rutas, agrupadas por módulo con su middleware. Personal y
                           Configuración viven bajo /seguridad: sólo cambia el permiso que las abre
routes/console.php         El scheduler: sgp:notificaciones cada diez minutos
public/assets/             app.css · imprimir.css (estiliza `.sgp-imprimir`) · app.js · webauthn.js
basededatos/               Los dos .sql y actualizaciones/
docker/                    php/Dockerfile (desarrollo, artisan serve) · php/Dockerfile.produccion
                           (php-fpm + OPcache, detrás de Caddy) · php/env.docker · env.produccion ·
                           secretos.env · caddy/Caddyfile · respaldo.sh
_sifen/                    El Automatizador SIFEN, de terceros: el SGP le habla sólo por HTTP
tests/Feature/             Las 240 pruebas
_sim/ _sim30/ _sim60/ _qa/ Bancos de simulación y QA: evidencia, no parte del sistema
```
## Identidad visual (preferencia del usuario — respetarla siempre)

**Monocromático verde agua, con enfoque accesible.** La paleta la dio el usuario en una lámina
—«Distribución de color & gama»— y vive como variables CSS al principio de
`public/assets/css/app.css`. **No inventar colores nuevos ni usar los de Bootstrap.**

> Hasta la 7.122.1 la identidad era negro + oro champagne (`--oro*`, `.btn-oro`, `--negro`,
> `--carbon`, `--blanco-hueso`). Las variables se renombraron **por su papel y no por su color**
> —`--acento*`, `--sobre-acento`, `--texto`, `--fondo`—; así el próximo cambio de paleta es
> cambiar valores. `AndamiajeTest::la_identidad_anterior_no_vuelve_escrita_a_mano` busca el oro
> (`#C9A84C`, `#8A6C1E`) y los nombres viejos en código, vistas, CSS y el Automatizador.

| Color | Hex | Papel | Variable |
|---|---|---|---|
| Verde muy oscuro | `#0F4C43` | texto y títulos · barra superior y pie | `--texto` · `--acento-oscuro` · `--sup-oscura` |
| Verde oscuro | `#1A6B5F` | botones primarios · barra de módulos | `--acento` · `--acento-enfasis` · `--sup-oscura-2` |
| Verde medio | `#375B59` | botones secundarios y bordes · texto secundario | `--texto-tenue` · `--borde-fuerte` |
| Verde claro | `#7AC3B7` | separadores y detalles · el acento sobre las barras | `--borde` · `--acento-claro` · `--acento-sobre-oscura` |
| Verde pastel | `#E1FAF5` | tarjetas y paneles · texto de las barras | `--superficie` · `--sobre-oscura` |
| Verde extra claro | `#F2FBF9` | fondo de la pantalla | `--fondo` |

La lámina repite `#7AC3B7` rotulado «texto principal»: sobre el fondo da 1,9:1. El texto va en
`#0F4C43`. Distribución 60 (fondo) · 25 (estructura: tarjetas, paneles, tablas) · 10 (texto).
**Las tarjetas son más oscuras que el fondo**: los campos de un formulario salen de
`--bs-body-bg`, un paso más claros que la tarjeta.

### El acento es OSCURO, y eso decide la polaridad

El oro era claro con texto negro; el verde agua es oscuro y lleva texto claro:

| Dónde | Regla |
|---|---|
| Texto sobre un botón primario | `--sobre-acento` (blanco, 6,3:1) |
| El acento sobre la barra superior y el pie | `--acento-sobre-oscura` (verde claro): el verde oscuro sobre la barra da 1,6:1 |
| Lo activo en la barra de módulos | `--sobre-oscura-vivo` (blanco, negrita) con la línea en verde claro — `--acento-sobre-oscura` ahí da 3,1:1 |
| La banda del KuDE | verde con texto **blanco** |
| El favicon | pastilla verde con la tijera blanca |

**Regla del acento: sólo donde hay acción o jerarquía** — botón principal, logo y nombre del
local, hover y estado activo, casilla marcada, anillo de foco, importes destacados
(`.val.acento`), el badge **En proceso**, los chips (`.sgp-chip`) y las altas rápidas
(`.btn-rapido`). En demasiados lugares pierde impacto; para texto y bordes van los neutros.

| Nivel | Clase | Aspecto |
|---|---|---|
| Principal | `.btn-acento` | relleno `--acento`, texto `--sobre-acento`: la acción principal de la pantalla, una sola |
| Secundario | `.sgp-chip`, `.btn-rapido` | reposo `--acento-suave`/`--acento-texto` (6,8:1) · hover `--acento`/`--sobre-acento` · `:active` `--fondo`/`--texto` |
| Terciario | `.sgp-rol-chip` | sólo contorno: información con jerarquía, no acción |

- **`--acento-suave` es para fondos de acción; `--acento-tinte` (más pálido) para lo que se
  señala sin ser un botón** (la fila elegida, un bloque de aviso suave). No intercambiarlos.
- `.btn-rapido` define sus estados con `--bs-btn-*`, o el estado activo de Bootstrap pisa el
  nuestro.
- **Lo neutro (`.btn-outline-neutro`) tiene que verse como botón**: relleno `--bs-secondary-bg`,
  borde `--borde-fuerte`, texto principal, hover en el acento suave. Un contorno del color de
  las líneas se funde con el fondo. **Un aviso no es un botón**: los badges van rellenos y en
  píldora, los botones rectangulares.
- **`--acento-enfasis`** es el acento para un dato destacado —un importe, un enlace, el ícono de
  un título—: en claro el verde oscuro, en oscuro el claro. Es una variable, no un selector del
  tema.
- **Los controles piden 3:1 de contorno** (WCAG): campos, combos y casillas llevan
  `--borde-control` (`#5E918A`, 3,3:1); el placeholder, `--placeholder`. Los separadores siguen
  en `--borde`.

### Los colores semánticos

Los únicos fuera de la identidad, sólo para comunicar estado, siempre por variable (hay
utilidades `.txt-ok`, `.txt-no`, `.txt-acento`):

| Variable | Significado |
|---|---|
| `--verde` / `-tinte` / `-borde` (`#2F5D2F`) | salió bien: Atendida, Emitida, Abierta |
| `--rojo` / `-tinte` / `-borde` (`#993535`) | se anuló o se canceló |
| `--ambar` / `-tinte` / `-borde` (`#6B5314`) | **hay que mirarlo**: pendiente, atrasada, sin confirmar, la caja cerrada |

El ámbar son los valores del oro de antes, con un solo trabajo: avisar. `AndamiajeTest` lo deja
pasar; lo que no deja volver es el oro como acento. Badges (`estado_badge()` + `.e-*`): lo en
curso lleva el acento (`.e-proc`), lo agendado o cerrado la estructura (`.e-prog`, `.e-muted`),
lo que hay que mirar ámbar (`.e-warn`), el resultado verde o rojo.

**Cinco lugares no leen `app.css`** y llevan los colores escritos: los correos
(`resources/views/correo/`), el PDF de la factura del portal, el informe impreso, el Excel de
Reportes y el KuDE del Automatizador (`KudeService`). Si cambia la paleta, se tocan a mano en la
misma tanda.

**Bootstrap trae su propio azul y grises compilados.** En `app.css` están sobrescritas las
variables `--bs-*` y pisados los componentes que no salen por variable (`.form-check-input:checked`,
`.nav-pills`, `.dropdown-menu`, anillos de foco, tablas y alertas). Si un componente nuevo
aparece azul, sumá el override ahí. **`.text-body` sale de `--bs-body-color-rgb`**, no de
`--bs-body-color`: las variables `-rgb` gemelas también cambian con el tema.

### Tema oscuro

Se elige en **Mi cuenta → Apariencia** (`preferencia_usuario.tema`): es de cada persona, no del
salón. El layout lo escribe como `data-tema="oscuro"` en el `<html>` desde la sesión, no con
JavaScript (parpadearía en claro).

- **El bloque `[data-tema="oscuro"]` de `app.css` sólo redefine variables.** Si hace falta un
  selector de componente ahí, algo se escribió con un color suelto: el arreglo es la variable.
- **La barra superior, la de módulos, el pie y las pastillas de módulo NO se invierten**: usan
  `--sup-oscura*`, `--sobre-oscura*` y `--acento-sobre-oscura`, que son fijas. Con las variables
  de la página, al invertir la paleta quedaban enlaces en 1,5:1.
- **El acento cambia con el tema**: en oscuro pasa al verde claro con texto oscuro (8,4:1), y
  `--acento-oscuro` (hover) pasa a ser más claro; `--sobre-acento` también se invierte.
- Los neutros oscuros siguen siendo verde agua; los tintes se mezclan hacia el fondo.
- **Para TEXTO sobre el fondo van `--texto` y `--acento-enfasis`, nunca `--sobre-acento`,
  `--acento-claro` ni las `--sobre-oscura*`**: las primeras se invierten, las otras no. Sobre un
  relleno del acento, `--sobre-acento` sí. Lo vigila
  `AndamiajeTest::el_texto_no_se_pinta_con_una_variable_que_se_da_vuelta`.
- `color-scheme:dark` va declarado (los campos nativos de fecha salían blancos). Las dos vistas
  de impresión no llevan el atributo: el papel va en claro.

### Los ajustes del sistema los elige el salón: un color y el resto se ajusta solo

**Configuración → Ajustes** (`configuracion.ajustes`): a la izquierda lo que se decide, a la
derecha la vista previa (mediana, 19 rem, con los botones claro/oscuro en su cabecera, que
cambian **sólo la previa**), y un solo botón al pie. Cuatro desplegables:

| Desplegable | Qué se elige | Dónde vive |
|---|---|---|
| **Identidad del sistema** | el nombre y el logo | `configuracion` |
| **Apariencia** (abierto de entrada) | el color: 18 paletas con nombre, o «Color principal del salón» | `configuracion.tema_colores` → `primario` |
| **Datos fiscales** | la actividad económica y el correo del KuDE | `configuracion` |
| **Texto** | tamaño (normal · grande · muy grande) y tipo de letra (del sistema · amplia · clásica) | ídem → `letra` y `fuente` |

**`App\Servicios\Tema` deriva los 42 tokens del color elegido**: de cada uno toma tono y
saturación, y cada token tiene su luminosidad de destino y su tope de saturación
(`Tema::reglas()`), medidos del verde agua. Cuatro decisiones que conviene no revertir:

- **El verde agua exacto es un caso aparte**: `Tema::paleta()` devuelve para `#1A6B5F` los
  valores literales de `app.css` — elegirlo o restablecer deja el sistema exactamente como se
  entrega. `AndamiajeTest::la_paleta_de_fabrica_es_la_que_dice_app_css` vigila la copia.
- **La polaridad se acomoda sola**: `sobre_acento` sale de medir contraste, no de una constante.
- **El contorno de un campo tiene su propia regla**, más oscura; el peor caso entre las
  dieciocho paletas es 3,1:1 (`cualquier_color_que_elija_el_salon_sigue_siendo_legible`).
- **El tema claro u oscuro lo sigue eligiendo cada persona**: se guardan las dos paletas.

**El tamaño de letra escala el documento entero** (`html{font-size:112.5%}`): funciona porque
`app.css` no tiene una sola medida en píxeles. Las fuentes son pilas del sistema operativo
(`Tema::FUENTES`), y **la pila va al layout con `{!! !!}`**: `{{ }}` escapa las comillas de
`"Times New Roman"` dentro del `<style>`. La previa mide el tamaño contra el que ya escala la
página (`data-ap-letra-base`). `Config::coloresPersonalizados()` compara los tokens contra la
paleta de fábrica; el `<style>` del layout va en tres partes (colores, tamaño, fuente), cada una
sólo si algo cambió.

**Lo único que se escribe dos veces son las quince líneas de conversión HSL** de la previa:
la tabla de derivación, el verde exacto y el mapa de la previa viajan desde PHP en atributos
(`data-ap-reglas`, `data-ap-identidad`, `data-ap-previa-mapa`), y lo que se guarda lo calcula
siempre el servidor. El mapa token → variable CSS vive en `Tema::variables()`.

**Sin JavaScript se elige y se guarda igual**: las paletas son etiquetas de radio, el color
propio un `input type=color`, los desplegables `<details>`. **Los campos de los desplegables van
sin `required`**: uno obligatorio dentro de un `<details>` cerrado hace que el navegador se
niegue a enviar el formulario sin decir nada; `app.js` lo pone al abrirlo. Es UN formulario con
UN botón; quitar el logo y volver a fábrica son formularios aparte alcanzados con `form`.

### La imagen de referencia del servicio

Al reservar, los servicios se eligen como tarjetas con su imagen (`<x-servicio-tarjeta>`, un
componente para el portal y Nueva cita). Se carga en la ficha del servicio, se guarda **el
nombre del archivo** (`servicio.imagen`) en `public/assets/servicios/` (ignorado por git), por
`App\Servicios\Imagen`. La tarjeta entera es un `<label>` —marca sin JavaScript—; sin imagen se
dice, no se pone una genérica; el acento va sólo en la elegida.

### «Todos» en un grupo de opciones múltiples

Todo grupo de casillas donde marcarlas todas signifique algo lleva su maestra, con
`data-marca-todo="#idDelGrupo"` en `app.js`:

- **La maestra va FUERA del contenedor del grupo** (adentro se contaría a sí misma) y **no lleva
  `name`**.
- No en los servicios de una cita ni en los canjes.
- Se pueden anidar (Roles: una por módulo y otra por rol).
## Las fotos que sube el salón

No están en el repositorio ni viajan en el ZIP: en el servidor viven sólo en volúmenes de Docker.

| Qué | Volumen | La base guarda |
|---|---|---|
| Fotos de los servicios | `imagenes_servicios` | `servicio.imagen`, el nombre |
| Logo del salón | `imagenes_logo` | `configuracion.logo` |
| **Fotos de perfil** | `imagenes_personas` | `persona.foto` — es un dato personal: `.gitignore` propio y `dejar_lista.sql` la vacía |

Las conserva `up -d --build` y `down`; las borra **`down -v`**, «Delete» del proyecto en el
panel y **desplegar con otro nombre de proyecto** (los volúmenes se llaman
`<proyecto>_imagenes_servicios`: con otro `-p` se crean vacíos y las fotos «desaparecen» sin
error). Si se pierden, `Imagen::url()` devuelve null y la pantalla se ve normal: por eso
`sgp:diagnostico` tiene la sección «Las fotos del salón», que compara cada nombre contra el disco.

## Interfaz

Bootstrap 5.3 + Bootstrap Icons por CDN, con la paleta aplicada encima.

### Cinco niveles de navegación

| Nivel | Dónde | Qué responde |
|---|---|---|
| Barra de módulos (`.sgp-nav`) | fija bajo el encabezado; **no se dibuja en el Panel**, que ya tiene las tarjetas | ¿a qué otro módulo voy? |
| Desplegable del módulo (`.sgp-nav-menu`) | al pasar el mouse | ¿a qué pantalla, sin pasar por la tarjeta? |
| Submenú lateral (`.sgp-nav-sub`) | al pasar el mouse por un grupo — hoy sólo Tesorería | ¿cuál de las del grupo? |
| Migas (`.sgp-migas`) | arriba del título | ¿dónde estoy y cómo vuelvo? |
| Tarjetas | panel → módulo → submódulos | ¿qué hay dentro de este módulo? |

Los tres primeros salen de `config/navegacion.php`, no de cada vista. Reglas del catálogo:

- **Cada pantalla lleva la misma clave de permiso que pide el middleware**; el cuarto valor en
  `false` marca una pantalla de detalle (necesita un id, no se ofrece en menús); `tambien`
  declara una pantalla prestada a otro módulo con el título con que se la nombra ahí; **el
  sexto valor en `true` dice «sólo el Administrador»** (Correo del sistema, que no tiene
  submódulo a propósito: su permiso declarado es el módulo padre).
- **Al leer el catálogo desde PHP, las claves llevan punto** (`seguridad.usuarios`):
  `config('navegacion.pantallas.' . $clave)` devuelve null sin quejarse. Se trae el arreglo
  entero y se indexa a mano.
- **Las migas sacan la entrada del módulo del catálogo `navegacion.modulos`, no de
  `<módulo>.index`**: Personal y Configuración no se mudaron de URL al partir Seguridad.
- El desplegable se abre con **CSS, no JavaScript**, sólo con mouse de verdad (`hover:hover`), y
  `overflow` vuelve a `visible` ahí. **En pantalla angosta la barra es un cajón** (`#sgpCajon`,
  una casilla escondida) que no le roba ancho al contenido y tampoco necesita JS. **La clienta
  tiene su propia barra** con las mismas piezas (`navegacion.portal`, campo `barra`).
- `AndamiajeTest::el_landing_de_cada_modulo_ofrece_todas_sus_pantallas` exige que toda pantalla
  del catálogo esté en la tarjeta **y toda tarjeta en la barra**.

El **pie** tiene identidad, **Secciones** (los módulos del rol; «módulo» es palabra del
desarrollo), Centro de Ayuda y Soporte, y la versión.

### El panel: lo que hay que mirar, no lo que hay que contar

Saludo chico arriba a la izquierda; a la izquierda **Próximas citas** (las atrasadas primero,
con «hace N»; el atajo a la agenda siempre en el título) y el **Resumen financiero** —cuántas
cajas abiertas y cuántas cuentas bancarias activas, con sus accesos y el aviso en rojo sin caja
abierta; y lo cobrado hoy contra ayer—; a la derecha los nueve módulos en `.sgp-modulos`.
Cada bloque se dibuja sólo para quien lo tiene (el financiero pide `facturacion.caja` o
`facturacion.cobros`). **`sgp-caja-barra` y `sgp-metrics` son ganchos de la prueba del panel.**
El CSS del panel vive en `app.css`. El pie va al fondo de la ventana (`body` columna, `main`
estira).

**El inicio del portal es el mismo panel para la clienta** (`portal/index`, mismas clases):
Tus próximas citas, Tu nivel y tus puntos, y las pantallas del portal en pastillas.
`vw_cliente_fidelizacion.descuento_del_nivel` es el NOMBRE del descuento; el porcentaje se lee
de `nivel` ⋈ `descuento`.

### En el celular las tablas son tarjetas

Toda lista de gestión, con el patrón de `app.css` bajo `max-width:575.98px`:

| Pieza | Qué hace |
|---|---|
| `.sgp-tabla-movil` en el `table-responsive` | cada `<tr>` pasa a tarjeta |
| `data-label="…"` en cada `<td>` | el rótulo del valor |
| `.sgp-movil-titulo` · `.sgp-movil-sujeto` | el dato principal, y **quién** (sin rótulo) |
| `.sgp-movil-acciones` | la botonera; `.sgp-btn-ico` escribe su `title` al lado, porque en el celular no hay mouse |
| `<tr class="sgp-fila-detalle">` + `.sgp-btn-detalle` | lo secundario plegado, pegado a la tarjeta de arriba |
| `.sgp-movil-oculto` + «Más» de `app.js` | columnas que sobran en la tarjeta |
| `<x-filtros>` plegado | arrancan cerrados, abiertos si hay alguno puesto; sin JavaScript |

- **El desplegable es para lo que NO entra**, no para todo lo secundario.
- En las listas de personas, la cara al lado del nombre (`<x-avatar>`); sin foto, las iniciales.
- **Lo que ADVIERTE se queda en la fila** (la reserva sin confirmar, la seña esperando
  confirmación, las alergias); lo ya cobrado puede esperar un toque. El aviso contradictorio no
  se dibuja (sin confirmar sobre una Cancelada).
- Dos botones no pueden decir casi lo mismo: el ojo de la atención registrada se llama
  «Atención», no «Detalle».
- **En la agenda, «Detalle» abre una VENTANA** (`#detCita{id}`: La cita · Quién viene · Dejó
  dicho · Cobros) dibujada fuera de la tabla, y las acciones van en `.sgp-acciones`, una grilla
  de dos columnas con el rótulo entero al lado del ícono, sacado del `title`. La fila es el
  resumen («Corte de dama ×2, Manicura»); de quién es cada servicio se lee en la ventana, con un
  renglón por persona.

### La sesión se cierra sola a los 30 minutos, y dice por qué

| Cuándo | Qué pasa |
|---|---|
| 30 min sin actividad (`sgp.sesion.inactividad_min`) | la cierra `ExigeSesion`, **no Laravel**: así la pantalla de ingreso puede explicar el motivo. Por eso `SESSION_LIFETIME` va más alto (45) |
| Al cerrar el navegador | `expire_on_close`, salvo «mantener activo en este dispositivo» |
| Alguien entra con la misma cuenta | ésta queda desplazada, con aviso; se comprueba **después** de la contraseña |

El refresco automático del portal y la huella en vivo **no cuentan como actividad**.

### La marca y la foto de perfil

- El logo (o la tijera de la identidad) se dibuja desde **`layout/_marca`** (modos `grande` y
  `linea`) en el ingreso, crear cuenta, el enlace de la cita y el pie. **El CSS de `.logo-big` no
  va scopeado bajo `.sgp-login`** (la pantalla del enlace lo usa fuera de esa tarjeta);
  `AndamiajeTest::la_marca_grande_no_depende_de_la_pantalla_de_ingreso` lo frena. Recuperar,
  código, verificar y huella usan el ícono de la tarea, no la marca.
- **Con logo cargado, la pastilla de color desaparece** (`sgp-logo tiene-img`): el color era el
  fondo de la tijera. Lo decide `layout/_marca`.
- **La foto de perfil va en `persona.foto`**, no en `usuario` (quien trabaja sin cuenta también
  tiene cara); se cambia desde Mi cuenta; sin foto van las **iniciales**
  (`Perfil::inicialesDe()`: nombre + apellido); al quitarla el archivo se borra.
- **El avatar no parpadea**: `sgp-avatar tiene-img` apaga el fondo, un `<link rel="preload"
  as="image">` en el `<head>` la pide junto con el CSS, y Caddy manda `Cache-Control: immutable`
  de un año para `/assets/*` — seguro porque todo lo de `/assets` va con `?v=` (`recurso()`).
  No hay miniatura: el contenedor no trae GD ni Imagick.
- **El ícono de la pestaña es el logo del salón** (`layout/_favicon`, SVG embebido sin logo);
  si agregás otra pantalla con `<head>` propio, incluilo.

### Centro de Ayuda y Soporte

Los canales por los que el cliente escribe al salón (`contacto_soporte`: varios, ordenados, con
etiqueta propia) se cargan en Seguridad → Contacto y salen en el pie. Los canales posibles están
en `Contacto::canales()`. **Es uno para todo el salón, no por sucursal**: la clienta no está
atada a ningún local. `Contacto::url()` normaliza el número (antepone el código de país; `wa.me`
sin `+`, `t.me` con él), exige que un usuario de Telegram empiece con letra, y **sólo acepta
`http` y `https`** — un `javascript:` quedaría inyectado en todas las pantallas.
`Contacto::delSalon()` descarta la fila cuyo valor no se pudo convertir.

### La ayuda contextual: `<x-ayuda>`

La explicación se guarda detrás de un ícono y aparece al tocarlo (patrón de Moodle).

```blade
<label class="form-label" for="email">Email *</label><x-ayuda>Ahí te mandamos el código.</x-ayuda>
<x-ayuda campo="cedula" />          {{-- del diccionario config/ayudas.php --}}
```

- **`data-bs-trigger="focus"`**: es el único disparador que cierra al tocar afuera.
- **El ícono va FUERA del `<label>`**: adentro, la etiqueta reenvía el clic al campo y el globo
  no abre nunca.
- Los popovers de Bootstrap son opt-in: los instancia `app.js`, que de paso saca el `title`
  (sin Bootstrap, el texto queda ahí).
- El texto se aplana: no meter Blade adentro. Los subtítulos que muestran **datos** no se
  guardan: son el contenido.
- **Esto es para lo que EXPLICA; lo que ADVIERTE se queda a la vista** (que la seña no se
  devuelve, que un local no tiene timbrado propio).
- `config/ayudas.php` es el diccionario por campo, con ejemplo donde el formato importa;
  `Ayuda::de()` resuelve los prefijos de las altas rápidas (`cr_nombre`, `pv_nombre` = `nombre`).
  `AndamiajeTest` no deja una entrada huérfana.

### Lo que cambió mientras mirabas: `@section('vivo')`

Cada pantalla es una foto; con dos personas sobre la misma agenda, una atiende y la otra la sigue
viendo Programada. `@section('vivo', 'agenda')` en la vista hace que `app.js` consulte cada 20 s
(`VivoController::CADA`) la ruta `vivo`, que devuelve **una huella** (`md5`) de lo que la pantalla
mira —conteo, último id, suma de estados, lo cobrado, lo facturado, la campanita, lo que falta
cargar— y nunca datos. Secciones: `agenda`, `cajas`, `panel`, `asistencia`.

- **No recarga encima de algo escrito**: con un modal abierto o un campo tocado
  (`[data-sgp-tocado]`) aparece un aviso con «Actualizar».
- No consulta con la pestaña en segundo plano ni cuenta como actividad de sesión. No hay
  websocket a propósito. Al probarlo con el panel del navegador escondido, `document.hidden` es
  `true`.

### La espera tiene que verse

Cada clic pide una página entera y el navegador no muestra nada mientras tanto. Tres piezas de
`SGPCarga` (`app.js`): `.sgp-barra-carga` (3 px arriba, sólo pasados 250 ms), `.btn.cargando`
(el ícono se vuelve spinner sin cambiar el ancho) y `.sgp-spinner` para los bloques que se
llenan por `fetch`.

- **Las descargas no la encienden** (`?export=`, el `.ics`): anotá en `navegaDeVerdad()` toda
  ruta nueva que devuelva un archivo. Se apaga en `pageshow`.
- **Es un adorno que puede faltar**: las vistas con JS propio declaran
  `var SGPCarga = window.SGPCarga || { envolver: function (p) { return p; } };`.
- **Las pantallas que no usan el layout general piden `app.js` y `@stack('scripts')` a mano**
  (`layout/app`, `auth/marco`, `auth/login`). Un `@push('scripts')` sin `@stack` no avisa: la
  pantalla se dibuja sin JavaScript — dejó a `webauthn/preguntar` con los botones muertos. **La
  salida de una pantalla no puede depender del JavaScript**: «Ahora no» es un `<form>` de verdad.
- **Los carteles de confirmación los dibuja el sistema** (`SGPConfirmar`, `data-confirmar`), no
  `window.confirm()`; cae al del navegador si Bootstrap no cargó.
- **Un modal con scroll y un `<form>` adentro necesita la regla de `app.css`**:
  `.modal-dialog-scrollable` sólo acota a `.modal-body` si es hijo directo de `.modal-content`.
  `AndamiajeTest::el_modal_con_scroll_no_lo_pierde_por_tener_un_formulario`.
- **Los modales van FUERA de los paneles con tabla**: uno dentro de un `<tr>` hereda cualquier
  `display:none` y no se puede mostrar.

### Formularios

- CSS y JS se enlazan con `recurso('css/app.css')`, no con `asset()`: pega la fecha del archivo
  como `?v=`.
- **Campos numéricos: `data-solo`** filtra al escribir — `numeros`, `documento` (cédula),
  `ruc` (conserva la **K**), `telefono`. La pantalla no puede ser más estricta que el servidor:
  cada juego copia su regla de `Persona::error()`. `nro_operacion` queda libre.
- Dinero: `class="input-miles"` (+ `data-decimales="2"`, `data-min`, `data-max`); `num()` lo
  interpreta en el servidor. **`input-miles` le saca los puntos**: un `DECIMAL` «10.00» que
  entra sin `cant()` se vuelve 1000.
- Buscador sobre un `<select>` largo: `<input data-filtra="#idDelSelect">` — filtra opciones en
  el navegador, no es el buscador de una lista.
- **Un `<select multiple>` pide Ctrl+clic**: los grupos van con casillas.
- **La ciudad es un combo** (`<x-ciudad>`, `config('sgp.ciudades')`) con «Otra» que abre texto
  libre: un `datalist` sugiere sin encerrar y termina con la misma ciudad escrita de tres formas.

### El prototipo de listado: filtros y paginación

Todas las pantallas de lista se dibujan igual, con `App\Servicios\Listado` y tres componentes:

```php
$f = Listado::filtros([
    'q'      => ['tipo' => 'texto',  'etiqueta' => 'Buscar', 'ph' => 'Nombre o cédula'],
    'estado' => ['tipo' => 'select', 'etiqueta' => 'Estado', 'opciones' => ['' => 'Todos', '1' => 'Activos']],
    'desde'  => ['tipo' => 'fecha',  'etiqueta' => 'Desde'],
]);
$w = ['1=1']; $par = [];
if (Listado::hay($f, 'q')) {
    $w[] = Listado::likeVarias(['pe.nombre', 'pe.cedula'], Listado::valor($f, 'q'), 'q', $par);
}
$desde = 'FROM … WHERE ' . implode(' AND ', $w);
if (Listado::pideExport()) {                    // «csv» o «pdf»
    return Listado::exportar('clientes', ['Cliente', 'Cédula'], $filas, $f, 'Clientes');
}
$pag  = Listado::paginacion((int) DB::scalar("SELECT COUNT(*) $desde", $par));
$filas = DB::select("SELECT … $desde ORDER BY … LIMIT {$pag['porPagina']} OFFSET {$pag['offset']}", $par);
```

```blade
<x-encabezado sub="…" :accion="['ruta' => 'clientes.form', 't' => 'Nuevo cliente', 'ic' => 'person-plus']" />
<x-filtros :f="$f" />   …   <x-paginacion :pag="$pag" :f="$f" :ocultos="['dia' => $dia]" />
```

- **El `WHERE` se arma una vez** y lo comparten el `COUNT(*)` y la página.
- **Los filtros van por GET** y `Listado::filtros()` ya sanea (un `select` sólo acepta una opción
  que exista). `ocultos` arrastra lo que define lo que se mira (el día de la agenda, la fecha de
  Asistencia).
- **Los `select` de un filtro ofrecen LO QUE HAY, no el catálogo**: `Listado::opcionesUsadas($sql,
  $par)` con un SQL de dos columnas `k` y `v`, **acotado igual que la lista** (misma sucursal).
- **Nunca cortar con `LIMIT` sin paginar**: a partir de la fila N+1 los datos dejan de existir
  para el usuario y no se nota.
- Se baja en CSV (BOM y punto y coma, para Excel) o PDF (`listado/imprimir` maquetada para A4),
  con **lo filtrado sin límite de página** y los filtros escritos en el encabezado. La firma del
  método tiene que declarar `View|StreamedResponse`.
- Un filtro en `null` sale como campo de texto: se saca del arreglo.
- Las migas las arma `<x-encabezado>` desde `config/navegacion.php`; `'modal'` en vez de `'ruta'`
  abre un modal en lugar de navegar.
- **Un botón de la fila que cambia justo el dato por el que la lista filtra hace desaparecer la
  fila y no se puede deshacer**: que el filtro sea una columna («Disponible acá»).

### Altas rápidas y no perder lo que la persona ya escribió

Crear una sucursal desde «Nuevo usuario» o un cliente desde «Nueva cita» manda **su propio POST
y vuelve con un redirect**. **`->withInput()` a secas no alcanza y empeora las cosas**: el alta
rápida es otro formulario, y varios de sus campos se llaman igual que los del grande. Lo resuelve
`App\Servicios\Borrador`, en dos mitades:

| Dónde | Qué |
|---|---|
| La vista del alta rápida | `data-borrador="#formPrincipal"` en su `<form>` |
| El controlador | `return Borrador::conservar($destino, $request);` |

`app.js` serializa el formulario grande en `_borrador`; `Borrador` lo devuelve a la sesión y
`old()` lo encuentra. **El formulario grande tiene que leer `old()` en todos sus campos.** La
contraseña nunca entra al borrador. Nueva cita no conserva `_old_input` salvo que el redirect
venga marcado (`Borrador::conservar()` pone la marca).

### La clienta que el salón ya tenía cargada

Casi todas entran por teléfono: tienen `persona` y `cliente` pero no `usuario`. El registro del
portal **enlaza la ficha existente** por correo entre las personas sin cuenta, en vez de
duplicarla; lo que ella carga no pisa con vacíos lo del salón; la cuenta nace inactiva y el
código va a ese correo. `ReglasDeNegocioTest::registrarse_enlaza_la_ficha_que_el_salon_ya_tenia`.
## Qué se aísla por sucursal y qué se comparte

Lo decidió el usuario módulo por módulo. La regla de fondo: **un aislado no arrastra nada a
otra sede** — un empleado no lleva su horario de un local al otro.

| Módulo | Se aísla | Se comparte | Cómo |
|---|---|---|---|
| **Citas** | todo | — | `cita.id_sucursal`; el turno y la ausencia se filtran por local |
| **Clientes** | valoraciones · catálogo de canjes | clientes · fidelización | la valoración se deduce de la cita; el canje, de `canjeable_sucursal` |
| **Servicios** | qué publica cada local | precios · descuentos · puntos por Gs. | catálogo único + `servicio_sucursal`; **se trae, no se recarga** (columna «Disponible acá») |
| **Inventario** | stock · compras · qué maneja cada local | proveedores | `producto_sucursal`; `movimiento_inventario.id_sucursal` |
| **Tesorería** | todo | — | facturas por `factura.id_sucursal`, cobros y pagos por la caja; **Cajas, Arqueos y Movimientos muestran sólo el local activo** (`sucursalDeTesoreria()`) |
| **Reportes** | se puede acotar | el consolidado | selector con «Todas» + bloque «Por sucursal» |
| **Seguridad / Personal** | turnos · asistencia · comisiones | usuarios · roles · auditoría (se ve entera y se filtra) | `comision.id_sucursal` (la del local le gana a la de todas), `auditoria.id_sucursal` |
| **Configuración** | — | ajustes · sucursales · contacto | las cuentas bancarias son de cada sucursal, en Tesorería |

- **El vale de canje vale en cualquier sede**: los puntos son del salón.
- **Las categorías se deducen**: una se ve si el local tiene al menos un servicio o producto suyo.
- **El solape de citas NO se filtra por sucursal**: la persona es una sola.
- Lo que se aísla sin columna nueva es preferible (la valoración sale de la cita, el pago del
  personal de su caja).

El acceso es **entrar → elegir sucursal → el sistema de esa sucursal**: con una sola se entra
sola. `usuario.id_sucursal` dice dónde trabaja habitualmente; en cuál está HOY lo dice la
sesión, y se comprueba en cada petición junto con el rol (si el local se dio de baja o le
sacaron la asignación, se manda a elegir). **El local se cambia desde la barra**, como combo
—`sucursal.entrar`, con los locales de `Sucursales::delUsuario()`, también en el celular— y
al cambiar se cae en el Panel. El botón de respaldo arranca visible y lo esconde `app.js`.

## Roles y permisos

| id | Rol | Alcance |
|---|---|---|
| 1 | Administrador | superadministrador: `Permisos::esAdmin()`, middleware `admin`. **`rol_modulo` no tiene ni una fila suya** |
| 2 | Profesional | atiende: citas, clientes, su asistencia. **No** administra Servicios, ni la caja, ni cobra ni factura (decisiones del usuario) |
| 3 | Asistente administrativo | operación diaria: citas, clientes, servicios, inventario, facturación, reportes, turnos, comisiones, asistencia |
| 4 | Cliente | el portal; `rol.es_personal = 0` |

Se crean y editan en Seguridad → Roles (`rol_modulo`); los roles 1 y 4 están protegidos (al
renombrarlos conservan `activo` y `es_personal`). **Nunca `id_rol IN (1,2,3)`**: filtrá con
`JOIN rol r … WHERE r.es_personal = 1`. Una cuenta puede llevar varios roles (`usuario_rol`, el
cambio de perspectiva); al cambiar, la sucursal se conserva si sigue valiendo para el rol nuevo.

### Submódulos: ningún módulo es todo o nada

**33 permisos**, clave `modulo.submodulo` (un valor atómico por fila):

| Módulo | Submódulos |
|---|---|
| `citas` | `.agenda` · `.atencion` · `.ausencias` |
| `clientes` | `.registro` · `.fidelizacion` · `.canjes` · `.valoraciones` |
| `servicios` | `.catalogo` · `.categorias` (también las zonas del cuerpo) · `.descuentos` |
| `inventario` | `.productos` · `.stock` · `.compras` · `.proveedores` |
| `facturacion` | `.facturas` · `.cobros` · `.caja` (Apertura y cierre, Arqueo) · `.cuentas` (Cuenta bancaria) · `.movimientos` · `.pagos` · `.proveedores` · `.timbrados` |
| `reportes` | no se divide |
| `seguridad` | `.usuarios` · `.roles` · `.auditoria` |
| `personal` | `.profesionales` · `.turnos` · `.asistencia` · `.comisiones` |
| `configuracion` | `.ajustes` · `.sucursales` · `.contacto` |

Todo sale de `config/permisos.php`: la matriz de Roles, las claves del POST y los nombres de
«Sin permiso». **Lo único que NO sale solo son los guardias**: cada ruta pide su clave con
`->middleware('modulo:seguridad.turnos')`; el landing del módulo es el único que pide el padre.
`Permisos::rolPuede()` resuelve la jerarquía en los dos sentidos: el padre da todos sus
submódulos, y cualquier submódulo deja entrar al módulo.

- La tarjeta del landing se filtra con `Permisos::tarjetasPermitidas()` (la clave va en el campo
  `'p'`). `config/navegacion.php` lleva la misma clave.
- En las vistas, `Permisos::puede('inventario.proveedores')` para no dibujar lo que no se va a
  poder usar. **Esconder el botón no es el control**: el middleware decide, y hay pruebas que
  piden el 403 real.
- **Al mudar una pantalla de módulo, revisá contra qué rol queda** (Timbrados es su propio
  submódulo por eso). **Canjes** también: fijar por cuántos puntos se regala un servicio es
  fijar precio.
- Los avisos internos y las alertas se dirigen **por permiso, no por rol** — y el Administrador
  entra por su rol, porque `rol_modulo` no lo lista.

**Partir o renombrar un módulo**: lo guardado en `rol_modulo` no da error, **pierde la pantalla
en silencio**. Van tres cosas: `permisos.equivalencias` (que `Permisos::leer()` y la matriz
aplican al leer; el padre viejo se traduce a **sus** submódulos, nunca al padre nuevo), el
`.sql` que se entrega con las claves nuevas, y los guardias de las rutas — **los nombres de
ruta no se mudan**.

### Profesionales y Usuarios: la persona y la cuenta

| Pantalla | Qué administra | Permiso |
|---|---|---|
| Personal → Profesionales | la **persona** y qué servicios hace (`persona_servicio`) | `personal.profesionales` |
| Seguridad → Usuarios | la **cuenta**: usuario, contraseña, rol, sucursales, turnos | `seguridad.usuarios` |

La persona se **elige** en la ficha de usuario, no se tipea. Qué servicios hace es de la
persona (`persona_servicio`, `fn_usuario_hace_servicio` resuelve desde el usuario), porque hay
gente que trabaja sin cuenta. `persona.es_personal` distingue a un profesional sin cuenta de
una fila suelta. La ficha de usuario muestra las tres secciones juntas, con un solo botón: un
`required` dentro de una pestaña cerrada hace que el navegador se niegue a enviar sin decir nada.
La contraseña vacía es «no la cambies». **Antes de mudar una tabla, buscala también en
`information_schema.routines` y `.views`**: `usuario_servicio` la leían cuatro funciones.

## Turnos y asistencia

**El turno es una plantilla, no una fecha**: nombre, horario, días de la semana y sucursal
(`turno_laboral` · `turno_dia` una fila por día · `usuario_turno` N:M). `dia_semana` va de
1 (lunes) a 7, como `date('N')` y `WEEKDAY()+1` — no `DAYOFWEEK()`. Los turnos de una sucursal
dejan al menos 60 minutos entre salida y entrada; una persona no queda en dos turnos que se
pisan, aunque sean de locales distintos. Cada turno guarda su `flexibilidad_entrada_min`.

**`rol.exige_turno`** dice si las cuentas de ese rol necesitan turno; basta con que uno de sus
roles lo exija. En la ficha, el bloque de turnos se muestra según `data-exige-turno` — y
esconder no borra.

**Asistencia** (`asistencia`: persona, turno, fecha; `justificada` NULL presente · 1 falta con
permiso · 0 sin permiso): se ficha con un botón y queda `ahora_bd()`; la entrada se habilita
dentro de la franja del turno (una hora antes, dos después) y **pasada la franja el botón no se
ofrece** (con un día anterior sí: es corregir la planilla, y la hora la pone quien corrige, dentro
del turno). Marcar una falta es constatar —entra sin aviso—; el permiso lo da después el
Administrador desde «Justificar», con motivo de al menos 10 caracteres. **Una falta no pisa una
entrada ya fichada.** La lista va por turno y del local activo, se actualiza en vivo, y
«Últimos registros» pagina de a 25 arrastrando la fecha. La atención elige el turno que cubre
la hora de la cita.

## Agenda y disponibilidad

`fn_verificar_disponibilidad` es la única autoridad sobre si un horario sirve: **ausencias,
turno laboral (del local) y solape con otra cita**.

### El criterio permisivo de los turnos es DEL SALÓN

Si alguien tiene turnos cargados, el salón usa turnos y **quien no los tenga no atiende**.
Resuelto persona por persona, la propietaria y la recepcionista se llevaban la mitad de las
citas, en domingo. Son tres lugares y hay que tocar los tres: `fn_verificar_disponibilidad`
(por local), `Agenda::slotsProfesional()` (el espejo) y `Agenda::profesionales()` (ni lo ofrece).
`fn_usuario_hace_servicio` y `fn_puede_realizar` siguen el mismo criterio: sin nada cargado,
hace todo.

### Dos personas pidiendo el mismo hueco

`sp_agendar_cita` y `sp_reprogramar_cita` toman un candado sobre el profesional (`SELECT …
FOR UPDATE`) antes de consultar la disponibilidad; la cancelación toma el de la cita **primero**
y mira el estado después. **El candado solo vale dentro de una transacción**: todos los caminos
pasan por `Agenda::agendar()` / `Agenda::reprogramar()`, que envuelven con `Bd::enTransaccion()`.
Un índice único `(id_usuario, fecha_hora)` no sirve (canceladas, solapes parciales).
`ConcurrenciaAgendaTest` lanza 5 procesos contra el mismo hueco.

### El espejo de PHP

Para pintar la pantalla los huecos se calculan en PHP (`Agenda::datosProfesional()` trae turnos,
citas y ausencias en tres consultas; `slotsProfesional()` arma en memoria: 0,11 s contra 38 s
preguntándole a la base hueco por hueco). **La autoridad sigue siendo la base al guardar.** Si
cambiás las reglas de disponibilidad en la base, reflejalas en el espejo:
`CimientosTest::el_espejo_de_php_dice_lo_mismo_que_la_base` compara los dos caminos hueco por
hueco. `Agenda::motivoHuecoPerdido()` explica por qué un hueco que la pantalla mostró ya no
sirve. Las pantallas no dejan escribir una fecha a mano: `citas/disponibilidad`,
`portal/disponibilidad` y `cita.disponibilidad` (el enlace del correo, sin sesión) sirven JSON y
`app.js` pinta días y horas (varios `[data-agenda]` por página; `data-agenda-personas` fija
cuántas vienen).

### Los horarios son la INTERSECCIÓN de las agendas de quienes atienden

Con más de un servicio, la hora que se ofrece es una en la que cada profesional del reparto está
libre **en su propio tramo** (`Agenda::slots()`, que devuelve cada hora con su duración y su
reparto). En «quien me atienda», `mejorReparto()` elige **el que termina antes y, a igual
tiempo, el que ocupa a menos gente**; el guardado usa el mismo cálculo (`repartoPara()`), así
no ofrece lo que después rechaza. **Pedida es pedida**: si la elegida no llega, a esa hora no
hay cita. Las duraciones y zonas se leen una vez por petición (`infoServicios()`).
`trg_citaserv_bi` mira a quien hace cada servicio (`COALESCE(NEW.id_usuario, dueño)`).

Cuando no hay hora, se dice **cuál es la variable que no cierra** (`porQueNoHayHora()`,
`porQueNoHayDia()`): quién no tiene lugar, quién no coincide y cuándo puede aparte, o qué
servicio no entra ese día. Los botones de turno acotan combos, días y horas, y sin ninguno el
turno se deduce del primer profesional pedido; el servidor vuelve a comprobar turno y servicio.
`Agenda::motivoSinCupo()` distingue «está tomado» de «no cabe en el turno más largo del local».
`Agenda::diasYaTomados()` saca del selector el día en que la clienta ya tiene ese servicio.

### Una cita, varios profesionales, varias personas

- `cita_servicio.id_usuario` (NULL = lo hace el dueño de la cita — **un NULL no es «nadie»**:
  `COALESCE`, nunca `IS NOT NULL`). La cita dura el bloque más largo (`fn_cita_duracion`
  agrupa por profesional), y `fn_cita_duracion_de` da lo que se le ocupa a cada uno.
- **Qué se puede hacer a la vez lo decide la ZONA DEL CUERPO** (`zona_servicio`,
  `servicio.id_zona`, administrable en Servicios → Zonas del cuerpo): misma zona se turna y
  suma, zonas distintas conviven. **La persona también es un recurso**: `Agenda::turnos()` busca
  el primer turno libre de zona y de profesional, acomodando de mayor a menor; el orden guardado
  (`cita_servicio.orden`) es del servicio; `fn_cita_inicio_de` dice desde cuándo. **El cupo de
  cada zona es la cantidad de personas** (`cita.personas`); el candado del profesional no se
  relaja nunca. `requiere_exclusividad` queda en la base sin uso.
- **El mismo servicio para varias personas** es una fila de `cita_servicio` por persona
  (`persona` 1..N; único `(id_cita, id_servicio, persona)`); las copias van con la misma
  profesional y en serie; `Agenda::vecesPorServicio()` fija cuántas veces va cada uno; la
  atención registra una `servicio_realizado` por persona.
- **El calendario mide con `duracionPrevista()`** (el mejor reparto) y `slots()` vuelve a
  comprobar hora por hora. `personas` se lee antes de validar.
- La cita **para otra persona** (`para_otra_persona`, `nombre_para`, sin crearle ficha) se
  superpone a propósito con las de la titular; `Agenda::citaDelClienteSePisa()` cuida el resto.
  Quiénes vienen: `cita_acompanante` (una fila por persona, `orden` desde 2), con
  `App\Servicios\Acompanantes`. Los nombres son obligatorios y el servidor los vuelve a pedir.
- La cita tiene dueño: `Agenda::principalDelReparto()`; sin nadie elegido decide el sistema.

### Cada profesional cierra SU parte

`cita_servicio.terminado_en` (NULL = todavía no). Cada uno cierra su parte desde «Registrar
atención» con su propio consumo; el Administrador elige de quién cierra (`cerrar_de`) y **no
toca lo de los otros**; la cita pasa a Atendida cuando no queda ninguna abierta, y **se factura
recién entera**. `fn_cita_duracion_de` deja de contar lo cerrado, así que la agenda la suelta
en el motor y en el espejo a la vez. `citaAjena()` acepta a la dueña **o** a quien tiene un
servicio a su nombre. La agenda del profesional muestra «Con quién» y «Mis servicios».

### El asistente de reserva: una decisión por pantalla

Las dos pantallas que reservan (`data-asistente`, pasos `data-paso`; Personas → Servicios →
Profesionales → Fecha y hora → Detalles → Confirmar, con Cliente adelante en Nueva cita):

- **Sin `app.js` se ven todos los pasos y se reserva igual**.
- **`required` se saca del paso escondido y se devuelve al mostrarlo** (`trabar()`).
- **El paso de profesionales MUEVE los combos, no los copia.**
- Ningún paso avanza a medias: `data-paso-requiere`, `data-paso-error`; `personas` es `required`
  y el nombre de cada acompañante se dibuja con `required` desde `app.js`.
- El repaso (`data-wiz-repaso`) se arma con los `data-` de las tarjetas, sin preguntarle al
  servidor. Cada paso se arma al entrar (`sgp:asistente-paso`). Tras un rechazo abre en el
  último paso con `old()`.
- «Personas» pregunta primero quién se atiende (Para mí / Para otra persona, con las alergias de
  ESA persona) y después si viene alguien más.

### Los botones de la fila son de la HORA de la cita

| Botón | Desde cuándo | Quién lo hace cumplir |
|---|---|---|
| Registrar atención · En proceso | `CitasController::MINUTOS_ANTES_DE_ATENDER` (25) antes | `atenderGuardar()` y `estado()` |
| Ausente | cuando la hora pasó | `estado()` |
| Cancelar · Reprogramar · Cambiar profesional · Cobrar seña | siempre | — |

La constante viaja a la vista como `$minutosAntes`; «falta fichaje» sigue la misma ventana.
En proceso exige la entrada fichada. Ausente y Cancelada cierran la fila (queda cobrar lo que
haya quedado debiendo). Cambiar el profesional es de administración, pide motivo y avisa a la
clienta por correo; el combo ofrece sólo a quien hace ese servicio.

### Reprogramar, atrasada, rango

- **La clienta reprograma UNA vez, con motivo**: el estado 2 (Reprogramada) es la marca; el
  botón se reemplaza por «Ya cambiada»; el enlace del correo también lo respeta (mira el estado,
  no el token). Al reservar se le dicen las tres condiciones (plazo de la seña, un solo cambio,
  la seña no se devuelve). El mostrador no tiene el tope.
- **Atrasada (7)**: 30 minutos después de la hora, Programada/Reprogramada pasa a Atrasada
  (bloquea agenda); 24 horas después, a Ausente (`CitasVencidas`, por `sgp:notificaciones`). La
  clienta que no se presenta queda ausente a los 15 minutos
  (`CitasVencidas::MINUTOS_SIN_PRESENTARSE`, decisión del salón). Una En proceso no se toca.
- **La agenda se mira por día** (filtro «Ver» vacío, con orden por lo que falta hacer: sale de
  `estado_cita.bloquea_agenda`) **o por rango** (`sem`, `mes`, `prox`, `todas`, ordenadas por
  fecha), siempre paginando. Los marcadores son sólo los que la consulta usa.
- Filtros por cliente, profesional, servicio y estado.
## La campanita: la bandeja del sistema

Es un solo lugar, dibujado por el layout para el personal, con dos servicios detrás:

| | `Alertas` | `Pendientes` |
|---|---|---|
| Qué dice | lo que está **pasando** ahora | lo que falta **configurar** |
| Ejemplos | una seña por confirmar, una caja abierta hace 24 h (`sgp.caja.horas_abierta_aviso`), un producto al mínimo | un timbrado sin cargar, el correo del sistema, un profesional sin turno o sin servicios, una cuenta sin arqueo |
| En la bandeja | primero | después, bajo el mismo rótulo «Avisos» |
| ¿Deja de contar al verlo? | sí (`alerta_vista`, por persona, con **clave estable** como `caja:12`, `stock:<suc>:<huella>`, `sena:<solicitud>`) | **nunca**: verlo no lo resuelve |

- Cada renglón lleva **dónde se resuelve y con qué permiso**; un aviso puede traer su `url`
  armada (la seña: `?dia=…&cobrar=<cita>`) y si no, `Navegacion::url(ruta)`.
- `Alertas::marcarVistas()` sólo acepta claves que hoy estén en la campanita de quien llama.
- Sin JavaScript la bandeja se abre y se lee; el número baja con JS. Entra en la huella en vivo.
- `Pendientes::mios()` corre en cada pantalla (unas dieciséis consultas chicas). Si pesa, la
  salida es una caché corta por usuario y sucursal, no devolver el bloque al Panel.
- **La mitad que se olvida**: la campanita llegó a ser un `<a href="#">` con todo el servicio
  escrito detrás. La prueba exige el conteo (`sgp-campana-n`) y el texto (`sgp-alerta-que`) en
  el HTML.
- `sgp:pendientes` es lo mismo por consola; `sgp:diagnostico` dice si el sistema está **sano**,
  esto si está **configurado**: el sistema no se rompe cuando falta un dato, cae en el criterio
  permisivo.

## Cambio de contraseña: segundo factor

Desde Mi cuenta pide la contraseña actual **y** un código al correo. `CuentaController::password`
valida y deja la nueva **ya hasheada en la sesión** (vence a los 30 minutos;
`passwordCancelar` la descarta); `passwordConfirmar` valida el código y recién ahí escribe
`usuario.password_hash` y regenera la sesión. **Sin correo cargado no se deja cambiar.** La
recuperación («me olvidé») es otro camino con su propio tipo de token.

## Avisos y recordatorios

`App\Servicios\Notificaciones` llena y despacha la cola de `notificacion`; los correos son
Mailables (`AvisoCita`, `AvisoInterno`, `CodigoSeguridad`, `ComprobanteCliente`, `ReciboSena`)
con plantillas en `resources/views/correo/` (estilos en línea, colores escritos).

- **Los enlaces salen de `app.url`**, no de `route()` a secas: el planificador no tiene
  petición y una acción de pantalla tiene el host que tipeó quien entró
  (`Notificaciones::urlReprogramar()`, `base()` avisa en el log si apunta a localhost).
- **El enlace del correo (`token_cita`) permite reprogramar o cancelar sin sesión**: dura 30
  días, muere al cancelar, y sus horarios salen de `cita.disponibilidad` (el token es la
  credencial, lo que se consulta sale de la cita).
- **El profesional no va a estar** (ausencia cargada o baja): `avisarProfesionalNoDisponible()`,
  con `id_usuario` NULL para todo el salón. Al cambiar el profesional de una cita:
  `avisarCambioDeProfesional()`.
- **Recordatorio**: `generarRecordatorios()` con la anticipación que cada cliente elige en
  Portal → Mis recordatorios (`preferencia_recordatorio`). Al reprogramar se descarta el
  pendiente y se genera el nuevo. `sp_generar_recordatorios` queda en la base sin uso: no mira
  la preferencia.
- **Los avisos internos** (`destinatario = 'INTERNO'`: stock al mínimo, cierre de caja) van con
  `AvisoInterno` a quien tenga el permiso del módulo (`inventario.stock`, `facturacion.caja`) —
  y el Administrador por su rol.
- Los procedimientos crean avisos con canal `WHATSAPP`; el despachador los toma y los manda por
  correo corrigiendo el canal. Lo que nunca va a poder mandarse se cierra como FALLIDA al día.
- **`php artisan sgp:notificaciones`** corre cada diez minutos con `withoutOverlapping()`
  (`routes/console.php`): despacha, marca Atrasada/Ausente, suelta las señas vencidas.
- Sin sesión (el enlace del correo) se audita con `Auditoria::registrarComo()`.

### La cuenta que ENVÍA los avisos se cambia desde el sistema

**Seguridad → Correo del sistema**, sólo el Administrador (middleware `admin`, no un submódulo);
vive en `configuracion.mail_usuario/mail_clave/mail_desde`, la clave **cifrada con la APP_KEY**;
`Config::aplicarAlMailer()` pisa el mailer al arrancar (web y planificador). **Vacío es que no
sale ningún correo**, y no en silencio: `sgp:diagnostico` lo cuenta como problema, el panel lo
lista con enlace, la pantalla lo dice. El remitente tiene que ser del mismo dominio que la
cuenta (Gmail lo rechaza). La clave nunca vuelve al navegador. Es distinta de `Config::email()`,
el correo **fiscal** del KuDE.

### El calendario del cliente

`App\Servicios\Calendario` arma el **.ics** (RFC 5545, PHP puro, `VALARM` con la anticipación
elegida). Son **dos botones y hacen falta los dos**: «Calendario del celular» (`cita.calendario`,
el `.ics` — Android lo baja sin abrirlo) y «Google Calendar» (`Calendario::urlGoogle()`, con
`ctz=America/Asuncion`). **Las horas van en hora flotante, sin `Z` y sin convertir a UTC**, a
propósito: una tzdata vieja convertiría a UTC−4 y la cita llegaría corrida. No unificar con
`ahora_bd()`, que resuelve otro problema.

## Inventario

### El frasco y el mililitro

El producto lleva `contenido` (cuánto trae el envase) y `unidad_consumo` (en qué se gasta); con
las dos es *fraccionado* y la pantalla pide mililitros. `producto_fraccionado()`,
`consumo_a_stock()`, `stock_a_consumo()`, `unidad_consumo()` en `formato.php`. **El stock se
guarda en la unidad de compra**; la conversión pasa al entrar y al salir. Un envase (caja,
frasco…) sin contenido cargado descuenta de a envases enteros, y la pantalla avisa.

**La cantidad necesita cuatro decimales**: `producto_utilizado.cantidad`,
`movimiento_inventario.cantidad`, `fn_producto_stock` (el `RETURNS` y su `v_stock`),
`trg_movinv_bi`, `trg_movinv_ai` y `sp_registrar_movimiento_inventario` están en
`DECIMAL(12,4)`; con dos, 15 ml descontaban 20 y 1 ml no entraba. `consumo_a_stock()` redondea
a 4: **si se cambia una, cambiá la otra.** La unidad se muestra al lado del campo
(`data-unidad`).

- `stock_minimo` es de cada local (`producto_sucursal`) y **tiene que ser mayor a cero**: en
  cero el aviso está apagado. `vw_producto_bajo_stock` lista con `<=` y un faltante de al menos
  una unidad; la campanita nombra los productos e Inventario → Stock es la **lista de compras**,
  que lleva a Nueva compra con los faltantes cargados.
- Los productos duplicados **se dan de baja, no se borran**: tienen movimientos colgando.
- Nueva compra ofrece el **catálogo entero** (comprar es cómo un producto entra a un local),
  reconoce el producto por id o por nombre normalizado, trae el último precio pagado (editable)
  y la tasa de IVA del producto, y la lupa abre el catálogo con stock y mínimo. El precio no
  puede quedar vacío.

### La compra refleja la factura del proveedor

**Una compra ES la factura del proveedor.**

| Qué dice el papel | Dónde vive | ¿Se guarda? |
|---|---|---|
| El descuento del comprobante | `compra.descuento`, un monto sobre el total | sí |
| El IVA de cada renglón | `detalle_compra.tasa_iva` (0 · 5 · 10), propuesto del producto | sí, como `detalle_factura.tasa_iva` |
| La nota de crédito del proveedor | `compra_nota_credito`: número, fecha, monto, motivo, el papel adjunto | sí |
| Bruto, total, pagado, acreditado, saldo | `fn_compra_bruto` · `_total` · `_pagado` · `_acreditado` · `_saldo` | **no: se calculan** |
| El IVA incluido por tasa | `vw_compra_impuestos` (descuento repartido, como la venta) | no |
| Qué cuota vence y cuánto le falta | `fn_compra_cuota_pendiente` · `fn_compra_cuota_falta` · `Compras::cuotas()` | **no: se deduce por orden** |

- El descuento es uno por comprobante (como `factura_descuento`), no puede pasarse de los
  renglones, y `fn_compra_total` no baja de cero.
- **La nota de crédito baja el saldo como un pago, pero NO es un pago**: no salió de ninguna
  caja, no toca ningún arqueo; vive en la ficha de la compra (Inventario → Compras → ver), se
  anula con motivo (`activo` + `anulado_motivo`), **su tope es el saldo** (un crédito a favor no
  se aplica a otras compras) y **no toca el stock** (la devolución va por Movimientos). El papel
  va fuera de `public/` por `App\Servicios\Respaldo`, el mismo que guarda el comprobante del
  gasto de caja.
- **Qué cuota cubre cada pago no se guarda**: lo que entra va a la cuota más vieja
  (`fn_compra_cuota_pendiente` con `SUM() OVER`). `fn_compra_vencimiento` devuelve la fecha de la
  cuota pendiente (devolvía la primera aunque estuviera pagada: la compra quedaba «vencida» para
  siempre).
- Las cuotas (`compra_cuota`, una fila por cuota) se cargan al registrar la compra a crédito; la
  pantalla reparte en partes iguales y lo que no divide exacto va en la última.
- **Dónde se ve**: Cuentas por pagar dice la condición y la cuota en la fila; el modal de pago
  abre la cuenta entera y **propone la cuota que vence, no el saldo**; con la caja cerrada se
  paga igual por banco.
- **El número de factura del proveedor se carga después**, desde la lista, la ficha o el modal
  del pago (sólo si estaba vacío; el formulario declara `desde` para volver a donde se vino).
  **No confundirlo con «Comprobante de este pago»**, que es la referencia del banco o el recibo,
  opcional.
- Un local recién abierto **trae** los productos de otra sede (sin copiar stock) y publica los
  servicios; **traer, no recargar**, porque cargarlo de nuevo lo escribe distinto y parte el
  stock.
## Facturación (Paraguay / DNIT)

Formato del Manual Técnico del SIFEN v150, grupo C: timbrado de 8 dígitos; establecimiento y
punto de expedición de 3; correlativo de 7 (`001-001-0000001`). Los timbrados se administran en
**Facturación → Timbrados** (`facturacion.timbrados`, submódulo propio porque no puede ir con
`facturacion`), con validación en la app y `CHECK` en la base (`chk_timbrado_rango7` es la
estricta: el nombre `chk_timbrado_rango` ya estaba ocupado). **El timbrado es por TIPO de
comprobante y por sucursal**: `fn_timbrado_vigente(tipo, fecha, sucursal)` cae al de otra sede
si el local no tiene el suyo —deliberado, dejar de facturar sería peor— y **la pantalla lo
dice**. Por eso `factura.id_sucursal` se guarda: con esa caída, el local no es derivable del
timbrado.

- **Al agregar un `CHECK`, actualizá `CHECKS` en `sgp:diagnostico`** (y `ESPERADO`): compara
  con «menos que», así que quedarse corto esconde justo lo que debería detectar.
- El comprobante se ve e imprime en Facturación → Facturas → Ver (`vw_detalle_factura`,
  `vw_factura_impuestos`); el IVA va incluido en el precio y se desglosa. Toma la cabecera del
  KuDE sin las leyendas de la DNIT.
- **`sp_emitir_factura` factura desde `cita_servicio`**: «Registrar atención» saca de ahí lo que
  se agendó y no se hizo.
- **Anular no es borrar**: la numeración no admite huecos; primero los cobros, después la
  factura; siempre con motivo en `auditoria`.

### El pago mixto es el modelo

`cobro` es **cada pago**, no el pago de la factura: `fn_factura_saldo` los resta a todos. El
modal trabaja con líneas (`metodo[]`, `monto[]`, `cuenta[]`), una llamada a `sp_registrar_cobro`
por línea **en una transacción**; arranca con una línea, cada una muestra sólo el detalle de su
medio (`app.js` clona el `<template class="sgp-cobro-molde">`; las clases `sgp-cobro-*` las
busca el JS) y calcula el vuelto **sólo sobre las líneas en efectivo**, que no se guarda. Los
campos ocultos siguen en el DOM: el controlador toma cada dato por posición. El detalle va a
`cobro_tarjeta` (`tipo_tarjeta` es `NOT NULL`: un select que nunca viaja vacío) o `cobro_banco`;
los triggers verifican que corresponda al tipo. La ventana de cobro es ancha y en dos columnas
(`sgp-cobro-2col`). El número de tarjeta, el cheque y la fecha del cheque se validan (30 días
para presentarlo, 180 de tolerancia, un diferido no pasa del año).

### Primero se cobra, después se elige el comprobante

Es el orden del mostrador. Un cobro puede colgar de la CITA (`cobro.id_cita`, `id_factura`
NULL) y `fn_factura_saldo` ya los descuenta: al emitir después, el comprobante sale saldado.
La observación distingue «Sena de reserva» de «Cobro de la atencion». Con comprobante emitido
el cobro va contra él.

La agenda contesta las tres situaciones de una cita atendida: **Cobrar** (abre la ventana de
cobro), **Debe Gs. X** / **Emitir** (`facturacion.emitir?cita=`, con esa cita primera y marcada;
`?cita=` sólo ordena) y **Cobrada**. La huella en vivo incluye lo cobrado y lo facturado, así el
otro mostrador no cobra dos veces. **Cobros lista «Falta cobrar N atenciones»**: con comprobante,
lo que falta lo dice **el comprobante** (`fn_factura_saldo` de los de signo 1) y contra la cita
sólo la parte sin comprobante — `fn_cita_total` es la cuenta de HOY, con las promos de hoy, y
sobre una cita facturada miente.

**La cita de varias**: `cita_servicio.persona`, `cobro.persona` (NULL = el grupo paga junto) y
`factura.persona` (NULL = toda la cita). `sp_emitir_factura(…, p_persona)` arma el detalle sólo
con lo de esa persona y `fn_factura_saldo` descuenta sólo sus cobros. Emitir pregunta **¿de
quién?**; «toda la cita» se apaga en cuanto alguna tiene el suyo; la cita a medio facturar sigue
en la lista; el receptor sabe de quién es. `Acompanantes::cuenta()` da lo que le falta a cada
una.

### Registrar atención

- El combo de profesional no aparece en lo agendado (ya está decidido); lo que se suma en el
  sillón ofrece **sólo lo que esa persona hace**, y «Sumar un servicio con otra profesional» va
  plegado. Los productos van agrupados por servicio con el servicio fijo en un `hidden`
  (`producto[]`, `cantidad[]`, `servicio_de[]` posicionales), bajo los servicios reservados con
  quien cierra; «Otra fila» clona dentro de su grupo.
- Muestra **tres renglones**: servicios marcados, ya pagó de seña, queda por cobrar.
- No se registra una atención que falta más de 25 minutos; sin fichaje tampoco, y se ficha desde
  ahí mismo. El consumo va **después y línea por línea**: un producto sin stock no borra la
  atención (`IN-02`). El autor de cada `servicio_realizado` es quien hizo el servicio.
- «Ver atención» es sólo lectura, con los productos usados; el servidor rechaza tocar una cita
  atendida.
- Sin productos habilitados en el local, lo dice y nombra el camino.

### Qué comprobantes se emiten

Los `activo = 1` de `tipo_comprobante`: **Factura** (1) y **Nota de crédito** (5), los dos
declarables (`config('sifen.tipos_electronicos')`), cada uno con su propio timbrado. La Factura
se emite de dos formas: **declarada** (con los datos del receptor) o **sin nombre** (innominada,
válida por debajo de `Sifen::TOPE_INNOMINADO` = Gs. 60.000.000, rechazo 1321) — **las dos se
declaran**; lo que cambia es qué datos lleva. `SIFEN_TIPO_DEFECTO` vale 1 y tiene que moverse
junto con la baja de un tipo. El «Comprobante de pago» y otros cinco tipos están dados de baja
(`activo = 0`), no borrados.

### Los datos del receptor se piden ANTES de emitir

`facturacion/receptor`, grupo D del manual: tipo y número de documento (D206/D208), nombre o
razón social (D211), correo (D216, **a donde llega el KuDE**), dirección y teléfono. **El orden
importa porque un rechazo no se reintenta**: el número ya se gastó. Se valida el DV del RUC por
módulo 11 con pesos **2..11** (`Sifen::dvRuc()`; verificado contra el CDC de ejemplo del manual;
el `80012345-6` del Automatizador tiene el DV mal) y el tope del consumidor final. Lo corregido
se guarda en `persona`; con RUC la razón social no se parte. La innominada pasa por la misma
pantalla en modo reducido.

### Emitir y declarar son DOS pasos

La factura se emite y **ya es válida**; el envío sale seguido pero **no atado**: si falla, queda
`PENDIENTE` y se reintenta desde el comprobante (mirá si ya tiene CDC). `RECHAZADO` no se
reintenta: se corrige el dato y se emite otra. Un fallo de red deja PENDIENTE, nunca RECHAZADO.
El mensaje dice si el PDF salió por correo y a dónde.

### El SGP y el Automatizador SIFEN

El SGP no habla con la DNIT ni firma: escribe el comprobante ya numerado en el formato de
texto del Automatizador (`_sifen/`, proyecto aparte, sólo HTTP) y guarda el CDC en
`factura_electronica`.

```
EMI|Salón|80012345|0|Avda…|Luque|021…|f@…|96021|PELUQUERIA…|16005678|2026-01-01|2026-12-31|Sucursal
FAC|001|001|0000123|2026-08-11|1|PYG|2        correlativo, fecha, condición, moneda, iTipTra (2 = servicios)
CLI|CI|4200000|Andrea Villalba|a@b.c||0981…
ITM|S001|Brushing|1|60000|10[|precio de lista]  IVA incluido; el total lo suma el Automatizador
```

- **El descuento va DENTRO del precio de cada renglón** (prorrateado, la última línea absorbe
  el redondeo: la suma da `fn_factura_total`) y **el precio de lista viaja como séptimo campo
  opcional**: un Automatizador viejo lo ignora y declara el mismo total. Del otro lado,
  `InvoiceFactory` lo toma como E721 y la diferencia como EA002/EA003; EA008 sigue del neto; F009
  y F011 van separados; E727 es E721 × cantidad. El KuDE muestra el descuento en monto, en su
  columna, sin fila DESCUENTO al pie.
- **`EMI|` lleva al emisor** (razón social de `configuracion`, RUC y DV **recalculado** de la
  sucursal que emitió, dirección, actividad y correo fiscal de Ajustes, el timbrado): sin él el
  KuDE salía a nombre de la empresa del `.env` de ejemplo. Es opcional: un TXT sin él usa el
  `.env`. Los códigos geográficos no se mandan.
- **Modo simulado** (`SIFEN_MODO=simulado`): arma el TXT y devuelve un CDC de prueba que
  empieza en `0`; **no llama a nadie**, así que el PDF no llega. Para el correo hace falta
  `SIFEN_MODO=http`, `SIFEN_URL=http://sifen:8090/` (el nombre del servicio), `SIFEN_TOKEN` igual
  al `SIFEN_API_TOKEN` del otro lado (o 401 = RECHAZADO), y la cuenta de correo del SGP.
- **El Automatizador sube con el compose y CONFIGURADO por variables** (`SIFEN_MODE=mock`,
  `APP_URL`, `MAIL_FROM_EMAIL` vacío): su `bootstrap.php` cae en `.env.example` si no encuentra
  `.env`, y un `.env` dentro del contenedor no sobrevive al despliegue. Volúmenes `sifen_certs`
  (el certificado, que no está en el repositorio) y `sifen_salida`. Con certificado,
  `SIFEN_MODE=prod` y las variables de su `bootstrap.php`, por el compose. Si la carpeta no
  está, el contenedor se apaga (un 404 sería RECHAZADO); `restart: on-failure`.
- **El comprobante lo manda UNO SOLO: el SGP**, con la cuenta de Correo del sistema, adjuntando
  el KuDE y el XML desde la copia local. Tres candados para que el Automatizador no mande:
  `X-SGP-Correo: no` en cada emisión (`Sifen::cabeceras()`, el que manda), `MAIL_FROM_EMAIL: ""`
  en el compose, y vacío en su `.env.example`. Si igual contesta `mail_enviado: true`, es una
  versión vieja y el SGP lo dice.
- **El comprobante se ve DESDE EL SISTEMA**: al declarar se bajan el PDF y el XML a
  `storage/app/sifen/<factura>/` junto con el TXT exacto (que se guarda aunque el envío falle);
  `facturacion/sifen/archivo` los sirve. `Sifen::bajarCopias()` para los declarados antes.
- **Con `SIFEN_ACTIVO=false` el módulo no existe** y se caen tres cosas de una (receptor,
  declaración, adjuntos): `sgp:diagnostico` lo avisa como OJO. Vive en el `.env` del entorno, no
  en `secretos.env`: hay que volver a desplegar.
- **El KuDE es del salón**: la DNIT no impone diseño; banda verde con texto blanco, acento sólo
  en la banda y la regla de la tabla. Los colores son constantes en `KudeService`.

### Mandarle el comprobante a la clienta

Botón **Enviar por correo** en el comprobante; desde Cobros el número abre el comprobante. El
detalle va en el cuerpo (`correo/comprobante`, nombres de `vw_factura_resumen`:
`descuento_total`, `total_neto`); si se declaró, con el KuDE y el XML adjuntos desde la copia
local. La dirección se puede cambiar sin tocar la ficha. Cada envío queda en `auditoria`. La
clienta los ve y los baja en **Portal → Mis citas** (`portal.factura_ver`,
`portal.factura_descargar` con Dompdf; un solo partial `portal/_factura_cuerpo`; la pertenencia
se comprueba en la consulta; una acreditada lo dice).

### La seña

**Cuánta seña se pide lo fija el salón**: `servicio.sena_porcentaje` (vacío = no pide) y
`fn_cita_sena_requerida(id_cita)`. Porcentaje y no monto, para que siga al precio; lo canjeado
no pide seña. Se ve **tres veces con su desglose** (`App\Servicios\Sena::desglose()`,
`facturacion/_sena_desglose` compartido por el portal y la agenda): al reservar (armado en el
navegador con `data-sena`/`data-sena-pct`; el servicio sin seña declara cero), al ir a pagarla,
y al confirmarla en el mostrador. **Que es un mínimo se dice a la vista.** Se topea contra el
total con descuento (FA-03).

**A dónde transferir** lo dice el sistema (no hay pasarela): las cuentas bancarias de la
sucursal de la cita marcadas **«Usar para señas»** (`Cuenta::paraSenas()`), con el alias primero
y su tipo en el rótulo («Alias (Cédula)», `App\Servicios\Pagos`), sin repetir el documento cuando
es el alias. Sin ninguna cargada se dice.

**La clienta registra la seña desde el portal** (`sena_solicitud`: no es un cobro, es el aviso;
el estado se deduce: sin cobro y sin rechazo es pendiente; con comprobante adjunto opcional en
`storage/app/senas`, servido por `facturacion.sena.comprobante`) y **el salón la confirma** desde
la agenda (`FacturacionController::sena`, que enlaza el cobro con la solicitud), eligiendo con
qué se pagó y sin tocar el monto; no se acepta menos de lo que el salón pide. También se carga
directo. La campanita avisa la solicitud pendiente. **La reserva con seña queda pendiente y el
lugar se guarda `sgp.agenda.sena_horas` (24)**: la cita dice «sin confirmar», y pasado el plazo
`cancelarSenasVencidas()` la suelta **y le avisa**; una solicitud pendiente no se cancela; ni las
de hoy ni las pasadas.

**Al confirmar la seña, la clienta recibe el recibo con la confirmación** (`Sena::recibo()`,
`Sena::mandarRecibo()`, `App\Mail\ReciboSena`): el recibo en el cuerpo **y en PDF adjunto**
(Dompdf A5, el mismo partial `correo/_recibo_sena_cuerpo`), con el enlace del token; dice «queda
confirmada» si lo señado cubre `fn_cita_sena_requerida`. Va después y no atado: si falla, el
aviso (tipo 2) se encola en `notificacion`; si sale, se anota ENVIADA. No es un comprobante
fiscal y lo dice. No se manda al cobrar una atención.

**La seña no se vincula a la factura**: queda como cobro con `id_cita`; `fn_factura_saldo` ya
la descuenta. El modal de cobro de la atención propone lo que falta, no la seña otra vez.

### Descuentos y promociones

Dos fuentes: el **nivel** de fidelización (`fn_cliente_descuento` → `nivel.id_descuento`, por
visitas) y las **promociones** (cualquier `descuento` no atado a un nivel, vigente, con
`servicio_descuento` para acotarla a servicios). **Se calculan por SERVICIO y se suman**: cada
servicio con el mejor descuento que le aplique; dos promos sobre el mismo servicio compiten y el
nivel no se acumula sobre uno que ya tiene promo; un monto fijo se aplica una vez sobre el
renglón más caro. `fn_cita_descuento_total`, `fn_factura_descuento_total`,
`sp_aplicar_descuentos`; `factura_descuento` es una tabla de filas. **Lo que se cobra antes de
facturar también lo descuenta**: `fn_cita_descuento_monto`, `fn_cita_promo_vigente`,
`fn_cita_total` (lista − canjeado − descuento). El precio de lista se muestra tachado. Una promo
al 0 % o que nace vencida se rechaza.

### Nota de crédito

`sp_emitir_nota_credito` (parcial o entera, con `id_factura_origen`) se declara ante la DNIT
igual que la factura, pregunta a dónde mandarla, revierte los puntos, y **descuenta el efectivo
del cajón que se ELIGE** entre las cajas abiertas del local que emitió la factura (por lo que
la clienta pagó en efectivo, prorrateado). La nota se emite siempre; si la devolución no pudo
salir (sin caja elegida entre varias, sin caja abierta, sin saldo), queda pendiente en
Movimiento de efectivo con la clase «Devolución al cliente», donde el monto sale del documento.
La duplicidad la impide `uq_movcaja_devolucion (id_factura, activo)`. El egreso va en su propio
`try`. `sp_emitir_nota_credito` no llama a `exigeCaja`, igual que emitir una factura.

**Las notas de DÉBITO** están fuera de alcance.
## Caja

**`caja` es una SESIÓN de trabajo sobre un cajón** (`caja_fisica`, con nombre y local). Una
sesión abierta por cajón, `trg_caja_bi` acotado a `NEW.id_caja_fisica`.

| Palabra | Qué es | Dónde | Cuándo |
|---|---|---|---|
| **Caja** | el cajón físico | `caja_fisica` | se carga una vez; se renombra; se borra sólo si nunca se abrió, si no se da de baja |
| **Apertura y cierre** | la sesión, con monto inicial y responsable | `caja` | dos veces por día |
| **Movimientos** | qué entró y salió | `cobro`, `movimiento_caja`, `pago_proveedor`, `pago_personal` | todo el día |
| **Arqueo** | contar el efectivo y compararlo | `caja.monto_contado` + `fn_caja_diferencia` | al cerrar |

**Tres pantallas** (todas del local activo): **Cajas** (tarjetas por cajón: saldo, responsable,
«abierta el …», y desde la tarjeta los modales de Movimientos del día, Arqueo y Abrir —
`facturacion/_arqueo_modal`, `_abrir_modal`, compartidos con la pantalla de la caja, con ids con
sufijo—), **Movimientos** (filtros, tabla, paginación; por caja y por cuenta) y **Arqueos**
(dos pestañas: Cajas y Cuentas bancarias, y desde las dos se arquea). Crear cajones es del
Administrador. El Panel dice **cuántas** cajas hay abiertas, igual para todos.

### Quién elige la caja, y sin caja no se mueve efectivo

**La SUCURSAL la decide el documento; el CAJÓN, quién opera.** `sp_registrar_cobro`,
`sp_registrar_sena` y `sp_pagar_compra` reciben la caja; con varias abiertas **la pantalla
pregunta** (`Caja::abiertasDe()`, `FacturacionController::cajaElegida()` valida contra las
sucursales de esa persona, `facturacion/_caja_elegir` en el cobro, la seña, el pago a
proveedores —cajas del local DE LA COMPRA— y la liquidación); con una sola no se pregunta pero
**se dice cuál es**. La red: el cajón de esta persona en el local del documento → cualquiera de
ese local → cualquiera suyo.

`exigeCaja($queIbaAHacer)` está en las acciones que sacan o meten plata **en efectivo o con
tarjeta** (`tocaElCajon`): cobrar, anular un cobro o un comprobante, la seña, el movimiento,
los pagos al personal y a proveedores y sus reversiones. **Lo bancario no lo llama** (desde la
7.121.0 sale de la cuenta) ni tampoco emitir una factura o una nota de crédito. Si agregás otra
acción que mueva dinero, llamala.

### El saldo de la caja es el EFECTIVO

```
monto_inicial + cobros EFECTIVO + otros_ingresos − egresos
             − pagos_proveedor EFECTIVO − pagos_personal EFECTIVO  = fn_caja_saldo
```

Tarjeta, transferencia y cheque se registran igual pero no tocan el cajón. `vw_caja_resumen`
expone las dos mitades (`cobros_efectivo` / `cobros_otros`, y lo mismo para pagos). Un egreso en
efectivo mayor al disponible se rechaza. **Si agregás otra salida de dinero**, tiene que
restarse en `fn_caja_saldo()` sólo cuando es efectivo, exponerse en `vw_caja_resumen` y validar
el disponible.

### El arqueo

`fn_caja_saldo` dice cuánto **debería** haber; al cerrar se escribe `caja.monto_contado` (un
hecho observado) y `id_usuario_cierre`; **la diferencia no se guarda** (`fn_caja_diferencia`) ni
su tipo (el signo). NULL no es cero («sin conteo»); menos de un guaraní es cuadrar; el motivo se
exige **sólo cuando no cuadra** (`motivo_diferencia`; también `observacion_apertura` y
`_cierre`). El modal muestra el desglose y dice qué no se cuenta. La clase «Faltante de caja»
de `movimiento_caja` ya baja el esperado: no cargar la misma plata por los dos lados. El
historial son dos registros (apertura y cierre) con Monto inicial, Esperado, Contado y
Diferencia en columnas propias. `sp_cerrar_caja` tiene su candado.

### Movimientos: las cuatro fuentes

`App\Servicios\Movimientos` (`partes()`, `delDia()`) une con UNION —no JOIN— cobros (entra),
`movimiento_caja` (según su clase), pagos a proveedores y liquidaciones (salen), del cajón **y
de las cuentas bancarias**; cada fila dice dónde pasó (**la cuenta manda sobre el cajón**). Sólo
se anula lo cargado a mano. El resumen por medio de pago va arriba, agrupado también por cajón.

### El movimiento manual: nada entra ni sale de la nada

`movimiento_caja` es su propio submódulo (`facturacion.movimientos`). Pregunta de dónde sale
(`destino` = `caja:ID` o `cuenta:ID`; `chk_mc_donde` exige uno solo). La clase
(`tipo_movimiento_caja`) decide el signo y el respaldo:

| Clase | Signo | Comprobante |
|---|---|---|
| Gasto con comprobante | sale | sí: número, RUC del emisor (módulo 11) y el papel |
| Retiro de la propietaria | sale | sí: ella factura al salón con su propio RUC |
| Faltante de caja | sale | no: es una diferencia (sólo del cajón) |
| Devolución al cliente | sale | no: la respalda la nota de crédito (sólo del cajón) |

Ninguna clase suma al cajón: lo único que entra son la apertura y los cobros. El concepto no
puede quedar vacío (`CHECK`); el movimiento guarda quién lo cargó; se anula con motivo y sólo
mientras la caja siga abierta (el de una cuenta, cuando sea).

### La cuenta bancaria es una CAJA dedicada al banco

`cuenta_bancaria` (**Tesorería → Cuenta bancaria**, `facturacion.cuentas`), una por local:
`cobro.id_cuenta`, `pago_proveedor.id_cuenta`, `pago_personal.id_cuenta` y
`movimiento_caja.id_cuenta` dicen a qué cuenta entró o de cuál salió; la cuenta viaja **por
línea** en el cobro (`cuenta[]`; `sgpAcomodarDonde()` en `app.js` muestra el combo de caja sólo
con efectivo y el de cuenta por línea bancaria). Los procedimientos no cambian de firma: la
cuenta se anota después con `Facturacion::anotarCuenta()`, y el pago a proveedor por banco se
deja con `id_caja = NULL`. **La tarjeta no suma a ninguna cuenta** (el posnet acredita días
después): sigue en la caja donde se pasó.

**La cuenta se ARQUEA como la caja, con historial** (`arqueo_cuenta`: fecha `NOW()` de la base,
contado, quién, motivo de la diferencia); `fn_cuenta_saldo` = último arqueo + `fn_cuenta_movido`
desde entonces; `fn_arqueo_cuenta_esperado` y `_diferencia` se calculan; el primer arqueo no
tiene esperado; **NULL no es cero** (sin arqueo el sistema se calla y la campanita pide el
primero). Es una cuenta del banco: puede haber más de lo que dice, así que **el control de los
pagos avisa y no bloquea**. Un arqueo no se borra. `Cuenta::paraSenas()`, `Cuenta::deSucursal()`,
`Cuenta::arquear()`. El titular va como texto (es lo que figura en el banco).

## Fidelización

- **El nivel** lo calcula `fn_cliente_nivel` por visitas; desde cuántas empieza cada uno y con
  qué descuento se administran en Servicios → Promociones (el nombre no se toca: lo nombran los
  comprobantes; dos niveles no pueden arrancar en el mismo número; se dan de baja, no se borran).
- **Los puntos** los acumula `Facturacion::acumularPuntos()` (`sp_registrar_puntos`, 1 punto
  cada `Config::puntosCadaGs()`, que vive en `configuracion.puntos_cada_gs` — tabla de UNA fila
  con columnas tipadas, `chk_config_unica`, `chk_config_puntos`); al anular se registra el
  movimiento contrario. Lo acumulado no se recalcula.
- **Canje** (`servicio_canjeable` el catálogo, `canje` el hecho; el estado se deduce con
  `fn_canje_estado`; los puntos y el vencimiento se guardan porque son lo acordado ese día):
  desde el portal y desde Clientes → Visitas y puntos (`clientes.fidelizacion`, `sp_canjear_servicio`),
  el catálogo en Clientes → Canjes (`clientes.canjes`). Al agendar el canje **acompaña** al
  servicio (mismo tiempo y profesional) y va **a cero en el comprobante**; `Canje::aplicarACita()`
  comprueba contra la clienta y los servicios reales de la cita (un canje sin su servicio no se
  gasta). Cancelar devuelve el canje, no los puntos. Los canjes valen en cualquier sede.
- **«Visitas y puntos» es de Clientes**; los parámetros son de Promociones.

## El portal de la clienta

- Barra propia con las mismas piezas; inicio con la forma del panel; «Mis citas» pagina las
  próximas y las anteriores con parámetros propios (`pp`, `ph`) que se arrastran entre sí;
  muestra profesionales (todos los de la cita, con `COALESCE` sobre `cita_servicio.id_usuario`),
  estado (**En proceso y Atrasada siguen en próximas**; una Programada que ya terminó es pasada),
  alergias, comprobantes, seña, y reprogramar o cancelar (`Portal → Mis citas`).
- **Durante la atención** (`portal/atencion`): se refresca cada 20 s contra `portal/atencion_json`
  (no en segundo plano), muestra servicios, quién, productos, total y seña; «Pedir algo más»
  (`cita_pedido`) es texto libre que confirma el profesional en persona; sólo con la cita En
  proceso. El total es lo cargado, no un comprobante.
- **Mi ficha / Mi cuenta**: las alergias con un solo partial (`portal/_alergias`) y un solo POST
  (`volver` dice a dónde). **Las alergias son de CADA persona de la cita**: la titular en
  `cliente.alergias`, la persona para quien es en `cita.alergias_para`, cada acompañante en
  `cita_acompanante.alergias` (`App\Servicios\Alergias`, sin consultas: compone lo que la pantalla
  trajo). Se cargan al agendar en las dos pantallas; el campo de la titular viene con lo cargado
  y `Alergias::guardarDelTitular()` sólo escribe si cambió (`alergias_titular_base`); NULL es
  «sin registrar», no «ninguna». En la fila de la agenda un badge rojo por persona (con dos o
  más, «⚠ N con alergias» que abre el detalle); en el detalle todas, incluidas las que no
  declararon.
- El portal elige el local al agendar y comprueba que el profesional atienda ahí.

## Informes

**Reportes son siete pantallas** —Resumen, Citas, Servicios, Profesionales, Ingresos, Compras,
Por sucursal— más **Todos** (los partials `reportes/_*` uno abajo del otro, con «ver aparte»);
las pestañas son enlaces `?r=` y la sección viaja escondida en el formulario de filtros. Las
ocho cifras van sólo en Resumen y en Todos.

- **Los filtros llegan a TODAS las consultas** (`ReglasDeNegocioTest::el_informe_no_mezcla_sucursales_ni_ofrece_las_ajenas`
  suma las partes contra el total). El selector ofrece sólo las sucursales de esa persona; con
  una, el filtro se pone solo.
- **Un número sin su denominador informa mal**: entra Pendientes (de `bloquea_agenda`); la
  asistencia se mide sobre las citas que ya ocurrieron; «% de los servicios» y no del total de
  citas; «Faltó» es del profesional (fichaje) y «No vino la clienta» de la cita; «Facturado» no
  es cobrado; «sin cargar» cuando no hay comisión. La demanda va por hora y por día
  (`WEEKDAY()+1`, y con `ONLY_FULL_GROUP_BY` el `GROUP BY` repite la misma expresión).
- **Gráficos sin librería** (`.sgp-graf-pista`/`.sgp-graf-barra`, un `width` en %); sin datos
  se dice (`reportes._sindatos`).
- **Excel** (`.xls` = HTML con tipo de Excel, celdas numéricas crudas, las barras con celdas de
  color, la columna del gráfico dice qué mide) y **PDF / Imprimir** (Dompdf; casillas de qué
  bloques salen, `ReportesController::BLOQUES`; todas marcadas de arranque; sin ninguna se
  imprime todo). Los tres salen de `datos()`. El período tiene el atajo Histórico; la deuda con
  proveedores no depende del período.
- Las cajas se ven en `imprimir.css` (`.sgp-imprimir`): tablas con cabecera repetida, el acento
  pasado a negro.
- El historial de la clienta pagina y trae su perfil por cita (visitas, servicios, facturado,
  cada cuántos días, qué pide, qué días, con quién), sin respetar los filtros de la tabla.

## La hora

**Usá `ahora_bd()`, no `date()`**, para sellar lo que se le muestra a una persona (el fichaje):
la tzdata de PHP se desactualiza sin avisar. `ahora_bd()` guarda la hora **una vez por proceso**:
en un proceso largo (el planificador, la batería de pruebas), cuando la fecha parte la historia
en antes y después, va `NOW()` en el SQL (`arqueo_cuenta.fecha`). La base tiene que tener bien
la hora: Docker le pasa `--default-time-zone=-03:00` (la tzdata de MariaDB 10.4 también es
vieja); en un VPS, `timedatectl set-timezone America/Asuncion` y comprobar con `SELECT NOW()`.
`sgp:diagnostico` mide `TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())` contra −10800 y muestra
`@@time_zone`, no `@@system_time_zone`. El `.ics` resuelve lo suyo con hora flotante: son dos
soluciones que conviven.
## Entorno

**Se trabaja sobre Docker, y sólo sobre Docker** (XAMPP salió en la 7.85.1; su Apache trae PHP
8.2 y Laravel 13 pide 8.3). Todo comando de `artisan` va con `docker compose exec app`, y
`mysql`/`mysqldump` con `docker compose exec bd`. `docker compose up` fija **MariaDB 10.4** —las
93 `CHECK` y las 73 rutinas están escritas para ese motor—, importa las dos bases solo y clava
la zona horaria. La base se publica en el **3307**; el `.env` del host apunta ahí.

- URL en desarrollo: `http://localhost:8000` · `admin` / `admin123` · `cliente` / `cliente123`.
- **Hay un segundo compose, el del servidor: `docker-compose.produccion.yml`** — ver abajo. Si
  lo levantás acá, después `docker compose exec app php artisan optimize:clear`:
  `bootstrap/cache` vive en el bind mount y su `optimize --no-dev` deja al contenedor de
  desarrollo sin PHPUnit.

### Los archivos de entorno

Laravel lee un solo `.env`, así que lo compartido se repite:

| Archivo | Qué es | ¿Se versiona? |
|---|---|---|
| `.env` | el real de esta computadora | no |
| `.env.example` | plantilla para desarrollar | sí |
| `docker/php/env.docker` | el `.env` de adentro del contenedor, montado encima | sí |
| `docker/php/secretos.env` | contraseña de la base, `APP_KEY`, `SIFEN_TOKEN` | **sí desde la 7.87.0**: el panel de Hostinger clona el repositorio en un temporal nuevo en cada despliegue. El precio: quedan en el historial de git y hay que **rotarlas** |
| `docker/php/env.produccion` | el `.env` del contenedor del servidor | sí |
| `.env.produccion.example` | plantilla del servidor | sí |

- `secretos.env` va como `env_file` con `required: false`; Laravel lo respeta porque Dotenv es
  inmutable. **La cuenta de correo NO va ahí** (`MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS` vacíos): se carga en Seguridad → Correo del sistema. `AndamiajeTest` no deja
  que vuelvan a llenarse.
- **Las tres plantillas listan las mismas 44 claves**, aunque el valor cambie:
  `for f in .env.example docker/php/env.docker .env.produccion.example; do grep -o '^[A-Z_][A-Z0-9_]*=' "$f" | sed 's/=$//' | sort -u; done`
- **`env.docker` existe montado**: sin él Docker crea una carpeta. Y **`artisan serve` sólo le
  reenvía al servidor web una lista blanca de variables**: por eso el contenedor tiene su `.env`
  y no variables sueltas. `MAIL_FROM_NAME="${APP_NAME}"` dentro de un `env_file` lo expande
  Compose y queda vacío.
- **Cambiar de base es una línea de `env.docker`**: `DB_DATABASE=peluqueria_test` (la copia
  cargada, la que viene puesta) o `peluqueria_bd` (instalación desde cero). Los nombres son esos
  dos: `peluqueria_bd_test` no existe, y la pantalla de ingreso contesta 200 igual hasta apretar
  Ingresar. `docker compose restart app` alcanza. `phpunit.xml` fija `peluqueria_test` aparte.

### El servidor de producción: un VPS de Hostinger con Docker

`https://sgp.columbiatcc.online`, VPS compartido con otros grupos, detrás de **Traefik** (la
plantilla del panel, en modo host: sin red compartida ni puerto publicado). Los pasos están en
`DESPLIEGUE.md` y `ACTUALIZAR.md`.

| | `docker-compose.yml` | `docker-compose.produccion.yml` |
|---|---|---|
| Sirve con | `artisan serve`, una petición por vez | php-fpm detrás de **Caddy** (traduce HTTP→FastCGI, sirve estáticos, `Cache-Control: immutable` en `/assets`) |
| La base | publicada en el 3307 | **sin ningún puerto** |
| Contraseñas | `root`/`root` en el archivo | de `secretos.env` |
| HTTPS | no | Traefik (TLS), con `trusted_proxies` en Caddy **y** `trustProxies` en `bootstrap/app.php`: sin las dos, los correos salen con `http://` |
| Planificador | no | servicio `cron` |
| OPcache | no | `validate_timestamps=0`: **después de subir código hay que recrear el contenedor** (`up -d --build`) |

- **Sin montajes de host**: el código, `vendor/`, los `.sql`, el `Caddyfile` y el Automatizador
  viajan dentro de las imágenes; lo que persiste va en volúmenes con nombre (`datos_bd`,
  `almacenamiento`, `imagenes_*`, `sifen_*`). Un volumen con ruta que no existe **Docker lo crea
  como carpeta, en silencio**.
- **La base se importa sola en el arranque de la aplicación** (no de MariaDB): sólo si tiene
  menos de 10 tablas, y comprobando antes que conteste — «no pude preguntar» no es «no hay
  nada». El cliente `mysql` de la imagen exige TLS: `--skip-ssl`.
- Se conecta como root a propósito (un usuario limitado da error 1449 por los `DEFINER`); lo que
  protege es que no haya puerto. Con el binlog, `log_bin_trust_function_creators = 1`, o las
  funciones no se crean y el import termina «bien».
- **`APP_URL` con el subdominio real** (de ahí salen los enlaces de los correos),
  `APP_DEBUG=false`, `APP_KEY` generada una vez con `openssl rand -base64 32`.
- **El dominio va ESCRITO en la etiqueta de Traefik**, no interpolado (Compose no lee el
  `env_file`). Cambiar el subdominio son dos lugares: la etiqueta y `APP_URL`.
- **El respaldo se agenda en el host** (`docker/respaldo.sh` copiado a `/usr/local/bin`, habla
  con `sgp_bd` por nombre): el volumen no es un respaldo. `/var/respaldos/sgp/`. La restauración
  está en `ACTUALIZAR.md`: respaldar lo de ahora primero, y volver a aplicar los guiones de
  `basededatos/actualizaciones/` posteriores al respaldo.
- WebAuthn necesita HTTPS y el dominio como `rpId`: no se prueba por IP en la red local, y el
  dominio del diálogo del sistema operativo no se puede cambiar (`rp.name` y `displayName` sí,
  salen de `Config::nombreSalon()`).
- El correo saliente por el 587 hay que confirmarlo con el proveedor.

### `peluqueria_bd` es la base que se entrega: esquema al día, sin datos

Queda: los catálogos del sistema, el catálogo demo (15 servicios, 10 productos, 3 proveedores,
3 timbrados, 4 profesionales con turno), la sucursal 1 y las cuentas `admin` y `cliente`. Se
borra: toda la operación, y cualquier persona real. Son **dos guiones y hacen falta los dos**:

```bash
mysql -u root peluqueria_bd < basededatos/dejar_lista.sql
mysql -u root peluqueria_bd < basededatos/datos_demo.sql
```

`dejar_lista.sql` trunca la operación (incluidas `cita_acompanante` y las fotos de perfil),
devuelve la marca de fábrica, baja a un local y saca a toda persona que no sea `admin` ni
`cliente`; `datos_demo.sql` vuelve a poner el catálogo, **por nombre y re-ejecutable**
(`producto` y `turno_laboral` no tienen único por nombre). **Si tocás `datos_demo.sql`,
corrélo.** Antes de commitear:

```bash
grep -c "INSERT INTO \`cita\`" "basededatos/peluqueria_bd(base).sql"   # tiene que dar 0
grep -oE "[a-z0-9._%-]+@(gmail|hotmail|outlook|yahoo)\.[a-z.]+" "basededatos/peluqueria_bd(base).sql"
```

### Solo hay DOS archivos `.sql`

| Archivo | Qué es |
|---|---|
| `basededatos/peluqueria_bd(base).sql` | la base **que se entrega**: esquema completo y lo mínimo para entrar. **Siempre al día**: se regenera en la misma tanda que cualquier cambio de tabla, columna, `CHECK`, vista, función, procedimiento o disparador |
| `basededatos/1mes_simulacion.sql` | la copia cargada (184 citas, 67 facturas, 33 clientas): carga `peluqueria_test` para probar con datos |

```bash
docker compose exec -T bd sh -c "mysqldump -uroot -proot --routines --triggers --events --single-transaction --default-character-set=utf8mb4 peluqueria_bd > /tmp/base.sql"
docker compose cp bd:/tmp/base.sql "basededatos/peluqueria_bd(base).sql"
```

- Siempre `mysqldump`, nunca el export de phpMyAdmin (perdía las `CHECK`) ni una tubería de
  PowerShell (BOM y acentos rotos). Sin `CREATE DATABASE` ni `USE`, así se cargan en otra base.
- **Las dos bases tienen que tener las MISMAS tablas** —lo que cambia son los datos—:
  `for f in "basededatos/peluqueria_bd(base).sql" basededatos/1mes_simulacion.sql; do grep -o 'CREATE TABLE \`[a-z_]*\`' "$f" | sort > "/tmp/$(basename "$f").tablas"; done; diff /tmp/*.tablas`
- Para probar sin tocar la real: `DROP DATABASE IF EXISTS peluqueria_test; CREATE DATABASE …
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` y `mysql … --default-character-set=utf8mb4
  peluqueria_test < /sql/1mes_simulacion.sql` (`basededatos/` está montado en `/sql`).
- No importar con `--skip-grant-tables`: las vistas quedan con DEFINER vacío (error 1449).

### Lo que no se sube al servidor

`basededatos/`, `CLAUDE.md`, `Referencias/`, `tests/`, `docker/` no hacen falta bajo la raíz
pública. La defensa de fondo: **la carpeta pública apunta a `public/`, nunca a la raíz** —
`sgp:diagnostico --produccion` lo comprueba.

### Cruzar dos bases: agregar sin pisar

Cargar el mes simulado **encima** de una base con datos no es correr el `.sql`: las dos usan los
mismos ids para cosas distintas. Cada tabla entra con id nuevo y un mapa viejo→nuevo; lo que no
se duplica se resuelve por su nombre natural (servicio y producto por nombre, usuario por
username, persona por cédula/RUC/correo, cliente por su persona o su cuenta); los correlativos se
desplazan después del último usado de cada timbrado; la caja simulada entra **cerrada**; las
claves propias no se copian; **los disparadores se apagan durante la carga y se recrean al
terminar, incluso si falla**; todo en una transacción con respaldo previo; y después se cuentan
huérfanos, correlativos, cajas abiertas y stock negativo.

## Cambiar el esquema de la base

No se usan migraciones. El circuito: aplicar el cambio en la base con SQL → probar contra
`peluqueria_test` y `php artisan test` → **regenerar `peluqueria_bd(base).sql` en la misma
tanda** → `sgp:diagnostico` (22 procedimientos, 51 funciones, 17 triggers, 18 vistas, 93
`CHECK`, y que la base coincida con el `.sql`: si falta una columna, dice cuál y qué comando
correr) → 3FN → el guion en `basededatos/actualizaciones/` (re-ejecutable, cada paso mira
`information_schema`, sin tocar datos; una rutina se reemplaza con `DROP … IF EXISTS` +
`CREATE`).

- **El orden al reestructurar**: clave foránea → índice → columna. Un índice único que sostiene
  una FK se reemplaza creando el nuevo **antes**, y tiene que empezar por la misma columna.
- Un `ADD COLUMN … DEFAULT CURRENT_TIMESTAMP` rellena las filas existentes con la hora del
  ALTER: la columna entra sin defecto, se rellena, y recién al final se le pone el defecto.
- `docker/bd/` **no sirve** para cambios de esquema: MariaDB corre lo que haya ahí una sola vez.
- Los ayudantes de `Tests\TestCase` (`citaFuturaAgendada`, `cajonDe`, `otraSucursal`,
  `conSucursal`, `entrarComo`, `clienteLibreHoy`) existen para garantizar premisas.

## Los nueve errores que este proyecto se hace a sí mismo

Casi todo lo que se rompió es la misma falla: algo se renombró o se movió, lo que apuntaba a
eso quedó apuntando al vacío, y **nada dio error**.

| Patrón | Cómo se ve | Qué lo detiene hoy |
|---|---|---|
| Una clave de permiso renombrada | el rol pierde la pantalla en silencio | `AndamiajeTest::toda_clave_de_permiso_que_se_pide_existe` y `…toda_clave_guardada_en_rol_modulo_sigue_significando_algo` |
| Código apuntando a un marcado que no existe | el CSS no aplica, el JS no ocurre | `…lo_que_busca_el_javascript_existe_en_el_marcado` y `…las_clases_propias_del_css_se_usan_en_alguna_vista` |
| Una vista leyendo una variable que dejó de existir | sale el valor de ejemplo, no el de la base | nada: Blade no avisa. Al mover un formulario, buscá la variable que leía |
| Una pantalla que se llega por `?id=` y escapa al filtro de la lista | se ve lo de otro local | el banco `_qa/` y `deOtroLocal()`. Filtrala por sucursal a mano |
| Un botón que cambia el dato por el que la lista filtra | la fila desaparece y no vuelve | que el filtro sea una columna |
| Una regla de la base replicada en PHP que se desincroniza | la pantalla ofrece lo que el servidor rechaza | `CimientosTest::el_espejo_de_php_dice_lo_mismo_que_la_base` |
| Una pantalla anunciada en un lado y no en el otro | sale en el menú y no en la tarjeta, o al revés | `AndamiajeTest::el_landing_de_cada_modulo_ofrece_todas_sus_pantallas`, en las dos direcciones. Al escribir una prueba así, mirá sólo el bloque de tarjetas |
| Una tabla que se muda y deja rutinas de la base apuntando al vacío | error 1356 en cualquier pantalla | buscarla en `information_schema.routines` y `.views` antes de mudarla |
| Un bloque que queda anidado dentro de un condicional ajeno | la pantalla se dibuja entera, sin esa parte | al mover un bloque de una vista, mirá qué `@if` lo envuelve: la indentación no lo dice |

Y dos más de la misma familia: **el mismo código se ve distinto según los datos**
(`AccesoTest::las_pantallas_andan_con_una_sucursal_y_con_varias` abre todo con un local, con dos
y parada en el recién abierto), y **una prueba que devuelve 200 no prueba que la pantalla ande**
(Caja contestaba 200 sin su formulario: comprobá que la acción esté).

## Las pruebas

**240 pruebas** contra `peluqueria_test`. No prueban PHP: prueban que las reglas de la base se
sigan cumpliendo.

| Archivo | Qué cuida |
|---|---|
| `ReglasDeNegocioTest` | las reglas: horarios, duraciones, caja, correlativos, seña, stock, permisos, y una prueba por cada corrección que se reportó |
| `AccesoTest` | abre las pantallas de Seguridad y de la operación diaria: una columna mal escrita revienta al dibujar, no al arrancar |
| `ConcurrenciaAgendaTest` | 5 procesos contra el mismo hueco: queda una sola cita |
| `ConcurrenciaCobroTest` | los otros candados con procesos de verdad: 3 cobros de la misma factura, 3 aperturas de caja, 3 salidas del mismo stock, cancelar contra reprogramar |
| `AndamiajeTest` | que las piezas sigan enganchadas: permisos, rutas, menús, CSS/JS contra el marcado, la identidad, los tres candados del remitente único |
| `CimientosTest` | el espejo de PHP contra la base, y la hora |
| `HuellaTest` | la pantalla de la huella con su JavaScript y sin él |

- **Nunca `RefreshDatabase`**. Las que escriben usan `DatabaseTransactions`; `ConcurrenciaAgendaTest`
  limpia en `tearDown()`, con `tests/reservar_en_paralelo.php` como proceso hijo.
- **Si `peluqueria_test` tiene otros datos —hoy, el respaldo del servidor—, la batería se corre
  contra una copia del mes simulado**:
  ```bash
  docker compose exec -T bd sh -c "mysql -uroot -proot -e 'DROP DATABASE IF EXISTS peluqueria_sim; CREATE DATABASE peluqueria_sim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' && mysql -uroot -proot --default-character-set=utf8mb4 peluqueria_sim < /sql/1mes_simulacion.sql"
  docker compose exec -T -e DB_DATABASE=peluqueria_sim app php artisan test
  ```
  Sobre datos reales fallan las que dependen de la forma del mes simulado; lo que hay que mirar
  ahí es si alguna falla por un error, no por una premisa.
- **Una prueba tiene que GARANTIZAR su propia premisa, no esperar a encontrarla**: la cita que
  necesita la crea (`citaFuturaAgendada()`), la clienta libre la busca (`clienteLibreHoy()`), el
  segundo local lo crea (`otraSucursal()`, con un turno de otra persona para que sea
  restrictivo). Si una prueba se pone roja y el sistema no cambió, mirá primero su premisa.
- **Una prueba de concurrencia, y toda prueba de una corrección, se comprueba en las DOS
  direcciones**: con el arreglo puesto pasa, con el arreglo sacado a propósito falla.
- **Una cita sin filas en `cita_servicio` dura cero minutos** y no se pisa con nada.
- Lo que restaura el estado va en `tearDown`, nunca después de un `assert`.
- **Las cachés estáticas se comparten entre pruebas del mismo proceso**: `Caja::olvidar()`,
  `Permisos::olvidar()`, y `ahora_bd()` (por eso la prueba del reloj le pregunta a la base).
- **Si sumás una pantalla, agregala a la lista de `AccesoTest`**. El contenedor no trae GD: una
  imagen de prueba es un PNG de 1×1 escrito a mano. `Bd::idDe` levanta `PDOException`, no
  `QueryException`.

## Fuera de alcance del TCC

Pasarelas de pago, app móvil nativa, **notas de débito**, **la venta de productos** (el modelo
la tiene lista —`producto.precio_venta`, `detalle_factura.id_producto`, el movimiento 7,
`trg_detfactura_ai`— y no la usa nadie; se dejan para no bajar los conteos del TCC) y
**SMS / WhatsApp** (en pausa: se encienden con credenciales de un proveedor; mientras tanto el
correo es obligatorio en el registro). Tres cosas excedieron el alcance declarado y conviene
justificarlas en el documento: multisucursal, facturación y la integración con SIFEN.
