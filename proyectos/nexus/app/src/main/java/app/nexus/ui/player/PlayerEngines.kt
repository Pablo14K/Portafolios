package app.nexus.ui.player

import android.content.Context
import android.graphics.Color
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.datasource.DataSource
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.okhttp.OkHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.DefaultRenderersFactory
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.ui.CaptionStyleCompat
import app.nexus.core.Net
import app.nexus.data.prefs.PlayerPrefs
import app.nexus.data.prefs.SubtitlePrefs
import app.nexus.domain.StreamContainer
import org.videolan.libvlc.LibVLC
import org.videolan.libvlc.Media
import org.videolan.libvlc.MediaPlayer
import android.net.Uri as AndroidUri

/**
 * Elige motor. La regla es la que se acordo: ExoPlayer siempre que pueda, VLC
 * cuando el enlace sea de los que ExoPlayer no abre (rtmp, udp, avi antiguos).
 */
fun chooseEngine(prefs: PlayerPrefs, request: PlaybackRequest): Engine = when (prefs.engine) {
    PlayerPrefs.Engine.EXOPLAYER -> Engine.EXO
    PlayerPrefs.Engine.VLC -> Engine.VLC
    PlayerPrefs.Engine.EXTERNAL -> Engine.EXTERNAL
    PlayerPrefs.Engine.AUTO ->
        if (request.requiresVlc || request.containerType == StreamContainer.RAW) {
            Engine.VLC
        } else {
            Engine.EXO
        }
}

enum class Engine { EXO, VLC, EXTERNAL }

fun buildExoPlayer(
    context: Context,
    request: PlaybackRequest,
    prefs: PlayerPrefs,
    subtitlePrefs: SubtitlePrefs
): ExoPlayer {
    val bufferMs = prefs.bufferSeconds.coerceIn(10, 300) * 1000

    val loadControl = DefaultLoadControl.Builder()
        .setBufferDurationsMs(
            /* minBufferMs = */ bufferMs / 2,
            /* maxBufferMs = */ bufferMs,
            /* bufferForPlaybackMs = */ 2_500,
            /* bufferForPlaybackAfterRebufferMs = */ 5_000
        )
        .build()

    val renderers = DefaultRenderersFactory(context).apply {
        setExtensionRendererMode(
            if (prefs.hardwareDecoding) {
                DefaultRenderersFactory.EXTENSION_RENDERER_MODE_OFF
            } else {
                DefaultRenderersFactory.EXTENSION_RENDERER_MODE_PREFER
            }
        )
        setEnableDecoderFallback(true)
    }

    return ExoPlayer.Builder(context)
        .setRenderersFactory(renderers)
        .setLoadControl(loadControl)
        .setMediaSourceFactory(DefaultMediaSourceFactory(dataSourceFactory(context, request)))
        .build()
        .apply {
            // Preferencias de pista: ExoPlayer elige solo la que encaje.
            trackSelectionParameters = trackSelectionParameters
                .buildUpon()
                .setPreferredTextLanguages(
                    subtitlePrefs.preferredLanguage,
                    subtitlePrefs.secondaryLanguage
                )
                .setPreferredAudioLanguage(subtitlePrefs.preferredLanguage)
                .setSelectUndeterminedTextLanguage(subtitlePrefs.enabledByDefault)
                .setMaxVideoSize(
                    Int.MAX_VALUE,
                    prefs.qualityOnWifi.height.takeIf { it > 0 } ?: Int.MAX_VALUE
                )
                .setIgnoredTextSelectionFlags(
                    if (subtitlePrefs.enabledByDefault) 0 else C.SELECTION_FLAG_DEFAULT
                )
                .build()

            setMediaItem(request.toMediaItem())
            if (request.startPositionMs > 0) seekTo(request.startPositionMs)
            setPlaybackSpeed(prefs.defaultSpeed)
            prepare()
        }
}

/**
 * Algunos enlaces exigen cabeceras concretas; sin ellas
 * devuelven 403. Los archivos locales van por el proveedor del sistema.
 */
