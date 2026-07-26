# Nexus — instrucciones para el agente

App Android de películas, series y anime. Kotlin + Jetpack Compose,
móvil y Android TV con un solo código. Reconstruida a partir de las funciones de
varias APKs de referencia (EPIX PLAY, Cliente EC, Film Plus, ModoCine, Anime
Cast), **sin portar su código**: se reimplementaron las funciones y la interfaz.

## Cómo compilar (Windows)

`JAVA_HOME` global es Java 8, pero se necesita **JDK 21**. Usa el JBR de Studio:

```bash
$env:JAVA_HOME="C:\Program Files\Android\Android Studio\jbr"
.\gradlew.bat :app:assembleDebug
```

- SDK 35, minSdk 24, Gradle 8.11.1 (en caché), AGP 8.9.0, Kotlin 2.1.0.
- APK: `app/build/outputs/apk/debug/app-debug.apk` (~105 MB).
- `app/build/` y `.gradle/` NO están aquí (se regeneran al compilar); tampoco
  deben subirse a OneDrive por tamaño.

## Arquitectura (cuatro capas)

1. **Metadatos** (`data/meta`): qué existe. TMDB para cine/series (clave en
   `local.properties` → `BuildConfig.TMDB_API_KEY`, interna, sin campo en
   Ajustes), AniList para anime, Jikan solo para títulos de episodio.
   `MetadataRepository` consulta en paralelo y entrelaza resultados.
2. **Fuentes** (`data/source`): dónde se ve. `SourceConnector` es la interfaz;
   `SourceRegistry` pregunta a todas a la vez y **emite en streaming** según cada
   una responde (`resolveStreaming`), con progreso. Conectores: `LocalFolderConnector`,
   `JellyfinConnector`, `AddonConnector` (Stremio), y los de scraping ya cableados
   (`Embed69Connector`, `VidSrcConnector`, `PelisplusConnector`,
   `TioanimeConnector`, `MonoschinosConnector`, `LatanimeConnector`). Detalle y
   cómo añadir más en **§ Fuentes online**.
3. **Reproducción** (`ui/player`): ExoPlayer por defecto. Ante cualquier fallo el
   player **salta solo al siguiente enlace** (todas las fuentes viajan como
   `alternatives` en `PlaybackRequest`); libVLC es el último recurso y su creación
   está blindada (si no arranca, no estrella la app). Ver **§ Reproducción robusta**.
4. **Estado** (`data/db` Room + `data/prefs` DataStore): progreso, favoritos,
   historial, ajustes. Todo local, sin backend.

DI a mano en `di/Graph.kt` (singleton). Sin Hilt.

## Reglas de la interfaz

- Paleta fija: acento `#00FF2C`, fondo `#000000`, tema oscuro siempre
  (`ui/theme/Theme.kt`). No introduzcas modo claro.
- Etiquetas y datos van en sans con peso (NO monoespaciada) — decisión
  deliberada del dueño; no la revoques.
- Cuatro pestañas: Inicio, Catálogo, Buscar, Biblioteca. **No hay IPTV ni TV en
  vivo** (se eliminó a propósito). No la reintroduzcas.

## Estado actual y qué falta

Funciona de punta a punta **con reproducción real** de cine, series y anime,
verificado en dispositivo (Galaxy S20 FE por depuración inalámbrica). De fábrica
ya vienen sembradas fuentes de scraping (ver abajo), así que la mayoría de
títulos populares tienen enlace sin configurar nada. El usuario aún puede añadir
su Jellyfin/Emby o un addon desde Ajustes → Fuentes.

Pendiente (diseñado en la maqueta, no construido): descargas, perfiles, las
secciones de Ajustes que aún no tienen pantalla, conector debrid. La maqueta
aprobada: revisa la memoria del proyecto (`nexus-app-project`) para el enlace.

**Cabos sueltos detectados en auditoría (2026-07-25)** — no son fallos visibles
hoy, pero son promesas a medias; si tocas esa zona, ciérralas:

- **PiP**: el manifest declara `supportsPictureInPicture` y `PlayerActivity`
  tiene un `onUserLeaveHint` cuyo comentario dice que el PiP «se activa desde
  PlayerScreen» — **no existe ninguna llamada a `enterPictureInPictureMode`**.
  El PiP nunca entra. O se implementa o se quita el comentario y el flag.
