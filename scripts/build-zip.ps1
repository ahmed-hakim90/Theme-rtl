# WooKapso - Build woo-kapso-VERSION.zip for WordPress (Plugins - Add New - Upload).
# Run from plugin root:  powershell -ExecutionPolicy Bypass -File .\scripts\build-zip.ps1

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$folderName = Split-Path -Leaf $pluginRoot

$mainFile = Join-Path $pluginRoot 'woo-kapso.php'
if (-not (Test-Path $mainFile)) {
    Write-Error "woo-kapso.php not found. Run this script from the plugin folder."
}

$content = Get-Content -Raw -Path $mainFile
# Single-quoted regex: '' inside = one apostrophe in the pattern (PS parser-safe).
if ($content -match 'define\s*\(\s*''WOOKAPSO_VERSION''\s*,\s*''(.+?)''\s*\)\s*;') {
    $version = $Matches[1].Trim()
} elseif ($content -match 'Version:\s*(\S+)') {
    $version = $Matches[1].Trim()
} else {
    Write-Error "Could not read version from woo-kapso.php"
}
if ([string]::IsNullOrWhiteSpace($version)) {
    Write-Error "Version is empty."
}

$distDir = Join-Path $pluginRoot 'dist'
if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}

$zipName = "{0}-{1}.zip" -f $folderName, $version
$zipPath = Join-Path $distDir $zipName

# Dev / local-only — لا تُضمَّن في حزمة الإنتاج
$exclude = @('dist', '.git', '.cursor', '.vscode', 'node_modules', 'agent-tools')
$stageRoot = Join-Path $env:TEMP ('wookapso-zip-' + [guid]::NewGuid().ToString('N'))
$stagePlugin = Join-Path $stageRoot $folderName
New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null

try {
    Get-ChildItem -LiteralPath $pluginRoot -Force | Where-Object {
        $exclude -notcontains $_.Name
    } | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stagePlugin $_.Name) -Recurse -Force
    }

    if (Test-Path $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Compress-Archive -Path $stagePlugin -DestinationPath $zipPath -CompressionLevel Optimal
}
finally {
    Remove-Item -LiteralPath $stageRoot -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ""
Write-Host "Created: $zipPath" -ForegroundColor Green
Write-Host "Upload: WP Admin - Plugins - Add New - Upload Plugin" -ForegroundColor Cyan
Write-Host ""
