package app.nexus.data.meta.tmdb

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class TmdbPage(
    val page: Int = 1,
    val results: List<TmdbResult> = emptyList(),
    @SerialName("total_pages") val totalPages: Int = 1
)

/**
 * TMDB devuelve peliculas, series y personas en el mismo array de /search/multi,
 * distinguidas por media_type. De ahi que casi todo sea opcional.
 */
@Serializable
data class TmdbResult(
    val id: Int,
    @SerialName("media_type") val mediaType: String? = null,
    val title: String? = null,
    val name: String? = null,
    @SerialName("original_title") val originalTitle: String? = null,
    @SerialName("original_name") val originalName: String? = null,
    @SerialName("poster_path") val posterPath: String? = null,
    @SerialName("backdrop_path") val backdropPath: String? = null,
    @SerialName("release_date") val releaseDate: String? = null,
    @SerialName("first_air_date") val firstAirDate: String? = null,
    @SerialName("vote_average") val voteAverage: Double? = null,
    val overview: String? = null
)

@Serializable
data class TmdbMovieDetail(
    val id: Int,
    val title: String,
    @SerialName("original_title") val originalTitle: String? = null,
    val overview: String? = null,
    @SerialName("poster_path") val posterPath: String? = null,
    @SerialName("backdrop_path") val backdropPath: String? = null,
    @SerialName("release_date") val releaseDate: String? = null,
    val runtime: Int? = null,
    @SerialName("vote_average") val voteAverage: Double? = null,
    val genres: List<TmdbGenre> = emptyList(),
    val status: String? = null,
    @SerialName("imdb_id") val imdbId: String? = null,
    val credits: TmdbCredits? = null,
    val similar: TmdbPage? = null
)

@Serializable
data class TmdbShowDetail(
    val id: Int,
    val name: String,
    @SerialName("original_name") val originalName: String? = null,
    val overview: String? = null,
    @SerialName("poster_path") val posterPath: String? = null,
    @SerialName("backdrop_path") val backdropPath: String? = null,
    @SerialName("first_air_date") val firstAirDate: String? = null,
    @SerialName("episode_run_time") val episodeRunTime: List<Int> = emptyList(),
    @SerialName("vote_average") val voteAverage: Double? = null,
    val genres: List<TmdbGenre> = emptyList(),
    val status: String? = null,
    val seasons: List<TmdbSeason> = emptyList(),
    val credits: TmdbCredits? = null,
    val similar: TmdbPage? = null,
    @SerialName("external_ids") val externalIds: TmdbExternalIds? = null
)

/** Las series no traen imdb_id de serie; hay que pedirlo aparte. */
@Serializable
data class TmdbExternalIds(@SerialName("imdb_id") val imdbId: String? = null)

@Serializable
data class TmdbGenre(val id: Int, val name: String)

@Serializable
data class TmdbSeason(
    @SerialName("season_number") val seasonNumber: Int,
    val name: String,
    @SerialName("episode_count") val episodeCount: Int = 0,
    @SerialName("poster_path") val posterPath: String? = null
)

@Serializable
data class TmdbSeasonDetail(
    @SerialName("season_number") val seasonNumber: Int = 0,
    val episodes: List<TmdbEpisode> = emptyList()
)

@Serializable
data class TmdbEpisode(
    @SerialName("episode_number") val episodeNumber: Int,
    @SerialName("season_number") val seasonNumber: Int = 0,
    val name: String? = null,
    val overview: String? = null,
    @SerialName("still_path") val stillPath: String? = null,
    val runtime: Int? = null,
    @SerialName("air_date") val airDate: String? = null
)

@Serializable
data class TmdbCredits(val cast: List<TmdbCast> = emptyList())

@Serializable
data class TmdbCast(
    val name: String,
    val character: String? = null,
    @SerialName("profile_path") val profilePath: String? = null
)
