package app.nexus.data.source

import app.nexus.domain.StreamLink
import app.nexus.domain.StreamRequest

/**
 * Una fuente de enlaces. Todo lo que sabe reproducir la app entra por aqui:
 * carpetas del movil, el catalogo publico de archive.org, un Jellyfin de casa,
 * un addon por URL o una cuenta debrid. La interfaz no sabe cual es cual.
 */
interface SourceConnector {

    /** Estable entre arranques: se guarda en la base de datos. */
    val id: String

    /** Lo que ve el usuario en la lista de enlaces. */
    val displayName: String

    /** Para el icono y para agrupar en la pantalla de fuentes. */
    val type: SourceType

    /**
     * Busca enlaces para lo que se pide. Debe devolver rapido o no devolver:
     * el registro corta a los pocos segundos para no dejar la pantalla colgada.
     *
     *
     * No lanza: si algo falla, devuelve lista vacia y registra el motivo.
     */
    suspend fun resolve(request: StreamRequest): List<StreamLink>

    /** Comprobacion de la pantalla de ajustes. */
    suspend fun healthCheck(): SourceHealth = SourceHealth.Ok(null)
}

enum class SourceType(val label: String) {
    LOCAL("Archivos del dispositivo"),
    SERVER("Servidor personal"),
    PUBLIC("Catalogo publico"),
    ADDON("Addon"),
    DEBRID("Debrid"),
    DIRECT("Enlace directo")
}

sealed interface SourceHealth {
    data class Ok(val latencyMs: Long?) : SourceHealth
    data class Failing(val reason: String) : SourceHealth
    data object Disabled : SourceHealth
}
