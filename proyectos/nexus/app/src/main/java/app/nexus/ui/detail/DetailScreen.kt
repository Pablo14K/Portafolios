package app.nexus.ui.detail

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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.FavoriteBorder
import androidx.compose.material.icons.filled.PlayArrow
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
import app.nexus.di.Graph
import app.nexus.domain.Episode
import app.nexus.domain.MediaDetail
import app.nexus.domain.MediaId
import app.nexus.domain.MediaKind
import app.nexus.ui.components.ErrorState
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.components.LoadingState
import app.nexus.ui.components.PosterCard
import app.nexus.ui.components.SectionHeader
import app.nexus.ui.components.Tag
import app.nexus.ui.components.scrimBrush
import app.nexus.ui.player.PlaybackRequest
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import coil3.compose.AsyncImage

@Composable
fun DetailScreen(
    graph: Graph,
    mediaIdRaw: String,
    onBack: () -> Unit,
    onPlay: (PlaybackRequest) -> Unit,
    onOpenDetail: (MediaId) -> Unit
) {
    val vm: DetailViewModel = viewModel(
        factory = DetailViewModel.Factory(graph, mediaIdRaw),
        key = mediaIdRaw
    )
    val state by vm.state.collectAsStateWithLifecycle()
    val links by vm.links.collectAsStateWithLifecycle()
    val favorite by vm.isFavorite.collectAsStateWithLifecycle()

    Box(Modifier.fillMaxSize()) {
        when {
            state.loading -> LoadingState("Cargando ficha")

            state.error != null -> ErrorState(state.error!!, onRetry = vm::load)

            state.detail != null -> DetailContent(
                detail = state.detail!!,
                state = state,
                favorite = favorite,
                onBack = onBack,
                onToggleFavorite = vm::toggleFavorite,
                onPlayMovie = { vm.openLinks() },
                onPlayEpisode = { ep -> vm.openLinks(ep.seasonNumber, ep.episodeNumber) },
                onSelectSeason = vm::selectSeason,
                onOpenDetail = onOpenDetail,
                progressFor = vm::progressFor
            )
        }

        if (links.visible) {
            SourceSheet(
                state = links,
                onDismiss = vm::closeLinks,
                onRefresh = vm::refreshLinks,
                onPick = { link ->
                    val detail = state.detail ?: return@SourceSheet
                    val request = links.request
                    val resume = vm.progressFor(request?.season, request?.episode)
                    onPlay(
                        PlaybackRequest.from(
                            link = link,
                            mediaId = mediaIdRaw,
                            title = detail.item.title,
                            subtitle = links.label.takeIf { it != detail.item.title },
                            season = request?.season,
                            episode = request?.episode,
                            startPositionMs = resume?.positionMs ?: 0L,
                            posterUrl = detail.item.posterUrl,
                            alternatives = links.links.filter { it.url != link.url }
                        )
                    )
                    vm.closeLinks()
                }
            )
        }
    }
}

