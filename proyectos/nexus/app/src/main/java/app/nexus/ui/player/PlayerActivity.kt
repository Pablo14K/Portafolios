package app.nexus.ui.player

import android.content.pm.ActivityInfo
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.lifecycle.lifecycleScope
import app.nexus.NexusApp
import app.nexus.data.prefs.PlayerPrefs
import app.nexus.ui.theme.NexusTheme
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

class PlayerActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val graph = (application as NexusApp).graph

        @Suppress("DEPRECATION")
        val request = (
            if (Build.VERSION.SDK_INT >= 33) {
                intent.getSerializableExtra(EXTRA_REQUEST, PlaybackRequest::class.java)
            } else {
                intent.getSerializableExtra(EXTRA_REQUEST) as? PlaybackRequest
            }
            ) ?: run {
            finish()
            return
        }

        requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
        goFullscreen()

        lifecycleScope.launch {
            val prefs = graph.settings.playerPrefs.first()
            val subtitles = graph.settings.subtitlePrefs.first()
            if (prefs.keepScreenOn) {
                window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
            }
            setContent {
                NexusTheme {
                    Box(
                        Modifier
                            .fillMaxSize()
                            .background(Color.Black)
                    ) {
                        PlayerScreen(
                            graph = graph,
                            request = request,
                            prefs = prefs,
                            subtitlePrefs = subtitles,
                            onExit = { finish() }
                        )
                    }
                }
            }
        }
    }

    private fun goFullscreen() {
        WindowCompat.setDecorFitsSystemWindows(window, false)
        WindowInsetsControllerCompat(window, window.decorView).apply {
            hide(WindowInsetsCompat.Type.systemBars())
            systemBarsBehavior =
                WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        }
    }

    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        // El PiP se activa desde PlayerScreen, que sabe si hay algo reproduciendo.
    }

    companion object {
        const val EXTRA_REQUEST = "playback_request"

        /** Por si algun dia se quiere abrir sin ajustes cargados. */
        val DEFAULT_PREFS = PlayerPrefs()
    }
}
