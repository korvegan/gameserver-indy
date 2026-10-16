param([Parameter(Mandatory = $true)][string]$RequestPath)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)
$name = [IO.Path]::GetFileName($RequestPath)
if ($name -notmatch '^request-([a-f0-9]{32})\.json$') { throw 'Invalid request name.' }
$nonce = $Matches[1]
$directory = Split-Path -Parent $RequestPath
$stateDirectory = Split-Path -Parent (Split-Path -Parent $directory)
$managedMarker = Join-Path $stateDirectory '.indy-managed-state'
if (Test-Path -LiteralPath $managedMarker -PathType Leaf) {
    if ((Get-Item -LiteralPath $managedMarker).Length -gt 4096) { throw 'Invalid manager data marker.' }
    $state = [IO.File]::ReadAllText($managedMarker) | ConvertFrom-Json
    if ($state -isnot [pscustomobject] -or $state.format -cne 'gameserver-indy-state' -or $state.version -isnot [int] -or $state.version -ne 1 -or
        $state.access_layout -cne 'shared-data') { throw 'Invalid manager data marker.' }
} else {
    $marker = Join-Path $stateDirectory '.indy-symfony-slice'
    if (-not (Test-Path -LiteralPath $marker -PathType Leaf) -or
        [IO.File]::ReadAllText($marker) -cne 'isolated-slice-v1') { throw 'Manager data marker missing.' }
}
$destination = Join-Path $directory ('reply-' + $nonce + '.json')
$result = @{ ok = $false }
try {
    $request = [IO.File]::ReadAllText($RequestPath) | ConvertFrom-Json
    $allowed = @('Action', 'ServerDirectory', 'ExecutableName', 'SteamAppId', 'ConfigArgument', 'ConfigPath', 'LauncherScript', 'DiagnosticDirectory', 'RuntimeEpoch', 'RuntimeFencePath')
    $arguments = @{}
    foreach ($property in $request.PSObject.Properties) {
        if ($allowed -cnotcontains $property.Name) { throw 'Unexpected process argument.' }
        $arguments[$property.Name] = $property.Value
    }
    if ($arguments.Action -ne 'Status' -and (-not $arguments.RuntimeEpoch -or -not $arguments.RuntimeFencePath)) {
        throw 'Lifecycle fence is required for managed actions.'
    }
    $script = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../scripts/windows-server.ps1'))
    $global:LASTEXITCODE = 0
    # Capture before FreeConsole changes native PHP's redirected stdout handle.
    $raw = & $script @arguments
    if ($LASTEXITCODE -eq 0) {
        $parsed = ($raw -join [Environment]::NewLine) | ConvertFrom-Json
        if ($parsed.PSObject.Properties.Name -contains 'error') { throw 'Native operation did not complete.' }
        $result = @{ ok = $true; result = $parsed }
    }
} catch { $result = @{ ok = $false } }
[IO.File]::WriteAllText($destination, ($result | ConvertTo-Json -Compress -Depth 8), (New-Object Text.UTF8Encoding($false)))
if (-not $result.ok) { exit 1 }
