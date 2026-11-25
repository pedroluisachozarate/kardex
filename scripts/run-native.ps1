param([string]$Device)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'native-env.ps1')
$kardexBuild=Join-Path $kardexRoot 'native-build'
if(-not (Test-Path -LiteralPath (Join-Path $kardexBuild 'nativephp.lock'))){throw 'Prepara primero native-build con prepare-native.ps1.'}
& (Join-Path $PSScriptRoot 'configure-native.ps1') -BuildPath $kardexBuild
Push-Location $kardexBuild
try {
    if($Device){& $kardexPhp artisan native:run android $Device --build=debug --no-tty}
    else {& $kardexPhp artisan native:run android --build=debug --no-tty}
    if($LASTEXITCODE -ne 0){throw 'La compilación o instalación falló. Revisa native-build/nativephp/android-build.log.'}
} finally {Pop-Location}