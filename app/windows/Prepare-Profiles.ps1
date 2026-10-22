param([Parameter(Mandatory)][string]$ManagerConfiguration, [Parameter(Mandatory)][string]$Destination)
$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSVersion -lt [version]'7.6' -or -not $IsWindows) { throw 'PowerShell 7.6+ unter Windows erforderlich.' }
if (Test-Path -LiteralPath $Destination) { throw 'Neues privates Zielverzeichnis erforderlich.' }
$manager = Get-Content -LiteralPath $ManagerConfiguration -Raw | ConvertFrom-Json -AsHashtable -DateKind String
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$profiles = @{}
$connections = @{}
$sources = @{}
. (Join-Path $PSScriptRoot 'GameProfile.ps1')
function Read-Json([string]$Path, [int]$Limit) {
    if ((Get-Item -LiteralPath $Path).Length -gt $Limit) { throw 'Spielkonfiguration zu gross.' }
    return [IO.File]::ReadAllText($Path) | ConvertFrom-Json -AsHashtable -DateKind String
}
foreach ($id in @('enshrouded','aska')) {
    $entries = @($manager.servers | Where-Object { $_.id -ceq $id })
    if ($entries.Count -ne 1) { throw 'Eindeutige vorhandene Spielbindung erforderlich.' }
    $entry = $entries[0]
    $working = [IO.Path]::GetFullPath($entry.server_directory)
    $executable = Join-Path $working $(if ($id -eq 'aska') {'AskaServer.exe'} else {'enshrouded_server.exe'})
    if (-not [IO.File]::Exists($executable)) { throw 'Vorhandene Spielinstallation fehlt.' }
    if ($id -eq 'enshrouded') {
        $configuration = Join-Path $working 'enshrouded_server.json'
        $game = Read-Json $configuration 1048576
        $world = [IO.Path]::GetFullPath($game.saveDirectory, $working)
        $hostName = $entry.connection.public_host
        $connections[$id] = if ($hostName -and $game.ip -in @('0.0.0.0','::') -and $game.queryPort -ge 1 -and $game.queryPort -le 65535) { $hostName + ':' + $game.queryPort } else { $null }
        $profiles[$id] = @{executable=$executable;configuration=$configuration;world_directory=$world;stop_timeout_ms=45000}
    } else {
        $configuration = [IO.Path]::GetFullPath($entry.config_path)
        $lines = [IO.File]::ReadAllLines($configuration)
        $save = @($lines | Where-Object { $_ -cmatch '^\s*save id\s*=' })
        $names = @($lines | Where-Object { $_ -cmatch '^\s*server name\s*=' })
        if ($save.Count -ne 1 -or $names.Count -ne 1) { throw 'Eindeutige ASKA-Bindung erforderlich.' }
        $saveId = ($save[0] -split '=',2)[1].Trim()
        $world = Join-Path (Get-WindowsUserProfile) ('AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_' + $saveId)
        $profiles[$id] = @{executable=$executable;configuration=$configuration;world_directory=$world;save_id=$saveId;stop_timeout_ms=45000}
        $connections[$id] = ($names[0] -split '=',2)[1].Trim()
    }
    Assert-World $id $profiles[$id] $working
    if (-not @(Get-ChildItem -LiteralPath $world -File -Recurse | Where-Object Length -gt 0).Count) { throw 'Vorhandene Welt enthaelt keine Daten.' }
    $sources[$id] = (Get-FileHash -LiteralPath $configuration -Algorithm SHA256).Hash.ToLowerInvariant()
}
$directory = [IO.Directory]::CreateDirectory($Destination)
$acl = [Security.AccessControl.DirectorySecurity]::new()
$acl.SetOwner($sid)
$acl.SetAccessRuleProtection($true,$false)
foreach ($identity in @($sid, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'), [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))) {
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($identity,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
}
Set-Acl -LiteralPath $directory.FullName -AclObject $acl
[IO.File]::WriteAllText((Join-Path $Destination 'profiles.local.json'), (@{version=1;owner_sid=$sid.Value;profiles=$profiles} | ConvertTo-Json -Depth 6))
[IO.File]::WriteAllText((Join-Path $Destination 'binding.json'), (@{connections=$connections;configuration_hashes=$sources} | ConvertTo-Json -Depth 4))
Write-Output 'PASS: vorhandene Installationen und Welten lesend gebunden; keine Spielaktion.'
