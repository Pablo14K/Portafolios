package app.nexus.ui.home

import androidx.compose.foundation.background
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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import app.nexus.data.db.ProgressEntity
import app.nexus.di.Graph
import app.nexus.domain.MediaId
import app.nexus.domain.MediaItem
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.components.LoadingState
import app.nexus.ui.components.PosterCard
import app.nexus.ui.components.SectionHeader
import app.nexus.ui.components.scrimBrush
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import coil3.compose.AsyncImage

@Composable
fun HomeScreen(
    graph: Graph,
    onOpenDetail: (MediaId) -> Unit,
    onOpenSettings: () -> Unit,
    contentPadding: PaddingValues
) {
    val vm: HomeViewModel = viewModel(factory = HomeViewModel.Factory(graph))
    val state by vm.state.collectAsStateWithLifecycle()
    val continueWatching by vm.continueWatching.collectAsStateWithLifecycle()
    val general by graph.general.collectAsStateWithLifecycle()

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = contentPadding
    ) {
        item {
            state.featured?.let { featured ->
                FeaturedHero(
                    item = featured,
                    onPlay = { onOpenDetail(featured.id) },
                    onOpenSettings = onOpenSettings
                )
            } ?: TopBar(onOpenSettings)
        }

        if (state.loading) {
            item { LoadingState("Cargando catalogo") }
        }

        if (state.needsTmdbKey && !state.loading) {
            item {
                Text(
                    "Solo se ve anime. Anade tu clave de TMDB en Ajustes para tener peliculas y series.",
                    style = MaterialTheme.typography.bodySmall,
                    color = NexusColors.Warn,
                    modifier = Modifier
                        .padding(16.dp)
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(5.dp))
                        .background(NexusColors.Warn.copy(alpha = 0.08f))
                        .clickable(onClick = onOpenSettings)
                        .padding(12.dp)
                )
            }
        }

        if (continueWatching.isNotEmpty()) {
            item {
                SectionHeader(
                    "Continuar viendo",
                    modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 8.dp)
                )
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(9.dp)
                ) {
                    items(continueWatching, key = { it.key }) { entry ->
                        ContinueCard(entry) {
                            onOpenDetail(MediaId.parse(entry.mediaId))
                        }
                    }
                }
            }
        }

        items(state.rows, key = { it.section.name }) { row ->
            SectionHeader(
                row.section.title,
                modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 8.dp)
            )
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(9.dp)
            ) {
                items(row.items, key = { it.id.toString() }) { media ->
                    PosterCard(
                        item = media,
                        width = general.posterSize.widthDp.dp,
                        showTitle = general.showTitlesUnderPosters,
                        onClick = { onOpenDetail(media.id) }
                    )
                }
            }
        }

        item { Box(Modifier.height(24.dp)) }
    }
}

@Composable
private fun TopBar(onOpenSettings: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text("NEXUS", style = MaterialTheme.typography.titleLarge, color = NexusColors.Text)
            Eyebrow("Cine · Series · Anime")
        }
        IconButton(onClick = onOpenSettings) {
            Icon(Icons.Default.Settings, "Ajustes", tint = NexusColors.TextDim)
        }
    }
}

@Composable
private fun FeaturedHero(
    item: MediaItem,
    onPlay: () -> Unit,
    onOpenSettings: () -> Unit
) {
    Box(
        Modifier
            .fillMaxWidth()
            .height(300.dp)
    ) {
        AsyncImage(
            model = item.backdropUrl,
            contentDescription = item.title,
            contentScale = ContentScale.Crop,
            modifier = Modifier.fillMaxSize()
        )
        Box(Modifier.fillMaxSize().background(scrimBrush()))

        Row(
            Modifier
                .align(Alignment.TopStart)
                .fillMaxWidth()
                .padding(start = 16.dp, end = 8.dp, top = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "NEXUS",
                style = MaterialTheme.typography.titleLarge,
                color = Color.White,
                modifier = Modifier.weight(1f)
            )
            IconButton(onClick = onOpenSettings) {
                Icon(Icons.Default.Settings, "Ajustes", tint = Color.White)
            }
        }

        Column(
            Modifier
                .align(Alignment.BottomStart)
                .padding(horizontal = 16.dp, vertical = 16.dp)
        ) {
            Text(
                item.title,
                style = MaterialTheme.typography.displaySmall,
                color = Color.White,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis
            )
            Eyebrow(
                listOfNotNull(
                    item.year?.toString(),
                    item.rating?.let { String.format(java.util.Locale.ROOT, "%.1f", it) }
                ).joinToString(" · "),
                color = NexusColors.TextDim,
                modifier = Modifier.padding(top = 5.dp, bottom = 10.dp)
            )
            Row(
                Modifier
                    .clip(RoundedCornerShape(5.dp))
                    .background(LocalAccent.current)
                    .clickable(onClick = onPlay)
                    .padding(horizontal = 18.dp, vertical = 9.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(
                    Icons.Default.PlayArrow,
                    null,
                    tint = Color.Black,
                    modifier = Modifier.size(17.dp)
                )
                Text(
                    "Ver ahora",
                    style = MaterialTheme.typography.titleMedium,
                    color = Color.Black,
                    modifier = Modifier.padding(start = 5.dp)
                )
            }
        }
    }
}

@Composable
private fun ContinueCard(entry: ProgressEntity, onClick: () -> Unit) {
    Column(
        Modifier
            .width(168.dp)
            .clickable(onClick = onClick)
    ) {
        Box(
            Modifier
                .fillMaxWidth()
                .aspectRatio(16f / 9f)
                .clip(RoundedCornerShape(6.dp))
                .background(NexusColors.SurfaceHigh)
        ) {
            AsyncImage(
                model = entry.posterUrl,
                contentDescription = entry.title,
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
        Text(
            entry.title,
            style = MaterialTheme.typography.bodySmall,
            color = NexusColors.Text,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.padding(top = 6.dp)
        )
        Eyebrow(
            buildString {
                if (entry.season != null && entry.episode != null) {
                    append("T${entry.season} E${entry.episode} · ")
                }
                append("${entry.percent}%")
            },
            color = NexusColors.Muted
        )
    }
}
