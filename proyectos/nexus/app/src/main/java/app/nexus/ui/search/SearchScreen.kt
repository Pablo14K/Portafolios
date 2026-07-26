package app.nexus.ui.search

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.input.ImeAction
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
import app.nexus.ui.components.SectionHeader
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors

@Composable
fun SearchScreen(
    graph: Graph,
    onOpenDetail: (MediaId) -> Unit,
    onOpenSettings: () -> Unit,
    contentPadding: PaddingValues
) {
    val vm: SearchViewModel = viewModel(factory = SearchViewModel.Factory(graph))
    val state by vm.state.collectAsStateWithLifecycle()
    val recent by vm.recentSearches.collectAsStateWithLifecycle()
    val general by graph.general.collectAsStateWithLifecycle()

    Column(
        Modifier
            .fillMaxSize()
            .padding(top = contentPadding.calculateTopPadding())
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .padding(start = 16.dp, end = 8.dp, top = 10.dp, bottom = 6.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    "NEXUS",
                    style = MaterialTheme.typography.titleLarge,
                    color = NexusColors.Text
                )
                Eyebrow("Cine · Series · Anime")
            }
            IconButton(onClick = onOpenSettings) {
                Icon(
                    Icons.Default.Settings,
                    contentDescription = "Ajustes",
                    tint = NexusColors.TextDim
                )
            }
        }

        OutlinedTextField(
            value = state.query,
            onValueChange = vm::onQueryChange,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 4.dp),
            placeholder = { Text("Buscar peliculas, series o anime") },
            leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
            trailingIcon = {
                if (state.query.isNotEmpty()) {
                    IconButton(onClick = { vm.onQueryChange("") }) {
                        Icon(Icons.Default.Close, contentDescription = "Borrar")
                    }
                }
            },
            singleLine = true,
            shape = RoundedCornerShape(6.dp),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { vm.submit() }),
            colors = OutlinedTextFieldDefaults.colors(
                focusedBorderColor = LocalAccent.current,
                unfocusedBorderColor = NexusColors.Line,
                cursorColor = LocalAccent.current,
                focusedTextColor = NexusColors.Text,
                unfocusedTextColor = NexusColors.Text,
                focusedLeadingIconColor = LocalAccent.current,
                unfocusedLeadingIconColor = NexusColors.Muted,
                focusedPlaceholderColor = NexusColors.Muted,
                unfocusedPlaceholderColor = NexusColors.Muted
            )
        )

        KindFilterRow(
            selected = state.filter,
            onSelect = vm::setFilter,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 6.dp)
        )

        state.warnings.forEach { warning ->
            Text(
                text = warning,
                style = MaterialTheme.typography.bodySmall,
                color = NexusColors.Warn,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 4.dp)
                    .clip(RoundedCornerShape(5.dp))
                    .background(NexusColors.Warn.copy(alpha = 0.08f))
                    .border(1.dp, NexusColors.Warn.copy(alpha = 0.3f), RoundedCornerShape(5.dp))
                    .clickable(onClick = onOpenSettings)
                    .padding(10.dp)
            )
        }

        when {
            state.loading && state.results.isEmpty() -> LoadingState("Buscando en los catalogos")

            state.searched && state.visible.isEmpty() -> EmptyState(
                title = "Sin resultados para \"${state.query}\"",
                detail = "Prueba con el titulo original, o revisa que los catalogos esten configurados en Ajustes."
            )

            state.visible.isNotEmpty() -> ResultsGrid(
                items = state.visible,
                columns = general.gridColumns,
                showTitles = general.showTitlesUnderPosters,
                bottomPadding = contentPadding.calculateBottomPadding(),
                onOpenDetail = onOpenDetail
            )

            else -> DiscoverPane(
                trending = state.trending,
                recent = recent,
                showTitles = general.showTitlesUnderPosters,
                bottomPadding = contentPadding.calculateBottomPadding(),
                onOpenDetail = onOpenDetail,
                onPickRecent = { vm.onQueryChange(it) },
                onClearHistory = vm::clearHistory
            )
        }
    }
}

