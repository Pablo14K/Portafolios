package app.nexus.data.source.extract

/**
 * Desempaquetador del ofuscador "Dean Edwards p,a,c,k,e,d", el que usan casi
 * todos los reproductores de estos hosts (VidHide, StreamWish y familia). El
 * m3u8 real vive dentro de ese bloque comprimido, no en el HTML.
 *
 * Es la traduccion directa del algoritmo de descompresion; no ejecuta nada, solo
 * rehace las sustituciones de tokens.
 */
object JsUnpacker {

    // Tolera espacios y las variantes de cabecera (p,a,c,k,e,d / e,r): algunos
    // hosts la reformatean minimamente y el patron rigido dejaba de casar.
    private val PACKED = Regex(
        """\}\s*\(\s*'(.*?)'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*'(.*?)'\.split\('\|'\)""",
        RegexOption.DOT_MATCHES_ALL
    )

    /** Devuelve el codigo ya expandido, o null si el texto no viene empaquetado. */
    fun unpack(text: String): String? {
        val m = PACKED.find(text) ?: return null
        var payload = m.groupValues[1].replace("\\'", "'").replace("\\\\", "\\")
        val radix = m.groupValues[2].toIntOrNull() ?: return null
        val count = m.groupValues[3].toIntOrNull() ?: return null
        val words = m.groupValues[4].split("|")

        for (i in count - 1 downTo 0) {
            val token = words.getOrNull(i)
            if (token.isNullOrEmpty()) continue
            payload = payload.replace(Regex("\\b" + Regex.escape(encode(i, radix)) + "\\b")) { token }
        }
        return payload
    }

    /** base-a con el mismo alfabeto (0-9 a-z, y >35 salta a caracteres altos). */
    private fun encode(n: Int, radix: Int): String {
        val prefix = if (n < radix) "" else encode(n / radix, radix)
        val digit = n % radix
        val ch = if (digit > 35) (digit + 29).toChar().toString() else digit.toString(36)
        return prefix + ch
    }
}
