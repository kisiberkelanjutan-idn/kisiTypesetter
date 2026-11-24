<#
.SYNOPSIS
    Verifies code integrity, syncs clean runtime files into dist/kisiTypesetter,
    and builds a clean kisiTypesetter.tar.gz archive (without desktop.ini or .git).
#>
$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent $scriptDir

Write-Host "1. Running integrity checks (BOM, forbidden strings, delimiters, JSON/XML)..." -ForegroundColor Cyan

$codeFiles = Get-ChildItem -LiteralPath $repoRoot -Recurse -File | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and
    $_.FullName -notmatch '\\dist\\' -and
    $_.Extension -in '.php', '.yml', '.yaml', '.xml', '.po', '.json'
}

foreach ($f in $codeFiles) {
    $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        throw "UTF-8 BOM detected in $($f.FullName)"
    }
}

$cpanelPath = Join-Path $repoRoot '.cpanel.yml'
if (Test-Path $cpanelPath) {
    $cBytes = [System.IO.File]::ReadAllBytes($cpanelPath)
    if ($cBytes -contains 13) {
        throw ".cpanel.yml must use LF line endings (CR byte found)."
    }
}

$secretHits = Get-ChildItem -LiteralPath $repoRoot -Recurse -File | Where-Object {
    $_.FullName -notmatch '\\\.git\\' -and
    $_.FullName -notmatch '\\dist\\' -and
    $_.Name -notin 'pre-commit', 'build-release.ps1', 'ci-release.yml' -and
    $_.Extension -in '.php', '.yml', '.yaml', '.md', '.txt', '.json', '.xml', '.po'
} | Select-String -Pattern '/home/[a-z0-9_]+/public_html|USERNAME_CPANEL|AIzaSy[0-9A-Za-z_-]{20,}'
if ($secretHits) {
    $secretHits | ForEach-Object { Write-Error "$($_.Path):$($_.LineNumber): $($_.Line)" }
    throw "Forbidden hardcoded credential or cPanel username detected."
}

# Validate JSON lexicons & version.xml
Get-ChildItem -LiteralPath (Join-Path $repoRoot 'resources\lexicon') -Filter '*.json' | ForEach-Object {
    $null = (Get-Content -Raw -LiteralPath $_.FullName) | ConvertFrom-Json
}
[xml]$null = Get-Content -Raw -LiteralPath (Join-Path $repoRoot 'version.xml')

