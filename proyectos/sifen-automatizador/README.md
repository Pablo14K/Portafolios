# SIFEN Automatizador

> **Versión reducida** de [SIFEN — Integración con la API del Estado](../sifen-integracion):
> el mismo motor de integración despojado a lo mínimo, sin interfaz ni base de datos.
> Un sistema externo deja un `.txt` en una carpeta y recoge el XML firmado y el PDF en
> otra.

| | |
| --- | --- |
| **Rol** | Desarrollo backend |
| **Periodo** | 2026 |
| **Stack** | PHP 8.1 · cron / watcher · Apache · cPanel · Docker |
| **Volumen** | ~4.300 líneas de PHP |
| **Origen** | Derivado del [sistema principal](../sifen-integracion), encargado en una pasantía en Vieloy Sistemas y conservado con autorización de la empresa |
| **Relación** | Versión reducida del [sistema completo](../sifen-integracion), del que reutiliza el motor de integración |
| **Integrado en** | [Sistema de Gestión para Peluquería](../sistema-gestion-peluqueria), que lo usa en producción para declarar sus comprobantes |

## Contexto

El sistema completo resuelve el problema para quien puede adoptarlo entero: su base
de datos, su interfaz, su flujo. Pero un negocio que ya tiene su propio software no
quiere cambiarlo — quiere que **eso que ya usa** pueda declarar sus documentos ante
SIFEN.

De ahí sale esta versión reducida: **el mismo motor de integración, despojado de todo
lo demás**. Sin interfaz web, sin base de datos, sin gestión de clientes ni de colas.
Solo la parte que convierte unos datos de entrada en un documento electrónico firmado
y aprobado.

Es un ejercicio de **diseño de integración**: cuánto se le puede exigir a quien
integra, y cuánto conviene absorber de este lado.

La integración es deliberadamente pobre en supuestos: el sistema externo solo tiene
que saber escribir un archivo de texto en una carpeta. No necesita hablar SOAP, ni
manejar certificados, ni conocer el formato XML de la DNIT, ni siquiera hacer una
llamada HTTP si no quiere.

## Qué construí

```
 Sistema del cliente          este automatizador                        DNIT
 ┌──────────────┐   .txt   ┌────────────────────────────────┐  XML firmado  ┌───────┐
 │  su software ├─────────►│ entrada/ → parse → XML → firma │──────────────►│ SIFEN │
 └──────────────┘          │          → QR → envío          │◄──────────────┤       │
                           │ salida/  ← XML + KuDE (PDF)    │   aprobado    └───────┘
                           │ errores/ ← motivo del rechazo  │
                           └────────────────────────────────┘
```

1. El sistema externo deposita un `.txt` en `entrada/`.
2. El automatizador lo parsea, arma el XML con la estructura exacta de la DNIT, lo
   firma, calcula el QR y lo envía.
3. Si se aprueba, deja el XML y el KuDE en PDF en `salida/`. Si falla, escribe el
   motivo en `errores/` y mueve el original a `procesados/`.

Deliberadamente **no reimplementa nada de la especificación**: reutiliza el motor del
sistema principal (CDC, XML v150, firma XMLDSig, QR y KuDE) montándolo como
dependencia de solo lectura. Así una corrección en las reglas de la DNIT se aplica en un único
sitio y ambos productos la heredan.

## Decisiones técnicas

### Un formato de entrada que cualquiera puede generar

El `.txt` usa registros por línea separados por `|`, agrupando un documento por
bloque. Es un formato que se escribe con un `printf` desde cualquier lenguaje, sin
librerías:

```text
EMI|razon_social|ruc|dv|direccion|ciudad|telefono|email|act_cod|act_desc|timbrado|desde|hasta|sucursal
FAC|establecimiento|punto|numero|fecha|condicion|moneda|tipo_transaccion
CLI|tipo|documento|nombre|email|direccion|telefono
ITM|codigo|descripcion|cantidad|precio_unitario|iva[|precio_lista]
PAG|tipo|monto
===
```

