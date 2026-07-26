package app.nexus.di

import android.content.Context
import app.nexus.BuildConfig
import app.nexus.core.Net
import app.nexus.data.db.NexusDatabase
import app.nexus.data.db.SourceEntity
import app.nexus.data.meta.MetadataRepository
import app.nexus.data.meta.anilist.AniListProvider
import app.nexus.data.meta.jikan.JikanClient
import app.nexus.data.meta.tmdb.TmdbProvider
import app.nexus.data.prefs.GeneralPrefs
import app.nexus.data.prefs.SettingsStore
import app.nexus.data.source.SourceConnector
import app.nexus.data.source.SourceRegistry
import app.nexus.data.source.connectors.AddonConnector
import app.nexus.data.source.connectors.AniwatchConnector
import app.nexus.data.source.connectors.ConsumetConnector
import app.nexus.data.source.connectors.Embed69Connector
import app.nexus.data.source.connectors.JellyfinConnector
import app.nexus.data.source.connectors.LatanimeConnector
import app.nexus.data.source.connectors.LocalFolderConnector
import app.nexus.data.source.connectors.MonoschinosConnector
import app.nexus.data.source.connectors.PelisplusConnector
import app.nexus.data.source.connectors.TioanimeConnector
import app.nexus.data.source.connectors.VidSrcConnector
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * Contenedor de dependencias a mano. Con una app de este tamano, Hilt anadiria
 * procesamiento de anotaciones sin ahorrar nada aqui.
 */
class Graph(context: Context) {

    val appContext: Context = context.applicationContext
    val scope = CoroutineScope(SupervisorJob())

    val http = Net.client(appContext)
    val db = NexusDatabase.get(appContext)
    val settings = SettingsStore(appContext)

    /**
     * Copia sincrona de los ajustes generales. Los proveedores necesitan leer
     * el idioma y la clave en caliente, dentro de llamadas que no son suspend.
     */
    private val generalCache = MutableStateFlow(GeneralPrefs())
    val general: StateFlow<GeneralPrefs> = generalCache.asStateFlow()

    private val tmdb = TmdbProvider(
        baseClient = http,
        apiKeyProvider = { BuildConfig.TMDB_API_KEY },
        languageProvider = { generalCache.value.metadataLanguage },
        regionProvider = { generalCache.value.region }
    )

    val metadata: MetadataRepository = MetadataRepository(
        listOf(
            tmdb,
            AniListProvider(
                client = http,
                preferRomaji = { generalCache.value.animeRomaji },
                jikan = JikanClient(http),
                // Cruce con TMDB (busca por titulo, cuadra por ano): aporta la
                // sinopsis en el idioma del usuario -AniList solo la tiene en
                // ingles- y, para las peliculas de anime, el IMDb, con el que
                // embed69 y los addons pueden resolverlas.
                tmdbCrossRef = { title, original, year ->
                    runCatching { tmdb.crossReference(title, original, year) }.getOrNull()
                }
            )
        )
    )

    val sources = SourceRegistry()

    init {
        scope.launch {
            settings.generalPrefs.collect { generalCache.value = it }
        }
        scope.launch {
            db.sources().observeAll().collect { rebuildConnectors(it) }
        }
        scope.launch { seedDefaultSourcesIfEmpty() }
    }

    /**
     * Unica fuente de serie: los archivos del propio dispositivo. Solo devuelve
     * lo que de verdad esta en el movil, asi que nunca aparece un titulo que no
     * corresponde. El resto las anade el usuario desde Ajustes > Fuentes.
     */
    private suspend fun seedDefaultSourcesIfEmpty() {
        if (db.sources().all().isEmpty()) {
            db.sources().upsert(
                SourceEntity(
                    id = "local",
                    name = "Archivos del dispositivo",
                    kind = KIND_LOCAL,
                    priority = 0
                )
            )
        }
        // Fuentes online de fabrica: varias en paralelo para que un mismo titulo
        // tenga varias opciones de enlace. Todas devuelven m3u8 directo. El
        // usuario puede quitarlas o reordenarlas desde Ajustes > Fuentes; van
        // detras de los archivos locales para que un archivo tuyo, si existe,
        // siga ganando. Para Consumet, username = proveedor y password = modo.
        val current = db.sources().all()
        // Retira las fuentes muertas auto-sembradas antes (solo si el usuario no
        // les puso un host propio).
        current.filter { it.id in STALE_SEEDED_IDS && it.host.isBlank() }
            .forEach { db.sources().delete(it.id) }

        val existing = current.map { it.id }.toSet()
        DEFAULT_ONLINE_SOURCES.forEach { seed ->
            if (seed.id !in existing) db.sources().upsert(seed)
        }
    }

    private fun rebuildConnectors(entities: List<SourceEntity>) {
        val connectors = entities
            .filter { it.enabled }
            .sortedBy { it.priority }
            .mapNotNull { build(it) }
        sources.replaceAll(connectors)
    }

