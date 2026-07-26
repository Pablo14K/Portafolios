# SIFEN Automatizador

> **Versión simplificada** de [SIFEN — Sistema de Facturación Electrónica](../sifen-facturacion-electronica):
> el mismo motor fiscal reducido a lo mínimo, sin interfaz ni base de datos. Un
> sistema externo deja un `.txt` en una carpeta y recoge el XML firmado y el PDF en otra.

| | |
| --- | --- |
| **Rol** | Desarrollo backend |
| **Periodo** | 2026 |
| **Stack** | PHP 8.1 · cron / watcher · Apache · cPanel |
| **Volumen** | ~4.000 líneas de PHP |
| **Origen** | Derivado del [sistema principal](../sifen-facturacion-electronica), encargado en una pasantía en Vieloy Sistemas y conservado con autorización de la empresa |
| **Relación** | Versión simplificada del [sistema de facturación completo](../sifen-facturacion-electronica), del que reutiliza el motor fiscal |

## Contexto

El sistema de facturación completo resuelve el problema para quien puede adoptarlo
entero: su base de datos, su interfaz, su flujo. Pero un comercio que ya tiene su
propio software de ventas no quiere cambiarlo — quiere que **eso que ya usa** emita
facturas electrónicas válidas.

De ahí sale esta versión reducida: **el mismo motor fiscal, despojado de todo lo
demás**. Sin interfaz web, sin base de datos, sin gestión de clientes ni de colas.
Solo la parte que convierte datos de una factura en un documento electrónico
firmado y aprobado.

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

Deliberadamente **no reimplementa la lógica fiscal**: reutiliza el motor del sistema
principal (CDC, XML v150, firma XMLDSig, QR y KuDE) montándolo como dependencia de
solo lectura. Así una corrección en las reglas de la DNIT se aplica en un único
sitio y ambos productos la heredan.

## Decisiones técnicas

### Un formato de entrada que cualquiera puede generar

El `.txt` usa registros por línea separados por `|`, agrupando una factura por
bloque. Es un formato que se escribe con un `printf` desde cualquier lenguaje, sin
librerías:

```text
FAC|establecimiento|punto|numero|fecha|condicion|moneda
CLI|tipo|documento|nombre|email|direccion|telefono
ITM|codigo|descripcion|cantidad|precio_unitario|iva
PAG|tipo|monto
===
```

`TxtParser` lo valida campo a campo antes de construir nada, de modo que un archivo
mal formado se rechaza con un mensaje concreto en `errores/` en vez de producir un
XML inválido que la DNIT devolvería con un código críptico.

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

Para los sistemas que prefieren empujar la factura en lugar de escribir en disco,
`public/index.php` expone un endpoint que acepta el mismo formato `.txt` por HTTP,
autenticado con un token compartido. `public/descargar.php` permite recuperar
después el XML y el PDF resultantes.

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
  InvoiceFactory.php  Construcción del payload de factura
  Procesador.php    Orquestación del ciclo completo
  Logger.php        Registro de la actividad
motor/              Motor fiscal reutilizado del sistema principal
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

## Herramientas utilizadas

| Herramienta | Para qué |
| --- | --- |
| **PHP 8.1** (`openssl`, `dom`, `mbstring`) | Lenguaje y criptografía |
| **Motor fiscal propio** (`motor/`) | CDC, XML v150, XMLDSig, QR y KuDE, heredados del sistema principal |
| **cron** | Ejecución periódica en hosting compartido |
| **Apache / cPanel** | Despliegue y endpoint HTTP |
| **Bash** | Scripts de arranque y parada del watcher |
| **Claude Code** | Asistencia en desarrollo |

Sin Composer ni dependencias externas, igual que el sistema principal: es requisito
para poder desplegarlo en un cPanel sin acceso a consola.

## Qué no está en el repositorio

- `.env` — credenciales reales de SMTP y token de la API. Está el `.env.example`.
- `certs/*.pem` — certificado y clave de prueba.

## Pendiente de completar

- [ ] Confirmar si se integró con un sistema de terceros en producción y cuál
- [ ] Resultado medible: facturas procesadas por día, tiempo medio por documento
- [ ] Captura del ciclo completo: `.txt` de entrada y KuDE resultante
