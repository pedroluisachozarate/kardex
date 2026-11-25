param([int]$Port=8003,[string]$BindAddress='0.0.0.0')
$ErrorActionPreference='Stop'
$kardexRoot=Split-Path -Parent $PSScriptRoot
$kardexBuild=Join-Path $kardexRoot 'native-build'
$kardexPhp=Join-Path $kardexRoot 'tmp/native-tools/php84/php.exe'
if(-not(Test-Path -LiteralPath $kardexPhp) -or -not(Test-Path -LiteralPath (Join-Path $kardexBuild 'vendor/autoload.php'))){throw 'Faltan las dependencias compartidas de la aplicación móvil.'}
$probe=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse($BindAddress),$Port);$probe.Server.ExclusiveAddressUse=$true
try{$probe.Start()}finally{$probe.Stop()}
# Run the same sources as Android, with a separate database and session on the PC.
foreach($folder in @('app','resources','config','routes')){Get-ChildItem -LiteralPath (Join-Path $kardexRoot $folder) -Force | Copy-Item -Destination (Join-Path $kardexBuild $folder) -Recurse -Force}
foreach($folder in @('css','js')){Get-ChildItem -LiteralPath (Join-Path $kardexRoot "public/$folder") -Force | Copy-Item -Destination (Join-Path $kardexBuild "public/$folder") -Recurse -Force}
Copy-Item -Path (Join-Path $kardexRoot 'database/migrations/*.php') -Destination (Join-Path $kardexBuild 'database/migrations') -Force
$desktopData=Join-Path $kardexRoot 'tmp/desktop-mobile'
New-Item -ItemType Directory -Path $desktopData -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $desktopData 'views') -Force | Out-Null
$desktopDatabase=Join-Path $desktopData 'database.sqlite'
if(-not(Test-Path -LiteralPath $desktopDatabase)){New-Item -ItemType File -Path $desktopDatabase | Out-Null}
$desktopStorage=Join-Path $desktopData 'storage'
foreach($folder in @('app/private','app/public','framework/cache/data','framework/sessions','framework/views','framework/php-uploads','logs')){New-Item -ItemType Directory -Path (Join-Path $desktopStorage $folder) -Force | Out-Null}
$env:LARAVEL_STORAGE_PATH=$desktopStorage
$env:KARDEX_DESKTOP_RUNTIME='true'
$env:KARDEX_MOBILE_ENABLED='true'
$env:SESSION_COOKIE='kardex_desktop_session'
$env:DB_DATABASE=$desktopDatabase
$env:VIEW_COMPILED_PATH=Join-Path $desktopData 'views'
Push-Location $kardexBuild
try{
 & $kardexPhp artisan migrate --force
 if($LASTEXITCODE -ne 0){throw 'No se pudo preparar el almacenamiento de la PC.'}
 Push-Location (Join-Path $kardexBuild 'public')
 try{& $kardexPhp -d "upload_tmp_dir=$desktopStorage/framework/php-uploads" -d display_errors=0 -d log_errors=1 -d upload_max_filesize=8M -d post_max_size=96M -S "${BindAddress}:$Port" (Join-Path $kardexBuild 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')}
 finally{Pop-Location}
}finally{Pop-Location}
