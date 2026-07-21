param(
    [string]$OutputDirectory = ""
)

$ErrorActionPreference = 'Stop'
$pluginRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$versionMatch = Select-String -LiteralPath (Join-Path $pluginRoot 'ashko-wp.php') -Pattern '^ \* Version: ([0-9.]+)$'
if (-not $versionMatch) { throw 'Plugin version header was not found.' }
$version = $versionMatch.Matches[0].Groups[1].Value
$buildRoot = [IO.Path]::GetFullPath((Join-Path $pluginRoot 'build'))
$stagePlugin = Join-Path $buildRoot 'ashko-wp'
if (-not $buildRoot.StartsWith($pluginRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe build path.' }
if (Test-Path -LiteralPath $buildRoot) { Remove-Item -LiteralPath $buildRoot -Recurse -Force }
New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null

foreach ($file in @('ashko-wp.php', 'README.md', 'CHANGELOG.md', 'LICENSE')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $file) -Destination $stagePlugin
}
foreach ($directory in @('includes', 'languages', 'docs', 'assets')) {
    Copy-Item -LiteralPath (Join-Path $pluginRoot $directory) -Destination $stagePlugin -Recurse
}

& php (Join-Path $pluginRoot 'scripts/check-public-tree.php') $stagePlugin
if ($LASTEXITCODE -ne 0) { throw 'Public tree check failed.' }

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) { $OutputDirectory = Join-Path $pluginRoot 'dist' }
$OutputDirectory = [IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$zipPath = Join-Path $OutputDirectory ("ashko-wp-{0}.zip" -f $version)
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
Compress-Archive -LiteralPath $stagePlugin -DestinationPath $zipPath -CompressionLevel Optimal
$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash
Remove-Item -LiteralPath $buildRoot -Recurse -Force
Write-Output ("ZIP={0}" -f $zipPath)
Write-Output ("SHA256={0}" -f $hash)
