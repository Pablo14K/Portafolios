package app.nexus.ui.settings

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.nexus.data.prefs.GeneralPrefs
import app.nexus.data.prefs.LinkFilters
import app.nexus.data.prefs.PlayerPrefs
import app.nexus.data.prefs.SubtitlePrefs
import app.nexus.di.Graph
import app.nexus.domain.Quality
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

@Composable
fun SettingsScreen(
    graph: Graph,
    onBack: () -> Unit,
    onOpenSources: () -> Unit
) {
    val scope = rememberCoroutineScope()
    val general by graph.general.collectAsStateWithLifecycle()
    val player by graph.settings.playerPrefs
        .collectAsStateWithLifecycle(initialValue = PlayerPrefs())
    val filters by graph.settings.linkFilters
        .collectAsStateWithLifecycle(initialValue = LinkFilters())
    val subtitles by graph.settings.subtitlePrefs
        .collectAsStateWithLifecycle(initialValue = SubtitlePrefs())
    val sources by graph.db.sources().observeAll()
        .collectAsStateWithLifecycle(initialValue = emptyList())
    val connectorCount by graph.sources.connectors
        .map { it.size }
        .collectAsStateWithLifecycle(initialValue = 0)

    LazyColumn(
        Modifier
            .fillMaxSize()
            .padding(top = WindowInsets.statusBars.asPaddingValues().calculateTopPadding())
    ) {
        item {
            Row(
                Modifier.fillMaxWidth().padding(end = 16.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.Default.ArrowBack, "Volver", tint = NexusColors.TextDim)
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        "Ajustes",
                        style = MaterialTheme.typography.titleLarge,
                        color = NexusColors.Text
                    )
                    Eyebrow("$connectorCount fuentes activas")
                }
            }
        }

        // --- Catalogo -----------------------------------------------------
        item { GroupHeader("Catalogo") }
        item {
            ValueRow(
                title = "Idioma de metadatos",
                subtitle = "Titulos y sinopsis",
                value = general.metadataLanguage
            ) {
                val options = listOf("es-ES", "es-MX", "en-US")
                val next = options[(options.indexOf(general.metadataLanguage) + 1) % options.size]
                scope.launch { graph.settings.updateGeneral { it.copy(metadataLanguage = next) } }
            }
        }
        item {
            SwitchRow(
                title = "Anime en romaji",
                subtitle = "Sousou no Frieren en vez de Frieren",
                checked = general.animeRomaji
            ) { value ->
                scope.launch { graph.settings.updateGeneral { it.copy(animeRomaji = value) } }
            }
        }

        // --- Fuentes ------------------------------------------------------
        item { GroupHeader("Fuentes") }
        item {
            ValueRow(
                title = "Gestionar fuentes",
                subtitle = sources.joinToString(", ") { it.name }.ifBlank { "Ninguna" },
                value = "${sources.count { it.enabled }} ACTIVAS",
                onClick = onOpenSources
            )
        }
        sources.filter { it.lastError != null }.forEach { failing ->
            item {
                ValueRow(
                    title = failing.name,
                    subtitle = failing.lastError,
                    value = "FALLO",
                    onClick = onOpenSources
                )
            }
        }

        // --- Filtros de enlaces -------------------------------------------
        item { GroupHeader("Filtros de enlaces") }
        item {
            ValueRow(
                title = "Calidad minima",
                subtitle = "Los enlaces por debajo no se muestran",
                value = filters.minQuality.label
            ) {
                val options = listOf(
                    Quality.SD_480, Quality.HD_720, Quality.FHD_1080, Quality.UHD_2160
                )
                val next = options[(options.indexOf(filters.minQuality) + 1) % options.size]
                scope.launch { graph.settings.updateLinkFilters { it.copy(minQuality = next) } }
            }
        }
        item {
            ValueRow(
                title = "Ordenar por",
                subtitle = "Que enlace sale primero",
                value = filters.sortBy.label
            ) {
                val options = LinkFilters.Sort.entries
                val next = options[(options.indexOf(filters.sortBy) + 1) % options.size]
                scope.launch { graph.settings.updateLinkFilters { it.copy(sortBy = next) } }
            }
        }
        item {
            SwitchRow(
                title = "Descartar CAM y TS",
                subtitle = "Copias grabadas en sala",
                checked = filters.discardCam
            ) { value ->
                scope.launch { graph.settings.updateLinkFilters { it.copy(discardCam = value) } }
            }
        }
        item {
            SwitchRow(
                title = "Saltar al siguiente si falla",
                subtitle = "Prueba hasta ${filters.maxRetries} enlaces",
                checked = filters.skipToNextOnFailure
            ) { value ->
                scope.launch {
                    graph.settings.updateLinkFilters { it.copy(skipToNextOnFailure = value) }
                }
            }
        }

        // --- Reproductor --------------------------------------------------
        item { GroupHeader("Reproductor") }
        item {
            ValueRow(
                title = "Motor de video",
                subtitle = "VLC salva formatos que ExoPlayer no abre",
                value = player.engine.label
            ) {
                val options = PlayerPrefs.Engine.entries.filter { it != PlayerPrefs.Engine.EXTERNAL }
                val next = options[(options.indexOf(player.engine) + 1) % options.size]
                scope.launch { graph.settings.updatePlayer { it.copy(engine = next) } }
            }
        }
        item {
            SwitchRow(
                title = "Decodificacion por hardware",
                subtitle = "Desactivar solo si se ve a tirones",
                checked = player.hardwareDecoding
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(hardwareDecoding = value) } }
            }
        }
        item {
            SwitchRow(
                title = "Reanudar donde lo deje",
                checked = player.resumePlayback
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(resumePlayback = value) } }
            }
        }
        item {
            ValueRow(
                title = "Bufer",
                subtitle = "Mas bufer, menos cortes",
                value = "${player.bufferSeconds} s"
            ) {
                val options = listOf(20, 40, 60, 120)
                val next = options[(options.indexOf(player.bufferSeconds) + 1) % options.size]
                scope.launch { graph.settings.updatePlayer { it.copy(bufferSeconds = next) } }
            }
        }
        item {
            ValueRow(
                title = "Doble toque avanza",
                value = "${player.doubleTapSeekSeconds} s"
            ) {
                val options = listOf(5, 10, 15, 30)
                val next = options[(options.indexOf(player.doubleTapSeekSeconds) + 1) % options.size]
                scope.launch {
                    graph.settings.updatePlayer { it.copy(doubleTapSeekSeconds = next) }
                }
            }
        }

        item {
            SwitchRow(
                title = "Siguiente episodio automatico",
                subtitle = "Con ${player.autoPlayCountdownSeconds} s de cuenta atras",
                checked = player.autoPlayNextEpisode
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(autoPlayNextEpisode = value) } }
            }
        }
        item {
            ValueRow(
                title = "Saltar intro",
                subtitle = "Automatico, con boton o desactivado",
                value = player.skipIntro.label
            ) {
                val options = PlayerPrefs.SkipMode.entries
                val next = options[(options.indexOf(player.skipIntro) + 1) % options.size]
                scope.launch { graph.settings.updatePlayer { it.copy(skipIntro = next) } }
            }
        }
        item {
            ValueRow(
                title = "Marcar como visto al",
                subtitle = "Deja de aparecer en continuar viendo",
                value = "${player.watchedThresholdPercent} %"
            ) {
                val options = listOf(80, 85, 90, 95)
                val next = options[(options.indexOf(player.watchedThresholdPercent) + 1) % options.size]
                scope.launch {
                    graph.settings.updatePlayer { it.copy(watchedThresholdPercent = next) }
                }
            }
        }
        item {
            SwitchRow(
                title = "Ajustar frecuencia de pantalla",
                subtitle = "Evita micro-tirones en contenido a 24 fps",
                checked = player.matchRefreshRate
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(matchRefreshRate = value) } }
            }
        }
        item {
            SwitchRow(
                title = "Imagen en imagen al salir",
                checked = player.pipOnLeave
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(pipOnLeave = value) } }
            }
        }
        item {
            SwitchRow(
                title = "Mantener la pantalla encendida",
                checked = player.keepScreenOn
            ) { value ->
                scope.launch { graph.settings.updatePlayer { it.copy(keepScreenOn = value) } }
            }
        }

        // --- Subtitulos ---------------------------------------------------
        item { GroupHeader("Subtitulos") }
        item {
            ValueRow(
                title = "Idioma preferido",
                value = subtitles.preferredLanguage.uppercase()
            ) {
                val options = listOf("es", "en", "pt", "ja")
                val next = options[(options.indexOf(subtitles.preferredLanguage) + 1) % options.size]
                scope.launch {
                    graph.settings.updateSubtitles { it.copy(preferredLanguage = next) }
                }
            }
        }
        item {
            SwitchRow(
                title = "Activados por defecto",
                checked = subtitles.enabledByDefault
            ) { value ->
                scope.launch { graph.settings.updateSubtitles { it.copy(enabledByDefault = value) } }
            }
        }
        item {
            SwitchRow(
                title = "Solo si el audio no esta en mi idioma",
                subtitle = "Sin subtitulos cuando hay doblaje",
                checked = subtitles.onlyWhenAudioDiffers
            ) { value ->
                scope.launch {
                    graph.settings.updateSubtitles { it.copy(onlyWhenAudioDiffers = value) }
                }
            }
        }
        item {
            ValueRow(title = "Tamano del texto", value = "${subtitles.textScalePercent} %") {
                val options = listOf(75, 100, 125, 150, 200)
                val next = options[(options.indexOf(subtitles.textScalePercent) + 1) % options.size]
                scope.launch { graph.settings.updateSubtitles { it.copy(textScalePercent = next) } }
            }
        }
        item {
            ValueRow(
                title = "Borde",
                subtitle = "Para que se lean sobre fondo claro",
                value = subtitles.outline.label
            ) {
                val options = SubtitlePrefs.Outline.entries
                val next = options[(options.indexOf(subtitles.outline) + 1) % options.size]
                scope.launch { graph.settings.updateSubtitles { it.copy(outline = next) } }
            }
        }
        item {
            ValueRow(
                title = "Opacidad del fondo",
                value = "${subtitles.backgroundOpacityPercent} %"
            ) {
                val options = listOf(0, 25, 55, 80)
                val next = options[
                    (options.indexOf(subtitles.backgroundOpacityPercent) + 1) % options.size
                ]
                scope.launch {
                    graph.settings.updateSubtitles { it.copy(backgroundOpacityPercent = next) }
                }
            }
        }
        item {
            SwitchRow(
                title = "Respetar estilos ASS",
                subtitle = "Los carteles del anime se ven como se disenaron",
                checked = subtitles.respectAssStyles
            ) { value ->
                scope.launch { graph.settings.updateSubtitles { it.copy(respectAssStyles = value) } }
            }
        }

        // --- Apariencia ---------------------------------------------------
        item { GroupHeader("Apariencia") }
        item {
            ValueRow(
                title = "Color de acento",
                subtitle = "El verde es el original",
                value = accentName(general.accentColor)
            ) {
                val next = ACCENTS[(ACCENTS.indexOf(general.accentColor) + 1) % ACCENTS.size]
                scope.launch { graph.settings.updateGeneral { it.copy(accentColor = next) } }
            }
        }
        item {
            ValueRow(title = "Tamano de poster", value = general.posterSize.label) {
                val options = GeneralPrefs.PosterSize.entries
                val next = options[(options.indexOf(general.posterSize) + 1) % options.size]
                scope.launch { graph.settings.updateGeneral { it.copy(posterSize = next) } }
            }
        }
        item {
            ValueRow(
                title = "Columnas en las rejillas",
                value = general.gridColumns.toString()
            ) {
                val next = if (general.gridColumns >= 5) 2 else general.gridColumns + 1
                scope.launch { graph.settings.updateGeneral { it.copy(gridColumns = next) } }
            }
        }
        item {
            SwitchRow(
                title = "Mostrar titulos bajo el poster",
                checked = general.showTitlesUnderPosters
            ) { value ->
                scope.launch {
                    graph.settings.updateGeneral { it.copy(showTitlesUnderPosters = value) }
                }
            }
        }

        // --- Descargas y red ----------------------------------------------
        item { GroupHeader("Descargas") }
        item {
            SwitchRow(
                title = "Descargar solo por Wi-Fi",
                checked = general.downloadsWifiOnly
            ) { value ->
                scope.launch { graph.settings.updateGeneral { it.copy(downloadsWifiOnly = value) } }
            }
        }
        item {
            ValueRow(
                title = "Descargas simultaneas",
                value = general.maxParallelDownloads.toString()
            ) {
                val next = if (general.maxParallelDownloads >= 4) 1 else general.maxParallelDownloads + 1
                scope.launch {
                    graph.settings.updateGeneral { it.copy(maxParallelDownloads = next) }
                }
            }
        }

        // --- Datos --------------------------------------------------------
        item { GroupHeader("Datos y privacidad") }
        item {
            SwitchRow(
                title = "Guardar historial de reproduccion",
                subtitle = "Al desactivarlo deja de registrarse lo nuevo",
                checked = general.historyEnabled
            ) { value ->
                scope.launch { graph.settings.updateGeneral { it.copy(historyEnabled = value) } }
            }
        }
        item {
            ValueRow(title = "Vaciar cache de enlaces", value = "VACIAR") {
                graph.sources.invalidate()
            }
        }
        item {
            ValueRow(title = "Borrar historial de reproduccion", value = "BORRAR") {
                scope.launch { graph.db.progress().clear() }
            }
        }
        item {
            ValueRow(title = "Restablecer todos los ajustes", value = "RESTABLECER") {
                scope.launch { graph.settings.resetAll() }
            }
        }

        item { Box(Modifier.height(40.dp)) }
    }
}

