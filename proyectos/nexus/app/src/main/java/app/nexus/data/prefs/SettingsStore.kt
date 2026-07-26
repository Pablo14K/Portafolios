package app.nexus.data.prefs

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.floatPreferencesKey
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.core.stringSetPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import app.nexus.domain.AudioTag
import app.nexus.domain.Quality
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore("nexus_settings")

/**
 * Todos los ajustes en un sitio. Cada bloque se expone como Flow para que la
 * interfaz reaccione sin recargar pantalla.
 */
class SettingsStore(context: Context) {

    private val store = context.applicationContext.dataStore

    // --- Fuentes y enlaces ------------------------------------------------

    val linkFilters: Flow<LinkFilters> = store.data.map { p ->
        LinkFilters(
            minQuality = p.quality(K.MIN_QUALITY, Quality.SD_480),
            maxQuality = p.quality(K.MAX_QUALITY, Quality.UHD_2160),
            maxSizeBytes = p[K.MAX_SIZE] ?: 0L,
            discardCam = p[K.DISCARD_CAM] ?: true,
            allowedAudio = p[K.ALLOWED_AUDIO]
                ?.mapNotNull { name -> runCatching { AudioTag.valueOf(name) }.getOrNull() }
                ?.toSet()
                ?: emptySet(),
            sortBy = p.enum(K.SORT_BY, LinkFilters.Sort.QUALITY),
            autoPlayBest = p[K.AUTOPLAY_BEST] ?: false,
            skipToNextOnFailure = p[K.SKIP_ON_FAIL] ?: true,
            maxRetries = p[K.MAX_RETRIES] ?: 3,
            cacheTtlMinutes = p[K.CACHE_TTL] ?: 30
        )
    }

    suspend fun updateLinkFilters(transform: (LinkFilters) -> LinkFilters) {
        val current = linkFilters.first()
        val next = transform(current)
        store.edit { p ->
            p[K.MIN_QUALITY] = next.minQuality.name
            p[K.MAX_QUALITY] = next.maxQuality.name
            p[K.MAX_SIZE] = next.maxSizeBytes
            p[K.DISCARD_CAM] = next.discardCam
            p[K.ALLOWED_AUDIO] = next.allowedAudio.map { it.name }.toSet()
            p[K.SORT_BY] = next.sortBy.name
            p[K.AUTOPLAY_BEST] = next.autoPlayBest
            p[K.SKIP_ON_FAIL] = next.skipToNextOnFailure
            p[K.MAX_RETRIES] = next.maxRetries
            p[K.CACHE_TTL] = next.cacheTtlMinutes
        }
    }

    // --- Reproductor ------------------------------------------------------

    val playerPrefs: Flow<PlayerPrefs> = store.data.map { p ->
        PlayerPrefs(
            engine = p.enum(K.ENGINE, PlayerPrefs.Engine.AUTO),
            hardwareDecoding = p[K.HW_DECODE] ?: true,
            matchRefreshRate = p[K.MATCH_FPS] ?: true,
            qualityOnWifi = p.quality(K.Q_WIFI, Quality.UHD_2160),
            qualityOnMobile = p.quality(K.Q_MOBILE, Quality.HD_720),
            bufferSeconds = p[K.BUFFER_S] ?: 60,
            resumePlayback = p[K.RESUME] ?: true,
            askBeforeResume = p[K.ASK_RESUME] ?: false,
            watchedThresholdPercent = p[K.WATCHED_PCT] ?: 90,
            autoPlayNextEpisode = p[K.AUTONEXT] ?: true,
            autoPlayCountdownSeconds = p[K.AUTONEXT_S] ?: 8,
            skipIntro = p.enum(K.SKIP_INTRO, PlayerPrefs.SkipMode.BUTTON),
            skipCredits = p[K.SKIP_CREDITS] ?: true,
            defaultSpeed = p[K.SPEED] ?: 1.0f,
            gestures = p[K.GESTURES] ?: true,
            doubleTapSeekSeconds = p[K.DBLTAP_S] ?: 10,
            pipOnLeave = p[K.PIP] ?: true,
            keepScreenOn = p[K.KEEP_ON] ?: true
        )
    }

    suspend fun updatePlayer(transform: (PlayerPrefs) -> PlayerPrefs) {
        val next = transform(playerPrefs.first())
        store.edit { p ->
            p[K.ENGINE] = next.engine.name
            p[K.HW_DECODE] = next.hardwareDecoding
            p[K.MATCH_FPS] = next.matchRefreshRate
            p[K.Q_WIFI] = next.qualityOnWifi.name
            p[K.Q_MOBILE] = next.qualityOnMobile.name
            p[K.BUFFER_S] = next.bufferSeconds
            p[K.RESUME] = next.resumePlayback
            p[K.ASK_RESUME] = next.askBeforeResume
            p[K.WATCHED_PCT] = next.watchedThresholdPercent
            p[K.AUTONEXT] = next.autoPlayNextEpisode
            p[K.AUTONEXT_S] = next.autoPlayCountdownSeconds
            p[K.SKIP_INTRO] = next.skipIntro.name
            p[K.SKIP_CREDITS] = next.skipCredits
            p[K.SPEED] = next.defaultSpeed
            p[K.GESTURES] = next.gestures
            p[K.DBLTAP_S] = next.doubleTapSeekSeconds
            p[K.PIP] = next.pipOnLeave
            p[K.KEEP_ON] = next.keepScreenOn
        }
    }