- **`autoPlayNextEpisode`**: se guarda y tiene interruptor en Ajustes, pero
  **ningún código lo consulta**; no hay lógica de "siguiente episodio". Ahora
  mismo es un ajuste decorativo.
- **Subtítulos externos**: `StreamLink.subtitleUrls` lo rellenan Consumet y
  Aniwatch, pero `PlaybackRequest` **no tiene campo de subtítulos** y `from()`
  los descarta, así que nunca llegan al reproductor. (Impacto bajo hoy: esas dos
  fuentes no se siembran.)
- **Residuos sin uso**: `MediaKind.LIVE` (queda de la IPTV eliminada; hay
  etiqueta "VIVO" en `Common.kt`), `SourceType.DEBRID/PUBLIC/DIRECT`,
  `Engine.EXTERNAL` (está filtrado de las opciones de Ajustes, es inalcanzable),
  `MetadataProvider.isConfigured()`, y en los DAOs `progress().delete(key)` y
  `searchHistory().remove(entry)`.
- **Cosmético**: `Icons.Default.ArrowBack` está obsoleto en 4 pantallas (usar
  `Icons.AutoMirrored.Filled.ArrowBack`); es el único warning del build.

## Fuentes online (scraping) — implementado

Origen del material: carpeta `..\Apps\_ingenieria_inversa\` (análisis de las 5
APKs) y el doc `ENDPOINTS_Y_CREDENCIALES.md`. **No se porta código**: se
reimplementó la lógica. Endpoints fijos centralizados en `core/Endpoints.kt`.

**Cadena general**: metadatos (TMDB/AniList) → conector de sitio (busca el
título, saca la página del capítulo/película) → **extractor de host** (saca el
`.m3u8`/`.mp4`) → `StreamLink` con sus cabeceras.

Conectores actuales (`data/source/connectors`):

- `Embed69Connector` — **cine y series**. Usa embed69.org por id de IMDb:
  `/f/{imdb}/` (película) y `/f/{imdb}-{T}x{EP2}/` (episodio, EP a 2 dígitos).
  La lista de hosts va cifrada con **prueba de trabajo (SHA-256) + AES-CBC**,
  descifrada en `extract/Embed69Decoder.kt`.
- `VidSrcConnector` — **cine y series por IMDb**, redundante con embed69. Cubre
  también las **películas de anime** (que ahora llevan IMDb). VidSrc encadena
  iframes ofuscados (`vidsrc.to → vsembed → cloudnestra /rcp/`) hasta un m3u8; en
  vez de raspar esa cadena se carga el embed en `WebViewResolver` y se **espía el
  m3u8 a través de los iframes anidados**. **Se siembra DESHABILITADA (opt-in)**:
  es puramente WebView (pesada) y en prueba de dispositivo su player no soltó el
  m3u8 por autoplay (probable clic requerido en iframe cross-origin). Queda lista
  en Ajustes para activarla/repuntar el dominio (`baseUrl` configurable,
  vidsrc.to/.net/.xyz). Si un día su player autoreproduce, funciona sin más.
- `PelisplusConnector` — **series y anime** por raspado de pelisplushd.bz
  (`/serie|anime/{slug}/temporada/{T}/capitulo/{E}`). El capítulo puede delegar
  en embed69 (se descifra) o en xupalace (`go_to_playerVast('URL')`).
- `TioanimeConnector` — **anime VOSE** (audio original + subs español). La página
  del capítulo trae `var videos = [["Host","url"]]`.
- `MonoschinosConnector` — **anime VOSE**, hosts distintos. Servidores en
  `data-player="<base64>"` (base64 = URL del embed).
- `LatanimeConnector` — **anime latino y castellano** (lo que las otras no dan),
  además de subtitulado. Mismo patrón que monoschinos (`data-player="<base64>"`),
  base `latanime.org`. La variante de idioma va en el sufijo del slug
  (`-latino`/`-castellano`/`-sub-espanol`), que fija la etiqueta de audio; a
  igualdad de coincidencia se prefiere latino → castellano → sub. Episodio en
  `/ver/{slug}-episodio-{N}`.
- (Consumet/aniwatch: clases existen pero **NO se siembran**; sus instancias
  públicas estaban caídas. Sirven si el usuario apunta a una instancia viva.)
- (jkanime.net se evaluó y **se descartó**: sirve los servidores por proxies
  internos cifrados (`/jkplayer/um?e=…`, `/jkokru.php`) + `blob:`/MSE, no por
  embeds externos; el raspado directo daría casi siempre vacío. AnimeCast lo
  resolvía vía un backend privado autenticado, no reutilizable.)

Extractores de host (`data/source/extract`):

- `HostResolver` — vía **HTTP** rápida, en dos capas. (1) **Extractores con
  nombre** para hosts cuyo enlace real nunca aparece como `.m3u8/.mp4` en el HTML
  y que por eso el WebView tampoco pilla: **StreamTape** (monta `get_video` en JS
  concatenando dos trozos) y **DoodStream** (`/pass_md5/` + token + sufijo random).
  (2) Raspado **genérico**: saca el m3u8/mp4 del texto o del bloque empaquetado
  (`JsUnpacker`, Dean Edwards `eval(function(p,a,c,k,e,d))`), y acepta URLs
  **protocolo-relativo** (`//host/x.mp4`, p. ej. `MDCore.wurl` de Mixdrop).
  Resuelve VidHide/StreamWish/Filemoon. Para añadir un host, mira `named()`.
