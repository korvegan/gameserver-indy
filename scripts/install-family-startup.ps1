param(
    [string]$LauncherPath = (Join-Path $env:LOCALAPPDATA 'GameServerIndy\Start-Manager.ps1'),
    [string]$TaskName = 'GameServer Indy Verwaltung',
    [System.Management.Automation.PSCredential]$Credential,
    [switch]$PlanOnly
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $LauncherPath)) { throw 'Der installierte GameServer-Indy-Starter fehlt.' }
if ($LauncherPath -match '["\r\n]') { throw 'Ungueltiger Starterpfad.' }
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$program = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $LauncherPath + '" -NoBrowser'
if ($PlanOnly) {
    return [pscustomobject]@{ TaskName=$TaskName; Program=$program; Arguments=$arguments; User=$identity.Name; LogonType='Password'; Trigger='AtStartup'; StartsGameServers=$false }
}
Import-Module (Join-Path $PSHOME 'Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1')
# S4U has no access to encrypted user files. The ASKA starter uses CurrentUser
# DPAPI, so use the same Windows account with a password-backed task logon.
# Credentials stay in the Windows credential prompt/task scheduler, never files
# or command-line arguments. A Windows Hello PIN is not an account password.
if (-not $Credential) {
    $Credential = Get-Credential -UserName $identity.Name -Message 'GameServer Indy: Windows-Kontokennwort fuer Verwaltungsstart ohne Desktop-Anmeldung. Kein Windows-Hello-PIN. Das Kennwort wird nicht im Projekt gespeichert.'
}
if (-not $Credential) { throw 'Einrichtung abgebrochen. Vorhandene Aufgaben bleiben unveraendert.' }
$account = New-Object Security.Principal.NTAccount($Credential.UserName)
if ($account.Translate([Security.Principal.SecurityIdentifier]).Value -ne $identity.User.Value) {
    throw 'Das Konto muss dem Besitzer der bestehenden WSL-Distribution und ASKA-DPAPI-Anbindung entsprechen.'
}
Import-Module (Join-Path $PSHOME 'Modules\ScheduledTasks\ScheduledTasks.psd1')
$action = New-ScheduledTaskAction -Execute $program -Argument $arguments
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
$task = New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Description 'Startet nur die lokale Serververwaltung im bestehenden Windows-/WSL-Besitzerkonto. Keine Spielserver-Autostarts.'
$plain = $null
try {
    $plain = $Credential.GetNetworkCredential().Password
    Register-ScheduledTask -TaskName $TaskName -InputObject $task -User $Credential.UserName -Password $plain -Force | Out-Null
} finally { $plain = $null; $Credential = $null }
Write-Output 'Verwaltungsstart bei Windows-Start eingerichtet. Ohne Neustart/Abmeldung ist der unbeaufsichtigte Betrieb noch nicht praktisch bestaetigt.'
