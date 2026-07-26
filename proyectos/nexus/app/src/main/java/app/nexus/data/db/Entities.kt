package app.nexus.data.db

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * Una fuente guardada. El tipo decide que conector se construye al arrancar y
 * como se interpretan [host], [username] y [password].
 */
@Entity(tableName = "sources")
data class SourceEntity(
    @PrimaryKey val id: String,
    val name: String,
    /** LOCAL, JELLYFIN, ADDON, DEBRID */
    val kind: String,
    val host: String = "",
    val username: String = "",
    val password: String = "",
    val enabled: Boolean = true,
    /** Posicion en la lista de prioridad; menor gana. */
    val priority: Int = 0,
    val lastError: String? = null,
    val lastCheckedAt: Long = 0
)

/**
 * Progreso de reproduccion. La clave incluye temporada y episodio para que una
 * serie recuerde cada capitulo por separado.
 */
@Entity(
    tableName = "progress",
    indices = [Index(value = ["mediaId"]), Index(value = ["updatedAt"])]
)
data class ProgressEntity(
    @PrimaryKey val key: String,
    val mediaId: String,
    val kind: String,
    val title: String,
    val posterUrl: String? = null,
    val season: Int? = null,
    val episode: Int? = null,
    val positionMs: Long = 0,
    val durationMs: Long = 0,
    val watched: Boolean = false,
    val updatedAt: Long = System.currentTimeMillis()
) {
    val percent: Int
        get() = if (durationMs <= 0) 0 else ((positionMs * 100) / durationMs).toInt().coerceIn(0, 100)

    companion object {
        fun keyOf(mediaId: String, season: Int?, episode: Int?): String =
            "$mediaId|${season ?: -1}|${episode ?: -1}"
    }
}

@Entity(tableName = "favorites")
data class FavoriteEntity(
    @PrimaryKey val mediaId: String,
    val kind: String,
    val title: String,
    val posterUrl: String? = null,
    val year: Int? = null,
    val addedAt: Long = System.currentTimeMillis()
)

@Entity(tableName = "search_history")
data class SearchHistoryEntity(
    @PrimaryKey val query: String,
    val searchedAt: Long = System.currentTimeMillis()
)