- `WebViewResolver` — **respaldo**: carga el embed en un WebView oculto, ejecuta
  su JS y **espía la petición del m3u8** (`shouldInterceptRequest`); roba también
  **Cookie + Referer reales** (sin la cookie muchos CDN dan 403). Técnica calcada
  de `_reutilizable/03_fuentes/ModoCine_extractor_voe.js` y
  `CloudflareWebViewFetcher.java`. Genérico para Voe, Filemoon, Doodstream, etc.
  **Mata popups/clickunder** (`window.open` anulado, `onCreateWindow`→false, quita
  iframes de redes de ad; idea de `ModoCine_ad_blocker.js`) — conservador: no toca
  overlays por z-index para no borrar el botón de play. Máx. 2 WebViews a la vez
  (`Semaphore`), es lo lento del sistema.

### Cómo añadir otra fuente del mismo tipo

1. Nuevo `*Connector` copiando el patrón de `TioanimeConnector` (el más simple):
   `findSlug` flexible (busca por título **y** `originalTitle` romaji) → página del
   capítulo → extraer servidores → resolver con `HostResolver` y, si vacío,
   `WebViewResolver`. Usa el patrón de **retorno anticipado** (devuelve al tener 2
   enlaces) y **respaldo WebView** de 1 host.
2. Cablear en `di/Graph.kt`: constante `KIND_*`, rama en `build()`, y una entrada
   en `DEFAULT_ONLINE_SOURCES` (si debe venir de fábrica). Añadir la rama en
   `SourcesViewModel.probe()`.
3. Si el sitio usa un host nuevo cuyo m3u8 no sale por HTTP ni WebView genérico,
   añade su patrón a `HostResolver`.

### Gotchas aprendidos (respétalos)

- **Anime, título romaji**: AniList da el título en inglés ("The Future Diary")
  pero los sitios usan romaji ("mirai-nikki"). `AniListProvider` expone el romaji
  como `originalTitle` y los conectores buscan por ambos. Esto pasa también fuera
  del anime: busca siempre por `title` y `originalTitle`.
- **Emparejar título↔slug** (`connectors/SlugMatch.kt`): todos los conectores de
  raspado eligen el slug con `SlugMatch.best/matches`, **nunca** con "coge el
  primer resultado" ni `contains`. Los buscadores de estos sitios devuelven ruido
  (buscar "House" en pelisplushd trae `barbie-dreamhouse-adventures` primero, y
  `contains("house")` casaba con "dream·house·" → reproducía Barbie). La
  coincidencia es por **segmentos** (`-`): "house" casa con `dr-house`/`house-md`
  pero no con `dreamhouse`, y si nada casa se devuelve **null** (mejor sin enlace
  que uno equivocado). No reintroduzcas el fallback al primer resultado.
- **Duplicados** (misma obra en TMDB y AniList): `MetadataRepository.unifyDuplicates`
  agrupa por título-antes-de-`:` + año y **prefiere la entrada de anime** (para que
  se activen sus fuentes VOSE). No une nombres totalmente distintos (haría falta
  cruce MAL↔TMDB).
