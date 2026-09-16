param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pluginRoot = Join-Path $projectRoot 'media-dependency-map'
$buildRoot = Join-Path $projectRoot 'build'
New-Item -ItemType Directory -Force -Path $buildRoot | Out-Null
$destination = Join-Path $buildRoot 'media-dependency-map-0.1.0.zip'
Compress-Archive -LiteralPath $pluginRoot -DestinationPath $destination -Force
Write-Output $destination
