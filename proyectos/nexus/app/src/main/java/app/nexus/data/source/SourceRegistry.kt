package app.nexus.data.source

import android.util.Log
import app.nexus.data.prefs.LinkFilters
import app.nexus.domain.AudioTag
import app.nexus.domain.Quality
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.channelFlow
import kotlinx.coroutines.joinAll
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger

private const val TAG = "SourceRegistry"

/**
 * Pregunta a todas las fuentes activas a la vez y junta el resultado.
 *
 * El orden de [connectors] es la prioridad que el usuario fija arrastrando en
 * Ajustes: a igualdad de calidad, gana la fuente que este mas arriba.
 */
class SourceRegistry {

    private val _connectors = MutableStateFlow<List<SourceConnector>>(emptyList())
    val connectors: StateFlow<List<SourceConnector>> = _connectors.asStateFlow()

    /** Resultados recientes, para no volver a preguntar lo mismo al volver atras. */
    private val cache = ConcurrentHashMap<String, CacheEntry>()

    private data class CacheEntry(val links: List<StreamLink>, val atMillis: Long)

    fun unregister(id: String) {
        _connectors.value = _connectors.value.filterNot { it.id == id }
        cache.keys.removeAll { it.startsWith("$id|") }
    }

    fun replaceAll(connectors: List<SourceConnector>) {
        _connectors.value = connectors
        cache.clear()
    }

    fun invalidate() = cache.clear()

    /** Un avance de la busqueda: lo encontrado hasta ahora y cuanto falta. */
    data class Progress(
        val links: List<StreamLink>,
        val completed: Int,
        val total: Int
    ) {
        val done: Boolean get() = completed >= total
        val percent: Int get() = if (total <= 0) 100 else (completed * 100 / total)
    }

    /**
     * Igual que [resolve] pero emite los enlaces **segun van llegando** de cada
     * fuente, con el progreso. Asi la hoja puede mostrar lo que ya hay y avisar
     * de que sigue buscando en mas servidores, en vez de esperar a todas en
     * silencio.
     */
    fun resolveStreaming(
        request: StreamRequest,
        filters: LinkFilters = LinkFilters(),
        perSourceTimeoutMs: Long = 40_000,
        cacheTtlMillis: Long = 30 * 60 * 1000
    ): Flow<Progress> = channelFlow {
        val active = _connectors.value
        val total = active.size
        if (total == 0) {
            trySend(Progress(emptyList(), 0, 0))
            return@channelFlow
        }

        val key = cacheKey(request)
        val now = System.currentTimeMillis()
        val gathered = mutableListOf<Pair<StreamLink, Int>>()
        val completed = AtomicInteger(0)

        // Se emite un primer aviso con 0% para que la hoja arranque el indicador.
        trySend(Progress(emptyList(), 0, total))

        val jobs = active.mapIndexed { priority, connector ->
            launch(Dispatchers.IO) {
                val ck = "${connector.id}|$key"
                val cached = cache[ck]?.takeIf { now - it.atMillis < cacheTtlMillis }?.links
                val links = cached ?: run {
                    val started = System.nanoTime()
                    val got = withTimeoutOrNull(perSourceTimeoutMs) {
                        runCatching { connector.resolve(request) }
                            .onFailure { Log.w(TAG, "${connector.id} fallo: ${it.message}") }
                            .getOrDefault(emptyList())
                    } ?: emptyList()
                    val elapsed = (System.nanoTime() - started) / 1_000_000
                    got.map { it.copy(latencyMs = it.latencyMs ?: elapsed) }
                        // Solo se cachea lo que dio enlaces: una fuente que fallo
                        // o agoto el tiempo se reintenta al refrescar, y asi los
                        // refrescos van sumando fuentes en vez de sortear timing.
                        .also { if (it.isNotEmpty()) cache[ck] = CacheEntry(it, now) }
                }
                val snapshot = synchronized(gathered) {
                    gathered += links.map { it to priority }
                    rank(gathered, filters)
                }
                trySend(Progress(snapshot, completed.incrementAndGet(), total))
            }
        }
        jobs.joinAll()
    }

    /** Filtra, quita duplicados y ordena, igual que [resolve]. */
    private fun rank(links: List<Pair<StreamLink, Int>>, filters: LinkFilters): List<StreamLink> =
        links.filter { (link, _) -> filters.accepts(link) }
            .distinctBy { (link, _) -> link.url }
            .sortedWith(ranking(filters))
            .map { it.first }

    /**
     * Mejor primero. El usuario elige el criterio principal; los desempates son
     * siempre los mismos para que el orden no baile entre busquedas.
     */
    private fun ranking(filters: LinkFilters): Comparator<Pair<StreamLink, Int>> =
        when (filters.sortBy) {
            LinkFilters.Sort.QUALITY -> compareByDescending<Pair<StreamLink, Int>> { it.first.quality.height }
                .thenByDescending { it.first.instant }
                .thenBy { it.second }
                .thenBy { it.first.latencyMs ?: Long.MAX_VALUE }

            LinkFilters.Sort.SPEED -> compareByDescending<Pair<StreamLink, Int>> { it.first.instant }
                .thenBy { it.first.latencyMs ?: Long.MAX_VALUE }
                .thenByDescending { it.first.quality.height }
                .thenBy { it.second }

            LinkFilters.Sort.SIZE -> compareByDescending<Pair<StreamLink, Int>> { it.first.sizeBytes ?: 0L }
                .thenByDescending { it.first.quality.height }
                .thenBy { it.second }

            LinkFilters.Sort.SOURCE -> compareBy<Pair<StreamLink, Int>> { it.second }
                .thenByDescending { it.first.quality.height }
                .thenByDescending { it.first.instant }
        }

    private fun cacheKey(r: StreamRequest): String =
        "${r.detail.item.id}|${r.season ?: -1}|${r.episode ?: -1}"
}

/** Decide si un enlace sobrevive a los filtros de Ajustes > Fuentes. */
fun LinkFilters.accepts(link: StreamLink): Boolean {
    val q = link.quality
    if (q != Quality.UNKNOWN) {
        if (q.height < minQuality.height) return false
        if (maxQuality != Quality.UNKNOWN && q.height > maxQuality.height) return false
    }
    link.sizeBytes?.let { if (maxSizeBytes > 0 && it > maxSizeBytes) return false }
    if (discardCam && looksLikeCam(link.title ?: link.url)) return false
    if (allowedAudio.isNotEmpty() &&
        link.audio != AudioTag.DESCONOCIDO &&
        link.audio !in allowedAudio
    ) return false
    return true
}

private val CAM_MARKERS = listOf("cam", "hdcam", "camrip", "ts-", ".ts.", "telesync", "hdts", "screener", "dvdscr")

private fun looksLikeCam(text: String): Boolean {
    val t = text.lowercase()
    return CAM_MARKERS.any { t.contains(it) }
}
