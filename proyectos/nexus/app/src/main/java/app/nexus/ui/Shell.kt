package app.nexus.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.GridView
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors

/** Las cinco secciones principales, en el orden de la maqueta. */
enum class ShellTab(val label: String, val icon: ImageVector, val route: String) {
    HOME("Inicio", Icons.Default.Home, Routes.HOME),
    CATALOG("Catalogo", Icons.Default.GridView, Routes.CATALOG),
    SEARCH("Buscar", Icons.Default.Search, Routes.SEARCH),
    LIBRARY("Biblioteca", Icons.Default.VideoLibrary, Routes.LIBRARY)
}

private val BAR_HEIGHT = 58.dp

/**
 * Contenedor de las pantallas con pestana. La barra flota sobre el contenido,
 * asi que se le pasa el relleno a cada pantalla en vez de recortarla.
 */
@Composable
fun Shell(
    current: ShellTab?,
    onSelect: (ShellTab) -> Unit,
    content: @Composable (PaddingValues) -> Unit
) {
    val topInset = WindowInsets.statusBars.asPaddingValues().calculateTopPadding()
    val bottomInset = WindowInsets.navigationBars.asPaddingValues().calculateBottomPadding()

    // Ficha, ajustes y fuentes se ven a pantalla completa: la barra tapaba sus
    // botones inferiores.
    val showBar = current != null

    Box(Modifier.fillMaxSize()) {
        content(
            PaddingValues(
                top = topInset,
                bottom = if (showBar) BAR_HEIGHT + bottomInset else bottomInset
            )
        )

        if (showBar) {
            Column(
                Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(NexusColors.Void)
            ) {
                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(1.dp)
                        .background(NexusColors.LineSoft)
                )
                Row(
                    Modifier
                        .fillMaxWidth()
                        .height(BAR_HEIGHT),
                    horizontalArrangement = Arrangement.SpaceEvenly,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    ShellTab.entries.forEach { tab ->
                        TabItem(tab, tab == current) { onSelect(tab) }
                    }
                }
                Box(Modifier.height(bottomInset))
            }
        }
    }
}

@Composable
private fun TabItem(tab: ShellTab, active: Boolean, onClick: () -> Unit) {
    val tint = if (active) LocalAccent.current else NexusColors.Muted
    Column(
        Modifier
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null,
                onClick = onClick
            )
            .padding(horizontal = 10.dp, vertical = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Icon(tab.icon, contentDescription = tab.label, tint = tint, modifier = Modifier.size(21.dp))
        Text(
            tab.label,
            style = MaterialTheme.typography.labelSmall,
            color = tint,
            modifier = Modifier.padding(top = 3.dp)
        )
    }
}
