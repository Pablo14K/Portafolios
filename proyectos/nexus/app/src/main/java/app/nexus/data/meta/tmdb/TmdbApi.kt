package app.nexus.data.meta.tmdb

import retrofit2.http.GET
import retrofit2.http.Path
import retrofit2.http.Query

interface TmdbApi {

    @GET("search/multi")
    suspend fun searchMulti(
        @Query("query") query: String,
        @Query("language") language: String,
        @Query("include_adult") includeAdult: Boolean = false,
        @Query("page") page: Int = 1
    ): TmdbPage

    @GET("trending/all/week")
    suspend fun trending(@Query("language") language: String): TmdbPage

    /**
     * Un solo metodo para todas las filas: TMDB usa la misma forma de respuesta
     * en movie/popular, tv/on_the_air y compania.
     */
    @GET("{type}/{list}")
    suspend fun list(
        @Path("type") type: String,
        @Path("list") list: String,
        @Query("language") language: String,
        @Query("region") region: String,
        @Query("page") page: Int = 1
    ): TmdbPage

    @GET("discover/movie")
    suspend fun discoverMovies(
        @Query("language") language: String,
        @Query("region") region: String,
        @Query("sort_by") sortBy: String = "popularity.desc",
        @Query("with_genres") genres: String? = null,
        @Query("primary_release_date.gte") fromDate: String? = null,
        @Query("primary_release_date.lte") toDate: String? = null,
        @Query("page") page: Int = 1
    ): TmdbPage

    @GET("discover/tv")
    suspend fun discoverShows(
        @Query("language") language: String,
        @Query("sort_by") sortBy: String = "popularity.desc",
        @Query("with_genres") genres: String? = null,
        @Query("first_air_date.gte") fromDate: String? = null,
        @Query("first_air_date.lte") toDate: String? = null,
        @Query("page") page: Int = 1
    ): TmdbPage

    @GET("movie/{id}")
    suspend fun movie(
        @Path("id") id: Int,
        @Query("language") language: String,
        @Query("append_to_response") append: String = "credits,similar"
    ): TmdbMovieDetail

    @GET("tv/{id}")
    suspend fun show(
        @Path("id") id: Int,
        @Query("language") language: String,
        @Query("append_to_response") append: String = "credits,similar,external_ids"
    ): TmdbShowDetail

    @GET("tv/{id}/season/{season}")
    suspend fun season(
        @Path("id") id: Int,
        @Path("season") season: Int,
        @Query("language") language: String
    ): TmdbSeasonDetail

    companion object {
        const val BASE_URL = "https://api.themoviedb.org/3/"
        const val IMAGE_BASE = "https://image.tmdb.org/t/p/"

        fun poster(path: String?): String? = path?.let { "${IMAGE_BASE}w500$it" }
        fun backdrop(path: String?): String? = path?.let { "${IMAGE_BASE}w1280$it" }
        fun profile(path: String?): String? = path?.let { "${IMAGE_BASE}w185$it" }
        fun still(path: String?): String? = path?.let { "${IMAGE_BASE}w300$it" }
    }
}