    // --- Subtitulos -------------------------------------------------------

    val subtitlePrefs: Flow<SubtitlePrefs> = store.data.map { p ->
        SubtitlePrefs(
            preferredLanguage = p[K.SUB_LANG1] ?: "es",
            secondaryLanguage = p[K.SUB_LANG2] ?: "en",
            enabledByDefault = p[K.SUB_ON] ?: true,
            onlyWhenAudioDiffers = p[K.SUB_SMART] ?: false,
            autoSearchOnline = p[K.SUB_ONLINE] ?: true,
            textScalePercent = p[K.SUB_SCALE] ?: 100,
            textColor = p[K.SUB_COLOR] ?: 0xFFFFFFFF,
            outline = p.enum(K.SUB_OUTLINE, SubtitlePrefs.Outline.OUTLINE),
            backgroundOpacityPercent = p[K.SUB_BG] ?: 55,
            verticalOffsetPercent = p[K.SUB_OFFSET] ?: -6,
            respectAssStyles = p[K.SUB_ASS] ?: true,
            hideSdh = p[K.SUB_SDH] ?: false,
            encoding = p[K.SUB_ENC] ?: "UTF-8"
        )
    }

    suspend fun updateSubtitles(transform: (SubtitlePrefs) -> SubtitlePrefs) {
        val next = transform(subtitlePrefs.first())
        store.edit { p ->
            p[K.SUB_LANG1] = next.preferredLanguage
            p[K.SUB_LANG2] = next.secondaryLanguage
            p[K.SUB_ON] = next.enabledByDefault
            p[K.SUB_SMART] = next.onlyWhenAudioDiffers
            p[K.SUB_ONLINE] = next.autoSearchOnline
            p[K.SUB_SCALE] = next.textScalePercent
            p[K.SUB_COLOR] = next.textColor
            p[K.SUB_OUTLINE] = next.outline.name
            p[K.SUB_BG] = next.backgroundOpacityPercent
            p[K.SUB_OFFSET] = next.verticalOffsetPercent
            p[K.SUB_ASS] = next.respectAssStyles
            p[K.SUB_SDH] = next.hideSdh
            p[K.SUB_ENC] = next.encoding
        }
    }

    // --- General ----------------------------------------------------------

    val generalPrefs: Flow<GeneralPrefs> = store.data.map { p ->
        GeneralPrefs(
            appLanguage = p[K.APP_LANG] ?: "es",
            metadataLanguage = p[K.META_LANG] ?: "es-ES",
            region = p[K.REGION] ?: "EC",
            preferOriginalTitles = p[K.ORIG_TITLES] ?: false,
            animeRomaji = p[K.ROMAJI] ?: false,
            posterSize = p.enum(K.POSTER_SIZE, GeneralPrefs.PosterSize.MEDIUM),
            gridColumns = p[K.COLUMNS] ?: 3,
            showTitlesUnderPosters = p[K.POSTER_TITLES] ?: false,
            accentColor = p[K.ACCENT] ?: 0xFF00FF2C,
            tintFromPoster = p[K.TINT] ?: true,
            animations = p[K.ANIM] ?: true,
            forceTvInterface = p[K.FORCE_TV_SET]?.let { if (it) p[K.FORCE_TV] ?: false else null },
            downloadsWifiOnly = p[K.DL_WIFI] ?: true,
            maxParallelDownloads = p[K.DL_PARALLEL] ?: 2,
            historyEnabled = p[K.HISTORY] ?: true
        )
    }

    suspend fun updateGeneral(transform: (GeneralPrefs) -> GeneralPrefs) {
        val next = transform(generalPrefs.first())
        store.edit { p ->
            p[K.APP_LANG] = next.appLanguage
            p[K.META_LANG] = next.metadataLanguage
            p[K.REGION] = next.region
            p[K.ORIG_TITLES] = next.preferOriginalTitles
            p[K.ROMAJI] = next.animeRomaji
            p[K.POSTER_SIZE] = next.posterSize.name
            p[K.COLUMNS] = next.gridColumns
            p[K.POSTER_TITLES] = next.showTitlesUnderPosters
            p[K.ACCENT] = next.accentColor
            p[K.TINT] = next.tintFromPoster
            p[K.ANIM] = next.animations
            p[K.FORCE_TV_SET] = next.forceTvInterface != null
            p[K.FORCE_TV] = next.forceTvInterface ?: false
            p[K.DL_WIFI] = next.downloadsWifiOnly
            p[K.DL_PARALLEL] = next.maxParallelDownloads
            p[K.HISTORY] = next.historyEnabled
        }
    }

