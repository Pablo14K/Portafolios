package app.nexus.ui.catalog

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyGridState
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import app.nexus.di.Graph
import app.nexus.domain.MediaId
import app.nexus.domain.MediaKind
import app.nexus.ui.components.EmptyState
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.components.LoadingState
import app.nexus.ui.components.PosterCard
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import kotlinx.coroutines.flow.distinctUntilChanged

@Composable
fun CatalogScreen(
    graph: Graph,
    onOpenDetail: (MediaId) -> Unit,
    contentPadding: PaddingValues
) {
    val vm: CatalogViewModel = viewModel(factory = CatalogViewModel.Factory(graph))
    val state by vm.state.collectAsStateWithLifecycle()
    val general by graph.general.collectAsStateWithLifecycle()
    val gridState = rememberLazyGridState()

    ReachedBottom(gridState, threshold = 6) { vm.loadMore() }

    Column(
        Modifier
            .fillMaxSize()
            .padding(top = contentPadding.calculateTopPadding())
    ) {
        // Titulo y contador en la misma linea: apilados se pisaban.
        Row(
            Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            Text("Catalogo", style = MaterialTheme.typography.titleLarge, color = NexusColors.Text)
            Eyebrow(
                if (state.loading) "Cargando" else "${state.items.size} titulos cargados"
            )
        }

        Row(
            Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            listOf(
                "Peliculas" to MediaKind.MOVIE,
                "Series" to MediaKind.SHOW,
                "Anime" to MediaKind.ANIME
            ).forEach { (label, kind) ->
                Chip(label, state.kind == kind) { vm.selectKind(kind) }
            }
        }

        Row(
            Modifier.padding(horizontal = 16.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            CatalogSort.entries.forEach { sort ->
                Chip(sort.label, state.sort == sort, small = true) { vm.setSort(sort) }
            }
            Box(Modifier.weight(1f))
            if (state.selectedGenres.isNotEmpty() || state.sort != CatalogSort.POPULAR) {
                Text(
                    "Limpiar",
                    style = MaterialTheme.typography.labelSmall,
                    color = LocalAccent.current,
                    modifier = Modifier.clickable { vm.clearFilters() }
                )
            }
        }

        if (state.genres.isNotEmpty()) {
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                items(state.genres, key = { it.id }) { genre ->
                    Chip(genre.label, genre.id in state.selectedGenres, small = true) {
                        vm.toggleGenre(genre.id)
                    }
                }
            }
        }

        when {
            state.loading -> LoadingState("Cargando catalogo")

            state.items.isEmpty() -> EmptyState(
                title = "Sin resultados",
                detail = state.unavailableReason
            )

            else -> LazyVerticalGrid(
                state = gridState,
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
                items(state.items, key = { it.id.toString() }) { media ->
                    PosterCard(
                        item = media,
                        width = null,
                        showTitle = general.showTitlesUnderPosters,
                        modifier = Modifier.fillMaxWidth(),
                        onClick = { onOpenDetail(media.id) }
                    )
                }
                if (state.loadingMore) {
                    item { LoadingState("Cargando mas") }
                }
            }
        }
    }
}

/** Avisa cuando quedan pocos elementos por delante, para pedir la pagina siguiente. */
@Composable
private fun ReachedBottom(state: LazyGridState, threshold: Int, onReached: () -> Unit) {
    val shouldLoad by remember {
        derivedStateOf {
            val total = state.layoutInfo.totalItemsCount
            val last = state.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            total > 0 && last >= total - threshold
        }
    }
    LaunchedEffect(state) {
        snapshotFlow { shouldLoad }
            .distinctUntilChanged()
            .collect { if (it) onReached() }
    }
}

@Composable
private fun Chip(
    label: String,
    active: Boolean,
    small: Boolean = false,
    onClick: () -> Unit
) {
    Text(
        label,
        style = if (small) {
            MaterialTheme.typography.labelSmall
        } else {
            MaterialTheme.typography.labelMedium
        },
        color = if (active) NexusColors.Void else NexusColors.TextDim,
        modifier = Modifier
            .clip(RoundedCornerShape(20.dp))
            .background(if (active) LocalAccent.current else Color.Transparent)
            .border(
                1.dp,
                if (active) LocalAccent.current else NexusColors.Line,
                RoundedCornerShape(20.dp)
            )
            .clickable(onClick = onClick)
            .padding(horizontal = if (small) 10.dp else 13.dp, vertical = if (small) 5.dp else 6.dp)
    )
}
