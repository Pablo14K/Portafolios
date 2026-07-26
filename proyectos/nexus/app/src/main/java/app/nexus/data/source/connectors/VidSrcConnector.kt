package app.nexus.data.source.connectors

import android.content.Context
import android.util.Log
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import app.nexus.data.source.extract.HostLink
import app.nexus.data.source.extract.WebViewResolver
import app.nexus.domain.AudioTag
import app.nexus.domain.Quality
import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

private const val TAG = "VidSrc"

/**
 * Segunda fuente de **cine y series por id de IMDb**, independiente de embed69,
 * como redundancia: si embed69 no responde o no tiene un título, este suele
 * cubrirlo (y viceversa). Cubre también las **películas de anime**, que ahora
 * llevan IMDb (ver el cruce TMDB de `AniListProvider`).
 *
 * VidSrc no expone el enlace: encadena varios iframes ofuscados
 * (`vidsrc.to → vsembed → cloudnestra /rcp/ → /prorcp/`) hasta un player que pide
 * el `.m3u8`. En vez de perseguir esa cadena por HTTP -cambia a menudo-, se carga
 * el embed en el [WebViewResolver], que **espía la petición del m3u8 a través de
 * los iframes anidados** y roba sus cabeceras/cookie. Por eso es puramente
 * WebView (lento): va como fuente secundaria.
 *
 * El dominio de VidSrc rota; `baseUrl` es configurable desde Ajustes para
 * repuntarlo (vidsrc.to / vidsrc.net / vidsrc.xyz...) sin recompilar.
 */
class VidSrcConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    private val context: Context,
    baseUrl: String = "https://vidsrc.to"
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            // Todo en VidSrc se pide por IMDb. Sin él no hay nada que consultar; el
            // anime en serie (que a propósito no lleva IMDb) queda fuera, y solo
            // entran cine, series y películas de anime.
            val imdb = request.detail.imdbId?.takeIf { it.startsWith("tt") }
                ?: return@withContext emptyList()

            val embedUrl = if (request.isEpisode) {
                "$base/embed/tv/$imdb/${request.season}/${request.episode}"
            } else {
                "$base/embed/movie/$imdb"
            }

            val links = WebViewResolver.resolve(
                context = context,
                embedUrl = embedUrl,
                timeoutMs = 15_000,
                referer = "$base/"
            )
            Log.i(TAG, "$id imdb=$imdb episodio=${request.isEpisode} enlaces=${links.size}")
            links.map { toLink(it) }
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val ok = runCatching {
            val bounded = client.newBuilder().callTimeout(8, TimeUnit.SECONDS).build()
            val request = Request.Builder()
                .url("$base/")
                .header("User-Agent", Net.DEFAULT_USER_AGENT)
                .build()
            bounded.newCall(request).execute().use { it.isSuccessful }
        }.getOrDefault(false)
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (ok) SourceHealth.Ok(elapsed) else SourceHealth.Failing("vidsrc no responde")
    }

    private fun toLink(l: HostLink) = StreamLink(
        url = l.url,
        sourceId = id,
        sourceName = displayName,
        container = if (l.isM3u8) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
        quality = l.quality.takeIf { it != Quality.UNKNOWN } ?: Quality.HD_720,
        // VidSrc mezcla pistas (latino/original según el título); sin dato fiable
        // se deja como desconocido, que los filtros de audio dejan pasar siempre.
        audio = AudioTag.DESCONOCIDO,
        title = "VidSrc",
        headers = l.headers
    )
}
