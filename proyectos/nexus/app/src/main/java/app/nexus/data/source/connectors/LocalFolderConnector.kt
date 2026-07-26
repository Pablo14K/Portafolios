package app.nexus.data.source.connectors

import android.content.Context
import android.provider.MediaStore
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
import java.util.Locale

/**
 * Videos que ya estan en el dispositivo. Es la unica fuente que funciona sin
 * red y sin configurar nada, asi que sirve de prueba de que la cadena entera
 * (buscar, elegir enlace, reproducir) esta bien montada.
 */
class LocalFolderConnector(
    private val context: Context
) : SourceConnector {

    override val id: String = "local"
    override val displayName: String = "Archivos del dispositivo"
    override val type: SourceType = SourceType.LOCAL

    override suspend fun resolve(request: StreamRequest): List<StreamLink> =
        withContext(Dispatchers.IO) {
            val needles = buildNeedles(request)
            if (needles.isEmpty()) return@withContext emptyList()

            val projection = arrayOf(
                MediaStore.Video.Media._ID,
                MediaStore.Video.Media.DISPLAY_NAME,
                MediaStore.Video.Media.SIZE,
                MediaStore.Video.Media.HEIGHT,
                MediaStore.Video.Media.DATA
            )

            val out = mutableListOf<StreamLink>()
            runCatching {
                context.contentResolver.query(
                    MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
                    projection,
                    null,
                    null,
                    "${MediaStore.Video.Media.DATE_ADDED} DESC"
                )?.use { cursor ->
                    val idCol = cursor.getColumnIndexOrThrow(MediaStore.Video.Media._ID)
                    val nameCol = cursor.getColumnIndexOrThrow(MediaStore.Video.Media.DISPLAY_NAME)
                    val sizeCol = cursor.getColumnIndexOrThrow(MediaStore.Video.Media.SIZE)
                    val heightCol = cursor.getColumnIndexOrThrow(MediaStore.Video.Media.HEIGHT)

                    while (cursor.moveToNext()) {
                        val name = cursor.getString(nameCol) ?: continue
                        val normalized = normalize(name)
                        if (needles.none { containsWholeTitle(normalized, it) }) continue

                        val mediaId = cursor.getLong(idCol)
                        val uri = MediaStore.Video.Media.EXTERNAL_CONTENT_URI
                            .buildUpon()
                            .appendPath(mediaId.toString())
                            .build()

                        val height = cursor.getInt(heightCol)
                        out += StreamLink(
                            url = uri.toString(),
                            sourceId = id,
                            sourceName = displayName,
                            container = StreamContainer.LOCAL_FILE,
                            quality = if (height > 0) {
                                Quality.fromHeight(height)
                            } else {
                                Quality.fromLabel(name)
                            },
                            audio = audioFromName(name),
                            title = name,
                            sizeBytes = cursor.getLong(sizeCol).takeIf { it > 0 },
                            // Los contenedores raros (mkv con DTS, avi antiguos)
                            // se los queda VLC directamente.
                            requiresVlc = name.endsWith(".avi", true) ||
                                name.endsWith(".wmv", true) ||
                                name.endsWith(".flv", true),
                            instant = true,
                            latencyMs = 0
                        )
                    }
                }
            }
            out
        }

    override suspend fun healthCheck(): SourceHealth = SourceHealth.Ok(0)

    /**
     * Para un episodio se acepta tanto "S02E07" como "2x07", que son las dos
     * formas en que suelen venir nombrados los archivos.
     *
     * El titulo se exige entero, no por palabras sueltas: buscar "Supergirl"
     * no debe sacar cualquier archivo que lleve la palabra "girl".
     */
    private fun buildNeedles(request: StreamRequest): List<String> {
        val title = normalize(request.detail.item.title)
        if (title.length < 3) return emptyList()
        if (!request.isEpisode) return listOf(title)

        val s = request.season!!
        val e = request.episode!!
        return listOf(
            "$title ${"s%02de%02d".format(Locale.ROOT, s, e)}",
            "$title ${s}x${"%02d".format(Locale.ROOT, e)}",
            "$title ${"s%02de%02d".format(Locale.ROOT, s, e)}".replace(" ", "")
        )
    }

    /**
     * Coincidencia por palabra completa. Sin esto, "Up" casaba con "Superman" y
     * el usuario terminaba viendo algo que no habia pedido.
     */
    private fun containsWholeTitle(haystack: String, needle: String): Boolean {
        val index = haystack.indexOf(needle)
        if (index < 0) return false
        val beforeOk = index == 0 || !haystack[index - 1].isLetterOrDigit()
        val end = index + needle.length
        val afterOk = end == haystack.length || !haystack[end].isLetterOrDigit()
        return beforeOk && afterOk
    }

    /** Quita puntos, guiones y acentos para que "El.Eternauta-1080p" case. */
    private fun normalize(text: String): String = text
        .lowercase(Locale.ROOT)
        .replace(Regex("[._\\-\\[\\]()]+"), " ")
        .replace("á", "a").replace("é", "e").replace("í", "i")
        .replace("ó", "o").replace("ú", "u").replace("ñ", "n")
        .replace(Regex("\\s+"), " ")
        .trim()

    private fun audioFromName(name: String): AudioTag {
        val t = name.lowercase(Locale.ROOT)
        return when {
            t.contains("latino") || t.contains("lat") -> AudioTag.LATINO
            t.contains("castellano") || t.contains("cast") -> AudioTag.CASTELLANO
            t.contains("dual") -> AudioTag.DUAL
            t.contains("sub") || t.contains("vose") -> AudioTag.SUBTITULADO
            else -> AudioTag.DESCONOCIDO
        }
    }
}
