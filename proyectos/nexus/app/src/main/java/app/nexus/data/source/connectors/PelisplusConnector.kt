package app.nexus.data.source.connectors

import android.util.Log
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import android.content.Context
import app.nexus.data.source.extract.Embed69Decoder
import app.nexus.data.source.extract.HostResolver
import app.nexus.data.source.extract.WebViewResolver
import app.nexus.domain.AudioTag
import app.nexus.domain.MediaKind
import app.nexus.domain.Quality
import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit
import java.util.Locale

private const val TAG = "Pelisplus"

/**
 * Fuente de episodios (series y anime) por raspado de pelisplushd. Complementa a
 * [Embed69Connector], que solo cubre peliculas: aqui el sitio delega la lista de
 * servidores en xupalace, que a su vez apunta a los mismos hosts de video
 * (VidHide, StreamWish, Voe...). El m3u8 final lo saca [HostResolver].
 *
 * Cadena: buscar el slug -> pagina del capitulo -> xupalace -> hosts -> m3u8.
 */
class PelisplusConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    private val context: Context,
    baseUrl: String = "https://pelisplushd.bz"
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    private val bounded: OkHttpClient by lazy {
        client.newBuilder().callTimeout(8, TimeUnit.SECONDS).build()
    }

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            // Episodios de series y anime. Las peliculas ya las cubre embed69.
            // Para series suma redundancia (embed69 + los hosts de xupalace); para
            // anime es la via principal, ya que AniList no trae IMDb.
            if (!request.isEpisode) return@withContext emptyList()

            val section = if (request.detail.item.kind == MediaKind.ANIME) "anime" else "serie"
            val slug = findSlug(request.detail.item.title, section)
                ?: request.detail.item.originalTitle?.let { findSlug(it, section) }
                ?: return@withContext emptyList()

            val season = request.season ?: 1
            val episode = request.episode ?: 1
            val epUrl = "$base/$section/$slug/temporada/$season/capitulo/$episode"
            val html = fetch(epUrl) ?: return@withContext emptyList()

            val hosts = collectHosts(html).take(8)
            Log.i(TAG, "$id slug=$slug hosts=${hosts.size}")
            if (hosts.isEmpty()) return@withContext emptyList()

            // Igual que embed69: devolver en cuanto un par de hosts respondan.
            coroutineScope {
                val deferreds = hosts.map { host ->
                    async {
                        val links = withTimeoutOrNull(6_000) {
                            HostResolver.resolve(bounded, host.url)
                        } ?: emptyList()
                        links.map { l ->
                            StreamLink(
                                url = l.url,
                                sourceId = id,
                                sourceName = displayName,
                                container = if (l.isM3u8) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
                                quality = l.quality,
                                audio = host.audio,
                                title = "${host.name} · ${host.language}",
                                headers = l.headers
                            )
                        }
                    }
                }
                val collected = mutableListOf<StreamLink>()
                withTimeoutOrNull(9_000) {
                    for (d in deferreds) {
                        collected += runCatching { d.await() }.getOrDefault(emptyList())
                        if (collected.size >= 2) break
                    }
                }
                deferreds.forEach { it.cancel() }
                collected.ifEmpty { webViewFallback(hosts) }
            }
        }

    /** Respaldo por WebView cuando el HTTP no saca nada (ver Embed69Connector). */
    private suspend fun webViewFallback(hosts: List<Host>): List<StreamLink> {
        for (host in hosts.take(1)) {
            val links = WebViewResolver.resolve(context, host.url, timeoutMs = 7_000)
            if (links.isNotEmpty()) {
                return links.map { l ->
                    StreamLink(
                        url = l.url,
                        sourceId = id,
                        sourceName = displayName,
                        container = if (l.isM3u8) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
                        quality = l.quality,
                        audio = host.audio,
                        title = "${host.name} · ${host.language}",
                        headers = l.headers
                    )
                }
            }
        }
        return emptyList()
    }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val ok = fetch("$base/") != null
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (ok) SourceHealth.Ok(elapsed) else SourceHealth.Failing("pelisplushd no responde")
    }

    // --- Raspado ----------------------------------------------------------

    private data class Host(val name: String, val url: String, val language: String) {
        val audio: AudioTag
            get() = when {
                language.contains("lat", true) -> AudioTag.LATINO
                language.contains("cast", true) || language.contains("esp", true) -> AudioTag.CASTELLANO
                language.contains("sub", true) -> AudioTag.SUBTITULADO
                else -> AudioTag.DESCONOCIDO
            }
    }

    /**
     * Encuentra el slug del titulo. Los animes de AniList llegan con titulo
     * ingles (p. ej. "Frieren: Beyond Journey's End") y pelisplushd los indexa en
     * espanol/romaji, asi que se busca por el titulo principal (antes de ':') y se
     * casa de forma flexible.
     */
    private fun findSlug(title: String, section: String): String? {
        val main = title.substringBefore(':').substringBefore('(').trim().ifBlank { title }
        val html = fetch("$base/search?s=${encode(main)}") ?: return null
        val slugs = Regex("""href="https://[^"]*/$section/([^"/]+)"""")
            .findAll(html)
            .map { it.groupValues[1] }
            .distinct()
            .toList()
        // Elige el slug que de verdad corresponde, o null (nunca "el primero":
        // buscar "House" aqui devuelve "barbie-dreamhouse" el primero).
        return SlugMatch.best(slugs, title)
    }

    /**
     * Reune los hosts del capitulo. El sitio mete cada servidor en `video[n]`.
     * Segun el titulo, ese enlace puede ser:
     *  - embed69: lista cifrada de hosts -> se descifra con [Embed69Decoder].
     *  - xupalace: lista los hosts en `go_to_playerVast('URL', ...)`.
     *  - un host de video directo.
     */
    private fun collectHosts(html: String): List<Host> {
        val lang = detectLanguage(html)
        val out = LinkedHashSet<Host>()

        Regex("""video\[\d+\]\s*=\s*'([^']+)'""").findAll(html).forEach { m ->
            val url = m.groupValues[1]
            when {
                url.contains("embed69", true) -> {
                    Embed69Decoder.decode(bounded, url, "$base/").forEach { e ->
                        out += Host(e.server, e.url, e.language.ifBlank { lang })
                    }
                }

                url.contains("xupalace", true) || url.contains("/player", true) -> {
                    fetch(url)?.let { agg ->
                        Regex("""go_to_player\w*\('([^']+)'""").findAll(agg).forEach { g ->
                            out += Host(hostName(g.groupValues[1]), g.groupValues[1], lang)
                        }
                    }
                }

                else -> out += Host(hostName(url), url, lang)
            }
        }
        return out.toList()
    }

    private fun detectLanguage(html: String): String {
        val t = html.lowercase(Locale.ROOT)
        return when {
            t.contains("latino") -> "LAT"
            t.contains("castellano") || t.contains("español") -> "CAST"
            t.contains("subtitul") -> "SUB"
            else -> ""
        }
    }

    private fun hostName(url: String): String =
        Regex("""https?://(?:www\.)?([^./]+)""").find(url)?.groupValues?.get(1) ?: "host"

    private fun encode(value: String): String =
        java.net.URLEncoder.encode(value, "UTF-8").replace("+", "%20")

    private fun fetch(url: String): String? = runCatching {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", Net.DEFAULT_USER_AGENT)
            .header("Referer", "$base/")
            .header("Accept", "text/html,application/xhtml+xml,*/*")
            .build()
        bounded.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return null
            response.body?.string()
        }
    }.onFailure { Log.w(TAG, "$id: ${it.message}") }.getOrNull()
}
