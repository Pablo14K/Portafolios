package app.nexus.ui

import android.content.Intent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import app.nexus.di.Graph
import app.nexus.ui.catalog.CatalogScreen
import app.nexus.ui.detail.DetailScreen
import app.nexus.ui.home.HomeScreen
import app.nexus.ui.library.LibraryScreen
import app.nexus.ui.player.PlaybackRequest
import app.nexus.ui.player.PlayerActivity
import app.nexus.ui.search.SearchScreen
import app.nexus.ui.settings.SettingsScreen
import app.nexus.ui.sources.SourcesScreen
import java.net.URLDecoder
import java.net.URLEncoder

object Routes {
    const val HOME = "home"
    const val CATALOG = "catalog"
    const val SEARCH = "search"
    const val LIBRARY = "library"
    const val DETAIL = "detail/{mediaId}"
    const val SETTINGS = "settings"
    const val SOURCES = "sources"

    fun detail(mediaId: String): String = "detail/" + URLEncoder.encode(mediaId, "UTF-8")
}

@Composable
fun NexusNavHost(graph: Graph) {
    val nav = rememberNavController()
    val context = LocalContext.current
    val backStack by nav.currentBackStackEntryAsState()
    val route = backStack?.destination?.route

    val currentTab = ShellTab.entries.firstOrNull { it.route == route }

    val play: (PlaybackRequest) -> Unit = { request ->
        context.startActivity(
            Intent(context, PlayerActivity::class.java)
                .putExtra(PlayerActivity.EXTRA_REQUEST, request)
        )
    }

    val openDetail: (app.nexus.domain.MediaId) -> Unit = { id ->
        nav.navigate(Routes.detail(id.toString()))
    }

    Shell(
        current = currentTab,
        onSelect = { tab -> nav.switchTab(tab) },
        content = { padding ->
            NexusRoutes(
                nav = nav,
                graph = graph,
                padding = padding,
                onPlay = play,
                onOpenDetail = openDetail
            )
        }
    )
}

@Composable
private fun NexusRoutes(
    nav: NavHostController,
    graph: Graph,
    padding: PaddingValues,
    onPlay: (PlaybackRequest) -> Unit,
    onOpenDetail: (app.nexus.domain.MediaId) -> Unit
) {
    NavHost(navController = nav, startDestination = Routes.HOME) {

        composable(Routes.HOME) {
            HomeScreen(
                graph = graph,
                onOpenDetail = onOpenDetail,
                onOpenSettings = { nav.navigate(Routes.SETTINGS) },
                contentPadding = padding
            )
        }

        composable(Routes.CATALOG) {
            CatalogScreen(
                graph = graph,
                onOpenDetail = onOpenDetail,
                contentPadding = padding
            )
        }

        composable(Routes.SEARCH) {
            SearchScreen(
                graph = graph,
                onOpenDetail = onOpenDetail,
                onOpenSettings = { nav.navigate(Routes.SETTINGS) },
                contentPadding = padding
            )
        }

        composable(Routes.LIBRARY) {
            LibraryScreen(
                graph = graph,
                onOpenDetail = onOpenDetail,
                contentPadding = padding
            )
        }

        composable(
            route = Routes.DETAIL,
            arguments = listOf(navArgument("mediaId") { type = NavType.StringType })
        ) { entry ->
            val raw = entry.arguments?.getString("mediaId").orEmpty()
            DetailScreen(
                graph = graph,
                mediaIdRaw = URLDecoder.decode(raw, "UTF-8"),
                onBack = { nav.popBackStack() },
                onPlay = onPlay,
                onOpenDetail = onOpenDetail
            )
        }

        composable(Routes.SETTINGS) {
            SettingsScreen(
                graph = graph,
                onBack = { nav.popBackStack() },
                onOpenSources = { nav.navigate(Routes.SOURCES) }
            )
        }

        composable(Routes.SOURCES) {
            SourcesScreen(graph = graph, onBack = { nav.popBackStack() })
        }
    }
}

/**
 * Cambiar de pestana no debe apilar pantallas: se vuelve a la raiz guardando
 * el estado de cada una, como en cualquier app con barra inferior.
 */
private fun NavHostController.switchTab(tab: ShellTab) {
    navigate(tab.route) {
        popUpTo(graph.findStartDestination().id) { saveState = true }
        launchSingleTop = true
        restoreState = true
    }
}
