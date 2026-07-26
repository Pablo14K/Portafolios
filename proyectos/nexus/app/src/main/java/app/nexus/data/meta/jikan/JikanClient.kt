package app.nexus.data.meta.jikan

import android.util.Log
import app.nexus.core.Net
import app.nexus.domain.Episode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import okhttp3.OkHttpClient
import okhttp3.Request

private const val TAG = "Jikan"
private const val BASE = "https://api.jikan.moe/v4"

/**
 * Jikan, la API publica de MyAnimeList. Sin clave ni registro.
 *
 * Se usa solo para una cosa que AniList no da: el titulo real de cada episodio.
 * Es un servicio comunitario y se cae a ratos (devuelve 504), asi que nunca es
 * la fuente principal: si no contesta, el anime sigue funcionando con los
 * episodios numerados de AniList.
 */
class JikanClient(private val client: OkHttpClient) {

    /**
     * @param malId el id de MyAnimeList, que AniList ya entrega en `idMal`.
     *              Asi no hay que adivinar la correspondencia por titulo.
     */
    suspend fun episodes(malId: Int, expected: Int): List<Episode> =
        withContext(Dispatchers.IO) {
            val collected = mutableListOf<JikanEpisode>()
            var page = 1

            // Jikan pagina de 100 en 100 y limita a unas pocas peticiones por
            // segundo; con dos paginas se cubre casi cualquier serie.
            while (page <= 3) {
                val response = get<EpisodesResponse>("$BASE/anime/$malId/episodes?page=$page")
                    ?: break
                collected += response.data
                if (response.pagination?.hasNextPage != true) break
                page++
            }

            if (collected.isEmpty()) return@withContext emptyList()

            collected.mapIndexed { index, episode ->
                Episode(
                    seasonNumber = 1,
                    episodeNumber = episode.malId ?: (index + 1),
                    title = episode.title
                        ?: episode.titleRomaji
                        ?: "Episodio ${episode.malId ?: index + 1}",
                    airDate = episode.aired?.take(10)
                )
            }.let { list ->
                // Si Jikan trae menos de los que dice AniList, se completan
                // numerados para que no falten filas en la ficha.
                if (expected > list.size) {
                    list + ((list.size + 1)..expected).map {
                        Episode(seasonNumber = 1, episodeNumber = it, title = "Episodio $it")
                    }
                } else {
                    list
                }
            }
        }

    private inline fun <reified T> get(url: String): T? = runCatching {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", Net.DEFAULT_USER_AGENT)
            .header("Accept", "application/json")
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                Log.i(TAG, "no disponible (${response.code}); se usan episodios numerados")
                return null
            }
            val text = response.body?.string() ?: return null
            Net.json.decodeFromString<T>(text)
        }
    }.onFailure { Log.i(TAG, "sin respuesta: ${it.message}") }.getOrNull()
}

@Serializable
private data class EpisodesResponse(
    val data: List<JikanEpisode> = emptyList(),
    val pagination: JikanPagination? = null
)

@Serializable
private data class JikanPagination(
    @SerialName("has_next_page") val hasNextPage: Boolean? = null
)

@Serializable
private data class JikanEpisode(
    @SerialName("mal_id") val malId: Int? = null,
    val title: String? = null,
    @SerialName("title_romanji") val titleRomaji: String? = null,
    val aired: String? = null,
    val filler: Boolean = false,
    val recap: Boolean = false
)
