# Manual check on the actual Windows desktop. Starts the manager and opens its UI.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$application = Join-Path $env:LOCALAPPDATA 'GameServerIndy'
$launcher = Join-Path $application 'Start-Manager.ps1'
$shortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) 'GameServer Indy.lnk'
$initial = & $launcher -CheckOnly
if ($initial.Running) { throw 'Fuer den Kaltstart-Test zuerst die laufende Verwaltung beenden.' }
$project = Split-Path -Parent $PSScriptRoot
$settingsPath = Join-Path $project 'manager.local.json'
if (-not (Test-Path -LiteralPath $settingsPath)) { $settingsPath = Join-Path $project 'manager.example.json' }
$settings = Get-Content -LiteralPath $settingsPath -Raw -Encoding UTF8 | ConvertFrom-Json
$profiles = @($settings.servers)
if (-not $settings.servers) { $profiles = @([pscustomobject]@{ id = 'enshrouded'; game = 'enshrouded'; server_directory = $settings.server_directory }) }
$bridge = Join-Path $PSScriptRoot 'windows-server.ps1'
function Get-GameProcesses {
    $states = foreach ($profile in $profiles) {
        $game = Get-Content -LiteralPath (Join-Path $project ('profiles\' + $profile.game + '.json')) -Raw -Encoding UTF8 | ConvertFrom-Json
        $state = & $bridge -Action Status -ServerDirectory $profile.server_directory -ExecutableName $game.executable | ConvertFrom-Json
        $profile.id + ':' + (@($state.processes | ForEach-Object { $_.pid } | Sort-Object) -join ',')
    }
    return ($states -join ';')
}
$gameBefore = Get-GameProcesses
Start-Process -FilePath $shortcut
$deadline = [DateTime]::UtcNow.AddSeconds(75)
do {
    Start-Sleep -Milliseconds 500
    $state = & $launcher -CheckOnly
    if ($state.Running) { break }
} while ([DateTime]::UtcNow -lt $deadline)
if (-not $state.Running) { throw 'Die Desktop-Verknuepfung hat die Verwaltung nicht rechtzeitig gestartet.' }
$reused = & $launcher -NoBrowser -PassThru
if (-not $reused.Running -or $reused.Started) { throw 'Der wiederholte Aufruf hat die laufende Verwaltung nicht wiederverwendet.' }
$gameAfter = Get-GameProcesses
if ($gameBefore -ne $gameAfter) { throw 'Der Spielserverstatus hat sich waehrend der Pruefung geaendert.' }
[pscustomobject]@{
    ColdStartConfirmed = $true
    ShortcutInvoked = $true
    RunningManagerReused = $true
    GameProcessesUnchanged = $true
    Url = $state.Url
} | ConvertTo-Json -Compress
