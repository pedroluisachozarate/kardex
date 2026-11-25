param(
    [Parameter(Mandatory=$true)][string]$Php,
    [Parameter(Mandatory=$true)][string]$ServerUrl,
    [string]$ComposerPhar='C:\ProgramData\ComposerSetup\bin\composer.phar',
    [switch]$LocalNetwork
)
$ErrorActionPreference='Stop'
$version=& $Php -r 'echo PHP_VERSION_ID;'
if($LASTEXITCODE -ne 0 -or [int]$version -lt 80400){throw 'NativePHP 4 requiere PHP 8.4+. El PHP 8.2 de XAMPP puede seguir sirviendo la web.'}
$uri=[Uri]$ServerUrl
$localHttp=$false
if($LocalNetwork -and $uri.Scheme -eq 'http'){
    $address=$null
    if([Net.IPAddress]::TryParse($uri.Host,[ref]$address) -and $address.AddressFamily -eq [Net.Sockets.AddressFamily]::InterNetwork){
        $bytes=$address.GetAddressBytes()
        $localHttp=($bytes[0] -eq 10 -or ($bytes[0] -eq 172 -and $bytes[1] -ge 16 -and $bytes[1] -le 31) -or ($bytes[0] -eq 192 -and $bytes[1] -eq 168))
    }
}
if(($uri.Scheme -ne 'https' -and -not $localHttp) -or -not $uri.Host -or $uri.UserInfo){throw 'Indica HTTPS, o una IP privada con -LocalNetwork para pruebas autorizadas por Wi-Fi.'}
if(-not (Test-Path -LiteralPath $ComposerPhar)){throw 'No se encuentra composer.phar.'}
$root=Split-Path -Parent $PSScriptRoot
$destination=Join-Path $root 'native-build'
if(Test-Path -LiteralPath $destination){throw 'native-build ya existe. Se conserva para proteger sus datos y configuración.'}
New-Item -ItemType Directory -Path $destination | Out-Null
foreach($folder in @('app','bootstrap','config','resources','routes')){
    Copy-Item -LiteralPath (Join-Path $root $folder) -Destination $destination -Recurse
}
New-Item -ItemType Directory -Path (Join-Path $destination 'public') -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $root 'public') -Force | Where-Object {$_.Name -ne 'storage' -and -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint)} | Copy-Item -Destination (Join-Path $destination 'public') -Recurse
foreach($file in @('artisan','composer.json','composer.lock')){Copy-Item -LiteralPath (Join-Path $root $file) -Destination $destination}
New-Item -ItemType Directory -Path (Join-Path $destination 'database/migrations') -Force | Out-Null
Copy-Item -Path (Join-Path $root 'database/migrations/*.php') -Destination (Join-Path $destination 'database/migrations')
foreach($folder in @('storage/app/private','storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/framework/testing','storage/logs')){New-Item -ItemType Directory -Path (Join-Path $destination $folder) -Force | Out-Null}
# Only source code was copied: no server .env, photos, SQLite database or backups.
Get-ChildItem -LiteralPath (Join-Path $destination 'bootstrap/cache') -File | Where-Object {$_.Extension -eq '.php'} | Remove-Item
$environment=@"
APP_NAME=Kardex
APP_ENV=local
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost
APP_LOCALE=es
DB_CONNECTION=sqlite
SESSION_DRIVER=file
SESSION_LIFETIME=43200
SESSION_ENCRYPT=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
KARDEX_MOBILE_ENABLED=true
KARDEX_SERVER_URL=$ServerUrl
KARDEX_MOBILE_LOCAL_HTTP=$($localHttp.ToString().ToLowerInvariant())
NATIVEPHP_APP_ID=bo.kardex.mobile
NATIVEPHP_APP_VERSION=DEBUG
NATIVEPHP_START_URL=/mobile
"@
[IO.File]::WriteAllText((Join-Path $destination '.env'),$environment,(New-Object System.Text.UTF8Encoding($false)))
New-Item -ItemType File -Path (Join-Path $destination 'database/database.sqlite') | Out-Null
Push-Location $destination
try {
    & $Php $ComposerPhar require 'nativephp/mobile:^4.0' 'nativephp/mobile-ui' --no-interaction
    if($LASTEXITCODE -ne 0){throw 'No se pudieron instalar las dependencias NativePHP.'}
    & $Php artisan key:generate
    if($LASTEXITCODE -ne 0){throw 'No se pudo generar la clave local.'}
    & $Php artisan migrate --force
    if($LASTEXITCODE -ne 0){throw 'No se pudo crear la base local.'}
    & $Php artisan vendor:publish --tag=nativephp-plugins-provider
    if($LASTEXITCODE -ne 0){throw 'No se pudo publicar el proveedor de plugins.'}
    & $Php artisan native:plugin:register nativephp/mobile-ui
    if($LASTEXITCODE -ne 0){throw 'No se pudo registrar la interfaz nativa.'}
    & $Php artisan native:install android --no-interaction
    if($LASTEXITCODE -ne 0){throw 'No se completó la instalación NativePHP. Revisa SDK, Java y 7zip.'}
    & (Join-Path $PSScriptRoot 'configure-native.ps1') -BuildPath $destination
} finally {Pop-Location}
Write-Output "Preparado en $destination. Antes de compilar: comprobar plugins, cámara y guardar/sincronizar en dispositivo real."
