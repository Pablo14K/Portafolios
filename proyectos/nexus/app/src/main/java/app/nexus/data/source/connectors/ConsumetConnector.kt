package app.nexus.data.source.connectors

import android.util.Log
import app.nexus.core.Endpoints
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import app.nexus.domain.AudioTag
import app.nexus.domain.MediaKind
import app.nexus.domain.MetaProvider
import app.nexus.domain.Quality
import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import app.nexus.domain.SubtitleTrack
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.Locale

private const val TAG = "Consumet"

/**
 * Fuente sobre una instancia de Consumet (open-source, auto-alojable). Es la via
 * que devuelve enlaces reproducibles sin extractor por-sitio, porque Consumet ya
 * resuelve el m3u8 y los subtitulos.
 *
 * Una misma instancia sirve varios proveedores. Cada fuente registrada fija su
 * [mode] y su [provider], asi que el usuario puede tener varias en paralelo
 * (Zoro, AnimeKai, FlixHQ, Goku...) y quedarse con la que mejor le vaya:
 *  - Anime  -> `/meta/anilist/info` + `/meta/anilist/watch/{ep}` (id de AniList).
 *  - Cine/series -> `/movies/{provider}/{query}` + `/info` + `/watch`.
 *
 * No lanza: ante cualquier fallo devuelve lista vacia y lo registra.
 */
class ConsumetConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    /** Base de la instancia Consumet, sin barra final. */
    baseUrl: String = Endpoints.Consumet.BASE,
    /** Que caminos atiende esta fuente. */
    private val mode: Mode = Mode.BOTH,
    /** Proveedor de anime dentro de meta/anilist (zoro, animekai, anix...). */
    private val animeProvider: String = Endpoints.Consumet.ANIME_PROVIDER,
    /** Proveedor de cine/series (flixhq, goku, sflix...). */
    private val moviesProvider: String = "flixhq"
) : SourceConnector {

    enum class Mode { ANIME, MOVIES, BOTH }

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            val anime = isAnime(request)
            when {
                anime && mode != Mode.MOVIES -> resolveAnime(request)
                !anime && mode != Mode.ANIME -> resolveMovies(request)
                else -> emptyList()
            }
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val ok = when (mode) {
            Mode.ANIME -> get<AniListInfo>("$base/meta/anilist/info/1?provider=$animeProvider") != null
            Mode.MOVIES -> get<FlixSearch>("$base/movies/$moviesProvider/matrix") != null
            Mode.BOTH -> get<FlixSearch>("$base/movies/$moviesProvider/matrix") != null ||
                get<AniListInfo>("$base/meta/anilist/info/1?provider=$animeProvider") != null
        }
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (ok) SourceHealth.Ok(elapsed) else SourceHealth.Failing("La instancia Consumet no responde")
    }

    // --- Anime (meta/anilist) --------------------------------------------

    private fun isAnime(request: StreamRequest): Boolean =
        request.detail.item.kind == MediaKind.ANIME ||
            request.detail.item.id.provider == MetaProvider.ANILIST

    private fun resolveAnime(request: StreamRequest): List<StreamLink> {
        val anilistId = request.detail.item.id
            .takeIf { it.provider == MetaProvider.ANILIST }
            ?.value
            ?: return emptyList()

        val info = get<AniListInfo>(
            "$base/meta/anilist/info/$anilistId?provider=$animeProvider"
        ) ?: return emptyList()

        val wanted = request.episode
        val episode = if (wanted == null) {
            info.episodes.firstOrNull()
        } else {
            info.episodes.firstOrNull { it.number == wanted }
        } ?: return emptyList()

        val sources = get<WatchResponse>(
            "$base/meta/anilist/watch/${encode(episode.id)}"
        ) ?: return emptyList()

        return sources.toLinks(request, episode.title ?: request.detail.item.title)
    }

    // --- Cine y series (movies/{provider}) -------------------------------

    private fun resolveMovies(request: StreamRequest): List<StreamLink> {
        val title = request.detail.item.title
        val results = get<FlixSearch>("$base/movies/$moviesProvider/${encode(title)}")
            ?.results
            .orEmpty()
        if (results.isEmpty()) return emptyList()

        val wantSeries = request.isEpisode
        val match = results.firstOrNull { candidate ->
            titleMatches(candidate.title, title) &&
                isSeries(candidate.type) == wantSeries &&
                yearOk(candidate.releaseDate, request.detail.item.year)
        } ?: results.firstOrNull { titleMatches(it.title, title) }
        ?: return emptyList()

        val info = get<FlixInfo>("$base/movies/$moviesProvider/info?id=${encode(match.id)}")
            ?: return emptyList()

        val episode = if (wantSeries) {
            info.episodes.firstOrNull {
                it.number == request.episode && (it.season == null || it.season == request.season)
            }
        } else {
            info.episodes.firstOrNull()
        } ?: return emptyList()

        val watch = get<WatchResponse>(
            "$base/movies/$moviesProvider/watch?episodeId=${encode(episode.id)}&mediaId=${encode(match.id)}"
        ) ?: return emptyList()

        return watch.toLinks(request, match.title ?: title)
    }

    // --- Comun -----------------------------------------------------------

    private fun WatchResponse.toLinks(request: StreamRequest, label: String): List<StreamLink> {
        val headers = this.headers.orEmpty()
        val subs = subtitles
            .filter { !it.url.isNullOrBlank() && !it.lang.equals("thumbnails", true) }
            .mapNotNull { s ->
                val url = s.url ?: return@mapNotNull null
                SubtitleTrack(url = url, language = s.lang ?: "und", label = s.lang ?: "Sub")
            }

        return sources
            .filter { !it.url.isNullOrBlank() }
            .map { source ->
                val url = source.url!!
                val hls = source.isM3U8 == true || url.contains(".m3u8", true)
                StreamLink(
                    url = url,
                    sourceId = id,
                    sourceName = displayName,
                    container = if (hls) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
                    quality = Quality.fromLabel(source.quality).takeIf { it != Quality.UNKNOWN }
                        ?: qualityFrom(request),
                    audio = AudioTag.SUBTITULADO.takeIf { subs.isNotEmpty() } ?: AudioTag.ORIGINAL,
                    title = "$label · ${source.quality ?: if (hls) "auto" else "mp4"}",
                    headers = headers,
                    subtitleUrls = subs
                )
            }
    }

    private fun qualityFrom(request: StreamRequest): Quality =
        if (request.detail.item.kind == MediaKind.ANIME) Quality.HD_720 else Quality.FHD_1080

    private inline fun <reified T> get(url: String): T? = runCatching {
        val httpUrl = url.toHttpUrlOrNull() ?: return null
        val req = Request.Builder()
            .url(httpUrl)
            .header("User-Agent", Net.DEFAULT_USER_AGENT)
            .header("Accept", "application/json")
            .build()
        client.newCall(req).execute().use { response ->
            if (!response.isSuccessful) return null
            val text = response.body?.string() ?: return null
            Net.json.decodeFromString<T>(text)
        }
    }.onFailure { Log.w(TAG, "$id: ${it.message}") }.getOrNull()

    private fun encode(value: String): String =
        java.net.URLEncoder.encode(value, "UTF-8").replace("+", "%20")

    private fun titleMatches(candidate: String?, wanted: String): Boolean {
        if (candidate == null) return false
        return normalize(candidate) == normalize(wanted) ||
            normalize(candidate).contains(normalize(wanted))
    }

    private fun isSeries(type: String?): Boolean {
        val t = type?.lowercase(Locale.ROOT).orEmpty()
        return t.contains("tv") || t.contains("series")
    }

    private fun yearOk(releaseDate: String?, year: Int?): Boolean {
        if (year == null || releaseDate.isNullOrBlank()) return true
        val found = Regex("(19|20)\\d{2}").find(releaseDate)?.value?.toIntOrNull() ?: return true
        return kotlin.math.abs(found - year) <= 1
    }

    private fun normalize(text: String): String = text
        .lowercase(Locale.ROOT)
        .replace(Regex("[._\\-\\[\\]()|:]+"), " ")
        .replace("á", "a").replace("é", "e").replace("í", "i")
        .replace("ó", "o").replace("ú", "u").replace("ñ", "n")
        .replace(Regex("\\s+"), " ")
        .trim()
}

// --- Respuestas de Consumet (campos comunes a todas sus versiones) --------

@Serializable
private data class AniListInfo(val episodes: List<AniListEpisode> = emptyList())

@Serializable
private data class AniListEpisode(
    val id: String,
    val number: Int? = null,
    val title: String? = null
)

@Serializable
private data class FlixSearch(val results: List<FlixResult> = emptyList())

@Serializable
private data class FlixResult(
    val id: String,
    val title: String? = null,
    val type: String? = null,
    val releaseDate: String? = null
)

@Serializable
private data class FlixInfo(val episodes: List<FlixEpisode> = emptyList())

@Serializable
private data class FlixEpisode(
    val id: String,
    val number: Int? = null,
    val season: Int? = null,
    val title: String? = null
)

@Serializable
private data class WatchResponse(
    val headers: Map<String, String>? = null,
    val sources: List<WatchSource> = emptyList(),
    val subtitles: List<WatchSubtitle> = emptyList()
)

@Serializable
private data class WatchSource(
    val url: String? = null,
    val quality: String? = null,
    val isM3U8: Boolean? = null
)

@Serializable
private data class WatchSubtitle(
    val url: String? = null,
    val lang: String? = null
)
