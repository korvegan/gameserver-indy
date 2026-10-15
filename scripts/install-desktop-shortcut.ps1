param(
    [string]$WslDistribution,
    [string]$WslUser,
    [string]$ProjectDirectory,
    [ValidateRange(1, 65535)][int]$Port = 8765,
    [string]$DesktopDirectory = [Environment]::GetFolderPath('Desktop'),
    [string]$ApplicationDirectory = (Join-Path $env:LOCALAPPDATA 'EnshroudedIndy')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$source = Join-Path $PSScriptRoot 'start-manager.ps1'
$parameters = @{ Describe = $true; Port = $Port }
foreach ($name in @('WslDistribution', 'WslUser', 'ProjectDirectory')) {
    if ($PSBoundParameters.ContainsKey($name)) { $parameters[$name] = Get-Variable -Name $name -ValueOnly }
}
$settings = & $source @parameters
$shortcutPath = Join-Path $DesktopDirectory 'Enshrouded Indy.lnk'
$launcherPath = Join-Path $ApplicationDirectory 'Start-Manager.ps1'
$shell = New-Object -ComObject WScript.Shell
if (Test-Path -LiteralPath $shortcutPath) {
    $previous = $shell.CreateShortcut($shortcutPath)
    if ($previous.Arguments.IndexOf('-File "' + $launcherPath + '"', [StringComparison]::OrdinalIgnoreCase) -lt 0) {
        throw 'Eine andere Verknuepfung mit diesem Namen existiert bereits. Sie wurde nicht veraendert.'
    }
}
New-Item -ItemType Directory -Path $ApplicationDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $DesktopDirectory -Force | Out-Null
Copy-Item -LiteralPath $source -Destination $launcherPath -Force
$settings | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $ApplicationDirectory 'launcher.local.json') -Encoding UTF8
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$shortcut.Arguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $launcherPath + '"'
$shortcut.WorkingDirectory = $ApplicationDirectory
$shortcut.IconLocation = (Join-Path $env:WINDIR 'System32\shell32.dll') + ',14'
$managerSettings = Join-Path (Split-Path -Parent $PSScriptRoot) 'manager.local.json'
if (Test-Path -LiteralPath $managerSettings) {
    $serverDirectory = (Get-Content -LiteralPath $managerSettings -Raw -Encoding UTF8 | ConvertFrom-Json).server_directory
    $icon = Join-Path $serverDirectory 'enshrouded_server.exe'
    if (Test-Path -LiteralPath $icon) { $shortcut.IconLocation = $icon + ',0' }
}
$shortcut.Description = 'Startet die lokale Enshrouded-Verwaltung und oeffnet die Browseroberflaeche.'
$shortcut.WindowStyle = 7
$shortcut.Save()
$saved = $shell.CreateShortcut($shortcutPath)
if ($saved.TargetPath -ne $shortcut.TargetPath -or $saved.Arguments -ne $shortcut.Arguments) {
    throw 'Die gespeicherte Verknuepfung stimmt nicht mit dem Starter ueberein.'
}
[pscustomobject]@{ Shortcut = $shortcutPath; Launcher = $launcherPath; Url = ('http://127.0.0.1:' + $settings.Port) }