@Composable
private fun DetailContent(
    detail: MediaDetail,
    state: DetailUiState,
    favorite: Boolean,
    onBack: () -> Unit,
    onToggleFavorite: () -> Unit,
    onPlayMovie: () -> Unit,
    onPlayEpisode: (Episode) -> Unit,
    onSelectSeason: (Int) -> Unit,
    onOpenDetail: (MediaId) -> Unit,
    progressFor: (Int?, Int?) -> app.nexus.data.db.ProgressEntity?
) {
    val item = detail.item
    val isSeries = item.kind != MediaKind.MOVIE && detail.seasons.isNotEmpty()

    LazyColumn(Modifier.fillMaxSize()) {

        item {
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(260.dp)
            ) {
                AsyncImage(
                    model = item.backdropUrl ?: item.posterUrl,
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize()
                )
                Box(Modifier.fillMaxSize().background(scrimBrush()))

                Row(
                    Modifier
                        .align(Alignment.TopStart)
                        .fillMaxWidth()
                        .padding(4.dp)
                ) {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Default.ArrowBack, "Volver", tint = Color.White)
                    }
                    Box(Modifier.weight(1f))
                    IconButton(onClick = onToggleFavorite) {
                        Icon(
                            if (favorite) Icons.Default.Favorite else Icons.Default.FavoriteBorder,
                            contentDescription = if (favorite) "Quitar de mi lista" else "Anadir a mi lista",
                            tint = if (favorite) LocalAccent.current else Color.White
                        )
                    }
                }

                Column(
                    Modifier
                        .align(Alignment.BottomStart)
                        .padding(horizontal = 16.dp, vertical = 14.dp)
                ) {
                    Text(
                        item.title,
                        style = MaterialTheme.typography.displaySmall,
                        color = Color.White,
                        maxLines = 3,
                        overflow = TextOverflow.Ellipsis
                    )
                    Eyebrow(
                        buildString {
                            item.year?.let { append(it) }
                            detail.genres.firstOrNull()?.let {
                                if (isNotEmpty()) append(" · ")
                                append(it)
                            }
                            detail.runtimeMinutes?.let {
                                if (isNotEmpty()) append(" · ")
                                append("${it} min")
                            }
                            item.rating?.let {
                                if (isNotEmpty()) append(" · ")
                                append(String.format(java.util.Locale.ROOT, "%.1f", it))
                            }
                        },
                        color = NexusColors.TextDim,
                        modifier = Modifier.padding(top = 6.dp)
                    )
                }
            }
        }

        if (!isSeries) {
            item {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    val resume = progressFor(null, null)
                    PrimaryButton(
                        label = if (resume != null && resume.percent in 1..94) {
                            "Continuar · ${resume.percent}%"
                        } else {
                            "Reproducir"
                        },
                        onClick = onPlayMovie,
                        modifier = Modifier.weight(1f)
                    )
                }
            }
        }

        item.overview?.let { overview ->
            item {
                Text(
                    overview,
                    style = MaterialTheme.typography.bodyMedium,
                    color = NexusColors.TextDim,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 6.dp)
                )
            }
        }

        if (detail.genres.isNotEmpty()) {
            item {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 6.dp),
                    horizontalArrangement = Arrangement.spacedBy(5.dp)
                ) {
                    detail.genres.take(4).forEach { Tag(it, color = NexusColors.TextDim) }
                }
            }
        }

        if (isSeries) {
            item {
                SeasonSelector(
                    detail = detail,
                    selected = state.selectedSeason,
                    onSelect = onSelectSeason,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp)
                )
            }

            if (state.episodesLoading) {
                item { LoadingState("Cargando episodios") }
            }

            items(state.episodes, key = { "${it.seasonNumber}-${it.episodeNumber}" }) { episode ->
                EpisodeRow(
                    episode = episode,
                    progressPercent = progressFor(episode.seasonNumber, episode.episodeNumber)
                        ?.percent,
                    onClick = { onPlayEpisode(episode) }
                )
            }
        }

        if (detail.cast.isNotEmpty()) {
            item {
                SectionHeader(
                    "Reparto",
                    modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 8.dp)
                )
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    items(detail.cast) { member ->
                        Column(
                            Modifier.width(66.dp),
                            horizontalAlignment = Alignment.CenterHorizontally
                        ) {
                            AsyncImage(
                                model = member.photoUrl,
                                contentDescription = member.name,
                                contentScale = ContentScale.Crop,
                                modifier = Modifier
                                    .size(56.dp)
                                    .clip(CircleShape)
                                    .background(NexusColors.SurfaceHigh)
                            )
                            Text(
                                member.name,
                                style = MaterialTheme.typography.bodySmall,
                                color = NexusColors.TextDim,
                                maxLines = 2,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.padding(top = 5.dp)
                            )
                        }
                    }
                }
            }
        }

        if (detail.similar.isNotEmpty()) {
            item {
                SectionHeader(
                    "Similares",
                    modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 20.dp, bottom = 8.dp)
                )
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    items(detail.similar, key = { it.id.toString() }) { similar ->
                        PosterCard(
                            item = similar,
                            width = 104.dp,
                            onClick = { onOpenDetail(similar.id) }
                        )
                    }
                }
            }
        }

        item { Box(Modifier.height(36.dp)) }
    }
}

@Composable
private fun PrimaryButton(
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier
) {
    Row(
        modifier
            .clip(RoundedCornerShape(5.dp))
            .background(LocalAccent.current)
            .clickable(onClick = onClick)
            .padding(vertical = 11.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(
            Icons.Default.PlayArrow,
            contentDescription = null,
            tint = Color.Black,
            modifier = Modifier.size(18.dp)
        )
        Text(
            label,
            style = MaterialTheme.typography.titleMedium,
            color = Color.Black,
            modifier = Modifier.padding(start = 6.dp)
        )
    }
}

@Composable
private fun SeasonSelector(
    detail: MediaDetail,
    selected: Int?,
    onSelect: (Int) -> Unit,
    modifier: Modifier = Modifier
) {
    LazyRow(modifier, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(detail.seasons) { season ->
            val active = season.number == selected
            Text(
                text = if (season.number == 0) "Especiales" else "T${season.number}",
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
                    .clickable { onSelect(season.number) }
                    .padding(horizontal = 12.dp, vertical = 6.dp)
            )
        }
    }
}

@Composable
private fun EpisodeRow(
    episode: Episode,
    progressPercent: Int?,
    onClick: () -> Unit
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Box(
            Modifier
                .width(84.dp)
                .aspectRatio(16f / 9f)
                .clip(RoundedCornerShape(5.dp))
                .background(NexusColors.SurfaceHigh)
        ) {
            AsyncImage(
                model = episode.stillUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize()
            )
            if (progressPercent != null && progressPercent > 0) {
                Box(
                    Modifier
                        .align(Alignment.BottomStart)
                        .fillMaxWidth(progressPercent / 100f)
                        .height(3.dp)
                        .background(LocalAccent.current)
                )
            }
        }

        Column(Modifier.weight(1f)) {
            Text(
                "${episode.episodeNumber}. ${episode.title}",
                style = MaterialTheme.typography.titleMedium,
                color = NexusColors.Text,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )
            val meta = listOfNotNull(
                episode.runtimeMinutes?.let { "$it min" },
                episode.airDate
            ).joinToString(" · ")
            if (meta.isNotEmpty()) {
                Eyebrow(meta, color = NexusColors.Muted)
            }
        }

        Icon(
            Icons.Default.PlayArrow,
            contentDescription = "Reproducir",
            tint = NexusColors.Muted,
            modifier = Modifier.size(18.dp)
        )
    }
}
