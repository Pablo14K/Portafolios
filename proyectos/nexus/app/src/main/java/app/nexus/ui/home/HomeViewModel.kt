package app.nexus.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import app.nexus.data.db.ProgressEntity
import app.nexus.di.Graph
import app.nexus.domain.CatalogRow
import app.nexus.domain.CatalogSection
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

data class HomeUiState(
    val loading: Boolean = true,
    val featured: MediaItem? = null,
    val rows: List<CatalogRow> = emptyList(),
    val needsTmdbKey: Boolean = false
)

class HomeViewModel(private val graph: Graph) : ViewModel() {

    private val _state = MutableStateFlow(HomeUiState())
    val state: StateFlow<HomeUiState> = _state.asStateFlow()

    /**
     * Continuar viendo sale de la base de datos, no de la red: tiene que estar
     * en pantalla aunque no haya conexion.
     */
    val continueWatching: StateFlow<List<ProgressEntity>> = graph.db.progress()
        .observeContinueWatching()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true)
            val rows = graph.metadata.rows(DEFAULT_SECTIONS)
            // El destacado es el primer titulo con imagen de fondo utilizable.
            val featured = rows.firstNotNullOfOrNull { row ->
                row.items.firstOrNull { it.backdropUrl != null }
            }
            _state.value = HomeUiState(
                loading = false,
                featured = featured,
                // El destacado no se repite mas abajo, este donde este.
                rows = rows.map { row ->
                    row.copy(items = row.items.filterNot { it.id == featured?.id })
                }.filter { it.items.isNotEmpty() },
                // Si lo unico que contesta es AniList, falta la clave de TMDB.
                needsTmdbKey = rows.none { it.section.kind != MediaKind.ANIME }
            )
        }
    }

    companion object {
        private val DEFAULT_SECTIONS = listOf(
            CatalogSection.TRENDING,
            CatalogSection.NOW_PLAYING,
            CatalogSection.ANIME_SEASON,
            CatalogSection.POPULAR_SHOWS,
            CatalogSection.ANIME_TRENDING,
            CatalogSection.POPULAR_MOVIES,
            CatalogSection.ON_THE_AIR,
            CatalogSection.TOP_RATED_MOVIES,
            CatalogSection.ANIME_TOP,
            CatalogSection.TOP_RATED_SHOWS
        )
    }

    class Factory(private val graph: Graph) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = HomeViewModel(graph) as T
    }
}
