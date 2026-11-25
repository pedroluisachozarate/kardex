param([string]$BuildPath=(Join-Path (Split-Path -Parent $PSScriptRoot) 'native-build'))
$ErrorActionPreference='Stop'
$setting=Get-Content (Join-Path $BuildPath '.env') | Where-Object {$_ -match '^KARDEX_SERVER_URL='} | Select-Object -First 1
if(-not $setting){throw 'Falta KARDEX_SERVER_URL en la compilación.'}
$uri=[Uri]($setting.Substring('KARDEX_SERVER_URL='.Length).Trim().Trim('"'))
if($uri.Scheme -notin @('https','http') -or -not $uri.Host -or $uri.UserInfo){throw 'Dirección de servidor inválida.'}
$origin=$uri.GetLeftPart([UriPartial]::Authority)
$assets=Join-Path $BuildPath 'nativephp/android/app/src/main/assets'
New-Item -ItemType Directory -Path $assets -Force | Out-Null
[IO.File]::WriteAllText((Join-Path $assets 'kardex-server.json'),(@{origin=$origin}|ConvertTo-Json -Compress),(New-Object System.Text.UTF8Encoding($false)))
$path=Join-Path $BuildPath 'nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'
$content=Get-Content $path -Raw -Encoding UTF8
if($content -notmatch 'KARDEX_SERVER_NAVIGATION'){
    $property=@"
    // KARDEX_SERVER_NAVIGATION: only the configured server may stay inside the app.
    private val kardexOrigin: Uri? by lazy {
        runCatching {
            Uri.parse(JSONObject(context.assets.open("kardex-server.json").bufferedReader().use { it.readText() }).getString("origin"))
        }.getOrNull()
    }
    private fun kardexPort(uri: Uri): Int = if (uri.port != -1) uri.port else if (uri.scheme == "https") 443 else 80
    private fun isKardexServer(uri: Uri): Boolean {
        val server = kardexOrigin ?: return false
        return uri.scheme == server.scheme && uri.host?.equals(server.host, true) == true && kardexPort(uri) == kardexPort(server)
    }
"@
    $needle='    private val TAG = "PHPMonitor"'
    if(-not $content.Contains($needle)){throw 'Cambió la plantilla WebViewManager; revisar antes de aplicar la integración.'}
    $content=$content.Replace($needle,($needle+"`n"+$property))
    $needle='            private val phpHandler = PHPWebViewClient(phpBridge, context as MainActivity)'
    $fallback=@"

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                super.onReceivedError(view, request, error)
                if (request.isForMainFrame && isKardexServer(request.url)) {
                    view.post { view.loadUrl("http://127.0.0.1/mobile/offline?disconnected=1") }
                }
            }
            override fun onReceivedHttpError(view: WebView, request: WebResourceRequest, response: WebResourceResponse) {
                super.onReceivedHttpError(view, request, response)
                if (request.isForMainFrame && response.statusCode >= 500 && isKardexServer(request.url)) {
                    view.post { view.loadUrl("http://127.0.0.1/mobile/offline?disconnected=1") }
                }
            }
