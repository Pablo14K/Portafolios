# Sistema de Gestión para Peluquería

> Sistema web de gestión integral para una peluquería de Luque (Paraguay): agenda,
> clientes, inventario, caja y portal de autogestión para la clienta. Multisucursal,
> en producción sobre un VPS.

| | |
| --- | --- |
| **Rol** | Desarrollo full-stack — trabajo en pareja con Noelia Belén Villalba Marín |
| **Periodo** | 2026 — en desarrollo |
| **Tipo** | Trabajo de Conclusión de Carrera — Ingeniería en Informática (Universidad Columbia del Paraguay) |
| **Stack** | Laravel 13 · PHP 8.3 · MariaDB 10.4 · Bootstrap 5 · Docker · Caddy · WebAuthn |
| **Versión** | 7.127.2 (16/09/2026) |
| **Volumen** | ~31.000 líneas de PHP · ~21.000 de Blade · ~14.000 de pruebas · 85 tablas · 22 procedimientos · 51 funciones · 17 triggers · 18 vistas · 93 `CHECK` |

> El proyecto se llama **SGP**. La grafía `SPG` sobrevive en el nombre del repositorio
> y en esta carpeta del portafolio; internamente el código y la documentación usan SGP.

## Contexto

Una peluquería de Luque gestionaba turnos, clientes y stock en papel y planillas
sueltas. No se sabía qué profesional estaba libre, qué productos faltaban ni cuánto
se había facturado en el día, y no había historial consultable por clienta.

El encargo del TCC fijaba la pila —PHP, MySQL, HTML5, CSS y Bootstrap— y el sistema
empezó en PHP sin framework, con un MVC escrito a mano. En la **versión 6.0.0 se
migró a Laravel 13**: la arquitectura cambió, las reglas no. La lógica de negocio
siguió viviendo donde estaba desde el principio, en la base de datos.

Tres cosas excedieron el alcance declarado y se justificaron aparte en el documento:
**multisucursal**, el **módulo de comprobantes** y su **integración con SIFEN**.

## Qué construí

Nueve módulos, 221 rutas declaradas una por una y 33 permisos con jerarquía de dos
niveles:

| Módulo | Qué resuelve |
| --- | --- |
| **Citas** | agenda, atención, ausencias; reparto entre varios profesionales |
| **Clientes** | registro, fidelización por niveles, canjes por puntos, valoraciones |
| **Servicios** | catálogo, categorías, zonas del cuerpo, promociones |
| **Inventario** | productos, stock por local, compras a crédito, proveedores |
| **Tesorería** | cobros, caja, arqueos, cuentas bancarias y emisión de comprobantes |
| **Reportes** | siete informes con exportación a Excel y PDF |
| **Seguridad** | usuarios, roles editables, auditoría |
| **Personal** | profesionales, turnos, asistencia por fichaje, comisiones |
| **Configuración** | ajustes de identidad visual, sucursales, canales de contacto |

Más el **portal de la clienta**: reserva, reprogramación, historial, puntos,
comprobantes descargables y seguimiento en vivo durante la atención.

## Decisiones técnicas

### La lógica de negocio vive en la base de datos, no en Eloquent

Es la decisión estructural del proyecto y la que más lo diferencia de un Laravel
convencional. **Eloquent no se usa para el negocio**: el esquema tiene una sola
fuente —un `.sql` versionado, sin migraciones— y PHP consume las rutinas del motor
a través de una capa propia, `App\Servicios\Bd`.

- **51 funciones** para todo lo derivado, que por definición no se guarda:
  `fn_producto_stock`, `fn_factura_total`, `fn_caja_saldo`, `fn_cuenta_saldo`,
  `fn_cliente_nivel`, `fn_verificar_disponibilidad`, `fn_cita_duracion_de`…
- **22 procedimientos** para lo transaccional: `sp_agendar_cita`,
  `sp_emitir_factura`, `sp_registrar_cobro`, `sp_emitir_nota_credito`,
  `sp_confirmar_compra`, `sp_cerrar_caja`…
- **17 triggers** que hacen cumplir reglas por su cuenta: `trg_movinv_bi` bloquea una
  salida sin stock, `trg_factura_bi` valida el timbrado, `trg_produtil_ai` descuenta
  al registrar un consumo.