@Composable
private fun KindFilterRow(
    selected: MediaKind?,
    onSelect: (MediaKind?) -> Unit,
    modifier: Modifier = Modifier
) {
    val options = listOf<Pair<String, MediaKind?>>(
        "Todo" to null,
        "Peliculas" to MediaKind.MOVIE,
        "Series" to MediaKind.SHOW,
        "Anime" to MediaKind.ANIME
    )
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        options.forEach { (label, kind) ->
            val active = selected == kind
            Text(
                text = label,
                style = MaterialTheme.typography.labelMedium,
                color = if (active) NexusColors.Void else NexusColors.TextDim,
                modifier = Modifier
                    .clip(RoundedCornerShape(20.dp))
                    .background(if (active) LocalAccent.current else NexusColors.Void)
                    .border(
                        1.dp,
                        if (active) LocalAccent.current else NexusColors.Line,
                        RoundedCornerShape(20.dp)
                    )
                    .clickable { onSelect(kind) }
                    .padding(horizontal = 12.dp, vertical = 6.dp)
            )
        }
    }
}

@Composable
private fun ResultsGrid(
    items: List<app.nexus.domain.MediaItem>,
    columns: Int,
    showTitles: Boolean,
    bottomPadding: androidx.compose.ui.unit.Dp,
    onOpenDetail: (MediaId) -> Unit
) {
    LazyVerticalGrid(
        columns = GridCells.Fixed(columns.coerceIn(2, 5)),
        contentPadding = PaddingValues(
            start = 16.dp,
            end = 16.dp,
            top = 16.dp,
            bottom = bottomPadding + 16.dp
        ),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        modifier = Modifier.fillMaxSize()
    ) {
        items(items, key = { it.id.toString() }) { item ->
            PosterCard(
                item = item,
                width = null,
                showTitle = showTitles,
                modifier = Modifier.fillMaxWidth(),
                onClick = { onOpenDetail(item.id) }
            )
        }
    }
}

@Composable
private fun DiscoverPane(
    trending: List<app.nexus.domain.MediaItem>,
    recent: List<String>,
    showTitles: Boolean,
    bottomPadding: androidx.compose.ui.unit.Dp,
    onOpenDetail: (MediaId) -> Unit,
    onPickRecent: (String) -> Unit,
    onClearHistory: () -> Unit
) {
    Column(Modifier.fillMaxSize().padding(top = 8.dp)) {

        if (recent.isNotEmpty()) {
            SectionHeader(
                "Busquedas recientes",
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 6.dp)
            )
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp)
            ) {
                items(recent) { query ->
                    Text(
                        text = query,
                        style = MaterialTheme.typography.labelMedium,
                        color = NexusColors.TextDim,
                        modifier = Modifier
                            .clip(RoundedCornerShape(20.dp))
                            .border(1.dp, NexusColors.Line, RoundedCornerShape(20.dp))
                            .clickable { onPickRecent(query) }
                            .padding(horizontal = 12.dp, vertical = 6.dp)
                    )
                }
                item {
                    Text(
                        text = "Borrar",
                        style = MaterialTheme.typography.labelMedium,
                        color = NexusColors.Muted,
                        modifier = Modifier
                            .clickable(onClick = onClearHistory)
                            .padding(horizontal = 10.dp, vertical = 6.dp)
                    )
                }
            }
        }

        if (trending.isEmpty()) {
            EmptyState(
                title = "Empieza escribiendo",
                detail = "El anime funciona sin configurar nada. Para peliculas y series, anade tu clave de TMDB en Ajustes."
            )
        } else {
            SectionHeader(
                "Tendencias",
                trailing = "Esta semana",
                modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 8.dp)
            )
            LazyVerticalGrid(
                columns = GridCells.Fixed(3),
                contentPadding = PaddingValues(
                    start = 16.dp,
                    end = 16.dp,
                    top = 4.dp,
                    bottom = bottomPadding + 16.dp
                ),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp)
            ) {
                items(trending, key = { it.id.toString() }) { item ->
                    PosterCard(
                        item = item,
                        width = null,
                        showTitle = showTitles,
                        modifier = Modifier.fillMaxWidth(),
                        onClick = { onOpenDetail(item.id) }
                    )
                }
            }
        }
    }
}