`TxtParser` lo valida campo a campo antes de construir nada, de modo que un archivo
mal formado se rechaza con un mensaje concreto en `errores/` en vez de producir un
XML inválido que la DNIT devolvería con un código críptico.

**Todo lo que se agregó es opcional, y esa es la regla del formato**: un `.txt`
escrito contra la versión anterior sigue significando exactamente lo mismo. Sin
`EMI|` se usan los datos del `.env`; sin `tipo_transaccion` vale 1 (venta de
mercadería), que era el valor fijo de antes; sin `precio_lista` el precio de lista es
el neto y el descuento del renglón es cero. Un integrador viejo no tiene que tocar
nada.

### El emisor puede venir en el archivo, porque no siempre es uno solo

`EMI|` existe porque el `.env` no puede expresar un emisor que cambia. Cuando quien
integra tiene **varias sucursales**, la dirección y el timbrado son los del local que
atendió, no los de la empresa: con un solo juego de valores en el archivo de
configuración, el KuDE salía a nombre del emisor de ejemplo. Si la línea viene, gana;
si no, se cae al `.env` como siempre.

### El descuento, declarado como lo modela el SIFEN

El campo 5 del `ITM` trae el **neto** —quien emite reparte su descuento entre los
renglones antes de mandar, porque el total lo suma este sistema— y el campo 7
opcional, el **precio de lista**. La diferencia es el descuento del renglón.

Hasta acá el precio de lista era «sólo para el KuDE» y el XML declaraba el neto como
precio unitario, sin descuento. Era válido, pero **el KuDE es la representación
gráfica del XML y decían cosas distintas**. Ahora los dos declaran precio, descuento
y total: E721 el precio de lista, EA002 el descuento particular, EA003 su porcentaje
y EA008 el neto por cantidad.

Al hacerlo salió a la luz un error que el descuento en cero tapaba: **E727
`dTotBruOpeItem` se estaba calculando desde el neto y restándole el descuento otra
vez**. Con descuento no se notaba en las pruebas porque no había descuentos; con
descuento real, el total del ítem salía descontado dos veces. E727 es el bruto
—precio de lista × cantidad— y EA008 el neto, y ahora cada uno sale de donde
corresponde.

### Dos modos de ejecución, según dónde se despliegue

- **Cron** (recomendado en hosting compartido): `bin/procesar.php` hace una pasada
  única sobre la carpeta y termina. Se programa cada minuto y no deja procesos
  vivos, que es lo que suelen permitir los cPanel.
  ```
  * * * * * /usr/bin/php /ruta/sifen_automatizador/bin/procesar.php >> /dev/null 2>&1
  ```
- **Watcher**: `bin/vigilar.php` se queda en bucle vigilando la carpeta con el
  intervalo que se le pase. Para servidores propios donde sí se puede tener un
  proceso permanente.

Ambos comparten el mismo `Procesador`, así que el comportamiento no cambia entre uno
y otro.

### Endpoint HTTP opcional, protegido por token

Para los sistemas que prefieren empujar el documento en lugar de escribir en disco,
`public/index.php` expone un endpoint que acepta el mismo formato `.txt` por HTTP,
autenticado con un token compartido. `public/descargar.php` permite recuperar
después el XML y el PDF resultantes.

### Quién manda el comprobante por correo se decide en cada petición

Con los dos sistemas mandando, la clienta recibe el comprobante **dos veces desde
direcciones distintas**, y cambiar la cuenta de correo en un lado arregla la mitad
del problema. Antes eso dependía de acordarse de dejar `MAIL_FROM_EMAIL` vacío en la
configuración; ahora quien emite lo dice en la petición con una cabecera
`X-SGP-Correo: no`, y sin ella el comportamiento es el de siempre.

Es una decisión de integración, no de configuración: la toma quien conoce el
contexto, no el archivo que alguien editó hace seis meses.