"@
    if(-not $content.Contains($needle)){throw 'No se encontró el cliente principal del navegador.'}
    $content=$content.Replace($needle,($needle+"`n"+$fallback))
    $needle='                if ((url.startsWith("http://") || url.startsWith("https://")) &&'
    if(-not $content.Contains($needle)){throw 'No se encontró el filtro de navegación externa.'}
    $content=$content.Replace($needle,('                if (request.isForMainFrame && isKardexServer(request.url)) return false'+"`n`n"+$needle))
    [IO.File]::WriteAllText($path,$content,(New-Object System.Text.UTF8Encoding($false)))
}
Write-Output "Navegación Android integrada con $origin; los otros dominios conservan el filtro original."
# KARDEX_ATTACHMENTS: native file inputs and PDFs use the existing FileProvider.
Copy-Item -LiteralPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'resources/native/android/KardexAttachments.kt') -Destination (Join-Path $BuildPath 'nativephp/android/app/src/main/java/com/nativephp/mobile/network/KardexAttachments.kt') -Force
$content=Get-Content $path -Raw -Encoding UTF8
if($content -notmatch 'KARDEX_ATTACHMENTS'){
    $needle='    private val TAG = "PHPMonitor"'
    $content=$content.Replace($needle,($needle+"`n"+'    // KARDEX_ATTACHMENTS'+"`n"+'    private val kardexAttachments by lazy { KardexAttachments(context as androidx.activity.ComponentActivity) }'))
    $needle='            override fun onShowCustomView(view: View, callback: CustomViewCallback) {'
    $chooser=@"
            override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                val origin = Uri.parse(view.url ?: "")
                if (!isKardexServer(origin) && origin.host != "127.0.0.1") { callback.onReceiveValue(null); return true }
                return kardexAttachments.choose(params, callback)
            }
"@
    $content=$content.Replace($needle,($chooser+"`n"+$needle))
    $needle='        webView.webViewClient = createCustomWebViewClient()'
    $download=@"
        webView.setDownloadListener { url, agent, disposition, mime, _ ->
            if (isKardexServer(Uri.parse(url))) {
                kardexAttachments.download(url, agent, disposition, mime)
            }
        }
"@
    $content=$content.Replace($needle,($needle+"`n"+$download))
    $needle='if (request.isForMainFrame && isKardexServer(request.url)) return false'
    $index=$content.IndexOf($needle)
    if($index -lt 0){throw 'No se encontró la navegación de ventanas nuevas.'}
    $popup=@"
if (request.isForMainFrame && isKardexServer(request.url)) {
                            if (request.url.path?.endsWith("/imprimir") == true) {
                                kardexAttachments.download(url, webView.settings.userAgentString, null, "application/pdf")
                            } else { webView.loadUrl(url) }
                            popupView.post { popupView.destroy() }
                            return true
                        }
"@
    $content=$content.Substring(0,$index)+$popup+$content.Substring($index+$needle.Length)
    $main=@"
if (request.isForMainFrame && isKardexServer(request.url)) {
                    if (request.url.path?.endsWith("/imprimir") == true) {
                        kardexAttachments.download(url, webView.settings.userAgentString, null, "application/pdf")
                        return true
                    }
                    return false
                }
"@
    if(-not $content.Contains($needle)){throw 'No se encontró la navegación principal.'}
    $content=$content.Replace($needle,$main)
    [IO.File]::WriteAllText($path,$content,(New-Object System.Text.UTF8Encoding($false)))
}
# Honor private/no-cache responses from the complete server application.
$content=Get-Content $path -Raw -Encoding UTF8
$content=$content.Replace('cacheMode = WebSettings.LOAD_CACHE_ELSE_NETWORK // Prefer cache for faster loads','cacheMode = WebSettings.LOAD_DEFAULT // Honor server cache headers and current authentication')
[IO.File]::WriteAllText($path,$content,(New-Object System.Text.UTF8Encoding($false)))

