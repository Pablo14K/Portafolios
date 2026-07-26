package app.nexus.data.meta.anilist

import app.nexus.core.Net
import app.nexus.data.meta.MetadataProvider
import app.nexus.data.meta.jikan.JikanClient
import app.nexus.domain.CatalogSection
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import app.nexus.domain.MetaProvider
import app.nexus.domain.Season
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

private const val ENDPOINT = "https://graphql.anilist.co"

/**
 * Anime. No pide clave ni registro, asi que la app funciona nada mas instalarla
 * aunque el usuario todavia no haya puesto la clave de TMDB.
 */
class AniListProvider(
    private val client: OkHttpClient,
    private val preferRomaji: () -> Boolean,
    /** Complemento opcional: solo aporta titulos de episodio. */
    private val jikan: JikanClient,
    /**
     * Cruce opcional con TMDB. AniList no trae sinopsis en español ni id de IMDb;
     * este gancho localiza la obra en TMDB y devuelve su ficha, de la que se toma
     * la sinopsis en el idioma del usuario y -solo para películas- el IMDb, para
     * que las fuentes que van por IMDb (embed69, addons) puedan resolverlas.
     * Devuelve null si no hay coincidencia, y entonces se conserva lo de AniList.
     */
    private val tmdbCrossRef: suspend (title: String, original: String?, year: Int?) -> MediaDetail? =
        { _, _, _ -> null }
) : MetadataProvider {

    override val id: String = "anilist"

    override suspend fun search(query: String, limit: Int): List<MediaItem> {
        val vars = buildJsonObject {
            put("search", query)
            put("perPage", limit)
        }
        val page = post<SearchResponse>(SEARCH_QUERY, vars)?.data?.page ?: return emptyList()
        return page.media.map { it.toItem() }
    }

    override suspend fun trending(limit: Int): List<MediaItem> {
        val vars = buildJsonObject { put("perPage", limit) }
        val page = post<SearchResponse>(TRENDING_QUERY, vars)?.data?.page ?: return emptyList()
        return page.media.map { it.toItem() }
    }

    override val sections: Set<CatalogSection> = setOf(
        CatalogSection.ANIME_TRENDING,
        CatalogSection.ANIME_SEASON,
        CatalogSection.ANIME_TOP
    )

    override suspend fun catalog(section: CatalogSection, page: Int): List<MediaItem> =
        when (section) {
            CatalogSection.ANIME_TRENDING -> query(
                TRENDING_QUERY,
                buildJsonObject {
                    put("perPage", 24)
                    put("page", page)
                }
            )

            CatalogSection.ANIME_SEASON -> {
                val (season, year) = currentSeason()
                query(
                    SEASON_QUERY,
                    buildJsonObject {
                        put("season", season)
                        put("seasonYear", year)
                        put("perPage", 24)
                        put("page", page)
                    }
                )
            }

            CatalogSection.ANIME_TOP -> query(
                TOP_QUERY,
                buildJsonObject {
                    put("perPage", 24)
                    put("page", page)
                }
            )

            else -> emptyList()
        }

    private suspend fun query(gql: String, vars: JsonObject): List<MediaItem> =
        post<SearchResponse>(gql, vars)?.data?.page?.media.orEmpty().map { it.toItem() }

    /** AniList espera WINTER/SPRING/SUMMER/FALL en mayusculas. */
    private fun currentSeason(): Pair<String, Int> {
        val now = java.util.Calendar.getInstance()
        val month = now.get(java.util.Calendar.MONTH) + 1
        val season = when (month) {
            12, 1, 2 -> "WINTER"
            3, 4, 5 -> "SPRING"
            6, 7, 8 -> "SUMMER"
            else -> "FALL"
        }
        // Diciembre pertenece a la temporada de invierno del ano siguiente.
        val year = now.get(java.util.Calendar.YEAR) + if (month == 12) 1 else 0
        return season to year
    }

    override suspend fun detail(id: MediaId): MediaDetail? {
        val numeric = id.value.toIntOrNull() ?: return null
        val vars = buildJsonObject { put("id", numeric) }
        val media = post<DetailResponse>(DETAIL_QUERY, vars)?.data?.media ?: return null
        val base = media.toItem()
        // Se cruza una sola vez con TMDB: da la sinopsis en el idioma del usuario
        // (AniList solo la tiene en ingles) y, para peliculas, el IMDb.
        val cross = runCatching {
            tmdbCrossRef(base.title, base.originalTitle, base.year)
        }.getOrNull()
        val localized = cross?.item?.overview?.takeIf { it.isNotBlank() }
        val item = if (localized != null) base.copy(overview = localized) else base

        val isMovie = media.format == "MOVIE"
        // AniList no tiene temporadas: cada temporada es una entrada distinta.
        // Se expone como una unica temporada para que la ficha se comporte igual;
        // una pelicula se deja sin temporadas para que se reproduzca como pelicula
        // (ruta /f/{imdb}/ de embed69) y no como episodio 1x01.
        val episodeCount = media.episodes ?: 0
        return MediaDetail(
            item = item,
            runtimeMinutes = media.duration,
            genres = media.genres,
            seasons = if (!isMovie && episodeCount > 0) {
                listOf(Season(1, "Episodios", episodeCount, item.posterUrl))
            } else {
                emptyList()
            },
            status = media.status,
            // Solo peliculas: el IMDb del equivalente en TMDB, para que embed69 y
            // los addons (que van por IMDb) las resuelvan. En series no se rellena,
            // para no arriesgar desajustes de numeracion temporada/episodio con esas
            // fuentes; el anime en serie ya lo cubren tioanime/monoschinos/latanime/
            // pelisplus por titulo.
            imdbId = if (isMovie) cross?.imdbId else null,
            similar = media.recommendations?.nodes
                .orEmpty()
                .mapNotNull { it.mediaRecommendation?.toItem() }
        )
    }

    override suspend fun episodes(id: MediaId, season: Int): List<Episode> {
        val numeric = id.value.toIntOrNull() ?: return emptyList()
        val media = post<DetailResponse>(
            DETAIL_QUERY,
            buildJsonObject { put("id", numeric) }
        )?.data?.media ?: return emptyList()

        val count = media.episodes ?: 0
        if (count <= 0) return emptyList()

        // AniList no publica el titulo de cada episodio, pero si entrega el id
        // de MyAnimeList. Con ese id, Jikan devuelve titulos y fechas reales;
        // si Jikan no contesta se cae a episodios numerados sin romper la ficha.
        media.idMal?.let { malId ->
            val fromJikan = jikan.episodes(malId, count)
            if (fromJikan.isNotEmpty()) {
                return fromJikan.map { it.copy(runtimeMinutes = media.duration) }
            }
        }

        return (1..count).map { n ->
            Episode(
                seasonNumber = 1,
                episodeNumber = n,
                title = "Episodio $n",
                runtimeMinutes = media.duration
            )
        }
    }

    // --- Transporte -------------------------------------------------------

    private suspend inline fun <reified T> post(query: String, variables: JsonObject): T? =
        withContext(Dispatchers.IO) {
            val body = buildJsonObject {
                put("query", JsonPrimitive(query))
                put("variables", variables)
            }.toString().toRequestBody("application/json".toMediaType())

            val request = Request.Builder()
                .url(ENDPOINT)
                .post(body)
                .header("Accept", "application/json")
                .build()

            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return@withContext null
                val text = response.body?.string() ?: return@withContext null
                runCatching { Net.json.decodeFromString<T>(text) }.getOrNull()
            }
        }

    private fun AniMedia.toItem(): MediaItem {
        val romaji = preferRomaji()
        val shown = if (romaji) {
            title?.romaji ?: title?.english ?: title?.native
        } else {
            title?.english ?: title?.romaji ?: title?.native
        }
        return MediaItem(
            id = MediaId(MetaProvider.ANILIST, id.toString()),
            kind = MediaKind.ANIME,
            title = shown.orEmpty(),
            // Romaji como titulo original: es lo que usan los sitios en espanol
            // para su slug (p. ej. "Mirai Nikki"), asi las fuentes lo encuentran
            // aunque el titulo mostrado sea el ingles ("The Future Diary").
            originalTitle = title?.romaji ?: title?.native,
            year = startDate?.year,
            posterUrl = coverImage?.extraLarge ?: coverImage?.large,
            backdropUrl = bannerImage,
            rating = averageScore?.let { it / 10.0 },
            overview = description
                // La sinopsis viene con HTML incrustado.
                ?.replace(Regex("<br\\s*/?>"), "\n")
                ?.replace(Regex("<[^>]+>"), "")
                ?.trim()
                ?.takeIf(String::isNotBlank)
        )
    }
}