/** Paleta de acentos. El primero es el color de la maqueta. */
private val ACCENTS = listOf(
    0xFF00FF2CL,
    0xFF00E5FFL,
    0xFFFF2D55L,
    0xFFFFC02EL,
    0xFFB14DFFL
)

private fun accentName(value: Long): String = when (value) {
    0xFF00FF2CL -> "Verde senal"
    0xFF00E5FFL -> "Cian"
    0xFFFF2D55L -> "Rojo"
    0xFFFFC02EL -> "Ambar"
    0xFFB14DFFL -> "Violeta"
    else -> "Personalizado"
}

@Composable
private fun GroupHeader(title: String) {
    Box(
        Modifier
            .fillMaxWidth()
            .background(NexusColors.SignalGhost)
            .padding(horizontal = 16.dp, vertical = 7.dp)
    ) {
        Eyebrow(title)
    }
}

@Composable
private fun ValueRow(
    title: String,
    value: String,
    subtitle: String? = null,
    onClick: (() -> Unit)? = null
) {
    Row(
        Modifier
            .fillMaxWidth()
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 16.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.titleMedium, color = NexusColors.Text)
            subtitle?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = NexusColors.Muted)
            }
        }
        Eyebrow(value)
    }
}

@Composable
private fun SwitchRow(
    title: String,
    checked: Boolean,
    subtitle: String? = null,
    onChange: (Boolean) -> Unit
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable { onChange(!checked) }
            .padding(start = 16.dp, end = 10.dp, top = 4.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.titleMedium, color = NexusColors.Text)
            subtitle?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = NexusColors.Muted)
            }
        }
        Switch(
            checked = checked,
            onCheckedChange = onChange,
            colors = SwitchDefaults.colors(
                checkedThumbColor = Color.Black,
                checkedTrackColor = LocalAccent.current,
                uncheckedThumbColor = NexusColors.Muted,
                uncheckedTrackColor = NexusColors.Surface,
                uncheckedBorderColor = NexusColors.Line
            )
        )
    }
}
