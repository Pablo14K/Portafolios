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
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.Locale

private const val TAG = "Jellyfin"

/**
 * Servidor propio: Jellyfin o Emby, que comparten API en lo que aqui se usa.
 *
 * Es la fuente mas fiable de todas porque el contenido es tuyo y esta en tu
 * red: responde en milisegundos y no caduca. Por eso sus enlaces se marcan
 * como [StreamLink.instant] y suelen ganar el desempate.
 */
class JellyfinConnector(
    override val id: String,
    override val displayName: String,
    private val client: OkHttpClient,
    private val host: String,
    private val username: String,
    private val password: String
) : SourceConnector {

    override val type: SourceType = SourceType.SERVER

    private val base: HttpUrl? = host.trim().trimEnd('/')
        .let { if (it.startsWith("http")) it else "http://$it" }
        .toHttpUrlOrNull()

    /** Token y usuario se piden una vez y se reutilizan mientras valgan. */
    @Volatile
    private var session: Session? = null

    private data class Session(val token: String, val userId: String)

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            val root = base ?: return@withContext emptyList()
            val auth = authenticate(root) ?: return@withContext emptyList()

            val title = request.detail.item.title
            val wantedType = if (request.isEpisode) "Series" else "Movie"

            val results = search(root, auth, title, wantedType) ?: return@withContext emptyList()
            val match = results.firstOrNull { candidate ->
                matches(candidate, title, request.detail.item.year)
            } ?: return@withContext emptyList()

            if (!request.isEpisode) {
                return@withContext listOf(streamLink(root, auth, match))
            }

            val episodes = episodes(root, auth, match.id, request.season!!) ?: return@withContext emptyList()
            episodes
                .filter { it.indexNumber == request.episode }
                .map { streamLink(root, auth, it) }
        }

    override suspend fun healthCheck(): SourceHealth = withContext(Dispatchers.IO) {
        val root = base ?: return@withContext SourceHealth.Failing("La direccion no es valida")
        val started = System.nanoTime()
        // Un token viejo puede haber caducado: se fuerza uno nuevo al probar.
        session = null
        val auth = authenticate(root)
        val elapsed = (System.nanoTime() - started) / 1_000_000
        if (auth == null) {
            SourceHealth.Failing("No se pudo entrar. Revisa direccion, usuario y clave.")
        } else {
            SourceHealth.Ok(elapsed)
        }
    }

    // --- API --------------------------------------------------------------

    private fun authenticate(root: HttpUrl): Session? {
        session?.let { return it }

        val body = buildJsonObject {
            put("Username", username)
            put("Pw", password)
        }.toString().toRequestBody("application/json".toMediaType())

        val request = Request.Builder()
            .url(root.newBuilder().addPathSegments("Users/AuthenticateByName").build())
            .post(body)
            .header("Authorization", AUTH_HEADER)
            .header("Content-Type", "application/json")
            .build()

        return runCatching {
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                val text = response.body?.string() ?: return null
                val result = Net.json.decodeFromString<AuthResult>(text)
                val token = result.accessToken ?: return null
                val userId = result.user?.id ?: return null
                Session(token, userId).also { session = it }
            }
        }.onFailure { Log.w(TAG, "$id auth: ${it.message}") }.getOrNull()
    }

    private fun search(
        root: HttpUrl,
        auth: Session,
        term: String,
        itemType: String
    ): List<JellyfinItem>? {
        val url = root.newBuilder()
            .addPathSegment("Items")
            .addQueryParameter("userId", auth.userId)
            .addQueryParameter("searchTerm", term)
            .addQueryParameter("IncludeItemTypes", itemType)
            .addQueryParameter("Recursive", "true")
            .addQueryParameter("Limit", "20")
            .addQueryParameter("Fields", "MediaSources,ProductionYear")
            .build()
        return get<ItemsResult>(url, auth)?.items
    }

    private fun episodes(
        root: HttpUrl,
        auth: Session,
        seriesId: String,
        season: Int
    ): List<JellyfinItem>? {
        val url = root.newBuilder()
            .addPathSegments("Shows/$seriesId/Episodes")
            .addQueryParameter("userId", auth.userId)
            .addQueryParameter("season", season.toString())
            .addQueryParameter("Fields", "MediaSources")
            .build()
        return get<ItemsResult>(url, auth)?.items
    }

    private inline fun <reified T> get(url: HttpUrl, auth: Session): T? {
        val request = Request.Builder()
            .url(url)
            .header("Authorization", "$AUTH_HEADER, Token=\"${auth.token}\"")
            .build()
        return runCatching {
            client.newCall(request).execute().use { response ->
                if (response.code == 401) {
                    // El token caduco: se descarta para reintentar en la
                    // siguiente busqueda con uno nuevo.
                    session = null
                    return null
                }
                if (!response.isSuccessful) return null
                val text = response.body?.string() ?: return null
                Net.json.decodeFromString<T>(text)
            }
        }.onFailure { Log.w(TAG, "$id: ${it.message}") }.getOrNull()
    }

    /**
     * Reproduccion directa: se pide el archivo tal cual, sin transcodificar.
     * Si el dispositivo no puede con el formato, ya salta VLC.
     */
    private fun streamLink(root: HttpUrl, auth: Session, item: JellyfinItem): StreamLink {
        val source = item.mediaSources?.firstOrNull()
        val videoStream = source?.mediaStreams?.firstOrNull { it.type == "Video" }

        val url = root.newBuilder()
            .addPathSegments("Videos/${item.id}/stream")
            .addQueryParameter("static", "true")
            .addQueryParameter("api_key", auth.token)
            .build()
            .toString()

        val container = source?.container?.lowercase(Locale.ROOT)
        return StreamLink(
            url = url,
            sourceId = id,
            sourceName = displayName,
            container = StreamContainer.PROGRESSIVE,
            quality = Quality.fromHeight(videoStream?.height)
                .takeIf { it != Quality.UNKNOWN }
                ?: Quality.fromLabel(source?.name ?: item.name),
            audio = audioFromStreams(source),
            title = item.name,
            sizeBytes = source?.size,
            // En red local no hay espera: son los enlaces que deben ir primero.
            instant = true,
            requiresVlc = container == "avi" || container == "wmv" || container == "flv"
        )
    }

    // --- Ayudas -----------------------------------------------------------

    private fun audioFromStreams(source: JellyfinMediaSource?): AudioTag {
        val languages = source?.mediaStreams
            .orEmpty()
            .filter { it.type == "Audio" }
            .mapNotNull { it.language?.lowercase(Locale.ROOT) }

        return when {
            languages.isEmpty() -> AudioTag.DESCONOCIDO
            languages.size > 1 -> AudioTag.DUAL
            languages.any { it.startsWith("spa") || it.startsWith("es") } -> AudioTag.LATINO
            else -> AudioTag.ORIGINAL
        }
    }

    /**
     * El titulo se exige como palabra completa y, si hay ano, que cuadre. Con
     * coincidencia parcial acababan colandose peliculas que no eran la pedida.
     */
    private fun matches(item: JellyfinItem, title: String, year: Int?): Boolean {
        val haystack = normalize(item.name)
        val needle = normalize(title)
        val index = haystack.indexOf(needle)
        if (index < 0) return false

        val beforeOk = index == 0 || !haystack[index - 1].isLetterOrDigit()
        val end = index + needle.length
        val afterOk = end == haystack.length || !haystack[end].isLetterOrDigit()
        if (!beforeOk || !afterOk) return false

        if (year == null || item.productionYear == null) return true
        return kotlin.math.abs(item.productionYear - year) <= 1
    }

    private fun normalize(text: String): String = text
        .lowercase(Locale.ROOT)
        .replace(Regex("[._\\-\\[\\]()|:]+"), " ")
        .replace("á", "a").replace("é", "e").replace("í", "i")
        .replace("ó", "o").replace("ú", "u").replace("ñ", "n")
        .replace(Regex("\\s+"), " ")
        .trim()

    private companion object {
        /**
         * Jellyfin exige identificarse en cada peticion con esta cabecera; sin
         * ella responde 400 antes de mirar el token.
         */
        const val AUTH_HEADER =
            "MediaBrowser Client=\"Nexus\", Device=\"Android\", DeviceId=\"nexus-android\", Version=\"1.0.0\""
    }
}

