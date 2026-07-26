package app.nexus.ui.player

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.AspectRatio
import androidx.compose.material.icons.filled.Forward30
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Replay10
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import androidx.media3.ui.SubtitleView
import app.nexus.data.db.ProgressEntity
import app.nexus.data.prefs.PlayerPrefs
import app.nexus.data.prefs.SubtitlePrefs
import app.nexus.di.Graph
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.videolan.libvlc.util.VLCVideoLayout
import java.util.Locale

@Composable
fun PlayerScreen(
    graph: Graph,
    request: PlaybackRequest,
    prefs: PlayerPrefs,
    subtitlePrefs: SubtitlePrefs,
    onExit: () -> Unit
) {
    val context = LocalContext.current

    // Lista de intentos: el enlace elegido y, detras, todas las demas fuentes.
    // Si uno falla, se pasa al siguiente en vez de abortar (o de estrellar VLC
    // con una URL mala).
    val candidates = remember(request) { listOf(request.asAlt()) + request.alternatives }
    var attempt by remember { mutableStateOf(0) }
    val active = candidates.getOrElse(attempt) { candidates.first() }

    var engine by remember { mutableStateOf(chooseEngine(prefs, request)) }
    var controlsVisible by remember { mutableStateOf(true) }
    var isPlaying by remember { mutableStateOf(true) }
    var buffering by remember { mutableStateOf(true) }
    var positionMs by remember { mutableLongStateOf(request.startPositionMs) }
    var durationMs by remember { mutableLongStateOf(0L) }
    var scrubbing by remember { mutableFloatStateOf(-1f) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    // Ajuste de imagen: algunos vídeos vienen con un aspect raro y en "Ajustar"
    // quedan como un recuadro pequeño; el botón deja alternar a Zoom/Llenar.
    var videoScale by remember { mutableStateOf(VideoScale.FIT) }

    // El candidato activo, con la posicion ACTUAL como punto de arranque: al
    // saltar de enlace (o caer a VLC) se sigue por donde ibas, no desde el
    // principio. Se recalcula solo al cambiar de intento, que es cuando importa.
    val activeRequest = remember(attempt) {
        request.copy(
            url = active.url,
            container = active.container,
            headers = HashMap(active.headers),
            requiresVlc = active.requiresVlc,
            startPositionMs = positionMs.coerceAtLeast(0L)
        )
    }

    // Al saltar de enlace: reinicia el motor segun el nuevo candidato y limpia
    // el error, para que el reintento arranque en limpio.
    LaunchedEffect(attempt) {
        if (attempt > 0) {
            engine = chooseEngine(prefs, activeRequest)
            errorMessage = null
            buffering = true
        }
    }

    /** true si se avanzo a otra fuente; false si ya no quedan. */
    fun tryNext(): Boolean = if (attempt < candidates.lastIndex) { attempt++; true } else false

    // Guarda el avance cada pocos segundos y al salir, para que "continuar
    // viendo" no dependa de que la app se cierre limpiamente.
    fun saveProgress() {
        if (durationMs <= 0) return
        val watched = positionMs * 100 / durationMs >= prefs.watchedThresholdPercent
        // Se guarda en el scope de la aplicacion, NO en uno de composicion: el
        // guardado final ocurre en onDispose, justo cuando el scope del
        // composable ya se esta cancelando, y la escritura se perdia (volvias a
        // "continuar viendo" y faltaba lo ultimo).
        graph.scope.launch {
            graph.db.progress().upsert(
                ProgressEntity(
                    key = ProgressEntity.keyOf(request.mediaId, request.season, request.episode),
                    mediaId = request.mediaId,
                    kind = "",
                    title = request.title,
                    posterUrl = request.posterUrl,
                    season = request.season,
                    episode = request.episode,
                    positionMs = positionMs,
                    durationMs = durationMs,
                    watched = watched
                )
            )
        }
    }

    Box(
        Modifier
            .fillMaxSize()
            .background(Color.Black)
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null
            ) { controlsVisible = !controlsVisible }
    ) {

        when (engine) {
            Engine.EXO, Engine.EXTERNAL -> {
                val player = remember(attempt) { buildExoPlayer(context, activeRequest, prefs, subtitlePrefs) }
                // true cuando ExoPlayer pinta el primer fotograma. Si al rato de
                // reproducir sigue en false, el video no se esta decodificando (solo
                // se oye el audio) y se cae a VLC. Ver el LaunchedEffect de abajo.
                var firstFrameRendered by remember(attempt) { mutableStateOf(false) }

                DisposableEffect(player) {
                    val listener = object : Player.Listener {
                        override fun onIsPlayingChanged(playing: Boolean) {
                            isPlaying = playing
                        }

                        override fun onRenderedFirstFrame() {
                            firstFrameRendered = true
                        }

                        override fun onPlaybackStateChanged(state: Int) {
                            buffering = state == Player.STATE_BUFFERING
                            if (state == Player.STATE_READY) {
                                durationMs = player.duration.coerceAtLeast(0L)
                            }
                        }

                        override fun onPlayerError(error: PlaybackException) {
                            // Ante cualquier fallo se prueba PRIMERO el siguiente
                            // enlace (otra fuente, con ExoPlayer): suele ser lo que
                            // de verdad reproduce. VLC queda de ultimo recurso, solo
                            // cuando ya no hay mas enlaces, porque es lento y en
                            // algunos equipos ni arranca.
                            when {
                                tryNext() -> Unit
                                prefs.engine == PlayerPrefs.Engine.AUTO && engine == Engine.EXO ->
                                    engine = Engine.VLC
                                else -> errorMessage = readableError(error)
                            }
                        }
                    }
                    player.addListener(listener)
                    player.playWhenReady = true
                    onDispose {
                        saveProgress()
                        player.removeListener(listener)
                        player.release()
                    }
                }

                LaunchedEffect(player) {
                    while (true) {
                        if (scrubbing < 0f) positionMs = player.currentPosition
                        durationMs = player.duration.coerceAtLeast(0L)
                        delay(500)
                    }
                }

                LaunchedEffect(positionMs / 10_000) { saveProgress() }

                // Imagen en negro con sonido: muchos animes vienen en H.264 10-bit
                // (High 10), que la mayoria de decodificadores por hardware no pintan
                // -se oye el audio pero no hay imagen, y ExoPlayer no da error, solo
                // no renderiza-. Si a los 6s de estar reproduciendo no se ha pintado
                // ni un fotograma, se reintenta el MISMO enlace en VLC, cuyo
                // decodificador por software si saca el 10-bit. En modo forzado a
                // ExoPlayer se respeta y se prueba otra fuente en su lugar.
                LaunchedEffect(isPlaying, firstFrameRendered) {
                    if (isPlaying && !firstFrameRendered) {
                        delay(6_000)
                        if (isPlaying && !firstFrameRendered) {
                            when {
                                prefs.engine == PlayerPrefs.Engine.AUTO && engine == Engine.EXO ->
                                    engine = Engine.VLC
                                else -> tryNext()
                            }
                        }
                    }
                }

                AndroidView(
                    modifier = Modifier.fillMaxSize(),
                    factory = { ctx ->
                        PlayerView(ctx).apply {
                            useController = false
                            resizeMode = videoScale.exoMode
                            setShutterBackgroundColor(android.graphics.Color.BLACK)
                            subtitleView?.apply {
                                setStyle(subtitleStyle(subtitlePrefs))
                                setFractionalTextSize(
                                    SubtitleView.DEFAULT_TEXT_SIZE_FRACTION *
                                        (subtitlePrefs.textScalePercent / 100f)
                                )
                                setApplyEmbeddedStyles(subtitlePrefs.respectAssStyles)
                            }
                            this.player = player
                        }
                    },
                    // Aplica el modo elegido con el boton sin recrear la vista.
                    update = { it.resizeMode = videoScale.exoMode }
                )

                PlayerChrome(
                    visible = controlsVisible,
                    title = request.title,
                    subtitle = request.subtitle,
                    isPlaying = isPlaying,
                    buffering = buffering,
                    positionMs = if (scrubbing >= 0f) (scrubbing * durationMs).toLong() else positionMs,
                    durationMs = durationMs,
                    engineLabel = "EXOPLAYER",
                    scaleLabel = videoScale.label,
                    onCycleScale = { videoScale = videoScale.next() },
                    onPlayPause = { if (player.isPlaying) player.pause() else player.play() },
                    onSeekBy = { delta -> player.seekTo((player.currentPosition + delta).coerceAtLeast(0)) },
                    onScrub = { scrubbing = it },
                    onScrubEnd = {
                        player.seekTo((it * durationMs).toLong())
                        scrubbing = -1f
                    },
                    seekStepMs = prefs.doubleTapSeekSeconds * 1000L,
                    onExit = { saveProgress(); onExit() }
                )
            }

            Engine.VLC -> {
                val vlcPlayer = remember(attempt) { VlcFactory.newPlayer(context, prefs, activeRequest) }

                if (vlcPlayer == null) {
                    // VLC no arranca en este dispositivo: siguiente enlace o error.
                    LaunchedEffect(attempt) {
                        if (!tryNext()) errorMessage = "No se pudo reproducir en ninguna fuente."
                    }
                } else {
                DisposableEffect(vlcPlayer) {
                    val listener = org.videolan.libvlc.MediaPlayer.EventListener { event ->
                        when (event.type) {
                            org.videolan.libvlc.MediaPlayer.Event.Playing -> {
                                isPlaying = true
                                buffering = false
                            }

                            org.videolan.libvlc.MediaPlayer.Event.Paused,
                            org.videolan.libvlc.MediaPlayer.Event.Stopped -> isPlaying = false

                            org.videolan.libvlc.MediaPlayer.Event.Buffering ->
                                buffering = event.buffering < 100f

                            org.videolan.libvlc.MediaPlayer.Event.TimeChanged -> {
                                if (scrubbing < 0f) positionMs = vlcPlayer.time
                            }

                            org.videolan.libvlc.MediaPlayer.Event.LengthChanged ->
                                durationMs = vlcPlayer.length

                            org.videolan.libvlc.MediaPlayer.Event.EncounteredError ->
                                // VLC tampoco pudo: se prueba la siguiente fuente
                                // (de nuevo con ExoPlayer) antes de rendirse.
                                if (!tryNext()) {
                                    errorMessage = "No se pudo reproducir en ninguna fuente."
                                }
                        }
                    }
                    vlcPlayer.setEventListener(listener)
                    onDispose {
                        saveProgress()
                        vlcPlayer.setEventListener(null)
                        vlcPlayer.stop()
                        vlcPlayer.detachViews()
                        vlcPlayer.release()
                    }
                }

                LaunchedEffect(positionMs / 10_000) { saveProgress() }

                AndroidView(
                    modifier = Modifier.fillMaxSize(),
                    factory = { ctx ->
                        VLCVideoLayout(ctx).also { layout ->
                            vlcPlayer.attachViews(layout, null, false, false)
                            // activeRequest, no request: al caer a VLC se retoma
                            // por donde ibas.
                            if (activeRequest.startPositionMs > 0) {
                                vlcPlayer.time = activeRequest.startPositionMs
                            }
                            vlcPlayer.play()
                        }
                    }
                )

                PlayerChrome(
                    visible = controlsVisible,
                    title = request.title,
                    subtitle = request.subtitle,
                    isPlaying = isPlaying,
                    buffering = buffering,
                    positionMs = if (scrubbing >= 0f) (scrubbing * durationMs).toLong() else positionMs,
                    durationMs = durationMs,
                    engineLabel = "VLC",
                    onPlayPause = { if (vlcPlayer.isPlaying) vlcPlayer.pause() else vlcPlayer.play() },
                    onSeekBy = { delta -> vlcPlayer.time = (vlcPlayer.time + delta).coerceAtLeast(0) },
                    onScrub = { scrubbing = it },
                    onScrubEnd = {
                        vlcPlayer.time = (it * durationMs).toLong()
                        scrubbing = -1f
                    },
                    seekStepMs = prefs.doubleTapSeekSeconds * 1000L,
                    onExit = { saveProgress(); onExit() }
                )
                }
            }
        }

        errorMessage?.let { message ->
            Column(
                Modifier
                    .align(Alignment.Center)
                    .padding(32.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                Eyebrow("No se pudo reproducir", color = NexusColors.Critical)
                Text(
                    message,
                    style = MaterialTheme.typography.bodyMedium,
                    color = NexusColors.TextDim
                )
                Text(
                    "Volver a la lista de enlaces",
                    style = MaterialTheme.typography.labelMedium,
                    color = LocalAccent.current,
                    modifier = Modifier
                        .clickable(onClick = onExit)
                        .padding(vertical = 8.dp)
                )
            }
        }
    }

    // Oculta los controles solo cuando algo se esta reproduciendo.
    LaunchedEffect(controlsVisible, isPlaying) {
        if (controlsVisible && isPlaying) {
            delay(4_000)
            controlsVisible = false
        }
    }
}

@Composable
private fun PlayerChrome(
    visible: Boolean,
    title: String,
    subtitle: String?,
    isPlaying: Boolean,
    buffering: Boolean,
    positionMs: Long,
    durationMs: Long,
    engineLabel: String,
    seekStepMs: Long,
    onPlayPause: () -> Unit,
    onSeekBy: (Long) -> Unit,
    onScrub: (Float) -> Unit,
    onScrubEnd: (Float) -> Unit,
    onExit: () -> Unit,
    // Ajuste de imagen: solo lo pasa ExoPlayer; en null no se muestra el boton.
    scaleLabel: String? = null,
    onCycleScale: (() -> Unit)? = null
) {
    // La rueda de carga se muestra aunque los controles esten ocultos: es la
    // unica senal de que el enlace sigue vivo mientras almacena en bufer.
    if (buffering) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(
                color = LocalAccent.current,
                strokeWidth = 2.dp,
                modifier = Modifier.size(38.dp)
            )
        }
    }

    AnimatedVisibility(visible = visible, enter = fadeIn(), exit = fadeOut()) {
        Box(Modifier.fillMaxSize()) {

            Row(
                Modifier
                    .align(Alignment.TopStart)
                    .fillMaxWidth()
                    .background(Color.Black.copy(alpha = 0.55f))
                    .padding(horizontal = 12.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                IconButton(onClick = onExit) {
                    Icon(Icons.Default.ArrowBack, "Salir", tint = Color.White)
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        title,
                        style = MaterialTheme.typography.titleMedium,
                        color = Color.White,
                        maxLines = 1
                    )
                    subtitle?.let { Eyebrow(it, color = NexusColors.TextDim) }
                }
                onCycleScale?.let { cycle ->
                    IconButton(onClick = cycle) {
                        Icon(Icons.Default.AspectRatio, "Ajuste de pantalla", tint = Color.White)
                    }
                    scaleLabel?.let { Eyebrow(it, color = NexusColors.TextDim) }
                }
                Eyebrow(engineLabel)
            }

            Row(
                Modifier.align(Alignment.Center),
                horizontalArrangement = Arrangement.spacedBy(28.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                IconButton(onClick = { onSeekBy(-seekStepMs) }) {
                    Icon(Icons.Default.Replay10, "Retroceder", tint = Color.White)
                }
                Box(
                    Modifier
                        .size(56.dp)
                        .clip(CircleShape)
                        .background(LocalAccent.current)
                        .clickable(onClick = onPlayPause),
                    contentAlignment = Alignment.Center
                ) {
                    Icon(
                        if (isPlaying) Icons.Default.Pause else Icons.Default.PlayArrow,
                        contentDescription = if (isPlaying) "Pausar" else "Reproducir",
                        tint = Color.Black
                    )
                }
                IconButton(onClick = { onSeekBy(seekStepMs * 3) }) {
                    Icon(Icons.Default.Forward30, "Avanzar", tint = Color.White)
                }
            }

            Column(
                Modifier
                    .align(Alignment.BottomStart)
                    .fillMaxWidth()
                    .background(Color.Black.copy(alpha = 0.6f))
                    .padding(horizontal = 14.dp, vertical = 8.dp)
            ) {
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    Eyebrow(formatTime(positionMs), color = NexusColors.TextDim)
                    Eyebrow(
                        if (durationMs > 0) "-${formatTime(durationMs - positionMs)}" else "EN DIRECTO",
                        color = NexusColors.TextDim
                    )
                }
                // Slider no entrega el valor final en onValueChangeFinished,
                // asi que hay que recordar el ultimo arrastre.
                var lastScrub by remember { mutableFloatStateOf(-1f) }
                Slider(
                    value = if (durationMs > 0) {
                        (positionMs.toFloat() / durationMs).coerceIn(0f, 1f)
                    } else {
                        0f
                    },
                    onValueChange = {
                        lastScrub = it
                        onScrub(it)
                    },
                    onValueChangeFinished = {
                        if (lastScrub >= 0f) {
                            onScrubEnd(lastScrub)
                            lastScrub = -1f
                        }
                    },
                    enabled = durationMs > 0,
                    colors = SliderDefaults.colors(
                        thumbColor = LocalAccent.current,
                        activeTrackColor = LocalAccent.current,
                        inactiveTrackColor = NexusColors.Line
                    )
                )
            }
        }
    }
}

