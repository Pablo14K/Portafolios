# Pablo Reinaldo De Jesús González Cáceres

Estudiante de Ingeniería en Informática, último año · Desarrollador backend y Android

<!--
  Los datos de contacto NO van en este fichero: el repositorio es público.
  Viven en cv/datos-contacto.md, que está en .gitignore, y se inyectan al
  generar el PDF y el DOCX.
-->
San Lorenzo, Paraguay · [GitHub](https://github.com/Pablo14K)

## Perfil profesional


Estudiante de Ingeniería en Informática en último año, a seis meses de titularme.
Construyo sistemas de facturación electrónica y de gestión en PHP, Laravel y MySQL, y
aplicaciones Android en Kotlin.

Trabajo cómodo cuando el requisito es estricto y verificable: implementé el régimen
de facturación electrónica SIFEN de la DNIT paraguaya —CDC, XML conforme al XSD
oficial, firma XMLDSig y KuDE— sobre PHP nativo, sin dependencias externas, para que
pudiera desplegarse en hosting compartido. Cuando no había librería disponible,
escribí lo que faltaba: un codificador QR conforme a ISO/IEC 18004, un generador de
PDF y un cliente SMTP.

El mismo criterio aplico al software de gestión: mi proyecto de mayor alcance es una
aplicación Laravel multisucursal desplegada en producción sobre Docker, con la lógica
de negocio en la base de datos, control de concurrencia con bloqueo pesimista y 240
pruebas automatizadas que corren contra una base de datos real.

Busco un puesto de desarrollador de aplicaciones web o móviles donde pueda trabajar
en todo el ciclo —base de datos, lógica de servidor e interfaz— y seguir creciendo
como profesional.

<!--
  Ajustar el último párrafo al puesto concreto de cada postulación.
  Si la vacante es de Android, subir Nexus al primer lugar de los proyectos.
-->

## Habilidades técnicas


- **Lenguajes:** PHP 8.3, Kotlin, Java, SQL, JavaScript, HTML5, CSS
- **Frameworks backend:** Laravel 13 — arquitectura por capas de servicio, middleware
  de autorización, Blade, planificador de tareas, PHPUnit
- **Bases de datos:** MySQL / MariaDB — modelado normalizado (3FN), procedimientos
  almacenados, funciones, triggers, vistas y restricciones CHECK; lógica de negocio en
  el motor. Transacciones y control de concurrencia con bloqueo pesimista
  (SELECT FOR UPDATE). Room en Android
- **Pruebas:** PHPUnit sobre base de datos real, pruebas de concurrencia con procesos
  en paralelo, pruebas de integridad estructural
- **Android:** Jetpack Compose, Material 3, Media3/ExoPlayer, Navigation, DataStore,
  WorkManager, Coroutines y Flow, androidx.tv para Android TV
- **Integraciones y protocolos:** SOAP, XML/XSD, REST, SMTP sobre sockets, MIME,
  scraping con Jsoup, DNS-over-HTTPS
- **Criptografía aplicada:** XMLDSig, RSA-SHA256, certificados X.509 y P12/PEM,
  OpenSSL, WebAuthn/FIDO2 (CBOR, COSE, ASN.1/DER)
- **Frontend:** Bootstrap 5, JavaScript sin framework, accesibilidad WCAG (contraste,
  navegación sin JavaScript, diseño adaptable)
- **Infraestructura:** Docker y Docker Compose, Caddy, Traefik, Apache, php-fpm,
  OPcache, cPanel, cron, Gradle, despliegue en VPS y en hosting compartido
- **Dominio:** facturación electrónica SIFEN v150 (DNIT Paraguay)
- **Herramientas:** Git, Android Studio, Claude Code, Codex, Antigravity

**Desarrollo asistido por IA.** Uso Claude Code, Codex y Antigravity como parte
habitual de mi flujo de trabajo: para acelerar la implementación, revisar código y
documentar. Las decisiones de arquitectura, el modelo de datos y la verificación son
mías, y todo lo generado pasa por la batería de pruebas antes de entrar.

## Proyectos


### SIFEN — Sistema de Facturación Electrónica (2026)
Sistema completo de facturación electrónica contra la DNIT de Paraguay. Reescribí el
motor eliminando un microservicio Node.js para dejarlo 100% PHP y desplegable en
cPanel. Incluye un codificador QR propio conforme a ISO/IEC 18004 (Reed-Solomon sobre
GF(256), validado contra la librería `qrcode` de Python), un generador de PDF con la
paginación que exige el Manual Técnico v150, firma XMLDSig con RSA-SHA256 y X.509, y
un sistema de colas que reanuda envíos interrumpidos sin duplicar comprobantes.
~6.500 líneas de PHP. → [Ficha](../proyectos/sifen-facturacion-electronica)

### Sistema de Gestión para Peluquería (2026, en producción)
Aplicación web Laravel 13 de gestión integral y multisucursal —agenda, clientes,
inventario, caja, facturación electrónica y portal de autogestión para el cliente—,
desplegada en producción sobre Docker en un VPS con php-fpm, Caddy y HTTPS. La lógica
de negocio vive en la base de datos: 85 tablas en 3FN, 51 funciones, 22 procedimientos
almacenados, 17 triggers, 18 vistas y 93 restricciones CHECK, consumidas desde una
capa de servicios propia en vez de reimplementarse en el ORM. Resuelve el reparto de
una cita entre varios profesionales y varias personas, con bloqueo pesimista para que
dos reservas simultáneas sobre el mismo horario dejen una sola cita. Emite facturas y
notas de crédito electrónicas ante la DNIT. Incluye login biométrico WebAuthn escrito
desde cero (decodificador CBOR, claves COSE y ASN.1/DER en PHP) y un motor de temas
que deriva 42 tokens de color de un solo color elegido, verificando contraste WCAG.
240 pruebas automatizadas, incluidas pruebas de concurrencia con procesos en paralelo.
Migré el sistema de PHP sin framework a Laravel 13 conservando el modelo de datos.
Trabajo de Conclusión de Carrera, en pareja.
→ [Ficha](../proyectos/sistema-gestion-peluqueria) ·
[Sistema en producción](https://sgp.columbiatcc.online)

### Nexus — App Android (2026, en desarrollo)
Aplicación Android en Kotlin y Jetpack Compose, con un solo código para móvil y
Android TV. Agrega tres proveedores de metadatos en paralelo y resuelve fuentes de
reproducción en streaming, emitiendo cada resultado según llega en vez de esperar al
más lento. El reproductor salta solo entre enlaces alternativos y detecta un fallo
que ExoPlayer no reporta —vídeo H.264 10-bit que no renderiza— cayendo a libVLC.
~10.700 líneas de Kotlin. → [Ficha](../proyectos/nexus)

### SIFEN Automatizador (2026)
Versión simplificada del sistema anterior: el mismo motor fiscal sin interfaz ni base
de datos, para que un comercio emita facturas electrónicas sin cambiar el software
que ya usa. La integración es una carpeta donde deja archivos de texto. Corre como
cron, como proceso vigilante o como contenedor Docker, y está integrado en producción
con el Sistema de Gestión para Peluquería, para el que se amplió el formato de entrada
conservando compatibilidad con los integradores anteriores.
→ [Ficha](../proyectos/sifen-automatizador)

## Experiencia laboral


### Programador (pasantía), Vieloy Sistemas
Abril 2026 – Mayo 2026 | Paraguay

- Desarrollo del sistema de facturación electrónica SIFEN encargado por la empresa:
  generación del XML según el Manual Técnico v150 de la DNIT, firma digital XMLDSig
  y representación gráfica KuDE, en PHP sin dependencias externas.
- El proyecto se descartó del lado de la empresa y me autorizaron a conservarlo, por
  lo que su desarrollo continuó por mi cuenta hasta dejarlo funcional.

## Formación académica


### Ingeniería en Informática, Universidad Columbia del Paraguay
Quinto año, en curso | Titulación prevista para principios de 2027

Trabajo de Conclusión de Carrera en desarrollo: «Sistema web de gestión para una
peluquería de Luque» — Línea de investigación: Sistemas Computacionales. El sistema
está desplegado y operando en producción.

## Idiomas


- **Español:** nativo
- **Guaraní:** básico
- **Inglés:** básico — lectura de documentación técnica. Cursado como materia
  universitaria, sin certificación externa.
