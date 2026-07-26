package app.nexus.ui.sources

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import app.nexus.data.db.SourceEntity
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.connectors.AddonConnector
import app.nexus.data.source.connectors.AniwatchConnector
import app.nexus.data.source.connectors.ConsumetConnector
import app.nexus.data.source.connectors.Embed69Connector
import app.nexus.data.source.connectors.JellyfinConnector
import app.nexus.data.source.connectors.LatanimeConnector
import app.nexus.data.source.connectors.MonoschinosConnector
import app.nexus.data.source.connectors.VidSrcConnector
import app.nexus.data.source.connectors.PelisplusConnector
import app.nexus.data.source.connectors.TioanimeConnector
import app.nexus.di.Graph
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.UUID

/** Lo que el formulario de alta necesita saber, sin acoplarse a la vista. */
data class SourceDraft(
    val kind: String = Graph.KIND_JELLYFIN,
    val name: String = "",
    val host: String = "",
    val username: String = "",
    val password: String = ""
) {
    /** Los que piden usuario y clave, frente a los que solo llevan una URL. */
    val needsCredentials: Boolean
        get() = kind == Graph.KIND_JELLYFIN

    fun isValid(): Boolean = when {
        name.isBlank() || host.isBlank() -> false
        needsCredentials -> username.isNotBlank() && password.isNotBlank()
        else -> true
    }

    fun title(): String = when (kind) {
        Graph.KIND_JELLYFIN -> "Servidor Jellyfin o Emby"
        else -> "Addon por URL"
    }

    fun hostLabel(): String = when (kind) {
        Graph.KIND_JELLYFIN -> "Direccion del servidor"
        else -> "URL del addon"
    }

    fun hostPlaceholder(): String = when (kind) {
        Graph.KIND_JELLYFIN -> "http://192.168.1.40:8096"
        else -> "https://.../manifest.json"
    }
}

sealed interface TestResult {
    data object Idle : TestResult
    data object Running : TestResult
    data class Ok(val detail: String) : TestResult
    data class Failed(val reason: String) : TestResult
}

class SourcesViewModel(private val graph: Graph) : ViewModel() {

    val sources: StateFlow<List<SourceEntity>> = graph.db.sources()
        .observeAll()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    private val _draft = MutableStateFlow<SourceDraft?>(null)
    val draft: StateFlow<SourceDraft?> = _draft.asStateFlow()

    private val _test = MutableStateFlow<TestResult>(TestResult.Idle)
    val test: StateFlow<TestResult> = _test.asStateFlow()

    /** id de la fuente que se esta comprobando desde la lista. */
    private val _checking = MutableStateFlow<String?>(null)
    val checking: StateFlow<String?> = _checking.asStateFlow()

    fun startAdding(kind: String = Graph.KIND_JELLYFIN) {
        _test.value = TestResult.Idle
        _draft.value = SourceDraft(kind = kind)
    }

    fun startEditing(entity: SourceEntity) {
        _test.value = TestResult.Idle
        _draft.value = SourceDraft(
            kind = entity.kind,
            name = entity.name,
            host = entity.host,
            username = entity.username,
            password = entity.password
        )
        editingId = entity.id
    }

    private var editingId: String? = null

    fun updateDraft(transform: (SourceDraft) -> SourceDraft) {
        _draft.value = _draft.value?.let(transform)
        _test.value = TestResult.Idle
    }

    fun cancelDraft() {
        _draft.value = null
        editingId = null
        _test.value = TestResult.Idle
    }

    /** Comprueba antes de guardar: una lista mal escrita no llega a la ficha. */
    fun testDraft() {
        val draft = _draft.value ?: return
        if (!draft.isValid()) {
            _test.value = TestResult.Failed("Faltan datos por rellenar")
            return
        }
        _test.value = TestResult.Running
        viewModelScope.launch {
            val health = withContext(Dispatchers.IO) { probe(draft) }
            _test.value = when (health) {
                is SourceHealth.Ok -> TestResult.Ok(
                    health.latencyMs?.let { "Responde en $it ms" } ?: "Responde"
                )

                is SourceHealth.Failing -> TestResult.Failed(health.reason)
                SourceHealth.Disabled -> TestResult.Failed("Desactivada")
            }
        }
    }

    fun saveDraft() {
        val draft = _draft.value ?: return
        if (!draft.isValid()) {
            _test.value = TestResult.Failed("Faltan datos por rellenar")
            return
        }
        viewModelScope.launch {
            val id = editingId ?: UUID.randomUUID().toString()
            val nextPriority = (sources.value.maxOfOrNull { it.priority } ?: 0) + 1
            val existing = sources.value.firstOrNull { it.id == id }
            graph.db.sources().upsert(
                SourceEntity(
                    id = id,
                    name = draft.name.trim(),
                    kind = draft.kind,
                    host = draft.host.trim(),
                    username = draft.username.trim(),
                    password = draft.password,
                    enabled = existing?.enabled ?: true,
                    priority = existing?.priority ?: nextPriority
                )
            )
            graph.sources.invalidate()
            cancelDraft()
        }
    }

