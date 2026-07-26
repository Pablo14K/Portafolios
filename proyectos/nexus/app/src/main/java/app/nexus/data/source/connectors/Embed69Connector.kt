package app.nexus.data.source.connectors

import android.content.Context
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import app.nexus.data.source.extract.DecodedEmbed
import app.nexus.data.source.extract.Embed69Decoder
import app.nexus.data.source.extract.HostResolver
import app.nexus.data.source.extract.WebViewResolver
import app.nexus.domain.AudioTag
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
import java.util.concurrent.TimeUnit
import java.util.Locale

/**
 * Resuelve enlaces a traves de embed69.org, el resolutor por id de IMDb que usan
 * varios sitios en espanol (pelisplushd, etc.). No hay que scrapear cada sitio:
 * embed69 ya reune los hosts (VidHide, StreamWish, Voe...) de un titulo.
 *
 * Cubre peliculas (`/f/{imdb}/`) y episodios (`/f/{imdb}-{T}x{EP}/`, con el
 * episodio a dos digitos). El descifrado (prueba de trabajo + AES) vive en
 * [Embed69Decoder]; aqui solo se arma la URL y se resuelve cada host a m3u8.
 */
class Embed69Connector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    private val context: Context,
    baseUrl: String = "https://embed69.org"
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    /**
     * Cliente acotado: una llamada nunca cuelga indefinidamente. 15s (no 8) porque
     * la pagina /f/ de embed69 va tras Cloudflare y en movil midio ~7,6s: con 8s se
     * pasaba por poco y daba "timeout" -> "sin resultados". El registro global
     * (40s por fuente) sigue acotando el total.
     */
    private val bounded: OkHttpClient by lazy {
        client.newBuilder().callTimeout(15, TimeUnit.SECONDS).build()
    }

    /** Hosts que el desempaquetador resuelve bien van primero. */
    private val hostRank = listOf(
        "vidhide", "streamwish", "filelions", "vidhidepro", "streamruby",
        "dood", "doodstream", "mixdrop", "voe", "rapidvideo"
    )

    private companion object {
        /** Hosts con JS/reto que solo el WebView resuelve. */
        val WEBVIEW_HOSTS = setOf(
            "voe", "streamwish", "filemoon", "doodstream", "dood",
            "streamtape", "vidhide", "filelions", "mixdrop", "rapidvideo"
        )
    }

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            val imdb = request.detail.imdbId?.takeIf { it.startsWith("tt") }
                ?: return@withContext emptyList()

            val path = if (request.isEpisode) {
                "$imdb-${request.season}x${"%02d".format(Locale.ROOT, request.episode)}"
            } else {
                imdb
            }

            val embeds = Embed69Decoder
                .decode(bounded, "$base/f/$path/", "https://pelisplushd.bz/")
                .sortedBy { e -> hostRank.indexOf(e.server.lowercase(Locale.ROOT)).let { if (it < 0) 99 else it } }
            if (embeds.isEmpty()) return@withContext emptyList()

            val fast = resolveHosts(embeds.take(8)) { embed, host -> toLink(embed, host) }
            fast.ifEmpty { webViewFallback(embeds) { embed, host -> toLink(embed, host) } }
        }

    /**
     * Cuando el raspado por HTTP no saca ningun enlace (VidHide caido para ese
     * titulo), se recurre al WebView, que si resuelve los hosts con JS/reto (Voe,
     * StreamWish, Filemoon...). Es lento, asi que solo se intentan los mejores
     * candidatos y de uno en uno.
     */
    private suspend fun webViewFallback(
        embeds: List<DecodedEmbed>,
        build: (DecodedEmbed, app.nexus.data.source.extract.HostLink) -> StreamLink
    ): List<StreamLink> {
        val candidates = embeds.filter { it.server.lowercase(Locale.ROOT) in WEBVIEW_HOSTS }.take(2)
        for (embed in candidates) {
            val hosts = WebViewResolver.resolve(context, embed.url, timeoutMs = 7_000)
            if (hosts.isNotEmpty()) return hosts.map { build(embed, it) }
        }
        return emptyList()
    }

    /**
     * Resuelve los hosts en paralelo pero devuelve en cuanto hay un par de
     * enlaces buenos, sin esperar a los hosts que cuelgan. Como [embeds] viene
     * ordenado por fiabilidad (VidHide primero), los primeros en completar son
     * los que de verdad reproducen.
     */
    private suspend fun resolveHosts(
        embeds: List<DecodedEmbed>,
        build: (DecodedEmbed, app.nexus.data.source.extract.HostLink) -> StreamLink
    ): List<StreamLink> = coroutineScope {
        val deferreds = embeds.map { embed ->
            async {
                val hosts = withTimeoutOrNull(6_000) { HostResolver.resolve(bounded, embed.url) }
                    ?: emptyList()
                hosts.map { host -> build(embed, host) }
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
        collected.toList()
    }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        // Matrix (tt0133093) siempre esta: sirve de sonda.
        val embeds = Embed69Decoder.decode(bounded, "$base/f/tt0133093/", "https://pelisplushd.bz/")
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (embeds.isNotEmpty()) SourceHealth.Ok(elapsed) else SourceHealth.Failing("embed69 no dio enlaces")
    }

    private fun toLink(embed: DecodedEmbed, host: app.nexus.data.source.extract.HostLink) = StreamLink(
        url = host.url,
        sourceId = id,
        sourceName = displayName,
        container = if (host.isM3u8) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
        quality = host.quality,
        audio = audioFrom(embed.language),
        title = "${embed.server} · ${embed.language}",
        headers = host.headers
    )

    private fun audioFrom(language: String): AudioTag = when (language.uppercase(Locale.ROOT)) {
        "LAT", "LATINO" -> AudioTag.LATINO
        "CAST", "ESP", "CASTELLANO" -> AudioTag.CASTELLANO
        "SUB", "VOSE", "SUBTITULADO" -> AudioTag.SUBTITULADO
        "ENG", "ORIGINAL", "VO" -> AudioTag.ORIGINAL
        else -> AudioTag.DESCONOCIDO
    }
}
