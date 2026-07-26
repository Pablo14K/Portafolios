package app.nexus.data.source.extract

import android.util.Log
import app.nexus.core.Net
import app.nexus.domain.Quality
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request

private const val TAG = "HostResolver"

/** Un enlace ya resoluble por el reproductor, sacado de un host de video. */
data class HostLink(
    val url: String,
    val quality: Quality,
    val headers: Map<String, String>,
    val isM3u8: Boolean
)

/**
 * Convierte la URL de embed de un host (voe, vidhide, streamwish...) en el m3u8
 * o mp4 final. Cada host esconde el enlace a su manera; aqui viven los patrones
 * conocidos, del mas fiable al mas raro. Si ninguno casa, devuelve lista vacia.
 *
 * La mayoria de estos CDN exigen la cabecera Referer del propio host: sin ella
 * responden 403. Por eso cada [HostLink] se lleva su Referer/Origin puestos.
 *
 * Dos capas:
 *  1. Extractores **con nombre** para hosts cuyo enlace real nunca aparece como
 *     `.m3u8`/`.mp4` en el HTML (StreamTape monta la URL en JS; DoodStream la
 *     firma con `/pass_md5/`). Son justo los que el sniff por WebView tampoco
 *     pilla, porque su peticion final no lleva extension reconocible.
 *  2. Raspado **generico** por regex + desempaquetado, que resuelve la familia
 *     VidHide/StreamWish/Filemoon y cualquiera que exponga el enlace en claro.
 */
object HostResolver {

    private val M3U8 = Regex("""https?:(?://|\\/\\/)?[^"'\\ )]+\.m3u8[^"'\\ )]*""")
    private val MP4 = Regex("""https?:(?://|\\/\\/)?[^"'\\ )]+\.mp4[^"'\\ )]*""")

    // Acepta tambien enlaces sin esquema (protocolo-relativo, //host/x.m3u8): asi
    // sale por HTTP el `MDCore.wurl="//..."` de Mixdrop y similares.
    private val FILE_FIELD =
        Regex("""["']?(?:file|source|src|wurl)["']?\s*:?\s*=?\s*["'](\/\/[^"']+\.(?:m3u8|mp4)[^"']*|https?:[^"']+\.(?:m3u8|mp4)[^"']*)["']""")

    fun resolve(client: OkHttpClient, embedUrl: String): List<HostLink> {
        val origin = originOf(embedUrl) ?: return emptyList()
        val host = embedUrl.toHttpUrlOrNull()?.host?.lowercase().orEmpty()

        // 1. Extractor especifico, si el host lo tiene.
        named(host)?.let { extractor ->
            val links = runCatching { extractor(client, embedUrl, origin) }
                .onFailure { Log.w(TAG, "extractor de $host fallo: ${it.message}") }
                .getOrDefault(emptyList())
            if (links.isNotEmpty()) return links
        }

        // 2. Raspado generico.
        val html = fetch(client, embedUrl, origin) ?: return emptyList()
        val haystack = buildString {
            append(html)
            JsUnpacker.unpack(html)?.let { append('\n').append(it) }
        }

        val found = LinkedHashSet<String>()
        FILE_FIELD.findAll(haystack).forEach { found += it.groupValues[1] }
        M3U8.findAll(haystack).forEach { found += it.value }
        MP4.findAll(haystack).forEach { found += it.value }

        val headers = refererHeaders(origin)
        return found
            .map { normalizeUrl(it) }
            .filter { it.startsWith("http") }
            .map { url -> hostLink(url, headers) }
    }

    // --- Extractores con nombre -------------------------------------------

    private fun named(host: String): ((OkHttpClient, String, String) -> List<HostLink>)? = when {
        host.contains("streamtape") || host.contains("strtape") || host.contains("strtpe") ||
            host.contains("stape") || host.contains("tapewithadblock") -> ::streamtape
        host.contains("dood") || host.contains("d0o0d") || host.contains("ds2play") ||
            host.contains("d000d") || host.contains("vide0") -> ::doodstream
        else -> null
    }

