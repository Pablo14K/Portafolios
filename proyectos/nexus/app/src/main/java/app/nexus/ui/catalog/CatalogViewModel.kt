package app.nexus.ui.catalog

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import app.nexus.di.Graph
import app.nexus.domain.CatalogSection
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Generos de TMDB. Los ids son fijos y publicos, no hace falta pedirlos. */
data class Genre(val id: Int, val label: String, val kind: MediaKind)

private val MOVIE_GENRES = listOf(
    Genre(28, "Accion", MediaKind.MOVIE),
    Genre(12, "Aventura", MediaKind.MOVIE),
    Genre(16, "Animacion", MediaKind.MOVIE),
    Genre(35, "Comedia", MediaKind.MOVIE),
    Genre(80, "Crimen", MediaKind.MOVIE),
    Genre(99, "Documental", MediaKind.MOVIE),
    Genre(18, "Drama", MediaKind.MOVIE),
    Genre(14, "Fantasia", MediaKind.MOVIE),
    Genre(27, "Terror", MediaKind.MOVIE),
    Genre(9648, "Misterio", MediaKind.MOVIE),
    Genre(10749, "Romance", MediaKind.MOVIE),
    Genre(878, "Ciencia ficcion", MediaKind.MOVIE),
    Genre(53, "Suspense", MediaKind.MOVIE)
)

private val SHOW_GENRES = listOf(
    Genre(10759, "Accion y aventura", MediaKind.SHOW),
    Genre(16, "Animacion", MediaKind.SHOW),
    Genre(35, "Comedia", MediaKind.SHOW),
    Genre(80, "Crimen", MediaKind.SHOW),
    Genre(99, "Documental", MediaKind.SHOW),
    Genre(18, "Drama", MediaKind.SHOW),
    Genre(9648, "Misterio", MediaKind.SHOW),
    Genre(10765, "Sci-Fi y fantasia", MediaKind.SHOW)
)

enum class CatalogSort(val label: String, val tmdb: String) {
    POPULAR("Popularidad", "popularity.desc"),
    RATING("Valoracion", "vote_average.desc"),
    RECENT("Mas recientes", "primary_release_date.desc")
}

data class CatalogUiState(
    val kind: MediaKind = MediaKind.MOVIE,
    val items: List<MediaItem> = emptyList(),
    val loading: Boolean = true,
    val loadingMore: Boolean = false,
    val endReached: Boolean = false,
    val selectedGenres: Set<Int> = emptySet(),
    val sort: CatalogSort = CatalogSort.POPULAR,
    val yearFrom: Int? = null,
    val yearTo: Int? = null,
    val unavailableReason: String? = null
) {
    val genres: List<Genre>
        get() = when (kind) {
            MediaKind.SHOW -> SHOW_GENRES
            MediaKind.MOVIE -> MOVIE_GENRES
            else -> emptyList()
        }
}

class CatalogViewModel(private val graph: Graph) : ViewModel() {

    private val _state = MutableStateFlow(CatalogUiState())
    val state: StateFlow<CatalogUiState> = _state.asStateFlow()

    private var page = 1
    private var job: Job? = null

    init {
        reload()
    }

    fun selectKind(kind: MediaKind) {
        if (kind == _state.value.kind) return
        // Los generos de peliculas y series no comparten ids: se limpian.
        _state.value = _state.value.copy(
            kind = kind,
            selectedGenres = emptySet(),
            items = emptyList()
        )
        reload()
    }

    fun toggleGenre(id: Int) {
        val current = _state.value.selectedGenres
        _state.value = _state.value.copy(
            selectedGenres = if (id in current) current - id else current + id
        )
        reload()
    }

    fun setSort(sort: CatalogSort) {
        _state.value = _state.value.copy(sort = sort)
        reload()
    }

    fun setYears(from: Int?, to: Int?) {
        _state.value = _state.value.copy(yearFrom = from, yearTo = to)
        reload()
    }

    fun clearFilters() {
        _state.value = _state.value.copy(
            selectedGenres = emptySet(),
            yearFrom = null,
            yearTo = null,
            sort = CatalogSort.POPULAR
        )
        reload()
    }

    fun reload() {
        page = 1
        job?.cancel()
        _state.value = _state.value.copy(loading = true, endReached = false, unavailableReason = null)
        job = viewModelScope.launch {
            val items = fetch(page)
            _state.value = _state.value.copy(
                items = items,
                loading = false,
                endReached = items.isEmpty(),
                unavailableReason = reasonIfEmpty(items)
            )
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loading || current.loadingMore || current.endReached) return
        _state.value = current.copy(loadingMore = true)
        viewModelScope.launch {
            page++
            val more = fetch(page)
            val existing = _state.value.items.map { it.id.toString() }.toSet()
            _state.value = _state.value.copy(
                items = _state.value.items + more.filterNot { it.id.toString() in existing },
                loadingMore = false,
                endReached = more.isEmpty()
            )
        }
    }

    private suspend fun fetch(page: Int): List<MediaItem> {
        val s = _state.value
        return if (s.kind == MediaKind.ANIME) {
            // AniList no acepta los mismos filtros; se sirve por seccion.
            val section = when (s.sort) {
                CatalogSort.RATING -> CatalogSection.ANIME_TOP
                CatalogSort.RECENT -> CatalogSection.ANIME_SEASON
                CatalogSort.POPULAR -> CatalogSection.ANIME_TRENDING
            }
            graph.metadata.catalog(section, page)
        } else {
            val tmdb = graph.metadata.tmdb() ?: return emptyList()
            runCatching {
                tmdb.discover(
                    kind = s.kind,
                    genreIds = s.selectedGenres.toList(),
                    yearFrom = s.yearFrom,
                    yearTo = s.yearTo,
                    sortBy = if (s.kind == MediaKind.SHOW && s.sort == CatalogSort.RECENT) {
                        "first_air_date.desc"
                    } else {
                        s.sort.tmdb
                    },
                    page = page
                )
            }.getOrDefault(emptyList())
        }
    }

    private fun reasonIfEmpty(items: List<MediaItem>): String? =
        if (items.isEmpty()) "Nada coincide con estos filtros." else null

    class Factory(private val graph: Graph) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = CatalogViewModel(graph) as T
    }
}
