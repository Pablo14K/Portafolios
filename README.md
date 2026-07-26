# Portafolio — Pablo González Cáceres

Ingeniero en Informática. Desarrollo backend, full-stack y Android. Trabajo sobre
todo en sistemas que tienen que cumplir una especificación estricta —facturación
electrónica, firma digital— y en aplicaciones que integran fuentes de datos
heterogéneas.

## Proyectos

| Proyecto | Descripción | Stack | Estado |
| --- | --- | --- | --- |
| [SIFEN — Facturación Electrónica](proyectos/sifen-facturacion-electronica) | Sistema completo de facturación electrónica contra la DNIT paraguaya: XML v150, firma XMLDSig, KuDE en PDF y QR, todo en PHP nativo sin dependencias. | PHP · MySQL · SOAP · XMLDSig | Funcional |
| [SIFEN Automatizador](proyectos/sifen-automatizador) | Versión simplificada del anterior: el mismo motor fiscal sin interfaz ni base de datos, para integrar sistemas de terceros por archivos de texto. | PHP · cron · cPanel | Funcional |
| [Nexus](proyectos/nexus) | App Android de películas, series y anime. Agrega varios catálogos, resuelve fuentes en paralelo y reproduce con recuperación automática ante fallos. Móvil y TV. | Kotlin · Compose · Media3 · Room | En desarrollo |
| [JahechaCine](proyectos/jahechacine) | Primera versión de la anterior. Catálogo de películas con TMDB en Java y vistas clásicas. | Java · Retrofit · Glide | Discontinuado |
| [Sistema de Gestión para Peluquería](proyectos/sistema-gestion-peluqueria) | Gestión integral para una peluquería: agenda, clientes, inventario, caja y portal de autogestión. Lógica de negocio en la base de datos y WebAuthn escrito a mano. | PHP · MySQL · Bootstrap · WebAuthn | En desarrollo |

Dos pares de proyectos están relacionados a propósito: **JahechaCine → Nexus** es
una reescritura completa tras topar con los límites de la primera versión, y
**SIFEN Automatizador** es la reducción del sistema de facturación a su motor.
Las fichas de cada uno explican qué cambió y por qué.

## CV

En [`cv/cv.md`](cv/cv.md).

## Estructura

```
.
├── cv/
│   └── cv.md                 CV en markdown (fuente única, se exporta a PDF/DOCX)
├── proyectos/
│   ├── _plantilla/           Plantilla de ficha de proyecto
│   └── <nombre-proyecto>/    Un directorio por proyecto, con su README.md
└── README.md                 Este índice
```
