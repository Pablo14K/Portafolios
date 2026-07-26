# SIFEN — Sistema de Facturación Electrónica (Paraguay)

> Sistema completo de facturación electrónica contra SIFEN, el régimen de la DNIT
> paraguaya: genera el XML del documento, lo firma digitalmente, produce el KuDE en
> PDF con QR y lo envía al cliente por correo. Íntegramente en PHP, sin dependencias
> externas.

| | |
| --- | --- |
| **Rol** | Desarrollo full-stack |
| **Periodo** | 2026 |
| **Stack** | PHP 8.1 · MySQL 8 / MariaDB · SOAP · XMLDSig · Apache / cPanel |
| **Volumen** | ~6.500 líneas de PHP · 13 tablas · 24 clases de dominio |
| **Origen** | Encargo recibido durante una pasantía en Vieloy Sistemas. La empresa descartó el proyecto y autorizó conservarlo; el desarrollo continuó por cuenta propia hasta dejarlo funcional |
| **Norma** | Manual Técnico SIFEN v150 (DNIT) · XSD `DE_v150.xsd` / `siRecepDE_v150.xsd` |

## Contexto

Emitir facturas electrónicas en Paraguay obliga a cumplir la especificación SIFEN
al detalle: un identificador CDC de 44 dígitos con dígito verificador módulo 11, un
XML cuya estructura y orden de grupos debe coincidir exactamente con el XSD oficial,
firma XMLDSig con certificado emitido por un prestador habilitado, un código QR con
la estructura `dCarQR` y una representación gráfica impresa (el KuDE) con un formato
reglado.

Las librerías del ecosistema para esto son escasas y suelen asumir Node.js o
Composer. El objetivo era un sistema desplegable en un hosting compartido con cPanel
—donde no hay Node ni acceso a consola— y por tanto **100% PHP, sin gestor de
dependencias**.

## Qué construí

La versión inicial delegaba la generación y firma en un microservicio Node.js. Lo
reescribí por completo para eliminar esa dependencia: hoy todo el flujo fiscal corre
en PHP nativo sobre las extensiones estándar (`openssl`, `dom`, `curl`, `pdo_mysql`).

El pipeline de emisión, en orden:

```
Factura (BD)
  └→ CdcGenerator      CDC de 44 dígitos + DV módulo 11
  └→ SifenXmlBuilder   XML rDE/DE según el XSD oficial
  └→ XmlSigner         firma XMLDSig: digest SHA-256, RSA-SHA256, certificado X509
  └→ QrGenerator       cadena dCarQR + hash, insertada en el XML firmado
  └→ SoapSifenClient   envío al web service de la DNIT (o simulación en modo mock)
  └→ KudeService       KuDE en PDF, multipágina, con el QR vectorizado
  └→ MailService       correo al cliente con el PDF y el XML adjuntos
```

Sobre esto, una interfaz web para cargar facturas, revisarlas y disparar el envío.

## Retos técnicos

### Codificador QR escrito desde cero

Sin Composer no había forma de traer una librería de QR, y el KuDE necesita un
código realmente escaneable. `QrCode.php` (516 líneas) implementa el estándar
**ISO/IEC 18004** en PHP puro: aritmética sobre **GF(256)** con polinomio primitivo
`0x11d`, corrección de errores **Reed-Solomon**, selección automática de versión,
las ocho máscaras con su puntuación de penalización, y los bloques de información de
formato y versión.

Para validarlo, comparé la matriz de módulos generada contra la librería `qrcode` de
Python en las mismas condiciones (versión, nivel de corrección y máscara fijados):
matrices idénticas.

### Generador de PDF propio, con paginación reglada

El KuDE tampoco podía apoyarse en una librería. `KudeService.php` (593 líneas)
escribe el PDF directamente: dibuja la cabecera del emisor, la tabla de ítems, los
totales, el CDC y la zona del QR como vectores.

La parte con más aristas fue la paginación. El Manual Técnico v150 (sección 13.3)
exige que, cuando los ítems no entran en una hoja, el documento continúe en páginas
numeradas «X/Y», que los totales aparezcan **solo en la última** y que la zona de
QR y CDC se repita al pie de **todas**. Está resuelto midiendo el alto disponible
antes de volcar cada fila y cerrando la página cuando la siguiente no cabe.

### XML conforme al XSD, sin margen de error

SIFEN rechaza el documento si el orden de los grupos no coincide con el esquema.
`SifenXmlBuilder.php` construye el árbol `rDE > DE > gOpeDE, gTimb, gDatGralOpe
(gOpeCom, gEmis, gDatRec), gDtipDE (gCamFE, gCamCond, gCamItem+), gTotSub`
respetando el orden y la obligatoriedad del XSD, sin espacios en blanco entre
etiquetas ni prefijos de namespace, y omitiendo los campos opcionales con valor cero
—una exigencia del capítulo 7.2.4 del manual que no es evidente leyendo solo el
esquema—. Lo validé contra el XSD oficial con `lxml`.

### Envío reanudable que no duplica comprobantes

El sistema está pensado para hosting compartido y conexiones inestables. El envío se
apoya en dos colas en MySQL, `fe_queue` (generación) y `email_queue` (envío), que se
procesan documento a documento y guardan el estado de cada uno.