    private fun build(entity: SourceEntity): SourceConnector? = when (entity.kind) {
        KIND_LOCAL -> LocalFolderConnector(appContext)

        KIND_JELLYFIN -> JellyfinConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            host = entity.host,
            username = entity.username,
            password = entity.password
        )

        KIND_ADDON -> AddonConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            addonUrl = entity.host
        )

        KIND_CONSUMET -> ConsumetConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            // host vacio = base por defecto de Endpoints; el usuario puede
            // apuntar a su propia instancia Consumet escribiendola en la fuente.
            baseUrl = entity.host.ifBlank { app.nexus.core.Endpoints.Consumet.BASE },
            mode = runCatching {
                ConsumetConnector.Mode.valueOf(entity.password.ifBlank { "BOTH" })
            }.getOrDefault(ConsumetConnector.Mode.BOTH),
            animeProvider = entity.username.ifBlank { app.nexus.core.Endpoints.Consumet.ANIME_PROVIDER },
            moviesProvider = entity.username.ifBlank { "flixhq" }
        )

        KIND_ANIWATCH -> AniwatchConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            baseUrl = entity.host.ifBlank { app.nexus.core.Endpoints.Consumet.ANIWATCH_BASE }
        )

        KIND_EMBED69 -> Embed69Connector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://embed69.org" }
        )

        KIND_PELISPLUS -> PelisplusConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://pelisplushd.bz" }
        )

        KIND_TIOANIME -> TioanimeConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://tioanime.com" }
        )

        KIND_MONOSCHINOS -> MonoschinosConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://monoschinos.st" }
        )

        KIND_LATANIME -> LatanimeConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://latanime.org" }
        )

        KIND_VIDSRC -> VidSrcConnector(
            id = entity.id,
            displayName = entity.name,
            client = http,
            context = appContext,
            baseUrl = entity.host.ifBlank { "https://vidsrc.to" }
        )

        else -> null
    }

    companion object {
        const val KIND_LOCAL = "LOCAL"
        const val KIND_JELLYFIN = "JELLYFIN"
        const val KIND_ADDON = "ADDON"
        const val KIND_CONSUMET = "CONSUMET"
        const val KIND_ANIWATCH = "ANIWATCH"
        const val KIND_EMBED69 = "EMBED69"
        const val KIND_PELISPLUS = "PELISPLUS"
        const val KIND_TIOANIME = "TIOANIME"
        const val KIND_MONOSCHINOS = "MONOSCHINOS"
        const val KIND_LATANIME = "LATANIME"
        const val KIND_VIDSRC = "VIDSRC"

        /**
         * Fuentes online que se siembran de fabrica, todas por raspado directo
         * (sin depender de instancias externas): embed69 (cine/series por IMDb) y
         * pelisplus/tioanime/monoschinos/latanime (anime por titulo), activas; mas
         * VidSrc (cine/series por IMDb, redundante con embed69) sembrada pero
         * DESHABILITADA -opt-in-. Las de Consumet/aniwatch se pueden anadir a mano
         * desde Ajustes cuando se disponga de una instancia viva; sembrarlas de
         * serie solo colgaba la busqueda porque las publicas estan caidas.
         */
        private val DEFAULT_ONLINE_SOURCES = listOf(
            SourceEntity(
                id = "embed69", name = "Cine · Embed69 (LAT/CAST)", kind = KIND_EMBED69,
                priority = 1
            ),
            SourceEntity(
                id = "pelisplus", name = "Series/Anime · Pelisplus", kind = KIND_PELISPLUS,
                priority = 2
            ),
            SourceEntity(
                id = "tioanime", name = "Anime VOSE · Tioanime", kind = KIND_TIOANIME,
                priority = 3
            ),
            SourceEntity(
                id = "monoschinos", name = "Anime VOSE · Monoschinos", kind = KIND_MONOSCHINOS,
                priority = 4
            ),
            SourceEntity(
                id = "latanime", name = "Anime LAT/CAST · Latanime", kind = KIND_LATANIME,
                priority = 5
            ),
            // VidSrc se siembra DESHABILITADA: es redundancia por IMDb, pero es
            // puramente WebView (pesada) y su player no siempre suelta el m3u8 por
            // autoplay. Queda "preparada" en Ajustes > Fuentes para activarla o
            // repuntar su dominio (vidsrc.to/.net/.xyz) quien la quiera.
            SourceEntity(
                id = "vidsrc", name = "Cine/Series · VidSrc (IMDb)", kind = KIND_VIDSRC,
                enabled = false, priority = 6
            )
        )

        /**
         * Fuentes que se auto-sembraron en versiones previas apuntando a
         * instancias publicas ya caidas. Se retiran una sola vez -solo si siguen
         * con host en blanco, es decir sin que el usuario las haya reconfigurado-
         * para que no cuelguen la busqueda.
         */
        private val STALE_SEEDED_IDS = listOf("flixhq", "goku", "zoro", "animekai", "hianime")

        @Volatile
        private var instance: Graph? = null

        fun get(context: Context): Graph = instance ?: synchronized(this) {
            instance ?: Graph(context).also { instance = it }
        }
    }
}
