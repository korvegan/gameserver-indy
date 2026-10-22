$ErrorActionPreference='Stop'
$source=Join-Path $PSScriptRoot '..\windows\Update-Startup.ps1'
$pwsh=(Get-Process -Id $PID).Path
$temporary=Join-Path ([IO.Path]::GetTempPath()) ('indy-startup-test-' + [guid]::NewGuid().ToString('N'))
$previous=$env:LOCALAPPDATA
$env:LOCALAPPDATA=$temporary
[IO.Directory]::CreateDirectory((Join-Path $temporary 'GameServerIndy')) | Out-Null
$starter=Join-Path $temporary 'GameServerIndy\Start-Manager.ps1'
[IO.File]::WriteAllText($starter,'fixture')
$global:IndyTaskFixture=[pscustomobject]@{Principal=[pscustomobject]@{UserId=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value;LogonType='Password'};
    Triggers=@([pscustomobject]@{CimClass=@{CimClassName='MSFT_TaskBootTrigger'}});
    Actions=@([pscustomobject]@{Execute='previous';Arguments=('-File "' + $starter + '" -NoBrowser')})}
$global:IndyTaskChanges=0
function Get-ScheduledTask { param($TaskName); if ($TaskName -ne 'GameServer Indy Verwaltung') { throw 'Unexpected task' };return $global:IndyTaskFixture }
function Export-ScheduledTask { param($TaskName);return '<fixture />' }
function New-ScheduledTaskAction { param($Execute,$Argument);return [pscustomobject]@{Execute=$Execute;Arguments=$Argument} }
function Set-ScheduledTask { param($TaskName,$Action);$global:IndyTaskChanges++;$global:IndyTaskFixture.Actions=@($Action) }
try {
    $result=& $source -PowerShellExecutable $pwsh | ConvertFrom-Json
    if ($result.Applied -or $global:IndyTaskChanges -ne 0) { throw 'Plan mutated task' }
    $result=& $source -PowerShellExecutable $pwsh -BackupDirectory $temporary -Apply | ConvertFrom-Json
    if (-not $result.Applied -or $global:IndyTaskChanges -ne 1 -or
        $global:IndyTaskFixture.Actions[0].Arguments -match 'Bypass' -or
        -not (Test-Path -LiteralPath (Join-Path $temporary 'startup-task.xml'))) { throw 'Task update or backup failed' }
    & $source -PowerShellExecutable $pwsh -BackupDirectory $temporary -Apply | Out-Null
    if ($global:IndyTaskChanges -ne 1) { throw 'Matching task was changed again' }
    $global:IndyTaskFixture.Principal.LogonType='S4U'
    $rejected=$false
    try { & $source -PowerShellExecutable $pwsh | Out-Null } catch { $rejected=$true }
    if (-not $rejected -or $global:IndyTaskChanges -ne 1) { throw 'Wrong account context accepted' }
    Write-Output 'PASS: task plan, action-only update, owner/password/boot gates and backup; all task mutations mocked'
} finally {
    $env:LOCALAPPDATA=$previous
    Remove-Variable -Name IndyTaskFixture,IndyTaskChanges -Scope Global
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
