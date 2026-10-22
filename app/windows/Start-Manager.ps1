param([switch]$NoBrowser, [switch]$CheckOnly, [switch]$PassThru)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$configuration = Join-Path $env:LOCALAPPDATA 'GameServerIndy\launcher.local.json'
$settings = Get-Content -LiteralPath $configuration -Raw -Encoding UTF8 | ConvertFrom-Json
if ($settings.RuntimeEngine -ne 'php-foundation' -or $settings.CodeCommit -notmatch '^[a-f0-9]{40}$' -or
    $settings.WslDistribution -notmatch '^[A-Za-z0-9_.-]+$' -or $settings.WslUser -notmatch '^[A-Za-z0-9_.-]+$' -or
    $settings.ProjectDirectory -notmatch '^/[^\s"\x00]+$' -or $settings.RuntimeExecutable -notmatch '^/[^\s"\x00]+$' -or
    $settings.FrankenPhpExecutable -notmatch '^/[^\s"\x00]+$' -or
    [int]$settings.Port -lt 1024 -or [int]$settings.Port -gt 65535) {
    throw 'Keine gueltige neue PHP-Startkonfiguration.'
}
$url = 'http://127.0.0.1:' + [int]$settings.Port
function Read-Foundation {
    try { $health = Invoke-RestMethod -Uri ($url + '/health') -TimeoutSec 1 -ErrorAction Stop }
    catch {
        if ($_.Exception.Response) { throw 'Port antwortet mit einem anderen oder fehlerhaften Dienst.' }
        return $null
    }
    if ($health.mode -ne 'fixture' -or $health.native_enabled -ne $false -or $health.build -ne $settings.CodeCommit) {
        throw 'Port gehoert nicht zum konfigurierten PHP-Grundgeruest.'
    }
    return $health
}
$health = Read-Foundation
$started = $false
if (-not $health -and -not $CheckOnly) {
    # WSL preserves unnecessary quotes in option values; local paths contain no whitespace.
    $arguments = @('--distribution', $settings.WslDistribution, '--user', $settings.WslUser,
        '--cd', $settings.ProjectDirectory, '--exec', '/usr/bin/env',
        ('SLICE_FRANKENPHP=' + $settings.FrankenPhpExecutable), ('SLICE_PHP=' + $settings.RuntimeExecutable), $settings.RuntimeExecutable,
        'app/bin/dev', [int]$settings.Port) -join ' '
    Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\wsl.exe') -ArgumentList $arguments -WindowStyle Hidden | Out-Null
    $started = $true
    $deadline = (Get-Date).AddSeconds(20)
    do {
        Start-Sleep -Milliseconds 200
        $health = Read-Foundation
    } while (-not $health -and (Get-Date) -lt $deadline)
    if (-not $health) { throw 'PHP-Grundgeruest wurde nicht bereit; lokalen Laufzustand pruefen.' }
}
if ($health -and -not $NoBrowser -and -not $CheckOnly) { Start-Process $url }
if ($PassThru -or $CheckOnly) {
    [pscustomobject]@{Running=[bool]$health;Started=$started;Url=$url;Mode='fixture';CodeCommit=$settings.CodeCommit} | ConvertTo-Json -Compress
}
