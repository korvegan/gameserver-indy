param([Parameter(Mandatory)][string]$PowerShellExecutable, [string]$BackupDirectory, [switch]$Apply)
$ErrorActionPreference='Stop'
$name='GameServer Indy Verwaltung'
$task=Get-ScheduledTask -TaskName $name
$current=[Security.Principal.WindowsIdentity]::GetCurrent().User
$owner=if ($task.Principal.UserId -like 'S-1-*') { [Security.Principal.SecurityIdentifier]::new($task.Principal.UserId) }
    else { [Security.Principal.NTAccount]::new($task.Principal.UserId).Translate([Security.Principal.SecurityIdentifier]) }
if ($owner.Value -ne $current.Value -or $task.Principal.LogonType -ne 'Password' -or
    @($task.Triggers).Count -ne 1 -or $task.Triggers[0].CimClass.CimClassName -ne 'MSFT_TaskBootTrigger' -or
    @($task.Actions).Count -ne 1) { throw 'Vorhandene Besitzer-/Passwort-/Bootaufgabe passt nicht.' }
$starter=Join-Path $env:LOCALAPPDATA 'GameServerIndy/Start-Manager.ps1'
if ($task.Actions[0].Arguments -notlike ('*' + $starter + '*') -or -not [IO.File]::Exists($PowerShellExecutable)) {
    throw 'Vorhandenen Einstieg oder PowerShell-Pfad pruefen.'
}
$version=& $PowerShellExecutable -NoLogo -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()'
if ($LASTEXITCODE -ne 0 -or [version]$version -lt [version]'7.6') { throw 'PowerShell 7.6+ erforderlich.' }
$arguments='-NoLogo -NoProfile -NonInteractive -File "' + $starter + '" -NoBrowser'
if ($Apply -and ($task.Actions[0].Execute -cne $PowerShellExecutable -or $task.Actions[0].Arguments -cne $arguments)) {
    if (-not [IO.Directory]::Exists($BackupDirectory)) { throw 'Privates bestehendes Sicherungsziel erforderlich.' }
    $backup=Join-Path $BackupDirectory 'startup-task.xml'
    if (Test-Path -LiteralPath $backup) { throw 'Aufgabensicherung besteht bereits.' }
    Export-ScheduledTask -TaskName $name | Set-Content -LiteralPath $backup -Encoding UTF8
    $action=New-ScheduledTaskAction -Execute $PowerShellExecutable -Argument $arguments
    Set-ScheduledTask -TaskName $name -Action $action | Out-Null
    $updated=Get-ScheduledTask -TaskName $name
    if ($updated.Actions[0].Execute -cne $PowerShellExecutable -or $updated.Actions[0].Arguments -cne $arguments -or
        $updated.Principal.UserId -cne $task.Principal.UserId -or $updated.Principal.LogonType -ne 'Password') {
        throw 'Aktualisierte Aufgabe lokal pruefen; nicht neu starten.'
    }
}
[pscustomobject]@{Task=$name;OwnerMatches=$true;Logon='Password';Boot=$true;Applied=[bool]$Apply;
    PowerShell=$PowerShellExecutable;Arguments=$arguments} | ConvertTo-Json -Compress
