package app.nexus.data.source.connectors

import android.content.Context
import android.util.Base64
import android.util.Log
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
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
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

private const val TAG = "Latanime"

/**
 * Tercera fuente de anime, la que aporta lo que tioanime y monoschinos no dan:
 * **audio latino y castellano** (además del subtitulado). Latanime cataloga cada
 * obra por variante de idioma (`...-latino`, `...-castellano`, `...-sub-espanol`),
 * así que el sufijo del slug ya dice qué pista trae el enlace.
 *
 * Estructura verificada en el sitio (idéntica a monoschinos): la página del
 * capítulo lista cada servidor en `data-player="<base64>"`, donde el base64 es
 * directamente la URL del embed (voe, filemoon, mxdrop, mp4upload, lulu...). Se
 * decodifica y se resuelve con [HostResolver]/[WebViewResolver].
 */
class LatanimeConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    private val context: Context,
    baseUrl: String = "https://latanime.org"
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    private val bounded: OkHttpClient by lazy {
        client.newBuilder().callTimeout(8, TimeUnit.SECONDS).build()
    }

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            if (!request.isEpisode || request.detail.item.kind != MediaKind.ANIME) {
                return@withContext emptyList()
            }

            val slug = findSlug(request.detail.item.title)
                ?: request.detail.item.originalTitle?.let { findSlug(it) }
                ?: return@withContext emptyList()
            val audio = audioFor(slug)
            val episode = request.episode ?: 1
            // El slug del catálogo ya lleva el sufijo de idioma; el del capítulo es
            // el mismo + `-episodio-N` (comprobado: no hay que recortar nada).
            val html = fetch("$base/ver/$slug-episodio-$episode") ?: return@withContext emptyList()

            val servers = parseServers(html)
            Log.i(TAG, "$id slug=$slug audio=$audio servers=${servers.size}")
            if (servers.isEmpty()) return@withContext emptyList()

            coroutineScope {
                val deferreds = servers.take(6).map { server ->
                    async {
                        val links = withTimeoutOrNull(6_000) { HostResolver.resolve(bounded, server.url) }
                            ?: emptyList()
                        links.map { toLink(server, it, audio) }
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
                collected.ifEmpty { webViewFallback(servers, audio) }
            }
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val ok = fetch("$base/") != null
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (ok) SourceHealth.Ok(elapsed) else SourceHealth.Failing("latanime no responde")
    }

    private suspend fun webViewFallback(servers: List<Server>, audio: AudioTag): List<StreamLink> {
        for (server in servers.take(1)) {
            val links = WebViewResolver.resolve(context, server.url, timeoutMs = 7_000)
            if (links.isNotEmpty()) return links.map { toLink(server, it, audio) }
        }
        return emptyList()
    }

    private fun toLink(server: Server, l: app.nexus.data.source.extract.HostLink, audio: AudioTag) = StreamLink(
        url = l.url,
        sourceId = id,
        sourceName = displayName,
        container = if (l.isM3u8) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
        quality = l.quality.takeIf { it != Quality.UNKNOWN } ?: Quality.HD_720,
        audio = audio,
        title = "${server.name} · ${audio.label}",
        headers = l.headers
    )

    // --- Raspado ----------------------------------------------------------

    private data class Server(val name: String, val url: String)

    private fun parseServers(html: String): List<Server> =
        Regex("""data-player="([A-Za-z0-9+/=]+)"""")
            .findAll(html)
            .mapNotNull { m ->
                val url = runCatching { String(Base64.decode(m.groupValues[1], Base64.DEFAULT)) }
                    .getOrNull()
                    ?.takeIf { it.startsWith("http") } ?: return@mapNotNull null
                Server(hostName(url), url)
            }
            // Mega va cifrado y no se puede extraer; el resto sí.
            .filter { !it.url.contains("mega.nz", true) }
            .distinctBy { it.url }
            .toList()

    /** El sufijo del slug indica la pista de audio de esa variante. */
    private fun audioFor(slug: String): AudioTag = when {
        slug.endsWith("-latino") -> AudioTag.LATINO
        slug.endsWith("-castellano") -> AudioTag.CASTELLANO
        else -> AudioTag.SUBTITULADO
    }

    private fun findSlug(title: String): String? {
        val main = title.substringBefore(':').substringBefore('(').trim().ifBlank { title }
        val html = fetch("$base/buscar?q=${encode(main)}") ?: return null
        val slugs = Regex("""href="[^"]*/anime/([^"/]+)"""")
            .findAll(html)
            .map { it.groupValues[1] }
            .distinct()
            .toList()
        // Solo los que de verdad casan (sin "coger el primero", que traia otra
        // obra); entre ellos se prefiere latino y luego castellano.
        val matches = SlugMatch.matches(slugs, title)
        return matches.firstOrNull { it.endsWith("-latino") }
            ?: matches.firstOrNull { it.endsWith("-castellano") }
            ?: matches.firstOrNull()
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
