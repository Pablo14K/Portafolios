package app.nexus.domain

/**
 * Las filas que sabe servir la app. Cada catalogo implementa las que le
 * corresponden y devuelve vacio en las demas; asi Inicio no necesita saber
 * quien contesta a que.
 */
enum class CatalogSection(val title: String, val kind: MediaKind) {
    TRENDING("Tendencias", MediaKind.MOVIE),
    POPULAR_MOVIES("Peliculas populares", MediaKind.MOVIE),
    NOW_PLAYING("En cartelera", MediaKind.MOVIE),
    TOP_RATED_MOVIES("Peliculas mejor valoradas", MediaKind.MOVIE),
    POPULAR_SHOWS("Series populares", MediaKind.SHOW),
    ON_THE_AIR("En emision", MediaKind.SHOW),
    TOP_RATED_SHOWS("Series mejor valoradas", MediaKind.SHOW),
    ANIME_TRENDING("Anime en tendencia", MediaKind.ANIME),
    ANIME_SEASON("Anime de la temporada", MediaKind.ANIME),
    ANIME_TOP("Anime mejor valorado", MediaKind.ANIME)
}

/** Una fila ya resuelta, lista para pintar. */
data class CatalogRow(
    val section: CatalogSection,
    val items: List<MediaItem>
)