    suspend fun resetAll() = store.edit { it.clear() }

    // --- Utilidades -------------------------------------------------------

    private fun Preferences.quality(key: Preferences.Key<String>, fallback: Quality): Quality =
        this[key]?.let { runCatching { Quality.valueOf(it) }.getOrNull() } ?: fallback

    private inline fun <reified E : Enum<E>> Preferences.enum(
        key: Preferences.Key<String>,
        fallback: E
    ): E = this[key]?.let { runCatching { enumValueOf<E>(it) }.getOrNull() } ?: fallback

    private object K {
        val MIN_QUALITY = stringPreferencesKey("min_quality")
        val MAX_QUALITY = stringPreferencesKey("max_quality")
        val MAX_SIZE = longPreferencesKey("max_size")
        val DISCARD_CAM = booleanPreferencesKey("discard_cam")
        val ALLOWED_AUDIO = stringSetPreferencesKey("allowed_audio")
        val SORT_BY = stringPreferencesKey("sort_by")
        val AUTOPLAY_BEST = booleanPreferencesKey("autoplay_best")
        val SKIP_ON_FAIL = booleanPreferencesKey("skip_on_fail")
        val MAX_RETRIES = intPreferencesKey("max_retries")
        val CACHE_TTL = intPreferencesKey("cache_ttl")

        val ENGINE = stringPreferencesKey("engine")
        val HW_DECODE = booleanPreferencesKey("hw_decode")
        val MATCH_FPS = booleanPreferencesKey("match_fps")
        val Q_WIFI = stringPreferencesKey("q_wifi")
        val Q_MOBILE = stringPreferencesKey("q_mobile")
        val BUFFER_S = intPreferencesKey("buffer_s")
        val RESUME = booleanPreferencesKey("resume")
        val ASK_RESUME = booleanPreferencesKey("ask_resume")
        val WATCHED_PCT = intPreferencesKey("watched_pct")
        val AUTONEXT = booleanPreferencesKey("autonext")
        val AUTONEXT_S = intPreferencesKey("autonext_s")
        val SKIP_INTRO = stringPreferencesKey("skip_intro")
        val SKIP_CREDITS = booleanPreferencesKey("skip_credits")
        val SPEED = floatPreferencesKey("speed")
        val GESTURES = booleanPreferencesKey("gestures")
        val DBLTAP_S = intPreferencesKey("dbltap_s")
        val PIP = booleanPreferencesKey("pip")
        val KEEP_ON = booleanPreferencesKey("keep_on")

        val SUB_LANG1 = stringPreferencesKey("sub_lang1")
        val SUB_LANG2 = stringPreferencesKey("sub_lang2")
        val SUB_ON = booleanPreferencesKey("sub_on")
        val SUB_SMART = booleanPreferencesKey("sub_smart")
        val SUB_ONLINE = booleanPreferencesKey("sub_online")
        val SUB_SCALE = intPreferencesKey("sub_scale")
        val SUB_COLOR = longPreferencesKey("sub_color")
        val SUB_OUTLINE = stringPreferencesKey("sub_outline")
        val SUB_BG = intPreferencesKey("sub_bg")
        val SUB_OFFSET = intPreferencesKey("sub_offset")
        val SUB_ASS = booleanPreferencesKey("sub_ass")
        val SUB_SDH = booleanPreferencesKey("sub_sdh")
        val SUB_ENC = stringPreferencesKey("sub_enc")

        val APP_LANG = stringPreferencesKey("app_lang")
        val META_LANG = stringPreferencesKey("meta_lang")
        val REGION = stringPreferencesKey("region")
        val ORIG_TITLES = booleanPreferencesKey("orig_titles")
        val ROMAJI = booleanPreferencesKey("romaji")
        val POSTER_SIZE = stringPreferencesKey("poster_size")
        val COLUMNS = intPreferencesKey("columns")
        val POSTER_TITLES = booleanPreferencesKey("poster_titles")
        val ACCENT = longPreferencesKey("accent")
        val TINT = booleanPreferencesKey("tint")
        val ANIM = booleanPreferencesKey("anim")
        val FORCE_TV = booleanPreferencesKey("force_tv")
        val FORCE_TV_SET = booleanPreferencesKey("force_tv_set")
        val DL_WIFI = booleanPreferencesKey("dl_wifi")
        val DL_PARALLEL = intPreferencesKey("dl_parallel")
        val HISTORY = booleanPreferencesKey("history")
    }
}
