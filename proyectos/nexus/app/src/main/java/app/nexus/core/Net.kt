package app.nexus.core

import android.content.Context
import android.util.Log
import kotlinx.serialization.json.Json
import okhttp3.Cache
import okhttp3.Dns
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.dnsoverhttps.DnsOverHttps
import java.io.File
import java.net.InetAddress
import java.security.SecureRandom
import java.security.cert.X509Certificate
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

/**
 * Un solo cliente HTTP para toda la app: catalogo, conectores, subtitulos y
 * video. Compartir el pool de conexiones ahorra bastante cuando varias
 * fuentes atacan al mismo host a la vez.
 */
object Net {

    val json: Json = Json {
        ignoreUnknownKeys = true
        coerceInputValues = true
        isLenient = true
        explicitNulls = false
    }

    @Volatile
    private var client: OkHttpClient? = null

    fun client(context: Context): OkHttpClient = client ?: synchronized(this) {
        client ?: build(context).also { client = it }
    }

    private fun build(context: Context): OkHttpClient {
        val cacheDir = File(context.applicationContext.cacheDir, "http")
        return OkHttpClient.Builder()
            .cache(Cache(cacheDir, 64L * 1024 * 1024))
            .connectTimeout(12, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .writeTimeout(20, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .followRedirects(true)
            .followSslRedirects(true)
            .dns(resilientDns())
            .acceptAnyCertificate()
            .build()
    }

    /**
     * DNS del sistema con respaldo por DNS-over-HTTPS (Cloudflare). Muchos de los
     * hosts de estos sitios estan bloqueados a nivel de DNS por los ISP -sobre
     * todo en LATAM-: el sitio esta vivo pero el movil no resuelve su IP y la
     * fuente parece caida. Se intenta primero el resolutor del sistema (rapido y
     * cacheado) y solo si devuelve vacio o lanza se recurre a DoH, que viaja por
     * HTTPS y el ISP no puede interceptar. Igual que EPIX PLAY (DnsManager con
     * DnsOverHttps de Cloudflare); no afecta al caso normal, solo rescata hosts
     * censurados.
     *
     * bootstrapDnsHosts lleva las IP literales de Cloudflare para no necesitar el
     * DNS del sistema (que puede ser justo el bloqueado) al contactar el propio
     * servidor DoH.
     */
    private fun resilientDns(): Dns {
        // Cliente propio y minimo para el DoH: sin cache ni el trust-all del
        // cliente principal, para no crear dependencia circular al construirlo.
        val bootstrap = OkHttpClient.Builder()
            .connectTimeout(6, TimeUnit.SECONDS)
            .build()
        val doh = runCatching {
            DnsOverHttps.Builder()
                .client(bootstrap)
                .url("https://cloudflare-dns.com/dns-query".toHttpUrl())
                .bootstrapDnsHosts(
                    InetAddress.getByName("1.1.1.1"),
                    InetAddress.getByName("1.0.0.1"),
                    InetAddress.getByName("2606:4700:4700::1111"),
                    InetAddress.getByName("2606:4700:4700::1001")
                )
                .build()
        }.getOrNull()

        // okhttp3.Dns es una interfaz Kotlin normal (no fun interface): no admite
        // conversion SAM, hay que implementarla con un objeto.
        return object : Dns {
            override fun lookup(hostname: String): List<InetAddress> {
                val system = runCatching { Dns.SYSTEM.lookup(hostname) }
                    .getOrDefault(emptyList())
                return if (system.isNotEmpty() || doh == null) {
                    system.ifEmpty { throw java.net.UnknownHostException(hostname) }
                } else {
                    runCatching { doh.lookup(hostname) }
                        .onSuccess { Log.i(TAG, "DoH rescato $hostname") }
                        .getOrElse { throw java.net.UnknownHostException(hostname) }
                }
            }
        }
    }

    private const val TAG = "Net"

    /**
     * Muchos CDN de estos hosts de video usan certificados autofirmados o de CA
     * no reconocidas; con la validacion estricta, el reproductor recibe
     * "Trust anchor not found" y aborta. Se acepta cualquier certificado para
     * poder reproducir (mismo enfoque que las apps de referencia). Solo afecta al
     * transporte de video/scraping; no se envian credenciales sensibles.
     */
    private fun OkHttpClient.Builder.acceptAnyCertificate(): OkHttpClient.Builder {
        val trustManager = object : X509TrustManager {
            override fun checkClientTrusted(chain: Array<X509Certificate>?, authType: String?) {}
            override fun checkServerTrusted(chain: Array<X509Certificate>?, authType: String?) {}
            override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
        }
        val sslContext = SSLContext.getInstance("TLS").apply {
            init(null, arrayOf<TrustManager>(trustManager), SecureRandom())
        }
        return sslSocketFactory(sslContext.socketFactory, trustManager)
            .hostnameVerifier { _, _ -> true }
    }

    /** Algunos servidores rechazan peticiones sin un agente de navegador. */
    const val DEFAULT_USER_AGENT =
        "Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36"
}
