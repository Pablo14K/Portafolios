package app.nexus.data.source.extract

import android.annotation.SuppressLint
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.os.Message
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import app.nexus.core.Net
import app.nexus.domain.Quality
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withPermit
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Resuelve hosts de video cargando su pagina en un WebView oculto y espiando la
 * peticion de red del m3u8/mp4 que hace el reproductor. Es la via generica para
 * los hosts que el raspado por HTTP no saca (Voe, StreamWish, Filemoon...):
 * ejecutan su JS, pasan su reto y piden el video; aqui solo se escucha esa
 * peticion y se roba la URL, tecnica calcada de ModoCine/EpixPlay.
 *
 * Es mas lento y pesado que [HostResolver], asi que se usa como respaldo y de
 * uno en uno (un solo WebView vivo a la vez, protegido por [mutex]).
 */
object WebViewResolver {

    // Hasta 2 WebViews a la vez: mas rapido para resolver varios hosts con JS a
    // la vez, sin cargar de mas el dispositivo con demasiados WebViews abiertos.
    private val gate = Semaphore(2)

    /** Extensiones/patrones que delatan el stream real frente a anuncios y assets. */
    private val STREAM = Regex("""\.(m3u8|mp4|mpd)(\?|$)""", RegexOption.IGNORE_CASE)
    private val IGNORE = Regex(
        """(\.(ts|jpg|jpeg|png|webp|gif|css|js|woff2?|vtt|svg|ico)(\?|$)|""" +
            """google|doubleclick|analytics|facebook|/ads?/|histats|popads|propeller)""",
        RegexOption.IGNORE_CASE
    )

    /** Lo hallado por el WebView: la URL del stream y las cabeceras que la abren. */
    private data class Found(val url: String, val headers: Map<String, String>)

    suspend fun resolve(
        context: Context,
        embedUrl: String,
        timeoutMs: Long = 12_000,
        // Referer con el que se carga el embed. Algunos hosts lo exigen; el que
        // enlaza cambia segun la fuente, por eso es parametro (por defecto el de
        // pelisplushd, que es de donde salio esta tecnica).
        referer: String = "https://pelisplushd.bz/"
    ): List<HostLink> =
        gate.withPermit {
            val origin = originOf(embedUrl) ?: return emptyList()
            // El sniff ya se auto-corta y limpia el WebView; el withTimeoutOrNull
            // es solo un respaldo con algo de margen.
            val found = withTimeoutOrNull(timeoutMs + 2_000) {
                sniff(context, embedUrl, timeoutMs, referer)
            } ?: return emptyList()

            // Cabeceras minimas + las reales que uso el WebView (Referer, Cookie...).
            // Sin la cookie de sesion, muchos CDN devuelven 403 y el player aborta.
            val headers = LinkedHashMap<String, String>().apply {
                put("Referer", "$origin/")
                put("Origin", origin)
                put("User-Agent", Net.DEFAULT_USER_AGENT)
                putAll(found.headers)
            }
            listOf(
                HostLink(
                    url = found.url,
                    quality = Quality.fromLabel(found.url),
                    headers = headers,
                    isM3u8 = found.url.contains(".m3u8", true)
                )
            )
        }

