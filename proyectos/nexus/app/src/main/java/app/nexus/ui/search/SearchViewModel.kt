package app.nexus.ui.search

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import app.nexus.data.db.SearchHistoryEntity
import app.nexus.di.Graph
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

data class SearchUiState(
    val query: String = "",
    val loading: Boolean = false,
    val results: List<MediaItem> = emptyList(),
    val trending: List<MediaItem> = emptyList(),
    val warnings: List<String> = emptyList(),
    val filter: MediaKind? = null,
    val searched: Boolean = false
) {
    val visible: List<MediaItem>
        get() = filter?.let { k -> results.filter { it.kind == k } } ?: results
}

@OptIn(FlowPreview::class)
class SearchViewModel(private val graph: Graph) : ViewModel() {

    private val _state = MutableStateFlow(SearchUiState())
    val state: StateFlow<SearchUiState> = _state.asStateFlow()

    val recentSearches = graph.db.searchHistory()
        .observeRecent()
        .map { list -> list.map { it.query } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    private var searchJob: Job? = null

    init {
        viewModelScope.launch {
            val trending = graph.metadata.trending(24)
            _state.value = _state.value.copy(trending = trending)
        }
    }

    fun onQueryChange(query: String) {
        _state.value = _state.value.copy(query = query)
        searchJob?.cancel()
        if (query.trim().length < 2) {
            _state.value = _state.value.copy(results = emptyList(), searched = false, loading = false)
            return
        }
        // Espera a que el usuario deje de teclear: cada pulsacion son dos
        // llamadas de red (TMDB y AniList).
        searchJob = viewModelScope.launch {
            kotlinx.coroutines.delay(350)
            runSearch(query)
        }
    }

    fun submit() {
        searchJob?.cancel()
        val q = _state.value.query.trim()
        if (q.length >= 2) {
            // Se guarda en searchJob igual que la busqueda por tecleo: si no, esta
            // corrutina quedaba suelta y una busqueda vieja podia terminar despues
            // de otra mas nueva y sobrescribir sus resultados.
            searchJob = viewModelScope.launch {
                runSearch(q)
                graph.db.searchHistory().record(SearchHistoryEntity(q))
            }
        }
    }

    fun setFilter(kind: MediaKind?) {
        _state.value = _state.value.copy(filter = kind)
    }

    fun clearHistory() {
        viewModelScope.launch { graph.db.searchHistory().clear() }
    }

    private suspend fun runSearch(query: String) {
        _state.value = _state.value.copy(loading = true)
        val result = graph.metadata.search(query)
        // Si el texto ya cambio mientras respondia la red, estos resultados son
        // viejos: pintarlos haria parpadear la lista con lo que ya no se busca.
        if (_state.value.query.trim() != query) return
        _state.value = _state.value.copy(
            loading = false,
            results = result.items,
            warnings = result.warnings,
            searched = true
        )
    }

    class Factory(private val graph: Graph) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            SearchViewModel(graph) as T
    }
}