// --- Modelos de respuesta -------------------------------------------------

@Serializable
private data class SearchResponse(val data: SearchData? = null)

@Serializable
private data class SearchData(@SerialName("Page") val page: AniPage? = null)

@Serializable
private data class AniPage(val media: List<AniMedia> = emptyList())

@Serializable
private data class DetailResponse(val data: DetailData? = null)

@Serializable
private data class DetailData(@SerialName("Media") val media: AniMedia? = null)

@Serializable
private data class AniMedia(
    val id: Int,
    val idMal: Int? = null,
    val title: AniTitle? = null,
    val description: String? = null,
    /** TV, TV_SHORT, MOVIE, SPECIAL, OVA, ONA, MUSIC. Se usa para tratar las
     *  peliculas como tales (sin temporada) y darles IMDb. */
    val format: String? = null,
    val episodes: Int? = null,
    val duration: Int? = null,
    val status: String? = null,
    val genres: List<String> = emptyList(),
    val averageScore: Int? = null,
    val coverImage: AniCover? = null,
    val bannerImage: String? = null,
    val startDate: AniDate? = null,
    val recommendations: AniRecommendations? = null
)

@Serializable
private data class AniTitle(
    val romaji: String? = null,
    val english: String? = null,
    val native: String? = null
)

@Serializable
private data class AniCover(val extraLarge: String? = null, val large: String? = null)