Si la conexión se corta a mitad de una tanda, el lote queda **suspendido** en lugar
de fallar entero; al reanudar, continúa por donde iba sin reenviar lo ya enviado ni
regenerar comprobantes con CDC nuevo. Cada transición queda registrada en
`invoice_events`, lo que permite auditar qué pasó con cada factura.

### Cliente SMTP sobre sockets

`MailService.php` habla SMTP directamente sobre `stream_socket_client`: negocia
**STARTTLS**, se autentica con `AUTH LOGIN` y compone el mensaje MIME multiparte con
el KuDE y el XML adjuntos. En modo evidencia puede volcar el correo a un `.eml` en
lugar de enviarlo, útil para probar sin un servidor SMTP delante.

## Modos de operación

| Modo | Comportamiento |
| --- | --- |
| `mock` | Genera, firma y aprueba localmente. **Sin valor fiscal.** Para desarrollo y demos. |
| `test` | Envía al ambiente de homologación de la DNIT. Requiere certificado. |
| `prod` | Envía al ambiente real. Requiere certificado habilitado por un PSC. |

## Arquitectura

```
app/
  public/index.php          Interfaz web (carga, revisión y envío)
  bootstrap.php             Arranque y autoload propio
  config/config.php         Configuración leída del entorno
  src/
    Service/
      CdcGenerator.php      CDC de 44 dígitos
      Mod11.php             Dígito verificador módulo 11
      SifenXmlBuilder.php   XML conforme al XSD v150
      XmlSigner.php         Firma XMLDSig (SHA-256 / RSA-SHA256 / X509)
      QrCode.php            Codificador QR ISO/IEC 18004 en PHP puro
      QrGenerator.php       Cadena dCarQR e inserción en el XML
      KudeService.php       Generador de PDF con paginación reglada
      MailService.php       Cliente SMTP sobre sockets
      SoapSifenClient.php   Web services de la DNIT
      CancellationService.php  Eventos de cancelación
      PipelineService.php   Orquestación de las colas
      TotalsCalculator.php  Cálculo de totales e IVA
      CertificateLoader.php Carga de certificados P12 / PEM
    Repository/             Acceso a datos por entidad
    Database/               Conexión y actualizador de esquema
  scripts/
    process_fe_queue.php    Worker de generación
    process_email_queue.php Worker de envío
db/init/                    Esquema completo
```

Tablas: `invoices`, `invoice_items`, `invoice_events`, `customers`, `payments`,
`emitter_settings`, `fe_queue`, `email_queue`, `email_log`, `personas`,
`departamentos`, `ciudades`, `tipos_documento_identidad`.

## Herramientas utilizadas

La restricción de partida —desplegable en cPanel, sin Node.js y **sin Composer**—
obligó a implementar a mano lo que normalmente se resolvería con una librería.

| Herramienta | Para qué |
| --- | --- |
| **PHP 8.1** | Lenguaje; toda la lógica fiscal |
| **ext-openssl** | Firma RSA-SHA256, certificados X.509 y P12/PEM |
| **ext-dom** + DOMXPath | Construcción y firma del XML |
| **ext-curl** / SOAP | Web services de la DNIT |
| **MySQL 8 / MariaDB** | Facturas, clientes, colas y trazas de eventos |
| **Apache / cPanel** | Despliegue en hosting compartido |
| **Autoload propio** | Carga de clases sin Composer |
| Codificador **QR** propio | ISO/IEC 18004; sustituye a una librería de QR |
| Generador **PDF** propio | KuDE; sustituye a FPDF/TCPDF |
| Cliente **SMTP** propio | Sobre sockets; sustituye a PHPMailer |
| **lxml** (Python) | Validación del XML contra el XSD oficial, en desarrollo |
| **qrcode** (Python) | Verificación de las matrices del codificador QR, en desarrollo |
| **Claude Code** y **Codex** | Asistencia en desarrollo |

Las tres últimas líneas de la tabla explican por qué las dos herramientas de Python
aparecen aquí: no forman parte del sistema, se usaron como referencia independiente
para comprobar que las implementaciones propias daban el resultado correcto.

## Requisitos

- PHP 8.1+ con `pdo_mysql`, `mbstring`, `openssl`, `curl`, `dom`
- MySQL 5.7+ / MariaDB 10.3+
- Para modo `test` o `prod`: certificado P12 emitido por un PSC habilitado por el MIC
  y el CSC otorgado por la DNIT

## Qué no está en el repositorio

Por seguridad y peso, quedaron fuera al publicar:

- `.env` — credenciales reales de SMTP y base de datos. Está el `.env.example`.
- `storage/certs/*.pem` — certificados y claves de prueba.
- `Documento/Manual Técnico Versión 150.pdf` — documento oficial de la DNIT,
  descargable desde [dnit.gov.py](https://www.dnit.gov.py).
- `storage/tmp/` y ficheros `.bak` — restos de desarrollo.
- El automatizador, que vivía como subcarpeta, está publicado aparte en
  [`sifen-automatizador`](../sifen-automatizador).

## Pendiente de completar

- [ ] Capturas: interfaz de carga, pestaña de envío y un KuDE generado
- [ ] Resultado medible: comprobantes emitidos, tiempo de emisión, tasa de aprobación
