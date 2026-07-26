package app.nexus.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.nexus.domain.MediaItem
import app.nexus.domain.MediaKind
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors
import coil3.compose.AsyncImage

/** Etiqueta monoespaciada verde: la unidad tipografica de la maqueta. */
@Composable
fun Eyebrow(text: String, modifier: Modifier = Modifier, color: Color = LocalAccent.current) {
    Text(
        text = text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = modifier
    )
}

@Composable
fun Tag(
    text: String,
    modifier: Modifier = Modifier,
    color: Color = LocalAccent.current
) {
    Text(
        text = text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = modifier
            .clip(RoundedCornerShape(3.dp))
            .border(1.dp, color.copy(alpha = 0.45f), RoundedCornerShape(3.dp))
            .background(color.copy(alpha = 0.10f))
            .padding(horizontal = 5.dp, vertical = 2.dp)
    )
}

@Composable
fun PosterCard(
    item: MediaItem,
    modifier: Modifier = Modifier,
    /** null deja que el contenedor mande: se usa asi en las rejillas. */
    width: androidx.compose.ui.unit.Dp? = 116.dp,
    progressPercent: Int? = null,
    showTitle: Boolean = false,
    onClick: () -> Unit
) {
    Column(modifier = if (width != null) modifier.width(width) else modifier) {
        Box(
            Modifier
                .fillMaxWidth()
                .aspectRatio(2f / 3f)
                .clip(RoundedCornerShape(7.dp))
                .background(NexusColors.SurfaceHigh)
                .border(1.dp, NexusColors.LineSoft, RoundedCornerShape(7.dp))
                .clickable(onClick = onClick)
        ) {
            AsyncImage(
                model = item.posterUrl,
                contentDescription = item.title,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize()
            )

            if (item.posterUrl == null) {
                Text(
                    text = item.title,
                    style = MaterialTheme.typography.bodySmall,
                    color = NexusColors.TextDim,
                    textAlign = TextAlign.Center,
                    maxLines = 4,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier
                        .align(Alignment.Center)
                        .padding(8.dp)
                )
            }

            KindBadge(
                item.kind,
                Modifier
                    .align(Alignment.TopStart)
                    .padding(5.dp)
            )

            if (progressPercent != null && progressPercent > 0) {
                Box(
                    Modifier
                        .align(Alignment.BottomStart)
                        .fillMaxWidth()
                        .height(3.dp)
                        .background(Color.Black.copy(alpha = 0.55f))
                ) {
                    Box(
                        Modifier
                            .fillMaxWidth(progressPercent / 100f)
                            .fillMaxSize()
                            .background(LocalAccent.current)
                    )
                }
            }
        }

        if (showTitle) {
            Text(
                text = item.title,
                style = MaterialTheme.typography.bodySmall,
                color = NexusColors.Text,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 6.dp)
            )
            item.year?.let {
                Text(
                    text = it.toString(),
                    style = MaterialTheme.typography.labelSmall,
                    color = NexusColors.Muted
                )
            }
        }
    }
}

@Composable
private fun KindBadge(kind: MediaKind, modifier: Modifier = Modifier) {
    val label = when (kind) {
        MediaKind.MOVIE -> "PELICULA"
        MediaKind.SHOW -> "SERIE"
        MediaKind.ANIME -> "ANIME"
        MediaKind.LIVE -> "VIVO"
    }
    Text(
        text = label,
        style = MaterialTheme.typography.labelSmall,
        color = LocalAccent.current,
        modifier = modifier
            .clip(RoundedCornerShape(3.dp))
            .background(Color.Black.copy(alpha = 0.75f))
            .padding(horizontal = 4.dp, vertical = 1.dp)
    )
}

@Composable
fun LoadingState(label: String = "Cargando", modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.fillMaxWidth().padding(vertical = 40.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        CircularProgressIndicator(
            color = LocalAccent.current,
            strokeWidth = 2.dp,
            modifier = Modifier.size(26.dp)
        )
        Eyebrow(label, color = NexusColors.Muted)
    }
}

@Composable
fun EmptyState(
    title: String,
    detail: String? = null,
    modifier: Modifier = Modifier,
    action: (@Composable () -> Unit)? = null
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 44.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text(
            text = title,
            style = MaterialTheme.typography.titleMedium,
            color = NexusColors.Text,
            textAlign = TextAlign.Center
        )
        detail?.let {
            Text(
                text = it,
                style = MaterialTheme.typography.bodySmall,
                color = NexusColors.Muted,
                textAlign = TextAlign.Center
            )
        }
        action?.invoke()
    }
}

@Composable
fun ErrorState(message: String, modifier: Modifier = Modifier, onRetry: (() -> Unit)? = null) {
    Column(
        modifier = modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 44.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        Eyebrow("Algo fallo", color = NexusColors.Critical)
        Text(
            text = message,
            style = MaterialTheme.typography.bodySmall,
            color = NexusColors.TextDim,
            textAlign = TextAlign.Center
        )
        onRetry?.let {
            Text(
                text = "Reintentar",
                style = MaterialTheme.typography.labelMedium,
                color = LocalAccent.current,
                modifier = Modifier
                    .clip(RoundedCornerShape(4.dp))
                    .border(1.dp, LocalAccent.current, RoundedCornerShape(4.dp))
                    .clickable(onClick = it)
                    .padding(horizontal = 14.dp, vertical = 7.dp)
            )
        }
    }
}

/** Degradado negro de abajo arriba: se usa sobre carteles y fondos. */
fun scrimBrush(): Brush = Brush.verticalGradient(
    0f to Color.Transparent,
    0.55f to Color.Black.copy(alpha = 0.55f),
    1f to Color.Black
)

@Composable
fun SectionHeader(title: String, trailing: String? = null, modifier: Modifier = Modifier) {
    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text = title,
            style = MaterialTheme.typography.titleMedium,
            color = NexusColors.Text
        )
        trailing?.let { Eyebrow(it) }
    }
}
