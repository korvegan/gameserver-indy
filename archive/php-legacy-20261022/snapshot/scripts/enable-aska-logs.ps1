param([Parameter(Mandatory = $true)][string]$LauncherScript)
$ErrorActionPreference = 'Stop'
$content = [IO.File]::ReadAllText($LauncherScript)
if ($content.Contains("Get-Command Write-IndyServerLog")) { return }
$newline = if ($content.Contains("`r`n")) { "`r`n" } else { "`n" }
$needle = 'function Read-ServerMarker {' + $newline + '    param([string]$Text, [hashtable]$State)'
if (-not $content.Contains($needle)) { throw 'Dieser ASKA-Starter hat eine andere Struktur. Keine Aenderung vorgenommen.' }
$hook = '    if (Get-Command Write-IndyServerLog -ErrorAction SilentlyContinue) { Write-IndyServerLog -Text $Text -Source server }'
$backupDirectory = Join-Path (Split-Path -Parent $LauncherScript) 'backups'
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$backup = Join-Path $backupDirectory ('starter-before-dashboard-logs-' + [Guid]::NewGuid().ToString('N') + '.ps1')
Copy-Item -LiteralPath $LauncherScript -Destination $backup
[IO.File]::WriteAllText($LauncherScript, $content.Replace($needle, $needle + $newline + $hook), (New-Object Text.UTF8Encoding($true)))
[pscustomobject]@{ Integrated = $true; Backup = $backup }
