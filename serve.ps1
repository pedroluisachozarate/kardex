param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000,
    [string]$BindAddress = '127.0.0.1'
)

$ErrorActionPreference = 'Stop'
$portProbe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Parse($BindAddress), $Port)
$portProbe.Server.ExclusiveAddressUse = $true
try {
    $portProbe.Start()
} catch {
    throw "El puerto $Port ya está ocupado. Cierra el servidor anterior antes de iniciar otra instancia."
} finally {
    $portProbe.Stop()
}
$projectRoot = $PSScriptRoot
$phpCommand = if (Test-Path -LiteralPath 'C:\xampp\php\php.exe') { 'C:\xampp\php\php.exe' } else { (Get-Command php).Source }
$uploadDirectory = Join-Path $projectRoot 'storage\framework\php-uploads'
New-Item -ItemType Directory -Path $uploadDirectory -Force | Out-Null
$router = Join-Path $projectRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'

Push-Location (Join-Path $projectRoot 'public')
try {
    & $phpCommand -d "upload_tmp_dir=$uploadDirectory" -d zlib.output_compression=1 -d display_errors=0 -d log_errors=1 -d upload_max_filesize=8M -d post_max_size=96M -S "${BindAddress}:$Port" $router
} finally {
    Pop-Location
}
