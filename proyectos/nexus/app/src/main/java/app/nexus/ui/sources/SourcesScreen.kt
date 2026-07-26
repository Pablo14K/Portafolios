package app.nexus.ui.sources

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.NetworkCheck
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import app.nexus.data.db.SourceEntity
import app.nexus.di.Graph
import app.nexus.ui.components.EmptyState
import app.nexus.ui.components.Eyebrow
import app.nexus.ui.theme.LocalAccent
import app.nexus.ui.theme.NexusColors

@Composable
fun SourcesScreen(graph: Graph, onBack: () -> Unit) {
    val vm: SourcesViewModel = viewModel(factory = SourcesViewModel.Factory(graph))
    val sources by vm.sources.collectAsStateWithLifecycle()
    val draft by vm.draft.collectAsStateWithLifecycle()
    val checking by vm.checking.collectAsStateWithLifecycle()

    Box(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier
                .fillMaxSize()
                .padding(top = WindowInsets.statusBars.asPaddingValues().calculateTopPadding())
        ) {
            item {
                Row(
                    Modifier.fillMaxWidth().padding(end = 8.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Default.ArrowBack, "Volver", tint = NexusColors.TextDim)
                    }
                    Column(Modifier.weight(1f)) {
                        Text(
                            "Fuentes",
                            style = MaterialTheme.typography.titleLarge,
                            color = NexusColors.Text
                        )
                        Eyebrow("El orden es la prioridad")
                    }
                    IconButton(onClick = { vm.startAdding() }) {
                        Icon(Icons.Default.Add, "Anadir fuente", tint = LocalAccent.current)
                    }
                }
            }

            if (sources.isEmpty()) {
                item {
                    EmptyState(
                        title = "No hay fuentes",
                        detail = "Anade tu servidor Jellyfin o un addon para ampliar lo que se puede reproducir."
                    )
                }
            }

            items(sources, key = { it.id }) { source ->
                SourceRow(
                    source = source,
                    isFirst = source.id == sources.first().id,
                    isLast = source.id == sources.last().id,
                    checking = checking == source.id,
                    onToggle = { vm.setEnabled(source, it) },
                    onCheck = { vm.checkHealth(source) },
                    onUp = { vm.moveUp(source) },
                    onDown = { vm.moveDown(source) },
                    onDelete = { vm.delete(source) },
                    onEdit = { vm.startEditing(source) }
                )
            }

            item {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Eyebrow("Anadir", color = NexusColors.Muted)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlineChip("Jellyfin / Emby") { vm.startAdding(Graph.KIND_JELLYFIN) }
                        OutlineChip("Addon por URL") { vm.startAdding(Graph.KIND_ADDON) }
                    }
                }
            }

            item { Box(Modifier.height(40.dp)) }
        }
    }

    draft?.let { current ->
        SourceDialog(
            draft = current,
            testResult = vm.test.collectAsStateWithLifecycle().value,
            onChange = vm::updateDraft,
            onTest = vm::testDraft,
            onSave = vm::saveDraft,
            onDismiss = vm::cancelDraft
        )
    }
}

@Composable
private fun SourceRow(
    source: SourceEntity,
    isFirst: Boolean,
    isLast: Boolean,
    checking: Boolean,
    onToggle: (Boolean) -> Unit,
    onCheck: () -> Unit,
    onUp: () -> Unit,
    onDown: () -> Unit,
    onDelete: () -> Unit,
    onEdit: () -> Unit
) {
    // Las de serie no se editan ni se borran: no tienen nada que configurar.
    val isLocal = source.kind == Graph.KIND_LOCAL

    Row(
        Modifier
            .fillMaxWidth()
            .clickable(enabled = !isLocal, onClick = onEdit)
            .padding(start = 16.dp, end = 6.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                source.name,
                style = MaterialTheme.typography.titleMedium,
                color = if (source.enabled) NexusColors.Text else NexusColors.Muted
            )
            val status = when {
                checking -> "Comprobando"
                source.lastError != null -> source.lastError
                source.lastCheckedAt > 0 -> "Responde"
                else -> kindLabel(source.kind)
            }
            Eyebrow(
                status.orEmpty(),
                color = if (source.lastError != null) NexusColors.Critical else NexusColors.Muted
            )
        }

        if (checking) {
            CircularProgressIndicator(
                color = LocalAccent.current,
                strokeWidth = 2.dp,
                modifier = Modifier.size(16.dp)
            )
        } else if (!isLocal) {
            IconButton(onClick = onCheck, modifier = Modifier.size(34.dp)) {
                Icon(
                    Icons.Default.NetworkCheck,
                    "Comprobar",
                    tint = NexusColors.Muted,
                    modifier = Modifier.size(17.dp)
                )
            }
        }

        IconButton(onClick = onUp, enabled = !isFirst, modifier = Modifier.size(30.dp)) {
            Icon(
                Icons.Default.ArrowUpward,
                "Subir prioridad",
                tint = if (isFirst) NexusColors.LineSoft else NexusColors.Muted,
                modifier = Modifier.size(15.dp)
            )
        }
        IconButton(onClick = onDown, enabled = !isLast, modifier = Modifier.size(30.dp)) {
            Icon(
                Icons.Default.ArrowDownward,
                "Bajar prioridad",
                tint = if (isLast) NexusColors.LineSoft else NexusColors.Muted,
                modifier = Modifier.size(15.dp)
            )
        }

        if (!isLocal) {
            IconButton(onClick = onDelete, modifier = Modifier.size(30.dp)) {
                Icon(
                    Icons.Default.Delete,
                    "Borrar",
                    tint = NexusColors.Muted,
                    modifier = Modifier.size(15.dp)
                )
            }
        }

        Switch(
            checked = source.enabled,
            onCheckedChange = onToggle,
            colors = SwitchDefaults.colors(
                checkedThumbColor = Color.Black,
                checkedTrackColor = LocalAccent.current,
                uncheckedThumbColor = NexusColors.Muted,
                uncheckedTrackColor = NexusColors.Surface,
                uncheckedBorderColor = NexusColors.Line
            )
        )
    }
}

