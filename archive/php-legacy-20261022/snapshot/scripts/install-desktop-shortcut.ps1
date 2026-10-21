param(
    [string]$WslDistribution,
    [string]$WslUser,
    [string]$ProjectDirectory,
    [ValidateSet('symfony')][string]$RuntimeEngine,
    [string]$RuntimeExecutable,
    [string]$StateDirectory,
    [ValidateRange(1, 65535)][int]$Port = 8765,
    [string]$DesktopDirectory = [Environment]::GetFolderPath('Desktop'),
    [string]$ApplicationDirectory = (Join-Path $env:LOCALAPPDATA 'GameServerIndy')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$source = Join-Path $PSScriptRoot 'start-manager.ps1'
$parameters = @{ Describe = $true }
$previousConfig = Join-Path $ApplicationDirectory 'launcher.local.json'
if (Test-Path -LiteralPath $previousConfig) { $parameters.ConfigPath = $previousConfig }
foreach ($name in @('WslDistribution', 'WslUser', 'ProjectDirectory', 'Port', 'RuntimeEngine', 'RuntimeExecutable', 'StateDirectory')) {
    if ($PSBoundParameters.ContainsKey($name)) { $parameters[$name] = Get-Variable -Name $name -ValueOnly }
}
$settings = & $source @parameters
$shortcutPath = Join-Path $DesktopDirectory 'GameServer Indy.lnk'
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
$shortcut.Description = 'Startet GameServer Indy und oeffnet die lokale Browseroberflaeche.'
$shortcut.WindowStyle = 7
$shortcut.Save()
$saved = $shell.CreateShortcut($shortcutPath)
if ($saved.TargetPath -ne $shortcut.TargetPath -or $saved.Arguments -ne $shortcut.Arguments) {
    throw 'Die gespeicherte Verknuepfung stimmt nicht mit dem Starter ueberein.'
}
# Migrate only our former shortcut, preserving unrelated links and archived logs.
$legacyPath = Join-Path $DesktopDirectory 'Enshrouded Indy.lnk'
if (Test-Path -LiteralPath $legacyPath) {
    $legacy = $shell.CreateShortcut($legacyPath)
    $legacyLauncher = Join-Path $env:LOCALAPPDATA 'EnshroudedIndy\Start-Manager.ps1'
    if ($legacy.Arguments.IndexOf('-File "' + $legacyLauncher + '"', [StringComparison]::OrdinalIgnoreCase) -ge 0) {
        Remove-Item -LiteralPath $legacyPath
    }
}
[pscustomobject]@{ Shortcut = $shortcutPath; Launcher = $launcherPath; Url = ('http://127.0.0.1:' + $settings.Port) }
