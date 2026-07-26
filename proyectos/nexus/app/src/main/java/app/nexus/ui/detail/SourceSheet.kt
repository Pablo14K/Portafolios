package app.nexus.ui.detail

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import app.nexus.domain.AudioTag
import app.nexus.domain.Quality
import app.nexus.domain.StreamLink
import app.nexus.ui.components.EmptyState
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.components.LoadingState
import app.nexus.ui.components.Tag
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors

/**
 * La hoja de enlaces. Es la pantalla que mas mira el usuario, asi que muestra
 * de un vistazo lo que decide la eleccion: calidad, idioma, tamano y fuente.
 */
@Composable
fun SourceSheet(
    state: LinksUiState,
    onDismiss: () -> Unit,
    onRefresh: () -> Unit,
    onPick: (StreamLink) -> Unit
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
        containerColor = NexusColors.Surface,
        contentColor = NexusColors.Text,
        dragHandle = {
            Box(
                Modifier
                    .padding(top = 9.dp, bottom = 3.dp)
                    .fillMaxWidth(),
                contentAlignment = Alignment.Center
            ) {
                Box(
                    Modifier
                        .size(width = 30.dp, height = 3.dp)
                        .background(NexusColors.Line)
                )
            }
        }
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    state.label,
                    style = MaterialTheme.typography.titleMedium,
                    color = NexusColors.Text,
                    maxLines = 1
                )
                Eyebrow(
                    when {
                        state.loading && state.links.isEmpty() ->
                            "Buscando servidores… ${state.percent}%"
                        state.loading ->
                            "${state.links.size} enlaces · buscando más… ${state.percent}%"
                        state.links.isEmpty() -> "Sin resultados"
                        else -> "${state.links.size} enlaces en " +
                            "${state.links.map { it.sourceId }.distinct().size} fuentes"
                    }
                )
            }
            IconButton(onClick = onRefresh) {
                Icon(Icons.Default.Refresh, "Volver a buscar", tint = NexusColors.TextDim)
            }
        }

        when {
            state.loading && state.links.isEmpty() ->
                LoadingState("Buscando servidores… ${state.percent}%")

            !state.loading && state.links.isEmpty() -> EmptyState(
                title = "Ninguna fuente tiene esto",
                detail = "Prueba con otro titulo, anade tu servidor Jellyfin, o revisa los filtros de calidad en Ajustes."
            )

            else -> LazyColumn(
                Modifier
                    .fillMaxWidth()
                    .heightIn(max = 460.dp)
            ) {
                val best = state.links.first()
                item {
                    Eyebrow(
                        "Recomendado",
                        modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                    )
                    LinkRow(link = best, highlighted = true, onClick = { onPick(best) })
                }
                if (state.links.size > 1) {
                    item {
                        Eyebrow(
                            "Otras fuentes",
                            color = NexusColors.Muted,
                            modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)
                        )
                    }
                    items(state.links.drop(1), key = { it.url }) { link ->
                        LinkRow(link = link, highlighted = false, onClick = { onPick(link) })
                    }
                }
                if (state.loading) {
                    item {
                        Eyebrow(
                            "Buscando más servidores… ${state.percent}% · puede aparecer en más",
                            color = LocalAccent.current,
                            modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp)
                        )
                    }
                }
                item { Box(Modifier.size(20.dp)) }
            }
        }
    }
}

@Composable
private fun LinkRow(
    link: StreamLink,
    highlighted: Boolean,
    onClick: () -> Unit
) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(if (highlighted) NexusColors.SignalGhost else NexusColors.Surface)
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Icon(
            Icons.Default.Bolt,
            contentDescription = null,
            tint = if (link.instant) LocalAccent.current else NexusColors.Muted,
            modifier = Modifier.size(17.dp)
        )

        Column(Modifier.weight(1f)) {
            Text(
                link.title?.takeIf { it.isNotBlank() } ?: link.sourceName,
                style = MaterialTheme.typography.titleMedium,
                color = NexusColors.Text,
                maxLines = 1
            )
            Eyebrow(
                listOfNotNull(
                    link.sourceName,
                    link.sizeLabel,
                    link.latencyMs?.let { "${it} ms" }
                ).joinToString(" · "),
                color = NexusColors.Muted
            )
        }

        Column(horizontalAlignment = Alignment.End) {
            if (link.quality != Quality.UNKNOWN) {
                Tag(link.quality.label)
            }
            if (link.audio != AudioTag.DESCONOCIDO) {
                Eyebrow(
                    link.audio.label,
                    color = NexusColors.Muted,
                    modifier = Modifier.padding(top = 3.dp)
                )
            }
        }
    }
}