# Check PHP syntax via php -l if available, plus accurate PHP/JS delimiter balance
function Test-PhpDelimiterBalance([string]$FilePath) {
    $raw = [System.IO.File]::ReadAllText($FilePath, [System.Text.Encoding]::UTF8)
    $chunks = New-Object System.Collections.Generic.List[string]
    if ($FilePath -match '\\templates\\') {
        $phpMatches = [regex]::Matches($raw, '<\?(?:php|=)(.*?)\?>', [System.Text.RegularExpressions.RegexOptions]::Singleline)
        foreach ($m in $phpMatches) { $chunks.Add($m.Groups[1].Value) }
    } else {
        $chunks.Add([regex]::Replace($raw, "(?ms)<<<'?([A-Z_][A-Z0-9_]*)'?\r?\n.*?\r?\n\s*\1;", "''"))
    }

    $bO = 0; $bC = 0; $pO = 0; $pC = 0; $kO = 0; $kC = 0
    foreach ($text in $chunks) {
        $len = $text.Length; $i = 0; $state = 'code'
        while ($i -lt $len) {
            $c = $text[$i]
            if ($state -eq 'linec') { if ($c -eq "`n") { $state = 'code' }; $i++; continue }
            if ($state -eq 'blockc') { if ($c -eq '*' -and ($i + 1) -lt $len -and $text[$i + 1] -eq '/') { $state = 'code'; $i += 2; continue }; $i++; continue }
            if ($state -eq 'sq') { if ($c -eq '\' -and ($i + 1) -lt $len) { $i += 2; continue }; if ($c -eq "'") { $state = 'code' }; $i++; continue }
            if ($state -eq 'dq') { if ($c -eq '\' -and ($i + 1) -lt $len) { $i += 2; continue }; if ($c -eq '"') { $state = 'code' }; $i++; continue }
            if ($c -eq '/' -and ($i + 1) -lt $len -and $text[$i + 1] -eq '/') { $state = 'linec'; $i += 2; continue }
            if ($c -eq '#') { $state = 'linec'; $i++; continue }
            if ($c -eq '/' -and ($i + 1) -lt $len -and $text[$i + 1] -eq '*') { $state = 'blockc'; $i += 2; continue }
            if ($c -eq "'") { $state = 'sq'; $i++; continue }
            if ($c -eq '"') { $state = 'dq'; $i++; continue }
            if ($c -eq '{') { $bO++ } elseif ($c -eq '}') { $bC++ }
            elseif ($c -eq '(') { $pO++ } elseif ($c -eq ')') { $pC++ }
            elseif ($c -eq '[') { $kO++ } elseif ($c -eq ']') { $kC++ }
            $i++
        }
        if ($state -in @('sq', 'dq', 'blockc')) {
            throw "Unclosed string or comment ($state) in $FilePath"
        }
    }
    if ($bO -ne $bC -or $pO -ne $pC -or $kO -ne $kC) {
        throw "PHP delimiter mismatch in ${FilePath}: {} $bO/$bC | () $pO/$pC | [] $kO/$kC"
    }
}

$phpCmd = Get-Command php -ErrorAction SilentlyContinue
Get-ChildItem -LiteralPath $repoRoot -Recurse -Filter '*.php' | Where-Object { $_.FullName -notmatch '\\dist\\' } | ForEach-Object {
    Test-PhpDelimiterBalance $_.FullName
    if ($phpCmd) {
        & php -l $_.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "php -l failed on $($_.FullName)" }
    }
}

Write-Host "   -> Integrity checks passed!" -ForegroundColor Green

Write-Host "2. Syncing clean runtime plugin files to dist/kisiTypesetter & staging archive..." -ForegroundColor Cyan
$distPluginDir = Join-Path $repoRoot 'dist\kisiTypesetter'
if (Test-Path $distPluginDir) { Remove-Item -Recurse -Force $distPluginDir }
New-Item -ItemType Directory -Force -Path $distPluginDir | Out-Null

$tmpStageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('kisi_pkg_' + [Guid]::NewGuid().ToString('N'))
$tmpPluginDir = Join-Path $tmpStageRoot 'kisiTypesetter'
New-Item -ItemType Directory -Force -Path $tmpPluginDir | Out-Null

$runtimeFiles = @('index.php', 'KisiTypesetterPlugin.php', 'version.xml', 'README.md')
$runtimeDirs = @('classes', 'locale', 'resources', 'templates')

foreach ($rf in $runtimeFiles) {
    $src = Join-Path $repoRoot $rf
    if (Test-Path $src) {
        Copy-Item -LiteralPath $src -Destination (Join-Path $distPluginDir $rf) -Force
        Copy-Item -LiteralPath $src -Destination (Join-Path $tmpPluginDir $rf) -Force
    }
}
foreach ($rd in $runtimeDirs) {
    $src = Join-Path $repoRoot $rd
    if (Test-Path $src) {
        Copy-Item -LiteralPath $src -Destination (Join-Path $distPluginDir $rd) -Recurse -Force
        Copy-Item -LiteralPath $src -Destination (Join-Path $tmpPluginDir $rd) -Recurse -Force
    }
}

# Remove any Google Drive desktop.ini from both dist and stage
Get-ChildItem -LiteralPath $distPluginDir -Recurse -Force -Filter 'desktop.ini' | Remove-Item -Force -ErrorAction SilentlyContinue
Get-ChildItem -LiteralPath $tmpPluginDir -Recurse -Force -Filter 'desktop.ini' | Remove-Item -Force -ErrorAction SilentlyContinue

$tarPath = Join-Path $repoRoot 'kisiTypesetter.tar.gz'
if (Test-Path $tarPath) { Remove-Item -Force $tarPath }

Push-Location $tmpStageRoot
try {
    & tar -czf $tarPath kisiTypesetter
    if ($LASTEXITCODE -ne 0) { throw "tar failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
    if (Test-Path $tmpStageRoot) { Remove-Item -Recurse -Force $tmpStageRoot -ErrorAction SilentlyContinue }
}

$tarContents = & tar -tzf $tarPath
if ($tarContents -match 'desktop\.ini|\.git') {
    throw "Archive contains forbidden desktop.ini or .git entries!"
}

Write-Host "   -> dist/kisiTypesetter synced and clean archive built: $tarPath ($((Get-Item $tarPath).Length) bytes)" -ForegroundColor Green
