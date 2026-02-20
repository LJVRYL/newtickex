package com.tickex.strwebview

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {

    companion object {
        private const val WEB_URL = "https://str.tickex.com.ar/login.php?app=1"
        private const val FILE_CHOOSER_REQUEST_CODE = 1001
    }

    private lateinit var webView: WebView
    private var filePathCallback: ValueCallback<Array<Uri>>? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Algunos teléfonos crashean si falta/está deshabilitado el proveedor de WebView.
        // Creamos el WebView programáticamente y, si falla, abrimos el sitio en el navegador.
        try {
            setContentView(R.layout.activity_main)
        } catch (e: Throwable) {
            openInBrowserAndFinish()
            return
        }

        val container = findViewById<FrameLayout>(R.id.web_container)
        try {
            webView = WebView(this)
            container.addView(
                webView,
                FrameLayout.LayoutParams(
                    FrameLayout.LayoutParams.MATCH_PARENT,
                    FrameLayout.LayoutParams.MATCH_PARENT
                )
            )
        } catch (e: Throwable) {
            openInBrowserAndFinish()
            return
        }

        val settings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.cacheMode = WebSettings.LOAD_DEFAULT
        settings.useWideViewPort = true
        settings.loadWithOverviewMode = true
    settings.userAgentString = settings.userAgentString + " TickexAppWebView"

        // Mejor compatibilidad para sitios que usan mixed content (idealmente evitá esto si todo es HTTPS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
        }

        // Cookies (incluye third-party para ciertos checkout)
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookieManager.setAcceptThirdPartyCookies(webView, true)
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val url = request?.url?.toString() ?: return false
                return handleExternalSchemes(url)
            }

            @Deprecated("Deprecated in Java")
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                val u = url ?: return false
                return handleExternalSchemes(u)
            }

        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallback

                val intent = fileChooserParams?.createIntent() ?: Intent(Intent.ACTION_GET_CONTENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = "*/*"
                }

                return try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST_CODE)
                    true
                } catch (e: ActivityNotFoundException) {
                    this@MainActivity.filePathCallback = null
                    false
                }
            }
        }

        // Back dentro del WebView
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    finish()
                }
            }
        })

        try {
            if (savedInstanceState == null) {
                webView.loadUrl(WEB_URL)
            } else {
                webView.restoreState(savedInstanceState)
            }
        } catch (e: Throwable) {
            openInBrowserAndFinish()
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        try {
            webView.saveState(outState)
        } catch (_: Throwable) {
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)

        if (requestCode == FILE_CHOOSER_REQUEST_CODE) {
            val callback = filePathCallback
            filePathCallback = null

            if (callback == null) return

            val result = WebChromeClient.FileChooserParams.parseResult(resultCode, data)
            callback.onReceiveValue(result)
        }
    }

    private fun handleExternalSchemes(url: String): Boolean {
        // Permitir navegación normal en http(s)
        if (URLUtil.isHttpUrl(url) || URLUtil.isHttpsUrl(url)) {
            return false
        }

        // Abrir esquemas comunes con apps del sistema
        if (url.startsWith("tel:") || url.startsWith("mailto:") || url.startsWith("sms:")) {
            return try {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                true
            } catch (e: Exception) {
                true
            }
        }

        // intent:// (algunos gateways / apps)
        if (url.startsWith("intent:")) {
            return try {
                val intent = Intent.parseUri(url, Intent.URI_INTENT_SCHEME)
                startActivity(intent)
                true
            } catch (e: Exception) {
                true
            }
        }

        // Fallback: intentar abrir con ACTION_VIEW
        return try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            true
        } catch (e: Exception) {
            true
        }
    }

    private fun openInBrowserAndFinish() {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(WEB_URL)))
        } catch (_: Throwable) {
        }
        finish()
    }
}
