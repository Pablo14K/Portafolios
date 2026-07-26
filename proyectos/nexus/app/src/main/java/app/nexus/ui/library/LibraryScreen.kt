package app.nexus.ui.library

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.nexus.data.db.FavoriteEntity
import app.nexus.data.db.ProgressEntity
import app.nexus.di.Graph
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import app.nexus.ui.components.EmptyState
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.components.PosterCard
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch

private enum class LibraryTab(val label: String) {
    LIST("Mi lista"),
    CONTINUE("Continuar"),
    HISTORY("Historial")
}

@Composable
fun LibraryScreen(
    graph: Graph,
    onOpenDetail: (MediaId) -> Unit,
    contentPadding: PaddingValues
) {
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(LibraryTab.LIST) }

    val favorites by graph.db.favorites().observeAll()
        .collectAsStateWithLifecycle(initialValue = emptyList())
    val continueWatching by graph.db.progress().observeContinueWatching()
        .collectAsStateWithLifecycle(initialValue = emptyList())
    val history by graph.db.progress().observeHistory()
        .collectAsStateWithLifecycle(initialValue = emptyList())
    val general by graph.general.collectAsStateWithLifecycle()

    Column(
        Modifier
            .fillMaxSize()
            .padding(top = contentPadding.calculateTopPadding())
    ) {
        Row(
            Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    "Biblioteca",
                    style = MaterialTheme.typography.titleLarge,
                    color = NexusColors.Text
                )
                Eyebrow(
                    "${favorites.size} en la lista · ${history.size} vistos"
                )
            }
            if (tab == LibraryTab.HISTORY && history.isNotEmpty()) {
                IconButton(onClick = { scope.launch { graph.db.progress().clear() } }) {
                    Icon(
                        Icons.Default.DeleteSweep,
                        "Borrar historial",
                        tint = NexusColors.Muted
                    )
                }
            }
        }

        Row(
            Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            LibraryTab.entries.forEach { option ->
                val active = option == tab
                Text(
                    option.label,
                    style = MaterialTheme.typography.labelMedium,
                    color = if (active) NexusColors.Void else NexusColors.TextDim,
                    modifier = Modifier
                        .clip(RoundedCornerShape(20.dp))
                        .background(if (active) LocalAccent.current else Color.Transparent)
                        .border(
                            1.dp,
                            if (active) LocalAccent.current else NexusColors.Line,
                            RoundedCornerShape(20.dp)
                        )
                        .clickable { tab = option }
                        .padding(horizontal = 13.dp, vertical = 6.dp)
                )
            }
        }

        when (tab) {
            LibraryTab.LIST -> if (favorites.isEmpty()) {
                EmptyState(
                    title = "Tu lista esta vacia",
                    detail = "Pulsa el corazon en cualquier ficha para guardarla aqui."
                )
            } else {
                LazyVerticalGrid(
                    columns = GridCells.Fixed(general.gridColumns.coerceIn(2, 5)),
                    contentPadding = PaddingValues(
                        start = 16.dp,
                        end = 16.dp,
                        top = 4.dp,
                        bottom = contentPadding.calculateBottomPadding() + 16.dp
                    ),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp)
                ) {
                    items(favorites, key = { it.mediaId }) { favorite ->
                        PosterCard(
                            item = favorite.toItem(),
                            width = null,
                            showTitle = true,
                            modifier = Modifier.fillMaxWidth(),
                            onClick = { onOpenDetail(MediaId.parse(favorite.mediaId)) }
                        )
                    }
                }
            }

            LibraryTab.CONTINUE -> ProgressList(
                entries = continueWatching,
                emptyTitle = "Nada empezado",
                emptyDetail = "Lo que dejes a medias aparecera aqui con su minuto exacto.",
                bottomPadding = contentPadding.calculateBottomPadding(),
                onOpen = { onOpenDetail(MediaId.parse(it.mediaId)) }
            )

            LibraryTab.HISTORY -> ProgressList(
                entries = history,
                emptyTitle = "Sin historial",
                emptyDetail = "Aqui queda lo que vas viendo. Se puede desactivar en Ajustes.",
                bottomPadding = contentPadding.calculateBottomPadding(),
                onOpen = { onOpenDetail(MediaId.parse(it.mediaId)) }
            )
        }
    }
}

@Composable
private fun ProgressList(
    entries: List<ProgressEntity>,
    emptyTitle: String,
    emptyDetail: String,
    bottomPadding: androidx.compose.ui.unit.Dp,
    onOpen: (ProgressEntity) -> Unit
) {
    if (entries.isEmpty()) {
        EmptyState(title = emptyTitle, detail = emptyDetail)
        return
    }
    LazyColumn(
        contentPadding = PaddingValues(bottom = bottomPadding + 16.dp)
    ) {
        items(entries, key = { it.key }) { entry ->
            Row(
                Modifier
                    .fillMaxWidth()
                    .clickable { onOpen(entry) }
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                Box(
                    Modifier
                        .width(96.dp)
                        .aspectRatio(16f / 9f)
                        .clip(RoundedCornerShape(5.dp))
                        .background(NexusColors.SurfaceHigh)
                ) {
                    AsyncImage(
                        model = entry.posterUrl,
                        contentDescription = null,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize()
                    )
                    Box(
                        Modifier
                            .align(Alignment.BottomStart)
                            .fillMaxWidth(entry.percent / 100f)
                            .height(3.dp)
                            .background(LocalAccent.current)
                    )
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        entry.title,
                        style = MaterialTheme.typography.titleMedium,
                        color = NexusColors.Text,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                    Eyebrow(
                        buildString {
                            if (entry.season != null && entry.episode != null) {
                                append("T${entry.season} E${entry.episode} · ")
                            }
                            append(if (entry.watched) "Visto" else "${entry.percent}%")
                        },
                        color = if (entry.watched) NexusColors.Muted else LocalAccent.current
                    )
                }
                Box(Modifier.size(6.dp))
            }
        }
    }
}

private fun FavoriteEntity.toItem() = MediaItem(
    id = MediaId.parse(mediaId),
    kind = runCatching { MediaKind.valueOf(kind) }.getOrDefault(MediaKind.MOVIE),
    title = title,
    year = year,
    posterUrl = posterUrl
)
