# Nexus

> App Android de películas, series y anime: agrega metadatos de varios catálogos,
> resuelve dónde ver cada título consultando fuentes en paralelo y reproduce con
> recuperación automática ante fallos. Un solo código para móvil y Android TV.

| | |
| --- | --- |
| **Rol** | Desarrollo Android completo (arquitectura, datos, interfaz y reproducción) |
| **Periodo** | 2026 — **en desarrollo activo** |
| **Tipo** | Proyecto propio |
| **Stack** | Kotlin 2.1 · Jetpack Compose · Media3/ExoPlayer · Room · OkHttp · Coroutines |
| **Volumen** | ~10.700 líneas de Kotlin · 59 ficheros · minSdk 24 / SDK 35 |
| **Antecesor** | Segunda versión de [JahechaCine](../jahechacine), reescrita desde cero |

## Contexto

[JahechaCine](../jahechacine) llegó hasta mostrar catálogo y ficha, pero se quedó
sin resolver la parte difícil: de dónde sale el vídeo. Su arquitectura —Java,
Activities, una sola API— no daba para crecer ahí.

Nexus es la reescritura completa con ese problema en el centro. La pregunta de
diseño no es «cómo listo películas», sino **«cómo pregunto a N sitios distintos a
la vez, con formatos y defensas distintas, y empiezo a dar resultados antes de que
respondan todos»**.

## Arquitectura

Cuatro capas, con una frontera clara entre *qué existe* y *dónde se ve*:

```
┌─ Metadatos (data/meta) ─────── qué existe
│    TMDB (cine y series) · AniList (anime) · Jikan (títulos de episodio)
│    MetadataRepository consulta en paralelo y entrelaza resultados
├─ Fuentes (data/source) ─────── dónde se ve
│    SourceConnector (interfaz) · SourceRegistry pregunta a todas a la vez
│    y emite en streaming según cada una responde, con progreso
├─ Reproducción (ui/player) ──── ExoPlayer, con libVLC de último recurso
└─ Estado (data/db, data/prefs)  Room + DataStore: progreso, favoritos, historial
```

Inyección de dependencias a mano en `di/Graph.kt`, sin Hilt. Todo el estado es
local: no hay backend propio.

## Decisiones técnicas

### Resolución de fuentes en streaming, no por lotes

`SourceRegistry.resolveStreaming` lanza todos los conectores a la vez y **emite
cada enlace en cuanto aparece**, en lugar de esperar a que terminen todos. La
interfaz muestra «Buscando servidores… X%» y va poblando la lista.

Sin esto, la espera sería la del conector más lento —hasta 40 segundos— aunque el
primero hubiera respondido en dos. Hay tope global de 46 s en el ViewModel para que
una fuente colgada no deje el indicador girando para siempre, y la caché guarda
**solo resultados no vacíos**, de modo que al refrescar se reintenta lo que falló y
los hallazgos se acumulan.

### Emparejar título con URL sin equivocarse de obra

Los buscadores de los sitios de origen devuelven mucho ruido. El primer enfoque
—coger el primer resultado, o `contains()`— producía errores absurdos: buscar
«House» traía `barbie-dreamhouse-adventures` primero, y `contains("house")` casaba
con «dream·house·», así que la app reproducía Barbie.

`SlugMatch` compara **por segmentos** separados por guion: «house» casa con
`dr-house` o `house-md`, pero no con `dreamhouse`. Y si nada casa con confianza
devuelve `null` — mejor quedarse sin enlace que servir el equivocado.

### Vídeo negro con sonido: el fallo que ExoPlayer no reporta

Mucho anime viene en **H.264 10-bit (High 10)**, un perfil que la mayoría de
decodificadores hardware de Android no pintan. El síntoma es desconcertante: se oye
el audio, la imagen queda en negro y **ExoPlayer no lanza ningún error** — no falla,
simplemente no renderiza, así que no hay nada que capturar.

La solución fue detectarlo por ausencia: si a los 6 segundos de estar reproduciendo
no ha llegado `onRenderedFirstFrame`, se cae al mismo enlace en **libVLC**, que
decodifica por software y sí saca el 10-bit. Si el usuario forzó ExoPlayer en
ajustes, se respeta su elección y se prueba otra fuente.

### Reproducción que no se rinde al primer fallo

`PlaybackRequest` viaja con **todas las alternativas** encontradas (URL, cabeceras y
contenedor). Ante cualquier error de reproducción el player salta solo al siguiente
enlace; libVLC es el último recurso y su creación está blindada —en algunos
dispositivos `LibVLC.<init>` lanza `UnsupportedOperationException` y antes se
llevaba por delante el proceso—. Si no se puede crear, el player salta o muestra
error, pero no cierra la app.

### Red hostil: certificados y DNS

Dos problemas que solo aparecen en dispositivo real, resueltos en `core/Net.kt`:

- **Certificados no confiables.** Muchos CDN de vídeo los usan y ExoPlayer aborta
  con `Trust anchor not found`. El cliente de streaming acepta el certificado; es
  una decisión consciente, limitada al tráfico de vídeo.
- **Bloqueo DNS del ISP.** El sitio está vivo pero el móvil no resuelve su IP, y la
  fuente parece caída sin estarlo. `resilientDns` intenta primero el DNS del sistema
  y solo si devuelve vacío recurre a **DNS-over-HTTPS de Cloudflare**. El caso normal
  no se ve afectado.

### Extracción de enlaces en dos capas

`HostResolver` va primero por **HTTP**, que es rápido: extractores con nombre para
hosts cuyo enlace real nunca aparece en el HTML (StreamTape monta la URL en JS
concatenando trozos; DoodStream exige `/pass_md5/` más token), y un raspado genérico
que desempaqueta el ofuscador de Dean Edwards (`eval(function(p,a,c,k,e,d))`) con
`JsUnpacker`.

