package app.nexus.domain

/** De donde vino un titulo. Un mismo titulo puede existir en varios catalogos. */
enum class MetaProvider { TMDB, ANILIST, LOCAL }

enum class MediaKind { MOVIE, SHOW, ANIME, LIVE }

/**
 * Identificador estable de un titulo. Se compone del catalogo y su id dentro
 * de ese catalogo, para que TMDB 550 y AniList 550 no colisionen.
 */
data class MediaId(
    val provider: MetaProvider,
    val value: String
) {
    override fun toString(): String = "${provider.name.lowercase()}:$value"

    companion object {
        fun parse(raw: String): MediaId {
            val i = raw.indexOf(':')
            if (i <= 0) return MediaId(MetaProvider.LOCAL, raw)
            val provider = runCatching {
                MetaProvider.valueOf(raw.substring(0, i).uppercase())
            }.getOrDefault(MetaProvider.LOCAL)
            return MediaId(provider, raw.substring(i + 1))
        }
    }
}

/** Lo minimo para pintar un poster en una fila o una rejilla. */
data class MediaItem(
    val id: MediaId,
    val kind: MediaKind,
    val title: String,
    val originalTitle: String? = null,
    val year: Int? = null,
    val posterUrl: String? = null,
    val backdropUrl: String? = null,
    val rating: Double? = null,
    val overview: String? = null
)

data class Season(
    val number: Int,
    val name: String,
    val episodeCount: Int,
    val posterUrl: String? = null
)

data class Episode(
    val seasonNumber: Int,
    val episodeNumber: Int,
    val title: String,
    val overview: String? = null,
    val stillUrl: String? = null,
    val runtimeMinutes: Int? = null,
    val airDate: String? = null
)

data class CastMember(
    val name: String,
    val character: String? = null,
    val photoUrl: String? = null
)

/** La ficha completa. */
data class MediaDetail(
    val item: MediaItem,
    val runtimeMinutes: Int? = null,
    val genres: List<String> = emptyList(),
    val cast: List<CastMember> = emptyList(),
    val seasons: List<Season> = emptyList(),
    val status: String? = null,
    val certification: String? = null,
    val imdbId: String? = null,
    val tmdbId: Int? = null,
    val similar: List<MediaItem> = emptyList()
)