- **93 restricciones `CHECK`** y 3FN estricta: ni valores no atómicos, ni datos
  repetidos entre tablas, ni columnas derivadas guardadas.

La integridad queda garantizada en un único punto: la aplicación, un script o el
propio gestor obtienen el mismo resultado. `App\Servicios\Bd` resuelve los parámetros
de salida, cierra el cursor después de cada `CALL` —sin eso la consulta siguiente
falla con *unbuffered queries*— y traduce la `QueryException` al mensaje que ve el
usuario.

### La agenda: un espejo en PHP, con la base como única autoridad

Calcular los huecos preguntándole a la base hora por hora tardaba **38 segundos**.
`Agenda::slotsProfesional()` trae turnos, citas y ausencias en tres consultas y arma
los huecos en memoria: **0,11 s**. Pero la pantalla no decide nada — al guardar
vuelve a mandar `fn_verificar_disponibilidad`.

Un espejo que se desincroniza ofrece lo que el servidor después rechaza, así que hay
una prueba que compara los dos caminos hueco por hueco
(`CimientosTest::el_espejo_de_php_dice_lo_mismo_que_la_base`).

Sobre eso se apoyan las reglas difíciles:

- **Una cita, varios profesionales y varias personas.** La hora ofrecida es la
  intersección de las agendas de quienes atienden, cada uno en su propio tramo. Qué
  se puede hacer a la vez lo decide la **zona del cuerpo**: misma zona se turna y
  suma, zonas distintas conviven.
- **Cada profesional cierra su parte** (`cita_servicio.terminado_en`), y la cita se
  libera en la agenda a medida que se cierra, no al final.
- **Cuando no hay hora, se dice cuál es la variable que no cierra**: quién no tiene
  lugar, quién no coincide, o qué servicio no entra ese día.

### Concurrencia real, probada con procesos de verdad

Dos personas pidiendo el mismo hueco es el caso que rompe una agenda. Los
procedimientos toman un candado (`SELECT … FOR UPDATE`) sobre el profesional antes de
consultar la disponibilidad, y todos los caminos pasan por una transacción — el
candado fuera de una transacción no vale nada. Un índice único `(id_usuario,
fecha_hora)` no servía: hay canceladas y solapes parciales.

`ConcurrenciaAgendaTest` lanza **5 procesos en paralelo** contra el mismo hueco y
exige que quede una sola cita. `ConcurrenciaCobroTest` hace lo mismo con 3 cobros de
la misma factura, 3 aperturas de la misma caja y 3 salidas del mismo stock.

### Integración con SIFEN, en dos pasos desacoplados

El módulo de comprobantes numera el documento según el Manual Técnico v150 de la DNIT
—ocho dígitos de autorización, tres de establecimiento, tres de punto de expedición y
siete de correlativo— y se lo pasa al
[Automatizador SIFEN](../sifen-automatizador) por HTTP.
**Emitir y declarar son dos pasos**: el documento es válido al emitirse, y el envío
sale seguido pero no atado; si falla queda `PENDIENTE` y se reintenta.

- El **timbrado es por tipo de comprobante y por sucursal**, y cae al de otra sede si
  el local no tiene el suyo: dejar de emitir sería peor, y la pantalla lo dice.
- Los datos del receptor se piden **antes** de emitir, porque un rechazo no se
  reintenta: el número ya se gastó. El DV del RUC se valida por módulo 11 con pesos
  2..11.
- **Anular no es borrar**: la numeración no admite huecos, así que se marca el estado
  y siempre con motivo en auditoría.
- El **descuento viaja dentro del precio de cada renglón**, prorrateado, con la última
  línea absorbiendo el redondeo, y el precio de lista como campo opcional — un
  Automatizador viejo lo ignora y declara el mismo total.

### Multisucursal decidido módulo por módulo

La regla de fondo: **un aislado no arrastra nada a otra sede**. Un empleado no lleva
su horario de un local al otro. Qué se aísla y qué se comparte se decidió uno por uno
—las citas se aíslan, los clientes se comparten; el stock se aísla, los proveedores
se comparten; el vale de canje vale en cualquier sede porque los puntos son del
salón— y el solape de citas **no** se filtra por sucursal, porque la persona es una
sola.

### Login biométrico con WebAuthn, escrito a mano