@Serializable
private data class AniDate(val year: Int? = null)

@Serializable
private data class AniRecommendations(val nodes: List<AniRecNode> = emptyList())

@Serializable
private data class AniRecNode(val mediaRecommendation: AniMedia? = null)

// --- Consultas ------------------------------------------------------------

private const val MEDIA_FIELDS = """
    id
    idMal
    title { romaji english native }
    description
    format
    episodes
    duration
    status
    genres
    averageScore
    coverImage { extraLarge large }
    bannerImage
    startDate { year }
"""

private val SEARCH_QUERY = """
query (${'$'}search: String, ${'$'}perPage: Int) {
  Page(perPage: ${'$'}perPage) {
    media(search: ${'$'}search, type: ANIME, sort: SEARCH_MATCH) { $MEDIA_FIELDS }
  }
}
""".trimIndent()

private val TRENDING_QUERY = """
query (${'$'}perPage: Int, ${'$'}page: Int) {
  Page(perPage: ${'$'}perPage, page: ${'$'}page) {
    media(type: ANIME, sort: TRENDING_DESC) { $MEDIA_FIELDS }
  }
}
""".trimIndent()

private val SEASON_QUERY = """
query (${'$'}season: MediaSeason, ${'$'}seasonYear: Int, ${'$'}perPage: Int, ${'$'}page: Int) {
  Page(perPage: ${'$'}perPage, page: ${'$'}page) {
    media(type: ANIME, season: ${'$'}season, seasonYear: ${'$'}seasonYear, sort: POPULARITY_DESC) {
      $MEDIA_FIELDS
    }
  }
}
""".trimIndent()

private val TOP_QUERY = """
query (${'$'}perPage: Int, ${'$'}page: Int) {
  Page(perPage: ${'$'}perPage, page: ${'$'}page) {
    media(type: ANIME, sort: SCORE_DESC) { $MEDIA_FIELDS }
  }
}
""".trimIndent()

private val DETAIL_QUERY = """
query (${'$'}id: Int) {
  Media(id: ${'$'}id, type: ANIME) {
    $MEDIA_FIELDS
    recommendations(perPage: 12, sort: RATING_DESC) {
      nodes { mediaRecommendation { $MEDIA_FIELDS } }
    }
  }
}
""".trimIndent()