    @SuppressLint("SetJavaScriptEnabled")
    private suspend fun sniff(context: Context, embedUrl: String, timeoutMs: Long, referer: String): Found {
        val result = CompletableDeferred<Found>()
        val main = Handler(Looper.getMainLooper())

        main.post {
            val webView = WebView(context.applicationContext)

            fun finish(found: Found?) {
                main.post {
                    runCatching {
                        webView.stopLoading()
                        webView.destroy()
                    }
                }
                if (found != null) result.complete(found) else result.completeExceptionally(NoSuchElementException())
            }

            // Watchdog propio: pase lo que pase, el WebView se destruye a tiempo.
            main.postDelayed({ finish(null) }, timeoutMs)

            with(webView.settings) {
                javaScriptEnabled = true
                domStorageEnabled = true
                mediaPlaybackRequiresUserGesture = false
                userAgentString = Net.DEFAULT_USER_AGENT
                blockNetworkImage = true
                loadsImagesAutomatically = false
                // Los embeds abren pestanas/popups de anuncios que pueden robar
                // la navegacion y arruinar el sniff. Se dejan gestionar por el
                // WebChromeClient de abajo, que los rechaza.
                setSupportMultipleWindows(true)
                javaScriptCanOpenWindowsAutomatically = false
            }

            // Rechaza cualquier ventana nueva (popup/clickunder): no crea WebView
            // hijo, asi que el anuncio no se abre y el principal sigue con el sniff.
            webView.webChromeClient = object : WebChromeClient() {
                override fun onCreateWindow(
                    view: WebView?,
                    isDialog: Boolean,
                    isUserGesture: Boolean,
                    resultMsg: Message?
                ): Boolean = false
            }

            webView.webViewClient = object : WebViewClient() {
                override fun shouldInterceptRequest(
                    view: WebView?,
                    request: WebResourceRequest?
                ): WebResourceResponse? {
                    val url = request?.url?.toString() ?: return null
                    if (!result.isCompleted && STREAM.containsMatchIn(url) && !IGNORE.containsMatchIn(url)) {
                        // Se roban las cabeceras exactas de la peticion (Referer,
                        // etc.) y la cookie de sesion, que es lo que autoriza el
                        // stream en muchos hosts.
                        val headers = LinkedHashMap<String, String>()
                        request.requestHeaders?.forEach { (k, v) ->
                            if (k.equals("Referer", true) || k.equals("Origin", true) ||
                                k.equals("User-Agent", true)
                            ) headers[k] = v
                        }
                        CookieManager.getInstance().getCookie(url)?.let { headers["Cookie"] = it }
                        finish(Found(url, headers))
                    }
                    return null
                }

                override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                    // Antes de que carguen los anuncios: anula window.open y quita
                    // los iframes de las redes de ad conocidas. Conservador a
                    // proposito -no toca overlays por z-index- para no borrar el
                    // propio boton de play que hay que pulsar. Idea de
                    // ModoCine_ad_blocker.js.
                    view?.evaluateJavascript(KILL_POPUPS, null)
                }

                override fun onPageFinished(view: WebView?, url: String?) {
                    // Muchos reproductores solo piden el m3u8 tras un "play".
                    view?.evaluateJavascript(KILL_POPUPS, null)
                    view?.evaluateJavascript(CLICK_PLAY, null)
                }
            }

            // Referer del sitio que enlaza; sin el, algunos hosts responden 403.
            val headers = mapOf("Referer" to referer)
            runCatching { webView.loadUrl(embedUrl, headers) }
                .onFailure { result.completeExceptionally(it) }
        }

        return result.await()
    }

    private fun originOf(url: String): String? {
        val m = Regex("""(https?://[^/]+)""").find(url) ?: return null
        return m.groupValues[1]
    }

    private const val KILL_POPUPS = """
        (function(){
          try { window.open = function(){ return null; }; } catch(e){}
          try {
            var ADS = /doubleclick|googlesyndication|propeller|popads|popcash|monetag|adsterra|histats|simplejsmenu|clickadu|hilltopads/i;
            var sweep = function(root){
              (root.querySelectorAll ? root.querySelectorAll('iframe,script') : []).forEach(function(n){
                var s = n.src || '';
                if (s && ADS.test(s)) { try { n.remove(); } catch(x){} }
              });
            };
            sweep(document);
            var obs = new MutationObserver(function(muts){
              muts.forEach(function(m){ m.addedNodes.forEach(function(n){
                if (n.nodeType === 1) {
                  var s = n.src || '';
                  if ((n.tagName === 'IFRAME' || n.tagName === 'SCRIPT') && s && ADS.test(s)) {
                    try { n.remove(); } catch(x){}
                  }
                }
              }); });
            });
            obs.observe(document.documentElement, { childList: true, subtree: true });
          } catch(e){}
        })();
    """

    private const val CLICK_PLAY = """
        (function(){
          try {
            var v = document.querySelector('video');
            if (v) { v.muted = true; v.play&&v.play().catch(function(){}); }
            var sel = ['#oframeplayer','.play','.jw-icon-display','[class*=play]','.vjs-big-play-button','button'];
            sel.forEach(function(s){ document.querySelectorAll(s).forEach(function(e){ try{e.click();}catch(x){} }); });
            document.body && document.body.click();
          } catch(e){}
        })();
    """
}
