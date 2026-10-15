# Isolated mocks. Never changes Tailscale, firewall rules or real scheduled tasks.
$ErrorActionPreference = 'Stop'
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('gameserver-family-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporary | Out-Null
$global:familyQaRules = @{}
$global:familyQaCommands = New-Object System.Collections.Generic.List[string]
$global:familyQaRegistered = $null
$global:familyQaServeJSON = '{}'
$global:familyQaFirewallEnabled = $true
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
    $global:familyQaRules[$Name] = [pscustomobject]@{Name=$Name;Enabled='True';Group=$Group;Direction=$Direction;Action=$Action;Profile=$Profile;InterfaceAlias=$InterfaceAlias;Protocol=$Protocol;LocalPort=if($Protocol -eq 'UDP'){'Any'}else{$LocalPort};LocalAddress='Any';RemoteAddress='Any';RemotePort='Any';Program='Any';Package=$null;Service='Any';InterfaceType='Any';DynamicTarget='Any';Authentication='NotRequired';Encryption='NotRequired';LocalUser='Any';RemoteUser='Any';RemoteMachine='Any'}
}
function Get-NetFirewallInterfaceFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return [pscustomobject]@{InterfaceAlias=$InputObject.InterfaceAlias} } }
function Get-NetFirewallPortFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallAddressFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallApplicationFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallServiceFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallInterfaceTypeFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallSecurityFilter { param([Parameter(ValueFromPipeline=$true)]$InputObject) process { return $InputObject } }
function Get-NetFirewallProfile { foreach($name in @('Domain','Private','Public')) { [pscustomobject]@{Name=$name;Enabled=$global:familyQaFirewallEnabled} } }
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
elseif($command -eq 'serve status --json'){ $global:familyQaServeJSON }
elseif($command -eq 'debug prefs'){ '{"ForceDaemon":true}' }
'@ | Set-Content -LiteralPath $stub
    $network = Join-Path $PSScriptRoot '..\scripts\configure-family-network.ps1'
    $first = & $network -Mode Configure -TailscaleExecutable $stub | ConvertFrom-Json
    Assert ($first.FirewallRuleCount -eq 2 -and $first.Unattended) 'Network configuration incomplete'
    $second = & $network -Mode Configure -TailscaleExecutable $stub | ConvertFrom-Json
    Assert ($global:familyQaRules.Count -eq 2) 'Duplicate rules'
    Assert ($global:familyQaCommands.Contains('serve --bg --https=443 http://127.0.0.1:8767')) 'Wrong Serve backend'
    Assert (-not ($global:familyQaCommands -match 'funnel|advertise-routes|advertise-exit-node')) 'Unexpected network exposure'
    $tcpRule = $global:familyQaRules['GameServerIndy-Tailnet-BlockOtherTCP']
    foreach($entry in @(@('Enabled','False'),@('Action','Allow'),@('Direction','Outbound'),@('InterfaceAlias','Ethernet'),@('LocalPort','Any'),@('RemoteAddress','192.0.2.1'),@('LocalAddress','192.0.2.2'),@('RemotePort','8080'),@('Program','restricted.exe'),@('Package','restricted-package'),@('Service','restricted-service'),@('InterfaceType','Wireless'),@('DynamicTarget','ProximityCasting'),@('Authentication','Required'),@('LocalUser','restricted-sid'))) {
        $key=$entry[0]; $previous=$tcpRule.$key; $tcpRule.$key=$entry[1]; $global:familyQaCommands.Clear(); $rejected=$false
        try { $null = & $network -Mode Configure -TailscaleExecutable $stub } catch { $rejected=$true }
        Assert $rejected ('Changed rule accepted: ' + $key)
        Assert (-not ($global:familyQaCommands -match '^set |^serve --bg')) 'Network changed before rule validation'
        $tcpRule.$key=$previous
    }
    $global:familyQaFirewallEnabled=$false; $global:familyQaCommands.Clear(); $rejected=$false
    try { $null = & $network -Mode Configure -TailscaleExecutable $stub } catch { $rejected=$true }
    Assert $rejected 'Disabled firewall profile accepted'
    Assert (-not ($global:familyQaCommands -match '^set |^serve --bg')) 'Network changed with disabled firewall'
    $global:familyQaFirewallEnabled=$true
    foreach($foreign in @(
        '{"TCP":{"8443":{"HTTPS":true}},"Web":{"indy.example.ts.net:8443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:9999"}}}}}',
        '{"Foreground":{"temporary":{"TCP":{"8443":{"HTTPS":true}}}}}',
        '{"AllowFunnel":{"indy.example.ts.net:443":true}}',
        '{"TCP":{"443":{"HTTPS":true}},"Web":{"indy.example.ts.net:443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:8765"}}}}}'
    )) {
        $global:familyQaServeJSON=$foreign; $global:familyQaCommands.Clear(); $rejected=$false
        try { $null = & $network -Mode Configure -TailscaleExecutable $stub } catch { $rejected=$true }
        Assert $rejected 'Foreign Serve configuration accepted'
        Assert (-not ($global:familyQaCommands -match '^set |^serve --bg')) 'Network changed before Serve validation'
    }
    $global:familyQaServeJSON='{"TCP":{"443":{"HTTPS":true}},"Web":{"indy.example.ts.net:443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:8767"}}}}}'
    $own = & $network -Mode Configure -TailscaleExecutable $stub | ConvertFrom-Json
    Assert $own.FirewallProtected 'Own configuration not verified'
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
} finally { Remove-Item -LiteralPath $temporary -Recurse -Force; Remove-Variable familyQaCommands,familyQaRules,familyQaRegistered,familyQaServeJSON,familyQaFirewallEnabled -Scope Global -ErrorAction SilentlyContinue }