# KARDEX_MOBILE_UX: keep local redirects intercepted and reserve system bars natively.
$content=Get-Content $path -Raw -Encoding UTF8
if($content -notmatch 'KARDEX_LOCAL_RETURN'){
    $needle='                // Handle system URL schemes (tel:, mailto:, sms:, geo:) - open with system handler'
    $navigation=@"
                // KARDEX_LOCAL_RETURN: fresh loads must re-enter PHP interception after a server response.
                val local = request.url.scheme == "http" && request.url.host == "127.0.0.1" && request.url.port in listOf(-1, 80)
                if (request.isForMainFrame && local && (request.isRedirect || isKardexServer(Uri.parse(view.url ?: "")))) {
                    view.loadUrl(url)
                    return true
                }
                if (request.isForMainFrame && isKardexServer(request.url) &&
                    (context.getSystemService(Context.CONNECTIVITY_SERVICE) as android.net.ConnectivityManager).activeNetwork == null) {
                    Toast.makeText(context, "Sin conexión con tu PC. Puedes trabajar en tus borradores.", Toast.LENGTH_LONG).show()
                    view.post { view.loadUrl("http://127.0.0.1/mobile/offline?disconnected=1") }
                    return true
                }
"@
    if(-not $content.Contains($needle)){throw 'No se encontró la navegación para instalar el retorno local.'}
    $content=$content.Replace($needle,($navigation+"`n"+$needle))
    $needle='            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {'
    $legacy=@"
            @Suppress("DEPRECATION")
            override fun onReceivedError(view: WebView, code: Int, description: String?, failingUrl: String?) {
                if (failingUrl != null && isKardexServer(Uri.parse(failingUrl))) {
                    Toast.makeText(context, "No se pudo conectar con tu PC. Tus borradores siguen guardados.", Toast.LENGTH_LONG).show()
                    view.post { view.loadUrl("http://127.0.0.1/mobile/offline?disconnected=1") }
                }
            }
"@
    if(-not $content.Contains($needle)){throw 'No se encontró el manejador de conexión.'}
    $content=$content.Replace($needle,($legacy+"`n"+$needle))
    $content=$content.Replace('                super.onReceivedError(view, request, error)','                // Both current and older WebViews route network failures to the local copy.')
    $content=$content.Replace('                if (request.isForMainFrame && isKardexServer(request.url)) {'+"`n"+'                    view.post', '                if (request.isForMainFrame && isKardexServer(request.url)) {'+"`n"+'                    Toast.makeText(context, "No se pudo conectar con tu PC. Puedes seguir trabajando aquí.", Toast.LENGTH_LONG).show()'+"`n"+'                    view.post')
    [IO.File]::WriteAllText($path,$content,(New-Object System.Text.UTF8Encoding($false)))
}
$activityPath=Join-Path $BuildPath 'nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'
$activity=Get-Content $activityPath -Raw -Encoding UTF8
$activity=$activity.Replace('Box(modifier = Modifier.fillMaxSize().background(Color(0xFFF7F9FA)).windowInsetsPadding(WindowInsets.systemBars)) {','Box(modifier = Modifier.fillMaxSize()) {')
$activity=([regex]::new([regex]::Escape('Box(modifier = Modifier.fillMaxSize()) {'))).Replace($activity,'Box(modifier = Modifier.fillMaxSize().background(Color(0xFFF7F9FA)).windowInsetsPadding(WindowInsets.systemBars)) {',1)
$activity=$activity.Replace('private val statusBarStyle = "auto"','private val statusBarStyle = "dark"')
foreach($side in @('left','top','right','bottom')){
    $activity=$activity.Replace(('val '+$side+'Px = ('+$side+' / density).toInt()'),('val '+$side+'Px = 0 // KARDEX: the native root already reserves system bars.'))
}
$activity=[regex]::Replace($activity,'(?s)private fun SplashScreen\(\) \{.*?(?=    @Composable\r?\n    private fun SplashText)',"private fun SplashScreen() { KardexLaunchScreen() }`n`n")
if($activity -notmatch 'private fun SplashScreen\(\) \{ KardexLaunchScreen\(\) \}'){throw 'No se pudo sustituir la pantalla inicial.'}
[IO.File]::WriteAllText($activityPath,$activity,(New-Object System.Text.UTF8Encoding($false)))
Copy-Item -LiteralPath (Join-Path (Split-Path -Parent $PSScriptRoot) 'resources/native/android/KardexLaunchScreen.kt') -Destination (Join-Path $BuildPath 'nativephp/android/app/src/main/java/com/nativephp/mobile/ui/KardexLaunchScreen.kt') -Force
