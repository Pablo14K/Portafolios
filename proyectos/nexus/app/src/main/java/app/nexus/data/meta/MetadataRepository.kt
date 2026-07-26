package app.nexus.data.meta

import android.util.Log
import app.nexus.data.meta.tmdb.TmdbProvider
import app.nexus.domain.CatalogRow
import app.nexus.domain.CatalogSection
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import app.nexus.domain.MetaProvider
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope

private const val TAG = "MetadataRepo"

/**
 * Junta los catalogos. Busca en todos a la vez y entrelaza los resultados para
 * que una busqueda de "frieren" no devuelva 20 peliculas antes del anime.
 */
class MetadataRepository(
    private val providers: List<MetadataProvider>
) {

    /** Motivos por los que un catalogo no participo, para avisar en pantalla. */
    data class SearchResult(
        val items: List<MediaItem>,
        val warnings: List<String> = emptyList()
    )

    suspend fun search(query: String, limitPerProvider: Int = 20): SearchResult = coroutineScope {
        if (query.isBlank()) return@coroutineScope SearchResult(emptyList())

        val jobs = providers.map { provider ->
            async {
                runCatching { provider.search(query, limitPerProvider) }
                    .fold(
                        onSuccess = { it to null },
                        onFailure = { error ->
                            Log.w(TAG, "${provider.id}: ${error.message}")
                            emptyList<MediaItem>() to (error as? MetadataNotConfigured)?.message
                        }
                    )
            }
        }

        val results = jobs.awaitAll()
        SearchResult(
            items = interleave(results.map { it.first }),
            warnings = results.mapNotNull { it.second }.distinct()
        )
    }

    suspend fun trending(limit: Int = 20): List<MediaItem> = coroutineScope {
        val jobs = providers.map { provider ->
            async { runCatching { provider.trending(limit) }.getOrDefault(emptyList()) }
        }
        interleave(jobs.awaitAll())
    }

    /**
     * Resuelve varias filas a la vez. Cada seccion va al catalogo que la sirve;
     * las que se quedan vacias no llegan a la pantalla.
     */
    suspend fun rows(sections: List<CatalogSection>): List<CatalogRow> = coroutineScope {
        sections.map { section ->
            async {
                val provider = providers.firstOrNull { section in it.sections }
                val items = provider
                    ?.let { runCatching { it.catalog(section) }.getOrDefault(emptyList()) }
                    .orEmpty()
                CatalogRow(section, items)
            }
        }.awaitAll().filter { it.items.isNotEmpty() }
    }

    suspend fun catalog(section: CatalogSection, page: Int = 1): List<MediaItem> {
        val provider = providers.firstOrNull { section in it.sections } ?: return emptyList()
        return runCatching { provider.catalog(section, page) }
            .onFailure { Log.w(TAG, "${section.name}: ${it.message}") }
            .getOrDefault(emptyList())
    }

    /** Acceso directo a TMDB para la rejilla con filtros. */
    fun tmdb(): TmdbProvider? = providers.filterIsInstance<TmdbProvider>().firstOrNull()

    suspend fun detail(id: MediaId): MediaDetail? =
        providerFor(id)?.let { provider ->
            runCatching { provider.detail(id) }
                .onFailure { Log.w(TAG, "detalle ${id}: ${it.message}") }
                .getOrNull()
        }

    suspend fun episodes(id: MediaId, season: Int): List<Episode> =
        providerFor(id)?.let { provider ->
            runCatching { provider.episodes(id, season) }
                .onFailure { Log.w(TAG, "episodios ${id}: ${it.message}") }
                .getOrDefault(emptyList())
        } ?: emptyList()

    private fun providerFor(id: MediaId): MetadataProvider? {
        val wanted = when (id.provider) {
            MetaProvider.TMDB -> "tmdb"
            MetaProvider.ANILIST -> "anilist"
            else -> null
        } ?: return null
        return providers.firstOrNull { it.id == wanted }
    }

    /** Uno de cada catalogo por vuelta, hasta agotarlos. */
    private fun interleave(lists: List<List<MediaItem>>): List<MediaItem> {
        val out = mutableListOf<MediaItem>()
        val seen = mutableSetOf<String>()
        var index = 0
        while (true) {
            var added = false
            for (list in lists) {
                val item = list.getOrNull(index) ?: continue
                added = true
                if (seen.add(item.id.toString())) out += item
            }
            if (!added) break
            index++
        }
        return unifyDuplicates(out)
    }

    /**
     * Une la misma obra cuando aparece en dos catalogos (p. ej. Frieren esta en
     * TMDB como serie y en AniList como anime). Se agrupa por titulo principal +
     * ano y se conserva **la entrada de anime**, para que al abrirla se activen
     * sus fuentes propias (VOSE subtitulado, /anime de pelisplushd).
     */
    private fun unifyDuplicates(items: List<MediaItem>): List<MediaItem> {
        val result = mutableListOf<MediaItem>()
        val keyToIndex = HashMap<String, Int>()
        for (item in items) {
            val key = fuzzyKey(item)
            if (key == null) {
                result += item
                continue
            }
            val at = keyToIndex[key]
            when {
                at == null -> {
                    keyToIndex[key] = result.size
                    result += item
                }
                item.kind == MediaKind.ANIME && result[at].kind != MediaKind.ANIME ->
                    result[at] = item
            }
        }
        return result
    }

    /** Clave difusa: titulo antes de ':' o '(' + ano. Null si no hay ano. */
    private fun fuzzyKey(item: MediaItem): String? {
        val year = item.year ?: return null
        val main = item.title.substringBefore(':').substringBefore('(')
            .lowercase()
            .replace("á", "a").replace("é", "e").replace("í", "i")
            .replace("ó", "o").replace("ú", "u").replace("ñ", "n")
            .replace(Regex("[^a-z0-9]+"), "")
        if (main.length < 3) return null
        return "$main|$year"
    }
}