- **Cruce anime→TMDB** (`tmdbCrossRef`, gancho en `Graph`, usa
  `TmdbProvider.crossReference`): AniList no trae sinopsis en español ni id de
  IMDb. Al abrir la ficha se cruza **una vez** por título+año con TMDB y de su
  ficha se toma: (a) la **sinopsis** en el idioma del usuario, siempre; (b) el
  **IMDb**, **solo para películas** (`format == MOVIE`). Igual que EPIX PLAY, que
  saca el IMDb de todo por `external_ids`.
- **Películas de anime**: una entrada de AniList con `format == MOVIE` se modela
  **sin temporadas** (para que se reproduzca como película, ruta `/f/{imdb}/` de
  embed69, no como episodio `1x01`) y se le rellena el IMDb del punto anterior.
  Así embed69/addons cubren el hueco que las fuentes de anime (todas por
  episodio) dejaban. En **series** de anime **no** se rellena IMDb a propósito:
  evitaría desajustes de numeración temporada/episodio con embed69/addons; el
  anime en serie ya lo cubren tioanime/monoschinos/latanime/pelisplus por título.
- **Timeouts**: `resolveStreaming` da 40 s por fuente; el ViewModel tiene tope
  global de 46 s (una fuente colgada no debe dejar el indicador cargando eterno).
  Caché **solo de resultados no vacíos** → al refrescar, las que fallaron se
  reintentan y se **acumulan** (no se invalida la caché en refresco).

## Reproducción robusta — implementado

- **SSL trust-all** en el cliente de streaming (`core/Net.kt`,
  `acceptAnyCertificate`): estos CDN usan certificados no confiables; sin esto
  ExoPlayer da `Trust anchor not found` y aborta. (Origen: FilmPlus empaqueta un
  TrustManager; misma necesidad.) El manifest ya permite cleartext.
- **DNS con respaldo DoH** (`core/Net.kt`, `resilientDns`): el ISP suele bloquear
  estos hosts a nivel DNS (el sitio vive, el móvil no resuelve su IP y la fuente
  parece caída). Se intenta el DNS del sistema y, solo si devuelve vacío/lanza, se
  cae a **DNS-over-HTTPS de Cloudflare** (que el ISP no puede interceptar). El caso
  normal no se ve afectado. Calcado de `EpixPlay/DnsManager.java`.
- **Auto-salto de enlace**: `PlaybackRequest` lleva todas las `alternatives` (url +
  headers + container). Ante cualquier error, el player prueba el siguiente enlace
  con ExoPlayer; VLC solo cuando no quedan más.
- **Imagen en negro con sonido → VLC**: muchos animes vienen en **H.264 10-bit
  (High 10)**, que la mayoría de decodificadores por hardware Android **no pintan**
  (se oye el audio pero no hay imagen, y ExoPlayer **no lanza error**, solo no
  renderiza). En `PlayerScreen`, si a los 6 s de estar reproduciendo no ha llegado
  `onRenderedFirstFrame`, se cae al MISMO enlace en **VLC** (software), que sí saca
  el 10-bit. Con motor forzado a ExoPlayer se respeta y se prueba otra fuente.
- **VLC blindado**: `VlcFactory.lib/newPlayer` devuelven null si `LibVLC.<init>`
  lanza (en algunos equipos tira `UnsupportedOperationException` y antes mataba el
  proceso). Si es null, el player salta o muestra error, no crashea.
- La hoja de enlaces (`SourceSheet`) muestra "Buscando servidores… X%" y "puede
  aparecer en más" mientras sigue resolviendo.

## Probar en dispositivo (depuración inalámbrica)

El usuario da IP:puerto + código de emparejamiento. Flujo:
`adb pair <ip>:<pairPort> <code>` → `adb mdns services` (da el puerto de conexión,
distinto) → `adb connect <ip>:<connPort>`. Luego `adb -s <dev> install -r ...`.
El paquete es `app.nexus.debug`. La sesión inalámbrica se cae al dormirse el
móvil y cambia de puerto; hay que re-emparejar.

## Al terminar un cambio

1. Compila con el comando de arriba y confirma `BUILD SUCCESSFUL`.
2. Si tocaste algo visible, dilo claro: no se ha probado en dispositivo salvo
   que el usuario lo confirme.
