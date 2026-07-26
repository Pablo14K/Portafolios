package app.nexus.data.prefs

import app.nexus.domain.AudioTag
import app.nexus.domain.Quality

/** Filtros que se aplican a los enlaces antes de mostrarlos. */
data class LinkFilters(
    val minQuality: Quality = Quality.SD_480,
    val maxQuality: Quality = Quality.UHD_2160,
    val maxSizeBytes: Long = 0L,
    val discardCam: Boolean = true,
    val allowedAudio: Set<AudioTag> = emptySet(),
    val sortBy: Sort = Sort.QUALITY,
    val autoPlayBest: Boolean = false,
    val skipToNextOnFailure: Boolean = true,
    val maxRetries: Int = 3,
    val cacheTtlMinutes: Int = 30
) {
    enum class Sort(val label: String) {
        QUALITY("Calidad"),
        SPEED("Velocidad"),
        SIZE("Tamano"),
        SOURCE("Orden de fuentes")
    }
}

data class PlayerPrefs(
    val engine: Engine = Engine.AUTO,
    val hardwareDecoding: Boolean = true,
    val matchRefreshRate: Boolean = true,
    val qualityOnWifi: Quality = Quality.UHD_2160,
    val qualityOnMobile: Quality = Quality.HD_720,
    val bufferSeconds: Int = 60,
    val resumePlayback: Boolean = true,
    val askBeforeResume: Boolean = false,
    val watchedThresholdPercent: Int = 90,
    val autoPlayNextEpisode: Boolean = true,
    val autoPlayCountdownSeconds: Int = 8,
    val skipIntro: SkipMode = SkipMode.BUTTON,
    val skipCredits: Boolean = true,
    val defaultSpeed: Float = 1.0f,
    val gestures: Boolean = true,
    val doubleTapSeekSeconds: Int = 10,
    val pipOnLeave: Boolean = true,
    val keepScreenOn: Boolean = true
) {
    enum class Engine(val label: String) {
        AUTO("ExoPlayer con respaldo VLC"),
        EXOPLAYER("Solo ExoPlayer"),
        VLC("Solo VLC"),
        EXTERNAL("Reproductor externo")
    }

    enum class SkipMode(val label: String) {
        OFF("Desactivado"),
        BUTTON("Mostrar boton"),
        AUTO("Saltar solo")
    }
}

data class SubtitlePrefs(
    val preferredLanguage: String = "es",
    val secondaryLanguage: String = "en",
    val enabledByDefault: Boolean = true,
    val onlyWhenAudioDiffers: Boolean = false,
    val autoSearchOnline: Boolean = true,
    val textScalePercent: Int = 100,
    val textColor: Long = 0xFFFFFFFF,
    val outline: Outline = Outline.OUTLINE,
    val backgroundOpacityPercent: Int = 55,
    val verticalOffsetPercent: Int = -6,
    val respectAssStyles: Boolean = true,
    val hideSdh: Boolean = false,
    val encoding: String = "UTF-8"
) {
    enum class Outline(val label: String) {
        NONE("Sin borde"),
        OUTLINE("Contorno"),
        SHADOW("Sombra"),
        RAISED("Relieve")
    }
}

data class GeneralPrefs(
    val appLanguage: String = "es",
    val metadataLanguage: String = "es-ES",
    val region: String = "EC",
    val preferOriginalTitles: Boolean = false,
    val animeRomaji: Boolean = false,
    val posterSize: PosterSize = PosterSize.MEDIUM,
    val gridColumns: Int = 3,
    val showTitlesUnderPosters: Boolean = false,
    val accentColor: Long = 0xFF00FF2C,
    val tintFromPoster: Boolean = true,
    val animations: Boolean = true,
    val forceTvInterface: Boolean? = null,
    val downloadsWifiOnly: Boolean = true,
    val maxParallelDownloads: Int = 2,
    val historyEnabled: Boolean = true
) {
    enum class PosterSize(val label: String, val widthDp: Int) {
        SMALL("Pequeno", 92),
        MEDIUM("Mediano", 116),
        LARGE("Grande", 148)
    }
}
