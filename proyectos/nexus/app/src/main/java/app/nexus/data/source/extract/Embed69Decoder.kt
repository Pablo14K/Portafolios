package app.nexus.data.source.extract

import android.util.Base64
import android.util.Log
import app.nexus.core.Net
import okhttp3.OkHttpClient
import okhttp3.Request
import java.security.MessageDigest
import javax.crypto.Cipher
import javax.crypto.spec.IvParameterSpec
import javax.crypto.spec.SecretKeySpec

private const val TAG = "Embed69Decoder"

/** Un host de video sacado de embed69, aun sin resolver a m3u8. */
data class DecodedEmbed(val server: String, val url: String, val language: String)

/**
 * Descifra la lista de hosts que embed69.org esconde en su pagina. Se usa tanto
 * desde [app.nexus.data.source.connectors.Embed69Connector] (que construye la
 * URL por id de IMDb) como desde cualquier conector que se tope con una URL de
 * embed69 al raspar un sitio.
 *
 * La proteccion tiene dos capas: (1) una prueba de trabajo -buscar un nonce cuyo
 * SHA-256 empiece por N ceros- y (2) AES-CBC con clave derivada de ese nonce.
 */
object Embed69Decoder {

    /** Descarga [pageUrl] y devuelve sus hosts ya descifrados. */
    fun decode(client: OkHttpClient, pageUrl: String, referer: String): List<DecodedEmbed> {
        val html = fetch(client, pageUrl, referer) ?: return emptyList()
        return decodeHtml(html)
    }

    fun decodeHtml(html: String): List<DecodedEmbed> {
        val challenge = Regex("""POW_CHALLENGE\s*=\s*'([^']+)'""").find(html)?.groupValues?.get(1)
            ?: return emptyList()
        val difficulty = Regex("""POW_DIFFICULTY\s*=\s*(\d+)""").find(html)?.groupValues?.get(1)?.toIntOrNull()
            ?: return emptyList()
        val salt = Regex("""POW_SALT\s*=\s*'([^']+)'""").find(html)?.groupValues?.get(1)
            ?: return emptyList()
        val dataLink = Regex("""dataLink\s*=\s*(\[.*?\]);""", RegexOption.DOT_MATCHES_ALL)
            .find(html)?.groupValues?.get(1) ?: return emptyList()

        val nonce = mineNonce(challenge, difficulty) ?: return emptyList()
        val key = sha256("$challenge$nonce$salt")

        val out = mutableListOf<DecodedEmbed>()
        val pairRegex = Regex(""""servername":"([^"]+)","link":"([^"]+)"""")
        val langRegex = Regex(""""video_language":"([^"]*)"""")

        dataLink.split("\"file_id\"").drop(1).forEach { chunk ->
            val language = langRegex.find(chunk)?.groupValues?.get(1) ?: ""
            pairRegex.findAll(chunk).forEach { match ->
                val url = decryptLink(match.groupValues[2], key) ?: return@forEach
                out += DecodedEmbed(match.groupValues[1], url, language)
            }
        }
        return out
    }

    private fun mineNonce(challenge: String, difficulty: Int): Long? {
        val prefix = "0".repeat(difficulty)
        val digest = MessageDigest.getInstance("SHA-256")
        var nonce = 0L
        val limit = 8_000_000L
        while (nonce < limit) {
            digest.reset()
            val hex = digest.digest("$challenge$nonce".toByteArray()).toHex()
            if (hex.startsWith(prefix)) return nonce
            nonce++
        }
        Log.w(TAG, "PoW sin solucion (dificultad $difficulty)")
        return null
    }

    private fun decryptLink(link: String, key: ByteArray): String? = runCatching {
        val raw = Base64.decode(link.replace("\\", ""), Base64.DEFAULT)
        if (raw.size <= 16) return null
        val iv = raw.copyOfRange(0, 16)
        val ciphertext = raw.copyOfRange(16, raw.size)
        val cipher = Cipher.getInstance("AES/CBC/PKCS5Padding")
        cipher.init(Cipher.DECRYPT_MODE, SecretKeySpec(key, "AES"), IvParameterSpec(iv))
        String(cipher.doFinal(ciphertext)).takeIf { it.startsWith("http") }
    }.onFailure { Log.w(TAG, "descifrado: ${it.message}") }.getOrNull()

    private fun fetch(client: OkHttpClient, url: String, referer: String): String? = runCatching {
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
    }.onFailure { Log.w(TAG, it.message ?: "fetch") }.getOrNull()

    private fun sha256(text: String): ByteArray =
        MessageDigest.getInstance("SHA-256").digest(text.toByteArray())

    private val HEX = "0123456789abcdef".toCharArray()

    private fun ByteArray.toHex(): String {
        val sb = StringBuilder(size * 2)
        for (b in this) {
            val v = b.toInt() and 0xFF
            sb.append(HEX[v ushr 4]).append(HEX[v and 0x0F])
        }
        return sb.toString()
    }
}