    fun setEnabled(entity: SourceEntity, enabled: Boolean) {
        viewModelScope.launch {
            graph.db.sources().setEnabled(entity.id, enabled)
            graph.sources.invalidate()
        }
    }

    fun delete(entity: SourceEntity) {
        viewModelScope.launch {
            graph.db.sources().delete(entity.id)
            graph.sources.unregister(entity.id)
        }
    }

    /** Sube la fuente una posicion intercambiando prioridades con la anterior. */
    fun moveUp(entity: SourceEntity) {
        val ordered = sources.value
        val index = ordered.indexOfFirst { it.id == entity.id }
        if (index <= 0) return
        val above = ordered[index - 1]
        viewModelScope.launch {
            graph.db.sources().setPriority(entity.id, above.priority)
            graph.db.sources().setPriority(above.id, entity.priority)
            graph.sources.invalidate()
        }
    }

    fun moveDown(entity: SourceEntity) {
        val ordered = sources.value
        val index = ordered.indexOfFirst { it.id == entity.id }
        if (index < 0 || index >= ordered.lastIndex) return
        val below = ordered[index + 1]
        viewModelScope.launch {
            graph.db.sources().setPriority(entity.id, below.priority)
            graph.db.sources().setPriority(below.id, entity.priority)
            graph.sources.invalidate()
        }
    }

    fun checkHealth(entity: SourceEntity) {
        _checking.value = entity.id
        viewModelScope.launch {
            val health = withContext(Dispatchers.IO) {
                probe(
                    SourceDraft(
                        kind = entity.kind,
                        name = entity.name,
                        host = entity.host,
                        username = entity.username,
                        password = entity.password
                    )
                )
            }
            val error = (health as? SourceHealth.Failing)?.reason
            graph.db.sources().setHealth(entity.id, error, System.currentTimeMillis())
            _checking.value = null
        }
    }

    /**
     * Construye un conector desechable solo para preguntarle si responde. No se
     * registra: si la comprobacion falla, no debe ensuciar la lista activa.
     */
    private suspend fun probe(draft: SourceDraft): SourceHealth = when (draft.kind) {
        Graph.KIND_JELLYFIN -> JellyfinConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            host = draft.host,
            username = draft.username,
            password = draft.password
        ).healthCheck()

        Graph.KIND_ADDON -> AddonConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            addonUrl = draft.host
        ).healthCheck()

        Graph.KIND_CONSUMET -> ConsumetConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            baseUrl = draft.host.ifBlank { app.nexus.core.Endpoints.Consumet.BASE },
            mode = runCatching {
                ConsumetConnector.Mode.valueOf(draft.password.ifBlank { "BOTH" })
            }.getOrDefault(ConsumetConnector.Mode.BOTH),
            animeProvider = draft.username.ifBlank { app.nexus.core.Endpoints.Consumet.ANIME_PROVIDER },
            moviesProvider = draft.username.ifBlank { "flixhq" }
        ).healthCheck()

        Graph.KIND_ANIWATCH -> AniwatchConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            baseUrl = draft.host.ifBlank { app.nexus.core.Endpoints.Consumet.ANIWATCH_BASE }
        ).healthCheck()

        Graph.KIND_EMBED69 -> Embed69Connector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://embed69.org" }
        ).healthCheck()

        Graph.KIND_PELISPLUS -> PelisplusConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://pelisplushd.bz" }
        ).healthCheck()

        Graph.KIND_TIOANIME -> TioanimeConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://tioanime.com" }
        ).healthCheck()

        Graph.KIND_MONOSCHINOS -> MonoschinosConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://monoschinos.st" }
        ).healthCheck()

        Graph.KIND_LATANIME -> LatanimeConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://latanime.org" }
        ).healthCheck()

        Graph.KIND_VIDSRC -> VidSrcConnector(
            id = "probe",
            displayName = draft.name,
            client = graph.http,
            context = graph.appContext,
            baseUrl = draft.host.ifBlank { "https://vidsrc.to" }
        ).healthCheck()

        Graph.KIND_LOCAL -> SourceHealth.Ok(0)

        else -> SourceHealth.Failing("Tipo de fuente desconocido")
    }

    class Factory(private val graph: Graph) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            SourcesViewModel(graph) as T
    }
}
