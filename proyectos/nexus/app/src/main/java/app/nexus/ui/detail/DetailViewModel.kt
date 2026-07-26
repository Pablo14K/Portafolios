package app.nexus.ui.detail

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import app.nexus.data.db.FavoriteEntity
import app.nexus.data.db.ProgressEntity
import app.nexus.di.Graph
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

data class DetailUiState(
    val loading: Boolean = true,
    val detail: MediaDetail? = null,
    val error: String? = null,
    val selectedSeason: Int? = null,
    val episodes: List<Episode> = emptyList(),
    val episodesLoading: Boolean = false,
    val progressByKey: Map<String, ProgressEntity> = emptyMap()
)

/** Estado de la hoja de enlaces, separado para que no recargue la ficha. */
data class LinksUiState(
    val visible: Boolean = false,
    val loading: Boolean = false,
    val links: List<StreamLink> = emptyList(),
    val request: StreamRequest? = null,
    val label: String = "",
    /** Fuentes ya consultadas y total, para el indicador de progreso. */
    val completed: Int = 0,
    val total: Int = 0
) {
    val percent: Int get() = if (total <= 0) 0 else (completed * 100 / total)
}

class DetailViewModel(
    private val graph: Graph,
    private val mediaIdRaw: String
) : ViewModel() {

    private val mediaId = MediaId.parse(mediaIdRaw)

    private val _state = MutableStateFlow(DetailUiState())
    val state: StateFlow<DetailUiState> = _state.asStateFlow()

    private val _links = MutableStateFlow(LinksUiState())
    val links: StateFlow<LinksUiState> = _links.asStateFlow()

    val isFavorite: StateFlow<Boolean> = graph.db.favorites()
        .observeIsFavorite(mediaIdRaw)
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), false)

    private var linksJob: Job? = null

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = _state.value.copy(loading = true, error = null)
            val detail = graph.metadata.detail(mediaId)
            if (detail == null) {
                _state.value = _state.value.copy(
                    loading = false,
                    error = "No se pudo cargar la ficha. Revisa la conexion o la clave de TMDB."
                )
                return@launch
            }
            val progress = graph.db.progress().forMedia(mediaIdRaw).associateBy { it.key }
            val firstSeason = detail.seasons.firstOrNull()?.number
            _state.value = DetailUiState(
                loading = false,
                detail = detail,
                selectedSeason = firstSeason,
                progressByKey = progress
            )
            firstSeason?.let { selectSeason(it) }
        }
    }

    fun selectSeason(season: Int) {
        _state.value = _state.value.copy(selectedSeason = season, episodesLoading = true)
        viewModelScope.launch {
            val episodes = graph.metadata.episodes(mediaId, season)
            _state.value = _state.value.copy(episodes = episodes, episodesLoading = false)
        }
    }

    fun toggleFavorite() {
        val detail = _state.value.detail ?: return
        viewModelScope.launch {
            if (isFavorite.value) {
                graph.db.favorites().remove(mediaIdRaw)
            } else {
                graph.db.favorites().add(
                    FavoriteEntity(
                        mediaId = mediaIdRaw,
                        kind = detail.item.kind.name,
                        title = detail.item.title,
                        posterUrl = detail.item.posterUrl,
                        year = detail.item.year
                    )
                )
            }
        }
    }

    /**
     * Abre la hoja y lanza la busqueda en todas las fuentes a la vez.
     *
     * @param keepExisting al refrescar, conserva los enlaces ya encontrados y solo
     * anade los nuevos (las fuentes que fallaron se reintentan; las que ya dieron
     * enlace vuelven al instante desde cache). Asi cada refresco suma en vez de
     * empezar de cero.
     */
    fun openLinks(season: Int? = null, episode: Int? = null, keepExisting: Boolean = false) {
        val detail = _state.value.detail ?: return
        val request = StreamRequest(detail, season, episode)
        val label = if (season != null && episode != null) {
            "${detail.item.title} · T${season} E${episode}"
        } else {
            detail.item.title
        }

        linksJob?.cancel()
        _links.value = _links.value.copy(
            visible = true,
            loading = true,
            request = request,
            label = label,
            links = if (keepExisting) _links.value.links else emptyList(),
            completed = 0,
            total = 0
        )

        linksJob = viewModelScope.launch {
            val filters = graph.settings.linkFilters.first()
            // Streaming: la hoja va mostrando los enlaces segun cada fuente
            // responde, con el progreso, en vez de esperar a todas en silencio.
            // El tope global cierra el indicador aunque una fuente se cuelgue sin
            // respetar su propio tiempo; lo ya encontrado se conserva.
            kotlinx.coroutines.withTimeoutOrNull(OVERALL_TIMEOUT_MS) {
                graph.sources.resolveStreaming(
                    request = request,
                    filters = filters,
                    cacheTtlMillis = filters.cacheTtlMinutes * 60_000L
                ).collect { progress ->
                    _links.value = _links.value.copy(
                        loading = !progress.done,
                        // Nunca se retrocede a vacio mientras se busca: solo crece.
                        links = if (progress.links.isNotEmpty()) progress.links else _links.value.links,
                        completed = progress.completed,
                        total = progress.total
                    )
                }
            }
            _links.value = _links.value.copy(loading = false)
        }
    }

    private companion object {
        const val OVERALL_TIMEOUT_MS = 46_000L
    }

    fun closeLinks() {
        linksJob?.cancel()
        _links.value = LinksUiState()
    }

    fun refreshLinks() {
        val current = _links.value.request ?: return
        // No se invalida la cache: las fuentes que ya dieron enlace se conservan
        // y solo se reintentan las que fallaron, sumando cada vez.
        openLinks(current.season, current.episode, keepExisting = true)
    }

    fun progressFor(season: Int?, episode: Int?): ProgressEntity? =
        _state.value.progressByKey[ProgressEntity.keyOf(mediaIdRaw, season, episode)]

    class Factory(
        private val graph: Graph,
        private val mediaIdRaw: String
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            DetailViewModel(graph, mediaIdRaw) as T
    }
}