    /**
     * StreamTape monta el enlace de `get_video` concatenando dos trozos de texto
     * en JS (`'...' + ('...').substring(n)`), justo para que un regex simple no lo
     * saque. Se rehace esa concatenacion. El resultado es un mp4 progresivo
     * firmado (token+expires); no lleva extension, por eso el WebView lo ignora.
     */
    private fun streamtape(client: OkHttpClient, embedUrl: String, origin: String): List<HostLink> {
        val html = fetch(client, embedUrl, origin) ?: return emptyList()
        val m = STREAMTAPE.find(html) ?: return emptyList()
        val head = m.groupValues[1]
        val tail = m.groupValues[2]
        val cut = m.groupValues[3].toIntOrNull() ?: 0
        val piece = if (cut in 0..tail.length) tail.substring(cut) else tail
        val assembled = (head + piece).let { if (it.startsWith("//")) "https:$it" else it }
        if (!assembled.contains("get_video")) return emptyList()
        return listOf(
            HostLink(
                url = assembled,
                quality = Quality.fromLabel(assembled),
                headers = refererHeaders(origin),
                isM3u8 = false
            )
        )
    }

    /**
     * DoodStream sirve el video por un endpoint firmado: la pagina trae una ruta
     * `/pass_md5/…`; al pedirla (con el embed como Referer) devuelve la base del
     * CDN, a la que se le pega un sufijo aleatorio y el token+expires. El enlace
     * final caduca en segundos y exige el Referer del propio dood, asi que viaja
     * en las cabeceras. Es mp4 progresivo.
     */
    private fun doodstream(client: OkHttpClient, embedUrl: String, origin: String): List<HostLink> {
        val html = fetch(client, embedUrl, origin) ?: return emptyList()
        val md5Path = PASS_MD5.find(html)?.value ?: return emptyList()
        val token = md5Path.substringAfterLast('/')
        val base = fetchText(client, "$origin$md5Path", referer = embedUrl)?.trim()
            ?.takeIf { it.startsWith("http") } ?: return emptyList()
        val expires = System.currentTimeMillis()
        val url = "$base${randomAlnum(10)}?token=$token&expires=$expires"
        return listOf(
            HostLink(
                url = url,
                quality = Quality.fromLabel(html),
                headers = refererHeaders(origin),
                isM3u8 = false
            )
        )
    }

    private val STREAMTAPE =
        Regex("""['"](\/\/[^'"]*?get_video[^'"]*?)['"]\s*\+\s*\(\s*['"]([^'"]+)['"]\s*\)\.substring\((\d+)\)""")
    private val PASS_MD5 = Regex("""/pass_md5/[\w-]+/[\w-]+""")

    // --- Ayudantes --------------------------------------------------------

    private fun hostLink(url: String, headers: Map<String, String>) = HostLink(
        url = url,
        quality = Quality.fromLabel(url),
        headers = headers,
        isM3u8 = url.contains(".m3u8", true)
    )

    private fun refererHeaders(origin: String) = mapOf(
        "Referer" to "$origin/",
        "Origin" to origin,
        "User-Agent" to Net.DEFAULT_USER_AGENT
    )

    /** Pasa a absoluto: `//host/x` y `https:\/\/host\/x` a `https://host/x`. */
    private fun normalizeUrl(raw: String): String {
        val clean = raw.replace("\\/", "/")
        return when {
            clean.startsWith("//") -> "https:$clean"
            else -> clean
        }
    }

    private fun randomAlnum(n: Int): String {
        val pool = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"
        return buildString { repeat(n) { append(pool.random()) } }
    }

    private fun fetch(client: OkHttpClient, url: String, origin: String): String? =
        fetchText(client, url, referer = "$origin/")

    private fun fetchText(client: OkHttpClient, url: String, referer: String): String? = runCatching {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", Net.DEFAULT_USER_AGENT)
            .header("Referer", referer)
            .header("Accept", "text/html,application/xhtml+xml,*/*")
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return null
            response.body?.string()
        }
    }.onFailure { Log.w(TAG, "fallo en $url: ${it.message}") }.getOrNull()

    private fun originOf(url: String): String? {
        val http = url.toHttpUrlOrNull() ?: return null
        return "${http.scheme}://${http.host}"
    }
}
