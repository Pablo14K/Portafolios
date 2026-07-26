package app.nexus.data.meta

import app.nexus.domain.CatalogSection
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem

/**
 * De donde salen portadas, sinopsis y episodios. Es independiente de donde se
 * reproduce: un titulo puede venir de TMDB y verse desde el Jellyfin de casa.
 */
interface MetadataProvider {

    val id: String

    /** true si esta listo para usarse; TMDB necesita clave, AniList no. */
    suspend fun isConfigured(): Boolean = true

    suspend fun search(query: String, limit: Int = 20): List<MediaItem>

    suspend fun detail(id: MediaId): MediaDetail?

    suspend fun episodes(id: MediaId, season: Int): List<Episode> = emptyList()

    suspend fun trending(limit: Int = 20): List<MediaItem> = emptyList()

    /** Las secciones que este catalogo no sirve devuelven lista vacia. */
    suspend fun catalog(section: CatalogSection, page: Int = 1): List<MediaItem> = emptyList()

    /** Que secciones sabe servir, para no pedirle lo que no tiene. */
    val sections: Set<CatalogSection> get() = emptySet()
}

/** Falta la clave de TMDB: la interfaz lo distingue de "no hay resultados". */
class MetadataNotConfigured(val providerId: String, message: String) : Exception(message)
