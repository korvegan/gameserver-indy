$ErrorActionPreference = 'Stop'
$source = Join-Path $PSScriptRoot '..\windows\Start-Manager.ps1'
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('indy-next-launcher-' + [guid]::NewGuid().ToString('N'))
$previous = $env:LOCALAPPDATA
$env:LOCALAPPDATA = $temporary
New-Item -ItemType Directory -Path (Join-Path $temporary 'GameServerIndy') | Out-Null
$configuration = Join-Path $temporary 'GameServerIndy\launcher.local.json'
$settings = @{RuntimeEngine='php-foundation';CodeCommit=('a' * 40);ProjectDirectory='/tmp/code';
    RuntimeExecutable='/tmp/php';FrankenPhpExecutable='/tmp/frankenphp';WslDistribution='Indy';WslUser='fixture';Port=18765}
$settings | ConvertTo-Json | Set-Content -LiteralPath $configuration -Encoding UTF8
$global:IndyLauncherFixture = @{Available=$false;Starts=0;Arguments=''}
function Invoke-RestMethod {
    [CmdletBinding()]param($Uri,$TimeoutSec)
    if (-not $global:IndyLauncherFixture.Available) { throw 'fixture connection refused' }
    return @{mode='fixture';native_enabled=$false;build=('a' * 40)}
}
function Start-Process {
    [CmdletBinding()]param($FilePath,$ArgumentList,$WindowStyle)
    if ($FilePath -notlike '*\wsl.exe') { throw 'Unexpected process' }
    $global:IndyLauncherFixture.Starts += 1
    $global:IndyLauncherFixture.Arguments = $ArgumentList
    $global:IndyLauncherFixture.Available = $true
}
try {
    $result = & $source -CheckOnly -NoBrowser -PassThru | ConvertFrom-Json
    if ($result.Running -or $global:IndyLauncherFixture.Starts -ne 0) { throw 'CheckOnly started a process' }
    $result = & $source -NoBrowser -PassThru | ConvertFrom-Json
    if (-not $result.Running -or -not $result.Started -or $global:IndyLauncherFixture.Starts -ne 1) { throw 'Start failed' }
    if ($global:IndyLauncherFixture.Arguments -notlike '*--distribution "Indy"*--exec /usr/bin/env "SLICE_FRANKENPHP=/tmp/frankenphp" "/tmp/php" "app/bin/dev"*' -or $global:IndyLauncherFixture.Arguments -like '*symfony*') { throw 'Wrong entrypoint' }
    $result = & $source -NoBrowser -PassThru | ConvertFrom-Json
    if ($result.Started -or $global:IndyLauncherFixture.Starts -ne 1) { throw 'Existing app was not reused' }
    $settings.RuntimeEngine = 'symfony'
    $settings | ConvertTo-Json | Set-Content -LiteralPath $configuration -Encoding UTF8
    $rejected = $false
    try { & $source -CheckOnly -NoBrowser | Out-Null } catch { $rejected = $true }
    if (-not $rejected) { throw 'Legacy configuration accepted' }
    Write-Output 'PASS: isolated Windows starter, check-only, start, reuse and legacy rejection; no real WSL/process/network action'
} finally {
    $env:LOCALAPPDATA = $previous
    Remove-Variable -Name IndyLauncherFixture -Scope Global
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