Cuando eso no basta, `WebViewResolver` carga el embed en un WebView oculto, deja
correr su JavaScript e **intercepta la petición del vídeo** en
`shouldInterceptRequest`, capturando de paso las cookies y el Referer reales —sin
ellos muchos CDN devuelven 403—. Anula popups y limita a dos WebViews simultáneos
con un `Semaphore`, porque es la parte lenta del sistema.

## Fuentes soportadas

| Tipo | Conectores |
| --- | --- |
| **Personal / autoalojado** | `LocalFolderConnector` (carpeta del dispositivo), `JellyfinConnector` |
| **Extensible** | `AddonConnector` (protocolo de addons de Stremio) |
| **Catálogos públicos** | Varios conectores de sitios web, cableados de fábrica |

Añadir una fuente nueva es implementar `SourceConnector` y registrarla en
`Graph.kt`; el resto del sistema no cambia.

## Método: análisis de aplicaciones de referencia

Los conectores y los extractores de host no salieron de la documentación de
ningún sitio —no existe— sino del **análisis de cinco aplicaciones Android que ya
resolvían el problema**. El objetivo era entender *qué técnica* usaban para
localizar y extraer un enlace de vídeo, no obtener su código.

El proceso, apoyado en Claude para leer y anotar el material desensamblado:

1. Analizar cómo cada aplicación trataba sus fuentes: por dónde entraba la
   petición, qué transformaba y qué devolvía.
2. Escribir la explicación del mecanismo en lenguaje llano.
3. **Reimplementar desde esa explicación**, en Kotlin y con la arquitectura de
   Nexus, sin portar código de origen.

Ese trabajo es el que permitió resolver casos que no se deducen mirando una web:
que StreamTape monte la URL en JavaScript concatenando dos fragmentos, que
DoodStream exija un `/pass_md5/` con token, que embed69 cifre su lista de hosts
con prueba de trabajo SHA-256 y AES-CBC, o que haga falta espiar la petición del
manifiesto desde un WebView porque el enlace nunca aparece en el HTML.

La misma revisión sirvió para **descartar** fuentes: jkanime se evaluó y se dejó
fuera al ver que servía el vídeo por proxies internos cifrados y MSE, donde el
raspado directo habría devuelto vacío casi siempre.

> Este método se aplicó **únicamente en Nexus**. Ninguno de los demás proyectos
> del portafolio partió de material de terceros.

## Herramientas utilizadas

| Herramienta | Para qué |
| --- | --- |
| **Kotlin 2.1** + Coroutines / Flow | Lenguaje y concurrencia |
| **Jetpack Compose** + Material 3 | Interfaz declarativa |
| **androidx.tv.material** | Adaptación a Android TV: mismas pantallas, distinta disposición y foco |
| **Navigation Compose** | Navegación entre pantallas |
| **Media3 / ExoPlayer** (HLS, DASH, RTSP) | Reproductor principal |
| **libVLC** | Reproductor de respaldo para formatos que ExoPlayer no traga |
| **Room** (con KSP) | Base de datos local: progreso, favoritos, historial |
| **DataStore Preferences** | Ajustes |
| **OkHttp** + DNS-over-HTTPS | Cliente HTTP y resolución DNS resiliente |
| **Retrofit** + kotlinx.serialization | Consumo de APIs REST |
| **Jsoup** | Parseo de HTML |
| **Coil** | Carga de imágenes en Compose |
| **WorkManager** | Tareas en segundo plano |
| **androidx.security.crypto** | Almacenamiento cifrado de credenciales de fuentes |
| **Gradle 8.11** + AGP 8.9, KTS | Compilación |
| **Claude Code** | Asistencia en desarrollo (ver abajo) |

## Desarrollo asistido por IA

El proyecto se desarrolló con **Claude Code** como asistente, tanto en el análisis
de las aplicaciones de referencia descrito arriba como en la implementación. El
fichero [`CLAUDE.md`](CLAUDE.md) es la memoria técnica que dirige esa colaboración:
recoge la arquitectura, las decisiones que no deben revocarse, los *gotchas*
aprendidos a base de fallos —el caso de Barbie, el H.264 10-bit, el título romaji
frente al inglés— y una auditoría de cabos sueltos con fecha.

Mantener ese documento al día resultó ser útil más allá de la IA: es la
documentación de arquitectura que el proyecto habría necesitado igualmente, y obliga
a escribir por qué se tomó cada decisión en el momento en que se toma.

## Estado

Funciona de punta a punta con reproducción real, verificado en dispositivo (Galaxy
S20 FE por depuración inalámbrica).

Pendiente, diseñado pero no construido: descargas, perfiles, algunas secciones de
Ajustes y conector debrid. La auditoría de julio de 2026 recogida en `CLAUDE.md`
identifica además cuatro promesas a medias —PiP declarado pero nunca invocado,
`autoPlayNextEpisode` guardado pero no consultado, subtítulos externos que se
descartan antes de llegar al player, y restos de la función de IPTV eliminada—.

## Compilar

Requiere **JDK 21** (el JBR de Android Studio sirve).

```bash
# Windows
$env:JAVA_HOME="C:\Program Files\Android\Android Studio\jbr"
.\gradlew.bat :app:assembleDebug
```

La clave de TMDB se lee de `local.properties` (`TMDB_API_KEY`), que no está en el
repositorio. El APK de depuración sale en `app/build/outputs/apk/debug/`.

## Pendiente de completar

- [ ] Capturas: inicio, ficha de título, hoja de fuentes y reproductor
- [ ] Vídeo corto del flujo completo — es lo que mejor vende esta app
- [ ] Captura de la interfaz en Android TV, que justifica el trabajo de adaptación
