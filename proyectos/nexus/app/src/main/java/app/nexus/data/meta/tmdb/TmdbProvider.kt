package app.nexus.data.meta.tmdb

import app.nexus.core.Net
import app.nexus.data.meta.MetadataNotConfigured
import app.nexus.data.meta.MetadataProvider
import app.nexus.domain.CastMember
import app.nexus.domain.CatalogSection
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import app.nexus.domain.MetaProvider
import app.nexus.domain.Season
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit

/**
 * Peliculas y series. Necesita una clave gratuita de TMDB; sin ella el resto de
 * la app sigue funcionando (el anime va por AniList, que no pide nada).
 *
 * La clave se inyecta como parametro de consulta en un interceptor para no
 * repetirla en cada llamada.
 */
class TmdbProvider(
    baseClient: OkHttpClient,
    private val apiKeyProvider: () -> String,
    private val languageProvider: () -> String,
    private val regionProvider: () -> String
) : MetadataProvider {

    override val id: String = "tmdb"

    private val api: TmdbApi = Retrofit.Builder()
        .baseUrl(TmdbApi.BASE_URL)
        .client(
            baseClient.newBuilder()
                .addInterceptor { chain ->
                    val key = apiKeyProvider()
                    val url = chain.request().url.newBuilder()
                        .addQueryParameter("api_key", key)
                        .build()
                    chain.proceed(chain.request().newBuilder().url(url).build())
                }
                .build()
        )
        .addConverterFactory(Net.json.asConverterFactory("application/json".toMediaType()))
        .build()
        .create(TmdbApi::class.java)

    override suspend fun isConfigured(): Boolean = apiKeyProvider().isNotBlank()

    private fun requireKey() {
        if (apiKeyProvider().isBlank()) {
            throw MetadataNotConfigured(
                id,
                "Falta la clave de TMDB. Anadela en Ajustes > Fuentes."
            )
        }
    }

    override suspend fun search(query: String, limit: Int): List<MediaItem> {
        requireKey()
        return api.searchMulti(query, languageProvider())
            .results
            .filter { it.mediaType == "movie" || it.mediaType == "tv" }
            .take(limit)
            .map { it.toItem() }
    }

    override suspend fun trending(limit: Int): List<MediaItem> {
        requireKey()
        return api.trending(languageProvider())
            .results
            .filter { it.mediaType == "movie" || it.mediaType == "tv" }
            .take(limit)
            .map { it.toItem() }
    }

    override val sections: Set<CatalogSection> = setOf(
        CatalogSection.TRENDING,
        CatalogSection.POPULAR_MOVIES,
        CatalogSection.NOW_PLAYING,
        CatalogSection.TOP_RATED_MOVIES,
        CatalogSection.POPULAR_SHOWS,
        CatalogSection.ON_THE_AIR,
        CatalogSection.TOP_RATED_SHOWS
    )

    override suspend fun catalog(section: CatalogSection, page: Int): List<MediaItem> {
        requireKey()
        val lang = languageProvider()
        val region = regionProvider()

        // La region cambia cartelera y estrenos; en el resto TMDB la ignora.
        val (type, list) = when (section) {
            CatalogSection.TRENDING -> return api.trending(lang).results
                .filter { it.mediaType == "movie" || it.mediaType == "tv" }
                .map { it.toItem() }

            CatalogSection.POPULAR_MOVIES -> "movie" to "popular"
            CatalogSection.NOW_PLAYING -> "movie" to "now_playing"
            CatalogSection.TOP_RATED_MOVIES -> "movie" to "top_rated"
            CatalogSection.POPULAR_SHOWS -> "tv" to "popular"
            CatalogSection.ON_THE_AIR -> "tv" to "on_the_air"
            CatalogSection.TOP_RATED_SHOWS -> "tv" to "top_rated"
            else -> return emptyList()
        }

        return api.list(type, list, lang, region, page).results
            .map { it.copy(mediaType = type).toItem() }
    }

    /** Rejilla del catalogo, con filtros de genero y anos. */
    suspend fun discover(
        kind: MediaKind,
        genreIds: List<Int> = emptyList(),
        yearFrom: Int? = null,
        yearTo: Int? = null,
        sortBy: String = "popularity.desc",
        page: Int = 1
    ): List<MediaItem> {
        requireKey()
        val lang = languageProvider()
        val genres = genreIds.takeIf { it.isNotEmpty() }?.joinToString(",")
        val from = yearFrom?.let { "$it-01-01" }
        val to = yearTo?.let { "$it-12-31" }
        return if (kind == MediaKind.MOVIE) {
            api.discoverMovies(lang, regionProvider(), sortBy, genres, from, to, page)
                .results.map { it.copy(mediaType = "movie").toItem() }
        } else {
            api.discoverShows(lang, sortBy, genres, from, to, page)
                .results.map { it.copy(mediaType = "tv").toItem() }
        }
    }

    override suspend fun detail(id: MediaId): MediaDetail? {
        requireKey()
        // El id lleva el tipo delante porque TMDB numera peliculas y series aparte.
        val (kind, numeric) = splitId(id.value) ?: return null
        val lang = languageProvider()
        return if (kind == MediaKind.MOVIE) {
            api.movie(numeric, lang).toDetail()
        } else {
            api.show(numeric, lang).toDetail()
        }
    }

    override suspend fun episodes(id: MediaId, season: Int): List<Episode> {
        requireKey()
        val (_, numeric) = splitId(id.value) ?: return emptyList()
        return api.season(numeric, season, languageProvider()).episodes.map {
            Episode(
                seasonNumber = if (it.seasonNumber > 0) it.seasonNumber else season,
                episodeNumber = it.episodeNumber,
                title = it.name ?: "Episodio ${it.episodeNumber}",
                overview = it.overview?.takeIf(String::isNotBlank),
                stillUrl = TmdbApi.still(it.stillPath),
                runtimeMinutes = it.runtime,
                airDate = it.airDate
            )
        }
    }

    /**
     * Cruce por título + año: localiza el equivalente de una obra en TMDB y
     * devuelve su ficha completa (con **IMDb** y sinopsis en el idioma del
     * usuario). Sirve para enriquecer entradas de AniList, que no traen IMDb:
     * así una película de anime puede resolverse por las fuentes que van por
     * IMDb (embed69, addons Stremio). Mismo patrón que EPIX PLAY, que saca el
     * IMDb de todo por `external_ids`.
     *
     * Devuelve null si TMDB no está configurado o no hay una coincidencia
     * razonable; nunca lanza.
     */
    suspend fun crossReference(title: String, original: String?, year: Int?): MediaDetail? {
        if (apiKeyProvider().isBlank()) return null
        val hits = runCatching { search(title, 6) }.getOrDefault(emptyList())
            .ifEmpty {
                original?.let { runCatching { search(it, 6) }.getOrDefault(emptyList()) }.orEmpty()
            }
        val pick = hits.firstOrNull { h ->
            year == null || h.year == null || kotlin.math.abs(h.year - year) <= 1
        } ?: hits.firstOrNull() ?: return null
        return runCatching { detail(pick.id) }.getOrNull()
    }

    // --- Conversion -------------------------------------------------------

    private fun splitId(value: String): Pair<MediaKind, Int>? {
        val parts = value.split('/')
        if (parts.size != 2) return null
        val kind = when (parts[0]) {
            "movie" -> MediaKind.MOVIE
            "tv" -> MediaKind.SHOW
            else -> return null
        }
        return kind to (parts[1].toIntOrNull() ?: return null)
    }

    private fun TmdbResult.toItem(): MediaItem {
        val isMovie = mediaType == "movie"
        val date = releaseDate ?: firstAirDate
        return MediaItem(
            id = MediaId(MetaProvider.TMDB, "${if (isMovie) "movie" else "tv"}/$id"),
            kind = if (isMovie) MediaKind.MOVIE else MediaKind.SHOW,
            title = title ?: name.orEmpty(),
            originalTitle = originalTitle ?: originalName,
            year = date?.take(4)?.toIntOrNull(),
            posterUrl = TmdbApi.poster(posterPath),
            backdropUrl = TmdbApi.backdrop(backdropPath),
            rating = voteAverage?.takeIf { it > 0 },
            overview = overview?.takeIf(String::isNotBlank)
        )
    }

    private fun TmdbMovieDetail.toDetail() = MediaDetail(
        item = MediaItem(
            id = MediaId(MetaProvider.TMDB, "movie/$id"),
            kind = MediaKind.MOVIE,
            title = title,
            originalTitle = originalTitle,
            year = releaseDate?.take(4)?.toIntOrNull(),
            posterUrl = TmdbApi.poster(posterPath),
            backdropUrl = TmdbApi.backdrop(backdropPath),
            rating = voteAverage?.takeIf { it > 0 },
            overview = overview?.takeIf(String::isNotBlank)
        ),
        runtimeMinutes = runtime,
        genres = genres.map { it.name },
        cast = credits?.cast.orEmpty().take(20).map { it.toCast() },
        status = status,
        imdbId = imdbId,
        tmdbId = id,
        similar = similar?.results.orEmpty().map {
            it.copy(mediaType = "movie").toItem()
        }
    )

    private fun TmdbShowDetail.toDetail() = MediaDetail(
        item = MediaItem(
            id = MediaId(MetaProvider.TMDB, "tv/$id"),
            kind = MediaKind.SHOW,
            title = name,
            originalTitle = originalName,
            year = firstAirDate?.take(4)?.toIntOrNull(),
            posterUrl = TmdbApi.poster(posterPath),
            backdropUrl = TmdbApi.backdrop(backdropPath),
            rating = voteAverage?.takeIf { it > 0 },
            overview = overview?.takeIf(String::isNotBlank)
        ),
        runtimeMinutes = episodeRunTime.firstOrNull(),
        genres = genres.map { it.name },
        cast = credits?.cast.orEmpty().take(20).map { it.toCast() },
        // La temporada 0 son especiales: se muestra al final, no primero.
        seasons = seasons
            .filter { it.episodeCount > 0 }
            .sortedBy { if (it.seasonNumber == 0) Int.MAX_VALUE else it.seasonNumber }
            .map {
                Season(
                    number = it.seasonNumber,
                    name = it.name,
                    episodeCount = it.episodeCount,
                    posterUrl = TmdbApi.poster(it.posterPath)
                )
            },
        status = status,
        imdbId = externalIds?.imdbId,
        tmdbId = id,
        similar = similar?.results.orEmpty().map {
            it.copy(mediaType = "tv").toItem()
        }
    )

    private fun TmdbCast.toCast() = CastMember(
        name = name,
        character = character?.takeIf(String::isNotBlank),
        photoUrl = TmdbApi.profile(profilePath)
    )
}
