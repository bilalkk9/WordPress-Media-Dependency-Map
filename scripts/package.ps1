param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pluginRoot = Join-Path $projectRoot 'media-dependency-map'
$buildRoot = Join-Path $projectRoot 'build'
New-Item -ItemType Directory -Force -Path $buildRoot | Out-Null
$header = Get-Content (Join-Path $pluginRoot 'media-dependency-map.php') -Raw
if ($header -notmatch 'Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') { throw 'Plugin version missing.' }
$destination = Join-Path $buildRoot ('media-dependency-map-' + $Matches[1] + '.zip')
Compress-Archive -LiteralPath $pluginRoot -DestinationPath $destination -Force
Write-Output $destination