El proyecto arrancó sin gestores de dependencias, así que el soporte de huella
(Windows Hello, Touch ID, huella de Android) está en PHP puro y sigue estándolo:
decodificador **CBOR** propio, extracción de la clave pública **COSE** (ES256/RS256)
y conversión a PEM construyendo las estructuras **ASN.1/DER**, y verificación de la
firma con OpenSSL validando origen y RP ID.

### La identidad visual se deriva de un color

El salón elige **un** color en Configuración → Ajustes, y `App\Servicios\Tema`
deriva los 42 tokens de la paleta: de cada uno toma tono y saturación, y cada token
tiene su luminosidad de destino y su tope de saturación. La polaridad del texto sobre
el acento sale de medir contraste, no de una constante, y el contorno de los campos
tiene su propia regla más oscura — el peor caso entre las dieciocho paletas es
**3,1:1**, por encima del mínimo de WCAG para controles.

El tema claro u oscuro lo sigue eligiendo cada persona, y se guardan las dos paletas.
Hay una prueba que comprueba que cualquier color que elija el salón siga siendo
legible.

### Lo que cambió mientras mirabas

Cada pantalla es una foto: con dos personas sobre la misma agenda, una atiende y la
otra la sigue viendo Programada. Las pantallas que lo necesitan declaran una sección
«vivo» y consultan cada 20 s una ruta que devuelve **una huella `md5`** de lo que la
pantalla mira —conteos, último id, suma de estados, lo cobrado, lo facturado— y nunca
datos. No recarga encima de algo escrito, no consulta en segundo plano y no cuenta
como actividad de sesión. Sin websocket, a propósito.

## Pruebas

**240 pruebas** contra una base de verdad, con el esquema del TCC. No prueban PHP:
prueban que las reglas de la base se sigan cumpliendo.

| Archivo | Qué cuida |
| --- | --- |
| `ReglasDeNegocioTest` | horarios, duraciones, caja, correlativos, seña, stock, permisos, y una prueba por cada corrección reportada |
| `AccesoTest` | abre todas las pantallas: una columna mal escrita revienta al dibujar, no al arrancar |
| `ConcurrenciaAgendaTest` · `ConcurrenciaCobroTest` | los candados, con procesos en paralelo de verdad |
| `AndamiajeTest` | que las piezas sigan enganchadas: permisos, rutas, menús, CSS y JS contra el marcado, la identidad visual |
| `CimientosTest` | el espejo de PHP contra la base, y la hora |
| `HuellaTest` | WebAuthn con JavaScript y sin él |

Nunca `RefreshDatabase`: borraría el esquema con todas sus rutinas. Las que escriben
usan `DatabaseTransactions`; las de concurrencia limpian a mano.

Además hay **bancos de simulación** —corridas de 30 y 60 días de operación con
procesos concurrentes— que quedan en el repositorio principal como evidencia del TCC,
con su informe de QA en `docs/`.

### El problema que este proyecto se hace a sí mismo

Casi todo lo que se rompió fue lo mismo: algo se renombró o se movió, lo que apuntaba
a eso quedó apuntando al vacío, y **nada dio error**. Una clave de permiso renombrada
hace que un rol pierda la pantalla en silencio; una tabla que se muda deja rutinas de
la base apuntando al vacío; una pantalla anunciada en el menú y no en la tarjeta. La
mayor parte de `AndamiajeTest` existe para eso: comprobar que las piezas sigan
enganchadas, en las dos direcciones.

## Despliegue

En producción sobre un **VPS de Hostinger con Docker**, detrás de Traefik:

| | Desarrollo | Producción |
| --- | --- | --- |
| Sirve con | `artisan serve` | **php-fpm detrás de Caddy** |
| La base | publicada en el 3307 | **sin ningún puerto** |
| HTTPS | no | Traefik, con `trusted_proxies` en Caddy y `trustProxies` en Laravel |
| Planificador | no | servicio `cron` |
| OPcache | no | `validate_timestamps=0` |

Sin montajes de host: el código, las dependencias, los `.sql` y el Automatizador
viajan dentro de las imágenes; lo que persiste va en volúmenes con nombre. La base se
importa sola en el arranque, sólo si tiene menos de diez tablas y comprobando antes
que conteste — «no pude preguntar» no es «no hay nada».

