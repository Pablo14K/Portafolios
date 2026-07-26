package app.nexus.data.source.connectors

import android.util.Log
import app.nexus.core.Net
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceHealth
import app.nexus.data.source.SourceType
import app.nexus.domain.AudioTag
import app.nexus.domain.Quality
import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.Locale

private const val TAG = "Addon"

/**
 * Addon por URL. Es el mecanismo que usa el apartado de addons de EPIX PLAY,
 * reimplementado sobre el protocolo abierto: el addon publica un manifiesto y
 * responde a `/stream/{tipo}/{id}.json`.
 *
 * Aqui solo vive el cliente. Que addon se instala y que devuelve es decision
 * de quien usa la app; la app no trae ninguno preconfigurado.
 */
class AddonConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    /** URL del manifiesto o su carpeta; se acepta cualquiera de las dos. */
    private val addonUrl: String
) : SourceConnector {

    override val type: SourceType = SourceType.ADDON

    /** Base sin `manifest.json` al final y sin barra sobrante. */
    private val base: String = addonUrl.trim()
        .removeSuffix("/")
        .removeSuffix("/manifest.json")
        .removeSuffix("manifest.json")
        .removeSuffix("/")

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            // El protocolo identifica los titulos por su id de IMDb. Sin el no
            // hay nada que preguntar.
            val imdb = request.detail.imdbId?.takeIf { it.startsWith("tt") }
                ?: return@withContext emptyList()

            val (type, contentId) = if (request.isEpisode) {
                "series" to "$imdb:${request.season}:${request.episode}"
            } else {
                "movie" to imdb
            }

            val url = "$base/stream/$type/$contentId.json"
            val response = get<StreamsResponse>(url) ?: return@withContext emptyList()

            response.streams
                // Los que solo traen infoHash son torrents y esta app no lleva
                // motor de torrent: mostrarlos seria prometer algo que no hace.
                .filter { !it.url.isNullOrBlank() }
                .take(20)
                .map { it.toLink() }
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val started = System.nanoTime()
        val manifest = get<AddonManifest>("$base/manifest.json")
        val elapsed = (System.nanoTime() - started) / 1_000_000
        when {
            manifest == null -> SourceHealth.Failing("No responde o no es un addon valido")
            !manifest.servesStreams() ->
                SourceHealth.Failing("Este addon no sirve enlaces, solo catalogo")

            else -> SourceHealth.Ok(elapsed)
        }
    }

    private inline fun <reified T> get(url: String): T? = runCatching {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", Net.DEFAULT_USER_AGENT)
            .header("Accept", "application/json")
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return null
            val text = response.body?.string() ?: return null
            Net.json.decodeFromString<T>(text)
        }
    }.onFailure { Log.w(TAG, "$id: ${it.message}") }.getOrNull()

    private fun AddonStream.toLink(): StreamLink {
        // Los addons meten calidad, tamano e idioma dentro del texto libre;
        // no hay campos para eso en el protocolo.
        val label = listOfNotNull(name, title).joinToString(" ")
        val streamUrl = url.orEmpty()

        return StreamLink(
            url = streamUrl,
            sourceId = id,
            sourceName = displayName,
            container = containerFor(streamUrl),
            quality = Quality.fromLabel(label),
            audio = audioFromLabel(label),
            title = title?.replace('\n', ' ')?.trim() ?: name,
            sizeBytes = parseSize(label),
            headers = behaviorHints?.proxyHeaders?.request.orEmpty(),
            requiresVlc = streamUrl.startsWith("rtmp", true)
        )
    }

    private fun containerFor(url: String): StreamContainer {
        val u = url.lowercase(Locale.ROOT)
        return when {
            u.contains(".m3u8") -> StreamContainer.HLS
            u.contains(".mpd") -> StreamContainer.DASH
            u.startsWith("rtsp") -> StreamContainer.RTSP
            u.startsWith("rtmp") -> StreamContainer.RAW
            else -> StreamContainer.PROGRESSIVE
        }
    }

    /** Reconoce "1.4 GB", "700MB" y demas formas sueltas dentro del texto. */
    private fun parseSize(label: String): Long? {
        val match = SIZE_PATTERN.find(label) ?: return null
        val amount = match.groupValues[1].replace(',', '.').toDoubleOrNull() ?: return null
        return when (match.groupValues[2].uppercase(Locale.ROOT)) {
            "GB", "GIB" -> (amount * 1_073_741_824).toLong()
            "MB", "MIB" -> (amount * 1_048_576).toLong()
            else -> null
        }
    }

    private fun audioFromLabel(label: String): AudioTag {
        val t = label.lowercase(Locale.ROOT)
        return when {
            t.contains("latino") -> AudioTag.LATINO
            t.contains("castellano") -> AudioTag.CASTELLANO
            t.contains("dual") -> AudioTag.DUAL
            t.contains("subtitul") || t.contains("vose") -> AudioTag.SUBTITULADO
            else -> AudioTag.DESCONOCIDO
        }
    }

    private companion object {
        val SIZE_PATTERN = Regex("""(\d+[.,]?\d*)\s*(GB|GiB|MB|MiB)""", RegexOption.IGNORE_CASE)
    }
}

// --- Protocolo ------------------------------------------------------------

/**
 * `resources` admite dos formas en el protocolo: una lista de nombres, o una
 * lista de objetos con mas detalle. Se lee en crudo para aceptar las dos.
 */
@Serializable
private data class AddonManifest(
    val id: String? = null,
    val name: String? = null,
    val version: String? = null,
    val resources: List<JsonElement> = emptyList(),
    val types: List<String> = emptyList()
) {
    fun servesStreams(): Boolean = resources.any { element ->
        when (element) {
            is JsonPrimitive -> element.contentOrNull.equals("stream", ignoreCase = true)
            is JsonObject -> (element["name"] as? JsonPrimitive)
                ?.contentOrNull
                .equals("stream", ignoreCase = true)

            else -> false
        }
    }
}

@Serializable
private data class StreamsResponse(val streams: List<AddonStream> = emptyList())

@Serializable
private data class AddonStream(
    val name: String? = null,
    val title: String? = null,
    val url: String? = null,
    val infoHash: String? = null,
    val behaviorHints: BehaviorHints? = null
)

@Serializable
private data class BehaviorHints(
    @SerialName("proxyHeaders") val proxyHeaders: ProxyHeaders? = null
)

@Serializable
private data class ProxyHeaders(val request: Map<String, String>? = null)
