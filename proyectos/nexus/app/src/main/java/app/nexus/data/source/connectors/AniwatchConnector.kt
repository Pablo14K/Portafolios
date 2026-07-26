package app.nexus.data.source.connectors

import android.util.Log
import app.nexus.core.Endpoints
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import app.nexus.domain.AudioTag
import app.nexus.domain.MediaKind
import app.nexus.domain.Quality
import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import app.nexus.domain.SubtitleTrack
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.Locale

private const val TAG = "Aniwatch"

/**
 * Segundo backend de anime, independiente de Consumet: aniwatch-api (hianime),
 * tambien open-source y auto-alojable. Da una opcion mas cuando Zoro/AnimeKai
 * no traen el episodio.
 *
 * A diferencia de Consumet, no parte del id de AniList: busca por titulo y casa
 * el resultado. Flujo estandar de aniwatch-api v2:
 *   search -> anime/{id}/episodes -> episode/sources.
 */
class AniwatchConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    baseUrl: String = Endpoints.Consumet.ANIWATCH_BASE
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    private val base: String = baseUrl.trim().removeSuffix("/")

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            // Solo anime: para cine y series estan las fuentes de Consumet.
            if (request.detail.item.kind != MediaKind.ANIME) return@withContext emptyList()

            val title = request.detail.item.title
            val search = get<SearchData>("$base/api/v2/hianime/search?q=${encode(title)}")
                ?: return@withContext emptyList()
            val match = search.data.animes.firstOrNull { titleMatches(it.name, title) }
                ?: search.data.animes.firstOrNull()
                ?: return@withContext emptyList()

            val episodesData = get<EpisodesData>("$base/api/v2/hianime/anime/${encode(match.id)}/episodes")
                ?: return@withContext emptyList()
            val wanted = request.episode
            val episode = if (wanted == null) {
                episodesData.data.episodes.firstOrNull()
            } else {
                episodesData.data.episodes.firstOrNull { it.number == wanted }
            } ?: return@withContext emptyList()

            // Se pide subtitulado; el respaldo a doblaje lo decide el usuario en
            // el catalogo. Se prueban servidores en orden hasta que uno responda.
            val links = mutableListOf<StreamLink>()
            for (category in listOf("sub", "dub")) {
                val sources = get<SourcesData>(
                    "$base/api/v2/hianime/episode/sources" +
                        "?animeEpisodeId=${encode(episode.episodeId)}&category=$category"
                ) ?: continue
                links += sources.toLinks(episode.title ?: title, category)
                if (links.isNotEmpty()) break
            }
            links
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val ok = get<SearchData>("$base/api/v2/hianime/search?q=naruto") != null
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (ok) SourceHealth.Ok(elapsed) else SourceHealth.Failing("aniwatch-api no responde")
    }

    private fun SourcesData.toLinks(label: String, category: String): List<StreamLink> {
        val header = mapOf("Referer" to (headers?.referer ?: "https://hianime.to/"))
        val subs = tracks
            .filter { it.kind.equals("captions", true) || it.kind.equals("subtitles", true) }
            .mapNotNull { t ->
                val url = t.file ?: return@mapNotNull null
                SubtitleTrack(url = url, language = t.label ?: "und", label = t.label ?: "Sub")
            }
        val audio = if (category == "dub") AudioTag.DUAL else AudioTag.SUBTITULADO
        return sources
            .filter { !it.url.isNullOrBlank() }
            .map { s ->
                val url = s.url!!
                val hls = s.type.equals("hls", true) || url.contains(".m3u8", true)
                StreamLink(
                    url = url,
                    sourceId = id,
                    sourceName = displayName,
                    container = if (hls) StreamContainer.HLS else StreamContainer.PROGRESSIVE,
                    quality = Quality.HD_720,
                    audio = audio,
                    title = "$label · ${category.uppercase(Locale.ROOT)}",
                    headers = header,
                    subtitleUrls = subs
                )
            }
    }

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
        return normalize(candidate).contains(normalize(wanted)) ||
            normalize(wanted).contains(normalize(candidate))
    }

    private fun normalize(text: String): String = text
        .lowercase(Locale.ROOT)
        .replace(Regex("[._\\-\\[\\]()|:]+"), " ")
        .replace(Regex("\\s+"), " ")
        .trim()
}

// --- Respuestas de aniwatch-api v2 ----------------------------------------

@Serializable
private data class SearchData(val data: SearchInner = SearchInner())

@Serializable
private data class SearchInner(val animes: List<AniwatchAnime> = emptyList())

@Serializable
private data class AniwatchAnime(val id: String, val name: String? = null)

@Serializable
private data class EpisodesData(val data: EpisodesInner = EpisodesInner())

@Serializable
private data class EpisodesInner(val episodes: List<AniwatchEpisode> = emptyList())

@Serializable
private data class AniwatchEpisode(
    val episodeId: String,
    val number: Int? = null,
    val title: String? = null
)

@Serializable
private data class SourcesData(
    val data: SourcesInner = SourcesInner()
) {
    val sources get() = data.sources
    val tracks get() = data.tracks
    val headers get() = data.headers
}

@Serializable
private data class SourcesInner(
    val sources: List<AniwatchSource> = emptyList(),
    val tracks: List<AniwatchTrack> = emptyList(),
    val headers: AniwatchHeaders? = null
)

@Serializable
private data class AniwatchSource(val url: String? = null, val type: String? = null)

@Serializable
private data class AniwatchTrack(
    val file: String? = null,
    val label: String? = null,
    val kind: String? = null
)

@Serializable
private data class AniwatchHeaders(@SerialName("Referer") val referer: String? = null)