// --- Respuestas -----------------------------------------------------------

@Serializable
private data class AuthResult(
    @SerialName("AccessToken") val accessToken: String? = null,
    @SerialName("User") val user: AuthUser? = null
)

@Serializable
private data class AuthUser(@SerialName("Id") val id: String? = null)

@Serializable
private data class ItemsResult(@SerialName("Items") val items: List<JellyfinItem> = emptyList())

@Serializable
private data class JellyfinItem(
    @SerialName("Id") val id: String,
    @SerialName("Name") val name: String = "",
    @SerialName("Type") val type: String? = null,
    @SerialName("ProductionYear") val productionYear: Int? = null,
    @SerialName("IndexNumber") val indexNumber: Int? = null,
    @SerialName("ParentIndexNumber") val parentIndexNumber: Int? = null,
    @SerialName("MediaSources") val mediaSources: List<JellyfinMediaSource>? = null
)

@Serializable
private data class JellyfinMediaSource(
    @SerialName("Name") val name: String? = null,
    @SerialName("Container") val container: String? = null,
    @SerialName("Size") val size: Long? = null,
    @SerialName("MediaStreams") val mediaStreams: List<JellyfinStream>? = null
)

@Serializable
private data class JellyfinStream(
    @SerialName("Type") val type: String? = null,
    @SerialName("Language") val language: String? = null,
    @SerialName("Height") val height: Int? = null,
    @SerialName("DisplayTitle") val displayTitle: String? = null
)
