package app.nexus.domain

/** Como hay que abrir el enlace. Decide que motor de video se usa. */
enum class StreamContainer {
    /** mp4, mkv, avi... servido por HTTP con rangos. */
    PROGRESSIVE,
    HLS,
    DASH,
    RTSP,
    /** rtmp, udp, y demas cosas que solo VLC sabe abrir. */
    RAW,
    /** Archivo del propio dispositivo. */
    LOCAL_FILE
}

enum class Quality(val height: Int, val label: String) {
    UNKNOWN(0, "?"),
    SD_480(480, "480p"),
    HD_720(720, "720p"),
    FHD_1080(1080, "1080p"),
    QHD_1440(1440, "1440p"),
    UHD_2160(2160, "4K");

    companion object {
        /** Adivina la calidad por el nombre del archivo, como hacen las cuatro apps. */
        fun fromLabel(text: String?): Quality {
            if (text.isNullOrBlank()) return UNKNOWN
            val t = text.lowercase()
            return when {
                t.contains("2160") || t.contains("4k") || t.contains("uhd") -> UHD_2160
                t.contains("1440") -> QHD_1440
                t.contains("1080") || t.contains("fhd") -> FHD_1080
                t.contains("720") || t.contains("hd") -> HD_720
                t.contains("480") || t.contains("sd") -> SD_480
                else -> UNKNOWN
            }
        }

        fun fromHeight(h: Int?): Quality = when {
            h == null || h <= 0 -> UNKNOWN
            h >= 2000 -> UHD_2160
            h >= 1300 -> QHD_1440
            h >= 900 -> FHD_1080
            h >= 640 -> HD_720
            else -> SD_480
        }
    }
}

/** Etiquetas de pista que las apps de referencia muestran como LAT / CAST / SUB. */
enum class AudioTag(val label: String) {
    LATINO("Latino"),
    CASTELLANO("Castellano"),
    SUBTITULADO("Subtitulado"),
    ORIGINAL("Original"),
    DUAL("Dual"),
    DESCONOCIDO("?")
}

/**
 * Un enlace reproducible devuelto por un conector.
 *
 * [headers] existe porque muchos servidores exigen Referer o User-Agent
 * concretos; sin ellos devuelven 403.
 */
data class StreamLink(
    val url: String,
    val sourceId: String,
    val sourceName: String,
    val container: StreamContainer,
    val quality: Quality = Quality.UNKNOWN,
    val audio: AudioTag = AudioTag.DESCONOCIDO,
    val title: String? = null,
    val sizeBytes: Long? = null,
    val headers: Map<String, String> = emptyMap(),
    val subtitleUrls: List<SubtitleTrack> = emptyList(),
    /** true cuando ExoPlayer no puede con esto y hay que ir directo a VLC. */
    val requiresVlc: Boolean = false,
    /** Enlaces ya cacheados en un debrid o en la red local: van primero. */
    val instant: Boolean = false,
    val latencyMs: Long? = null
) {
    val sizeLabel: String?
        get() = sizeBytes?.let {
            when {
                it >= 1_073_741_824 -> String.format("%.1f GB", it / 1_073_741_824.0)
                it >= 1_048_576 -> String.format("%.0f MB", it / 1_048_576.0)
                else -> "$it B"
            }
        }
}

data class SubtitleTrack(
    val url: String,
    val language: String,
    val label: String = language,
    val mimeType: String = "application/x-subrip"
)

/** Lo que se le pide a los conectores. */
data class StreamRequest(
    val detail: MediaDetail,
    val season: Int? = null,
    val episode: Int? = null
) {
    val isEpisode: Boolean get() = season != null && episode != null

    /** Texto de busqueda que usan los conectores sin catalogo propio. */
    fun searchQuery(): String = buildString {
        append(detail.item.title)
        detail.item.year?.let { if (!isEpisode) append(" $it") }
        if (isEpisode) append(String.format(" S%02dE%02d", season, episode))
    }
}
