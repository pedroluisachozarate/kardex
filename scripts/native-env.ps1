# Dot-source this file to use the isolated Android tools in the current terminal.
$kardexRoot=Split-Path -Parent $PSScriptRoot
$kardexTools=Join-Path $kardexRoot 'tmp/native-tools'
$kardexPhp=Join-Path $kardexTools 'php84/php.exe'
$kardexJdk=Get-ChildItem (Join-Path $kardexTools 'jdk17') -Directory -ErrorAction SilentlyContinue | Select-Object -First 1
if(-not (Test-Path -LiteralPath $kardexPhp) -or -not $kardexJdk){throw 'Faltan las herramientas portables PHP 8.4 y JDK 17 en tmp/native-tools.'}
$env:JAVA_HOME=$kardexJdk.FullName
$env:ANDROID_HOME=Join-Path $env:LOCALAPPDATA 'Android/Sdk'
$env:Path="$(Split-Path -Parent $kardexPhp);$env:JAVA_HOME\bin;$env:ANDROID_HOME\platform-tools;C:\Program Files\7-Zip;$env:Path"