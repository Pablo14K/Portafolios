package app.nexus.core

/**
 * Endpoints y credenciales de las APIs de referencia, centralizados en un solo
 * sitio. No se "indaga" nada aqui: son valores fijos que la app usa tal cual.
 *
 * Lo unico que hoy esta cableado de punta a punta es [Consumet] (anime y
 * cine/series por m3u8 directo), porque es la unica base abierta y auto-alojable
 * que devuelve enlaces reproducibles sin extractor por-sitio. El resto queda
 * declarado para los conectores que se construyan despues.
 */
object Endpoints {

    /**
     * Instancias open-source que AnimeCast auto-hospeda. Devuelven m3u8 + subs
     * directos, asi que sirven de fuente real sin depender de CSC lab: se pueden
     * desplegar en Vercel/Docker desde `github.com/consumet/api.consumet.org` y
     * `github.com/ghoshRitesh12/aniwatch-api`.
     */
    object Consumet {
        /** Consumet completo: rutas meta/anilist y movies/flixhq. */
        const val BASE = "https://scrape.csc-lab-api.xyz"

        /** aniwatch-api (hianime), respaldo para anime. */
        const val ANIWATCH_BASE = "https://scrape-aniwatch.csc-lab-api.xyz"

        /** Proveedor de anime por defecto dentro de meta/anilist. */
        const val ANIME_PROVIDER = "zoro"
    }

    /** API propia de AnimeCast (CSC lab): catalogo JkAnime con Basic auth. */
    object AnimeCast {
        const val JK_BASE = "https://app.animecast.xyz"
        const val JK_USER = "api.animecast.xyz"
        const val JK_PASSWORD = "R9UyEInvH4oRLplXi0fynT6BA45PlxHCICn8IkeEpJ6Oe261YL"
        const val APP_KEY_LOGIN = "csc-lab-app-anime"

        const val MIRURO_BASE = "https://scrape-miruro.csc-lab.co"
        const val MIRURO_KEY = "3UBPf07V5Ad9Gp33VzYT7ctmYdsbVBKffwVyo66QEjgt119s3m"
    }

    /** Metadatos. La clave interna de TMDB va por BuildConfig.TMDB_API_KEY. */
    object Tmdb {
        const val API_BASE = "https://api.themoviedb.org/3/"
        const val IMAGE_BASE = "https://image.tmdb.org/t/p/"
    }

    object Jikan {
        const val BASE = "https://api.jikan.moe/v4/"
    }

    /** OAuth de Trakt, tal cual venia en EPIX PLAY. */
    object Trakt {
        const val CLIENT_ID =
            "72832fc77399a4db21e49b911574cc12d48d70ffc11f5bf391d3c026aad99189"
        const val CLIENT_SECRET =
            "3af16fc0ffe851aaf70c718a6fdfe5b632b07ee1c216f493fbb1460e3002ddc4"
        const val REDIRECT_URI = "https://www.epixplay.com/"
        const val BASE = "https://api.trakt.tv/"
    }
}
