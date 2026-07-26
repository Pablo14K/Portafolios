package app.nexus.ui

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.nexus.NexusApp
import app.nexus.ui.theme.NexusColors
import app.nexus.ui.theme.NexusTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        val graph = (application as NexusApp).graph

        setContent {
            val general by graph.general.collectAsStateWithLifecycle()
            NexusTheme(accent = Color(general.accentColor)) {
                Box(
                    Modifier
                        .fillMaxSize()
                        .background(NexusColors.Void)
                ) {
                    NexusNavHost(graph = graph)
                }
            }
        }
    }
}