### Procesamiento idempotente

Cada archivo se mueve fuera de `entrada/` en cuanto se toma, de modo que dos
ejecuciones solapadas —un cron que se pisa con el anterior— no procesan el mismo
documento dos veces ni generan CDC duplicados.

## Estructura

```
bin/
  procesar.php      Corrida única, pensada para cron
  vigilar.php       Watcher en bucle
  arrancar.sh       Arranque y parada del watcher
  detener.sh
src/
  TxtParser.php     Parseo y validación del formato de entrada
  InvoiceFactory.php  Construcción del payload del documento
  Procesador.php    Orquestación del ciclo completo
  Logger.php        Registro de la actividad
motor/              Motor de integración reutilizado del sistema principal
public/
  index.php         Endpoint HTTP con token
  descargar.php     Descarga de XML y KuDE
docs/
  FORMATO_TXT.md    Especificación del formato de entrada
  DESPLIEGUE_CPANEL.md
entrada/ procesados/ salida/ errores/ logs/
```

## Modos SIFEN

Hereda los tres modos del sistema principal: `mock` (genera y aprueba localmente,
sin valor fiscal), `test` (homologación de la DNIT) y `prod` (producción). El modo
`mock` permite que un integrador pruebe su lado sin tener aún un certificado.

## En producción: integrado con el SGP

El [Sistema de Gestión para Peluquería](../sistema-gestion-peluqueria) es el primer
integrador real, y lo usa como **un contenedor más de su despliegue**. Eso obligó a
cerrar cosas que en un cPanel quedaban al criterio de quien instalaba:

- **Se configura por variables del compose, no por un `.env` dentro del contenedor**:
  un archivo adentro no sobrevive al despliegue, y `bootstrap.php` caía en
  `.env.example` sin avisar, así que el KuDE salía a nombre de la empresa de ejemplo.
- **El certificado va en un volumen con nombre**, fuera de la imagen. Si la carpeta no
  está, el contenedor se apaga a propósito: un 404 del endpoint se habría interpretado
  como comprobante rechazado.
- **Los dos sistemas no hablan por disco sino por HTTP**, y no se fusionó código: el
  SGP escribe el mismo `.txt` y lo manda al endpoint. Una corrección de las reglas de
  la DNIT sigue aplicándose en un solo sitio.

El KuDE también se volvió configurable en lo visual: la DNIT no impone diseño, así
que la banda y la regla de la tabla toman la paleta de quien emite. Los colores son
constantes en `KudeService` y no dependen de ninguna hoja de estilos.

## Herramientas utilizadas

| Herramienta | Para qué |
| --- | --- |
| **PHP 8.1** (`openssl`, `dom`, `mbstring`) | Lenguaje y criptografía |
| **Motor propio** (`motor/`) | CDC, XML v150, XMLDSig, QR y KuDE, heredados del sistema principal |
| **cron** | Ejecución periódica en hosting compartido |
| **Apache / cPanel** | Despliegue y endpoint HTTP |
| **Docker** | Despliegue como servicio junto al sistema que lo integra |
| **Bash** | Scripts de arranque y parada del watcher |
| **Claude Code · Codex · Antigravity** | Asistencia en desarrollo |

Sin Composer ni dependencias externas, igual que el sistema principal: es requisito
para poder desplegarlo en un cPanel sin acceso a consola.

## Qué no está en el repositorio

- `.env` — credenciales reales de SMTP y token de la API. Está el `.env.example`.
- `certs/*.pem` — certificado y clave de prueba.

## Pendiente de completar

- [x] Confirmar si se integró con un sistema de terceros en producción y cuál —
      el [SGP](../sistema-gestion-peluqueria), desde agosto de 2026
- [ ] Resultado medible: documentos procesados por día, tiempo medio por documento
- [ ] Captura del ciclo completo: `.txt` de entrada y KuDE resultante