@Composable
private fun SourceDialog(
    draft: SourceDraft,
    testResult: TestResult,
    onChange: ((SourceDraft) -> SourceDraft) -> Unit,
    onTest: () -> Unit,
    onSave: () -> Unit,
    onDismiss: () -> Unit
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = NexusColors.Surface,
        titleContentColor = NexusColors.Text,
        textContentColor = NexusColors.TextDim,
        title = {
            Text(draft.title(), style = MaterialTheme.typography.titleLarge)
        },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Field(
                    value = draft.name,
                    label = "Nombre",
                    onValueChange = { v -> onChange { it.copy(name = v) } }
                )
                Field(
                    value = draft.host,
                    label = draft.hostLabel(),
                    placeholder = draft.hostPlaceholder(),
                    onValueChange = { v -> onChange { it.copy(host = v) } }
                )
                if (draft.needsCredentials) {
                    Field(
                        value = draft.username,
                        label = "Usuario",
                        onValueChange = { v -> onChange { it.copy(username = v) } }
                    )
                    Field(
                        value = draft.password,
                        label = "Clave",
                        isPassword = true,
                        onValueChange = { v -> onChange { it.copy(password = v) } }
                    )
                }

                when (testResult) {
                    TestResult.Idle -> Unit
                    TestResult.Running -> Eyebrow("Comprobando...", color = NexusColors.Muted)
                    is TestResult.Ok -> Eyebrow(testResult.detail, color = LocalAccent.current)
                    is TestResult.Failed -> Eyebrow(
                        testResult.reason,
                        color = NexusColors.Critical
                    )
                }
            }
        },
        confirmButton = {
            Text(
                "Guardar",
                style = MaterialTheme.typography.titleMedium,
                color = LocalAccent.current,
                modifier = Modifier
                    .clickable(onClick = onSave)
                    .padding(horizontal = 12.dp, vertical = 8.dp)
            )
        },
        dismissButton = {
            Row {
                Text(
                    "Probar",
                    style = MaterialTheme.typography.titleMedium,
                    color = NexusColors.TextDim,
                    modifier = Modifier
                        .clickable(onClick = onTest)
                        .padding(horizontal = 12.dp, vertical = 8.dp)
                )
                Text(
                    "Cancelar",
                    style = MaterialTheme.typography.titleMedium,
                    color = NexusColors.Muted,
                    modifier = Modifier
                        .clickable(onClick = onDismiss)
                        .padding(horizontal = 12.dp, vertical = 8.dp)
                )
            }
        }
    )
}

@Composable
private fun Field(
    value: String,
    label: String,
    onValueChange: (String) -> Unit,
    placeholder: String? = null,
    isPassword: Boolean = false
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        placeholder = placeholder?.let { { Text(it) } },
        singleLine = true,
        visualTransformation = if (isPassword) {
            PasswordVisualTransformation()
        } else {
            androidx.compose.ui.text.input.VisualTransformation.None
        },
        shape = RoundedCornerShape(6.dp),
        modifier = Modifier.fillMaxWidth(),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = LocalAccent.current,
            unfocusedBorderColor = NexusColors.Line,
            cursorColor = LocalAccent.current,
            focusedTextColor = NexusColors.Text,
            unfocusedTextColor = NexusColors.Text,
            focusedLabelColor = LocalAccent.current,
            unfocusedLabelColor = NexusColors.Muted,
            focusedPlaceholderColor = NexusColors.Muted,
            unfocusedPlaceholderColor = NexusColors.Muted
        )
    )
}

@Composable
private fun OutlineChip(label: String, onClick: () -> Unit) {
    Text(
        label,
        style = MaterialTheme.typography.labelMedium,
        color = NexusColors.TextDim,
        modifier = Modifier
            .border(1.dp, NexusColors.Line, RoundedCornerShape(20.dp))
            .background(Color.Transparent, RoundedCornerShape(20.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp)
    )
}

private fun kindLabel(kind: String): String = when (kind) {
    Graph.KIND_LOCAL -> "Archivos del dispositivo"
    Graph.KIND_JELLYFIN -> "Jellyfin / Emby"
    Graph.KIND_ADDON -> "Addon"
    else -> kind
}
