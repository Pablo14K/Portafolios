package app.nexus.ui.player

import app.nexus.domain.StreamContainer
import app.nexus.domain.StreamLink
import java.io.Serializable

/**
 * Lo que se le pasa al reproductor. Se transporta por Intent, asi que no puede
 * arrastrar objetos de dominio con referencias a red o base de datos.
 */
data class PlaybackRequest(
    val url: String,
    val title: String,
    val subtitle: String? = null,
    val mediaId: String,
    val season: Int? = null,
    val episode: Int? = null,
    val container: String = StreamContainer.PROGRESSIVE.name,
    val headers: HashMap<String, String> = HashMap(),
    val requiresVlc: Boolean = false,
    val startPositionMs: Long = 0,
    val posterUrl: String? = null,
    /**
     * Enlaces alternativos completos (con sus cabeceras, que cambian por host),
     * por si el elegido falla: el reproductor salta al siguiente en vez de
     * abortar.
     */
    val alternatives: ArrayList<Alt> = ArrayList()
) : Serializable {

    /** Un enlace candidato con lo justo para reproducirlo. */
    data class Alt(
        val url: String,
        val container: String,
        val headers: HashMap<String, String>,
        val requiresVlc: Boolean
    ) : Serializable {
        companion object { private const val serialVersionUID = 1L }
    }

    val containerType: StreamContainer
        get() = runCatching { StreamContainer.valueOf(container) }
            .getOrDefault(StreamContainer.PROGRESSIVE)

    /** El propio enlace como candidato, para encabezar la lista de intentos. */
    fun asAlt(): Alt = Alt(url, container, headers, requiresVlc)

    companion object {
        private const val serialVersionUID = 1L

        fun from(
            link: StreamLink,
            mediaId: String,
            title: String,
            subtitle: String? = null,
            season: Int? = null,
            episode: Int? = null,
            startPositionMs: Long = 0,
            posterUrl: String? = null,
            alternatives: List<StreamLink> = emptyList()
        ) = PlaybackRequest(
            url = link.url,
            title = title,
            subtitle = subtitle,
            mediaId = mediaId,
            season = season,
            episode = episode,
            container = link.container.name,
            headers = HashMap(link.headers),
            requiresVlc = link.requiresVlc,
            startPositionMs = startPositionMs,
            posterUrl = posterUrl,
            alternatives = ArrayList(
                alternatives.map {
                    Alt(it.url, it.container.name, HashMap(it.headers), it.requiresVlc)
                }
            )
        )
    }
}
