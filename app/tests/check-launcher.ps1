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
    return @{mode=$global:IndyLauncherFixture.Mode ?? 'fixture';native_enabled=[bool]$global:IndyLauncherFixture.Production;build=('a' * 40);instance=$global:IndyLauncherFixture.Instance}
}
function Start-Process {
    [CmdletBinding()]param($FilePath,$ArgumentList,$WindowStyle)
    if ($FilePath -notlike '*\wsl.exe') { throw 'Unexpected process' }
    $global:IndyLauncherFixture.Starts += 1
    $global:IndyLauncherFixture.Arguments = $ArgumentList
    $global:IndyLauncherFixture.Available = $true
}
function Get-CimInstance { return @{LastBootUpTime=[datetime]'2026-10-22T08:00:00Z'} }
function Get-Process { return @{SessionId=0} }
try {
    $result = & $source -CheckOnly -NoBrowser -PassThru | ConvertFrom-Json
    if ($result.Running -or $global:IndyLauncherFixture.Starts -ne 0) { throw 'CheckOnly started a process' }
    $result = & $source -NoBrowser -PassThru | ConvertFrom-Json
    if (-not $result.Running -or -not $result.Started -or $global:IndyLauncherFixture.Starts -ne 1) { throw 'Start failed' }
    if ($global:IndyLauncherFixture.Arguments -ne '--distribution Indy --user fixture --cd /tmp/code --exec /usr/bin/env SLICE_FRANKENPHP=/tmp/frankenphp SLICE_PHP=/tmp/php /tmp/php app/bin/dev 18765') { throw 'Wrong entrypoint or literal WSL quotes' }
    $result = & $source -NoBrowser -PassThru | ConvertFrom-Json
    if ($result.Started -or $global:IndyLauncherFixture.Starts -ne 1) { throw 'Existing app was not reused' }
    $settings.ProjectDirectory = '/tmp/unsupported path'
    $settings | ConvertTo-Json | Set-Content -LiteralPath $configuration -Encoding UTF8
    $rejected = $false
    try { & $source -CheckOnly -NoBrowser | Out-Null } catch { $rejected = $true }
    if (-not $rejected) { throw 'Ambiguous whitespace path accepted' }
    $settings.ProjectDirectory = '/tmp/code'
    $settings.RuntimeEngine = 'symfony'
    $settings | ConvertTo-Json | Set-Content -LiteralPath $configuration -Encoding UTF8
    $rejected = $false
    try { & $source -CheckOnly -NoBrowser | Out-Null } catch { $rejected = $true }
    if (-not $rejected) { throw 'Legacy configuration accepted' }
    $settings.RuntimeEngine = 'php-mvp'
    $settings.AppStateDirectory = '/private/mvp-state-' + ('b' * 24)
    $settings.Instance = 'mvp-state-' + ('b' * 24)
    $settings | ConvertTo-Json | Set-Content -LiteralPath $configuration -Encoding UTF8
    $global:IndyLauncherFixture.Mode='production';$global:IndyLauncherFixture.Production=$true
    $global:IndyLauncherFixture.Instance=$settings.Instance;$global:IndyLauncherFixture.Available=$false
    $result = & $source -NoBrowser -PassThru | ConvertFrom-Json
    if (-not $result.Running -or $result.Mode -ne 'production' -or $global:IndyLauncherFixture.Starts -ne 2 -or
        $global:IndyLauncherFixture.Arguments -notlike '*app/bin/run /private/mvp-state-*') { throw 'Persistent startup failed' }
    $proof=Get-Content -LiteralPath (Join-Path $temporary 'GameServerIndy\startup-proof.json') -Raw | ConvertFrom-Json
    if ($proof.WindowsSessionId -ne 0 -or $proof.Instance -cne $settings.Instance) { throw 'Missing startup context proof' }
    $global:IndyLauncherFixture.Instance='mvp-state-' + ('c' * 24)
    $rejected=$false
    try { & $source -CheckOnly -NoBrowser | Out-Null } catch { $rejected=$true }
    if (-not $rejected -or $global:IndyLauncherFixture.Starts -ne 2) { throw 'Wrong state instance accepted' }
    Write-Output 'PASS: isolated Windows starter, check-only, start, reuse and legacy rejection; no real WSL/process/network action'
} finally {
    $env:LOCALAPPDATA = $previous
    Remove-Variable -Name IndyLauncherFixture -Scope Global
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