/**
 * Cómo encaja el vídeo en la pantalla. Se cicla con el botón del reproductor.
 *  - AJUSTAR: cabe entero conservando proporción (puede dejar bandas negras).
 *  - ZOOM: llena conservando proporción, recortando lo que sobra.
 *  - LLENAR: estira hasta llenar (deforma), para el caso raro de aspect mal marcado.
 */
private enum class VideoScale(val label: String, val exoMode: Int) {
    FIT("Ajustar", AspectRatioFrameLayout.RESIZE_MODE_FIT),
    ZOOM("Zoom", AspectRatioFrameLayout.RESIZE_MODE_ZOOM),
    FILL("Llenar", AspectRatioFrameLayout.RESIZE_MODE_FILL);

    fun next(): VideoScale = entries[(ordinal + 1) % entries.size]
}

private fun readableError(error: PlaybackException): String = when (error.errorCode) {
    PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_FAILED,
    PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_TIMEOUT ->
        "El servidor no responde. Prueba con otro enlace."

    PlaybackException.ERROR_CODE_IO_BAD_HTTP_STATUS ->
        "El servidor rechazo la peticion. El enlace puede haber caducado."

    PlaybackException.ERROR_CODE_DECODING_FORMAT_UNSUPPORTED,
    PlaybackException.ERROR_CODE_DECODER_INIT_FAILED ->
        "Este dispositivo no puede decodificar ese formato."

    else -> error.message ?: "Error desconocido al reproducir."
}

private fun formatTime(ms: Long): String {
    if (ms <= 0) return "0:00"
    val totalSeconds = ms / 1000
    val h = totalSeconds / 3600
    val m = (totalSeconds % 3600) / 60
    val s = totalSeconds % 60
    return if (h > 0) {
        String.format(Locale.ROOT, "%d:%02d:%02d", h, m, s)
    } else {
        String.format(Locale.ROOT, "%d:%02d", m, s)
    }
}
