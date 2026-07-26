package app.nexus.data.source.connectors

import java.util.Locale

/**
 * Elige, de los slugs que devuelve el buscador de un sitio, el que de verdad
 * corresponde al título pedido — o **nada** si ninguno casa con confianza.
 *
 * Antes cada conector caía, como último recurso, al **primer resultado** y usaba
 * coincidencia por **subcadena**. Ambas cosas traían contenido equivocado: buscar
 * "House" en pelisplushd devuelve primero `barbie-dreamhouse-adventures`, y
 * `"barbie-dreamhouse".contains("house")` es `true` (por "dream·house·"). Un
 * enlace equivocado es peor que ninguno, así que aquí:
 *  - la coincidencia es por **segmentos** (partes separadas por `-`): "house"
 *    casa con `dr-house`/`house-md` pero **no** con `dreamhouse`;
 *  - si nada casa se devuelve vacío, sin "coger el primero".
 */
object SlugMatch {

    fun slugify(text: String): String = text
        .lowercase(Locale.ROOT)
        .replace("á", "a").replace("é", "e").replace("í", "i")
        .replace("ó", "o").replace("ú", "u").replace("ñ", "n")
        .replace(Regex("[^a-z0-9]+"), "-")
        .trim('-')

    /** El mejor slug para el título, o null si ninguno casa con confianza. */
    fun best(candidates: List<String>, title: String): String? =
        matches(candidates, title).firstOrNull()

    /**
     * Todos los slugs que casan, ordenados por confianza: exacto → el título como
     * prefijo (`house` → `house-md`) → el título como segmento (`dr-house`). Vacío
     * si ninguno casa. Los sufijos aleatorios de algunos sitios (`...-NKMMKi`) no
     * estorban porque se compara en minúsculas y por segmentos.
     */
    fun matches(candidates: List<String>, title: String): List<String> {
        val main = title.substringBefore(':').substringBefore('(').trim().ifBlank { title }
        val wanted = slugify(main)
        if (wanted.length < 2) return emptyList()
        val words = wanted.split('-').filter { it.length >= 3 }

        fun norm(s: String) = s.lowercase(Locale.ROOT)

        val exact = candidates.filter { norm(it) == wanted }
        val prefix = candidates.filter { it !in exact && norm(it).startsWith("$wanted-") }
        val segment = candidates.filter {
            it !in exact && it !in prefix && isSegmentMatch(norm(it), words)
        }
        return exact + prefix + segment
    }

    private fun isSegmentMatch(slug: String, words: List<String>): Boolean {
        val segs = slug.split('-')
        return when {
            // Título de una sola palabra: debe ser un segmento entero del slug, y
            // con >=4 letras para no dispararse con palabras comunes ("the", "day").
            words.size == 1 -> words[0].length >= 4 && words[0] in segs
            // Título multipalabra: todas sus palabras son segmentos del slug.
            words.size >= 2 -> words.all { it in segs }
            else -> false
        }
    }
}
