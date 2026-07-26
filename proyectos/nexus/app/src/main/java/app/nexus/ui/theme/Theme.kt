package app.nexus.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/** Paleta fijada por el cliente: verde senal sobre negro puro. */
object NexusColors {
    val Signal = Color(0xFF00FF2C)
    val SignalDim = Color(0xFF0A6B1E)
    val SignalGhost = Color(0x1700FF2C)
    val Void = Color(0xFF000000)
    val Surface = Color(0xFF0D120D)
    val SurfaceHigh = Color(0xFF141B15)
    val Line = Color(0xFF1C3A21)
    val LineSoft = Color(0xFF122616)
    val Text = Color(0xFFDCF6E0)
    val TextDim = Color(0xFF8FA894)
    val Muted = Color(0xFF5D7462)
    val Warn = Color(0xFFFFC02E)
    val Critical = Color(0xFFFF3B57)
    val Info = Color(0xFF3EC8FF)
}

/** El acento es configurable; el resto de la paleta no. */
val LocalAccent = staticCompositionLocalOf { NexusColors.Signal }

private fun schemeFor(accent: Color) = darkColorScheme(
    primary = accent,
    onPrimary = NexusColors.Void,
    primaryContainer = NexusColors.SignalDim,
    onPrimaryContainer = NexusColors.Text,
    secondary = accent,
    onSecondary = NexusColors.Void,
    background = NexusColors.Void,
    onBackground = NexusColors.Text,
    surface = NexusColors.Surface,
    onSurface = NexusColors.Text,
    surfaceVariant = NexusColors.SurfaceHigh,
    onSurfaceVariant = NexusColors.TextDim,
    outline = NexusColors.Line,
    outlineVariant = NexusColors.LineSoft,
    error = NexusColors.Critical,
    onError = NexusColors.Void
)

private val NexusTypography = Typography(
    displaySmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 30.sp,
        letterSpacing = (-0.8).sp
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 24.sp,
        letterSpacing = (-0.5).sp
    ),
    titleLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 19.sp,
        letterSpacing = (-0.3).sp
    ),
    titleMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 15.sp
    ),
    bodyMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontSize = 14.sp,
        lineHeight = 20.sp
    ),
    bodySmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontSize = 12.5.sp,
        lineHeight = 17.sp
    ),
    // Etiquetas y datos. Eran monoespaciadas y a 10sp resultaban apretadas e
    // irregulares; ahora son sans con peso y un poco de espaciado, que separa
    // igual del cuerpo de texto pero se lee sin esfuerzo. Los digitos van a
    // ancho fijo para que las cifras en columna sigan cuadrando.
    labelSmall = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontSize = 11.sp,
        lineHeight = 14.sp,
        letterSpacing = 0.5.sp,
        fontWeight = FontWeight.SemiBold,
        fontFeatureSettings = "tnum"
    ),
    labelMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontSize = 12.5.sp,
        lineHeight = 16.sp,
        letterSpacing = 0.3.sp,
        fontWeight = FontWeight.Medium,
        fontFeatureSettings = "tnum"
    )
)

@Composable
fun NexusTheme(
    accent: Color = NexusColors.Signal,
    content: @Composable () -> Unit
) {
    // Tema oscuro siempre: la identidad de la app es verde sobre negro y el
    // modo claro romperia el contraste del acento.
    CompositionLocalProvider(LocalAccent provides accent) {
        MaterialTheme(
            colorScheme = schemeFor(accent),
            typography = NexusTypography,
            content = content
        )
    }
}
