# Isolated mocks. Never changes Tailscale, firewall rules or real scheduled tasks.
$ErrorActionPreference = 'Stop'
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('gameserver-family-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$global:familyQaRules = @{}
$global:familyQaCommands = New-Object System.Collections.Generic.List[string]
$global:familyQaRegistered = $null
function Assert($condition, $message) { if (-not $condition) { throw $message } }
function Import-Module { param($Name) Assert ($Name -match '\\(NetSecurity|ScheduledTasks|Microsoft.PowerShell.Security)\\') 'Unexpected module import'; }
function Get-NetAdapter { param($Name) return [pscustomobject]@{Name=$Name} }
function Get-NetFirewallRule { param($Name) foreach($item in $Name){ if($global:familyQaRules.ContainsKey($item)){ $global:familyQaRules[$item] } } }
function New-NetFirewallRule {
    param($Name,$DisplayName,$Group,$InterfaceAlias,$Direction,$Action,$Profile,$Protocol,$LocalPort)
    Assert ($InterfaceAlias -eq 'Tailscale') 'Rule escaped virtual adapter'
    Assert ($Direction -eq 'Inbound' -and $Action -eq 'Block' -and $Profile -eq 'Any') 'Wrong firewall boundary'
    if($Protocol -eq 'TCP'){ Assert (($LocalPort -join ',') -eq '1-442,444-65535') 'TCP/443 must remain the sole service' }
    else { Assert ($Protocol -eq 'UDP') 'Unexpected protocol' }
    $global:familyQaRules[$Name] = [pscustomobject]@{Name=$Name}
}
function Get-Service { param($Name) return [pscustomobject]@{Status='Running';StartType='Automatic'} }
function Register-ScheduledTask {
    param($TaskName,$InputObject,$User,$Password,[switch]$Force)
    Assert ($Password -eq 'isolated-test-only') 'Unexpected password'
    Assert ($InputObject.Actions.Arguments -match '-NoBrowser') 'Task may open browser'
    Assert ($InputObject.Actions.Arguments -notmatch 'isolated-test-only') 'Password entered arguments'
    $global:familyQaRegistered = $TaskName
}
function New-ScheduledTaskAction { param($Execute,$Argument) return [pscustomobject]@{Execute=$Execute;Arguments=$Argument} }
function New-ScheduledTaskTrigger { param([switch]$AtStartup) Assert $AtStartup 'Wrong trigger'; return [pscustomobject]@{AtStartup=$true} }
function New-ScheduledTaskSettingsSet {
    param([switch]$StartWhenAvailable,[switch]$AllowStartIfOnBatteries,[switch]$DontStopIfGoingOnBatteries,$ExecutionTimeLimit,$MultipleInstances,$RestartCount,$RestartInterval)
    return [pscustomobject]@{MultipleInstances=$MultipleInstances}
}
function New-ScheduledTask { param($Action,$Trigger,$Settings,$Description) return [pscustomobject]@{Actions=$Action;Triggers=$Trigger;Settings=$Settings} }
try {
    $stub = Join-Path $temporary 'tailscale stub.ps1'
    @'
$global:LASTEXITCODE=0
$command=$args -join ' '
$global:familyQaCommands.Add($command)
if($command -eq 'status --json'){ '{"BackendState":"Running","Self":{"DNSName":"indy.example.ts.net."}}' }
elseif($command -eq 'serve status --json'){ '{}' }
elseif($command -eq 'debug prefs'){ '{"ForceDaemon":true}' }
'@ | Set-Content -LiteralPath $stub
    $network = Join-Path $PSScriptRoot '..\scripts\configure-family-network.ps1'
    $first = & $network -Mode Configure -TailscaleExecutable $stub | ConvertFrom-Json
    Assert ($first.FirewallRuleCount -eq 2 -and $first.Unattended) 'Network configuration incomplete'
    $second = & $network -Mode Configure -TailscaleExecutable $stub | ConvertFrom-Json
    Assert ($global:familyQaRules.Count -eq 2) 'Duplicate rules'
    Assert ($global:familyQaCommands.Contains('serve --bg --https=443 http://127.0.0.1:8767')) 'Wrong Serve backend'
    Assert (-not ($global:familyQaCommands -match 'funnel|advertise-routes|advertise-exit-node')) 'Unexpected network exposure'
    $launcher = Join-Path $temporary 'Start Manager.ps1'
    '' | Set-Content -LiteralPath $launcher
    $startup = Join-Path $PSScriptRoot '..\scripts\install-family-startup.ps1'
    $plan = & $startup -LauncherPath $launcher -PlanOnly
    Assert ($plan.LogonType -eq 'Password' -and -not $plan.StartsGameServers) 'Unsafe task context'
    Assert ($null -eq $global:familyQaRegistered) 'Plan registered a task'
    $password = New-Object Security.SecureString
    foreach($character in 'isolated-test-only'.ToCharArray()){ $password.AppendChar($character) }
    $credential = New-Object Management.Automation.PSCredential([Security.Principal.WindowsIdentity]::GetCurrent().Name,$password)
    $null = & $startup -LauncherPath $launcher -TaskName 'Isolated GameServer Test' -Credential $credential
    Assert ($global:familyQaRegistered -eq 'Isolated GameServer Test') 'Task registration not requested'
    'Family setup tests passed: isolated network rules, idempotence, private backend, same-user password task, no game actions.'
} finally { Remove-Item -LiteralPath $temporary -Recurse -Force; Remove-Variable familyQaCommands,familyQaRules,familyQaRegistered -Scope Global -ErrorAction SilentlyContinue }