Hay un comando de diagnóstico, `sgp:diagnostico`, que compara lo que corre contra lo
que se entrega: cuenta rutinas, `CHECK` y columnas, y si falta una dice cuál y qué
comando correr.

## Estructura

Así está organizado el proyecto:

```
app/
  Ayudas/formato.php       funciones globales: money() num() ahora_bd() flash()…
  Servicios/               la capa propia — 36 clases, todo estático y sin estado
    Bd.php                 el puente a las rutinas de la base
    Agenda.php             huecos, reparto entre profesionales, agendar con candado
    Facturacion.php        emitir, cobrar, anular, nota de crédito, puntos
    Sifen.php              el TXT del comprobante para el Automatizador
    WebAuthn.php           huella en PHP puro (CBOR, COSE→PEM, OpenSSL)
    Tema.php               los 42 tokens derivados de un color
    Permisos.php           los 33 submódulos y su jerarquía
    …
  Http/Controllers/        uno por módulo
  Http/Middleware/         ExigeSesion · ExigePersonal · ExigeModulo · ExigeAdmin
  Console/Commands/        sgp:diagnostico · sgp:pendientes · sgp:notificaciones
config/                    sgp.php · navegacion.php · permisos.php · ayudas.php · sifen.php
resources/views/           134 plantillas Blade
routes/web.php             las 221 rutas, agrupadas por módulo con su middleware
public/assets/             app.css · imprimir.css · app.js · webauthn.js (sin build)
basededatos/
  peluqueria_bd(base).sql  el esquema: única fuente, sin migraciones
  actualizaciones/         los cambios de base que viajan al servidor, re-ejecutables
tests/Feature/             las 240 pruebas
docker/                    Dockerfile de desarrollo y de producción, Caddyfile, respaldo
```

## Ejecutar en local

Requiere Docker Desktop (en Windows, WSL2).

```bash
docker compose up
```

La primera vez baja las imágenes, instala las dependencias e importa las bases.
Después:

http://localhost:8000 · `admin` / `admin123` · `cliente` / `cliente123`

```bash
docker compose exec app php artisan test              # las 240 pruebas
docker compose exec app php artisan sgp:diagnostico   # la revisión del entorno
```

El proyecto mantiene además un historial completo de versiones —una fila por versión
con el porqué de cada cambio, qué estaba mal y qué se decidió no hacer—, la
documentación técnica, las guías de despliegue y actualización, y los bancos de
simulación con su informe de QA.

## Herramientas utilizadas

El TCC fijaba la pila en su sección «Herramientas a utilizar». Las adiciones están
justificadas en `docs/Herramientas_extras_utilizadas.docx`.

| Herramienta | Para qué | En el TCC |
| --- | --- | --- |
| **PHP 8.3** | Lógica de servidor | ✅ |
| **MySQL / MariaDB 10.4** | Base de datos; también la lógica de negocio | ✅ |
| **HTML5 · CSS · Bootstrap 5** | Interfaz | ✅ |
| **Laravel 13** | Framework — entró en la 6.0.0 | Extra, justificado |
| **Docker · Docker Compose** | Entorno reproducible; fija la versión del motor | Extra |
| **Caddy · Traefik** | Servidor y TLS en producción | Extra |
| **Dompdf** | PDF de comprobantes e informes | Extra |
| **WebAuthn / FIDO2** | Login biométrico, implementado a mano | Extra, opcional |
| **SIFEN (DNIT)** | Emisión de comprobantes declarados ante el Estado | Extra, justificado |
| **Claude Code · Codex · Antigravity** | Asistencia en desarrollo | — |

Sin Node.js y sin paso de compilación: Bootstrap viene por CDN y el CSS y el JS
propios se editan a mano.

## Estado

**En desarrollo, dentro del marco del TCC.** El sistema está desplegado y operando en
producción, con la batería de pruebas en verde y el diagnóstico sin observaciones.
Titulación prevista para principios de 2027.

## Pendiente de completar

- [ ] Capturas de las pantallas principales (agenda, caja, portal de la clienta)
- [ ] Resultado medible: tiempo de reserva antes y después, nº de citas gestionadas
- [ ] Repartir con claridad qué módulos hizo cada integrante de la pareja