private fun dataSourceFactory(context: Context, request: PlaybackRequest): DataSource.Factory {
    if (request.containerType == StreamContainer.LOCAL_FILE) {
        return DefaultDataSource.Factory(context)
    }
    val http = OkHttpDataSource.Factory(Net.client(context))
        .setUserAgent(request.headers["User-Agent"] ?: Net.DEFAULT_USER_AGENT)
        .apply {
            val extra = request.headers.filterKeys { !it.equals("User-Agent", true) }
            if (extra.isNotEmpty()) setDefaultRequestProperties(extra)
        }
    return DefaultDataSource.Factory(context, http)
}

private fun PlaybackRequest.toMediaItem(): MediaItem {
    val builder = MediaItem.Builder().setUri(AndroidUri.parse(url))
    when (containerType) {
        StreamContainer.HLS -> builder.setMimeType(MimeTypes.APPLICATION_M3U8)
        StreamContainer.DASH -> builder.setMimeType(MimeTypes.APPLICATION_MPD)
        StreamContainer.RTSP -> builder.setMimeType(MimeTypes.APPLICATION_RTSP)
        else -> Unit
    }
    return builder.build()
}

/**
 * Traduce los ajustes de subtitulos al formato que entiende la vista de
 * ExoPlayer. Sin esto, los controles de Ajustes serian decorativos.
 */
fun subtitleStyle(prefs: SubtitlePrefs): CaptionStyleCompat {
    val edgeType = when (prefs.outline) {
        SubtitlePrefs.Outline.NONE -> CaptionStyleCompat.EDGE_TYPE_NONE
        SubtitlePrefs.Outline.OUTLINE -> CaptionStyleCompat.EDGE_TYPE_OUTLINE
        SubtitlePrefs.Outline.SHADOW -> CaptionStyleCompat.EDGE_TYPE_DROP_SHADOW
        SubtitlePrefs.Outline.RAISED -> CaptionStyleCompat.EDGE_TYPE_RAISED
    }
    val backgroundAlpha = (prefs.backgroundOpacityPercent.coerceIn(0, 100) * 255) / 100
    return CaptionStyleCompat(
        prefs.textColor.toInt(),
        Color.argb(backgroundAlpha, 0, 0, 0),
        Color.TRANSPARENT,
        edgeType,
        Color.BLACK,
        null
    )
}

// --- VLC ------------------------------------------------------------------

/**
 * Instancia unica de LibVLC: crear una por reproduccion filtra memoria nativa.
 */
object VlcFactory {

    @Volatile
    private var libVlc: LibVLC? = null

    /**
     * Devuelve null si libVLC no puede inicializar en este dispositivo (algunos
     * lanzan UnsupportedOperationException). Antes eso estrellaba la app entera;
     * ahora el reproductor simplemente pasa al siguiente enlace.
     */
    fun lib(context: Context, prefs: PlayerPrefs): LibVLC? = libVlc ?: synchronized(this) {
        libVlc ?: runCatching {
            LibVLC(
                context.applicationContext,
                buildList {
                    add("--no-drop-late-frames")
                    add("--no-skip-frames")
                    add("--rtsp-tcp")
                    add("--network-caching=${prefs.bufferSeconds.coerceIn(5, 60) * 1000}")
                    if (!prefs.hardwareDecoding) add("--avcodec-hw=none")
                }
            )
        }.getOrNull()?.also { libVlc = it }
    }

    fun newPlayer(
        context: Context,
        prefs: PlayerPrefs,
        request: PlaybackRequest
    ): MediaPlayer? {
        val vlc = lib(context, prefs) ?: return null
        val player = MediaPlayer(vlc)
        val media = Media(vlc, AndroidUri.parse(request.url)).apply {
            request.headers.forEach { (key, value) ->
                // VLC pasa cabeceras por opciones con el prefijo del modulo http.
                when {
                    key.equals("User-Agent", true) -> addOption(":http-user-agent=$value")
                    key.equals("Referer", true) -> addOption(":http-referrer=$value")
                    key.equals("Cookie", true) -> addOption(":http-cookies=$value")
                }
            }
            setHWDecoderEnabled(prefs.hardwareDecoding, false)
        }
        player.media = media
        media.release()
        return player
    }
}
