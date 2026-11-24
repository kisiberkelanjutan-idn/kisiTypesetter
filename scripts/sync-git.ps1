<#
.SYNOPSIS
    Validates & packages the plugin, clears Google Drive desktop.ini inside .git/,
    commits changes cleanly, and pushes to GitHub (origin/main).
#>
param(
    [string]$Message = '',
    [switch]$SkipBuild,
    [string]$Tag = ''
)

$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Split-Path -Parent $scriptDir

Push-Location $repoRoot
try {
    if (-not $SkipBuild) {
        & (Join-Path $scriptDir 'build-release.ps1')
        if ($LASTEXITCODE -ne 0) { throw "build-release.ps1 failed with exit code $LASTEXITCODE" }
    }

    # Google Drive Desktop periodically writes desktop.ini inside .git/refs and .git/objects
    if (Test-Path '.git') {
        Get-ChildItem -LiteralPath '.git' -Recurse -Force -Filter 'desktop.ini' -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    & git config core.hooksPath .githooks
    & git config core.autocrlf false

    $dirty = & git status --porcelain
    if ($LASTEXITCODE -ne 0) { throw "git status failed" }

    if ($dirty) {
        if ([string]::IsNullOrWhiteSpace($Message)) {
            $Message = 'chore(kisiTypesetter): sync updates'
        }
        & git add -A
        if ($LASTEXITCODE -ne 0) { throw "git add failed" }
        & git commit -m $Message
        if ($LASTEXITCODE -ne 0) { throw "git commit failed" }
    }

    if (Test-Path '.git') {
        Get-ChildItem -LiteralPath '.git' -Recurse -Force -Filter 'desktop.ini' -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    & git push -u origin main
    if ($LASTEXITCODE -ne 0) { throw "git push origin main failed" }

    if (-not [string]::IsNullOrWhiteSpace($Tag)) {
        & git tag -a $Tag -m "Release $Tag"
        if ($LASTEXITCODE -ne 0) { throw "git tag failed" }
        & git push origin $Tag
        if ($LASTEXITCODE -ne 0) { throw "git push tag failed" }
    }

    Write-Host "GitHub sync completed cleanly." -ForegroundColor Green
} finally {
    Pop-Location
}
