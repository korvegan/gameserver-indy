param(
    [ValidateSet('Status', 'Configure')][string]$Mode = 'Status',
    [ValidateRange(1024,65535)][int]$BackendPort = 8767,
    [string]$TailscaleExecutable = (Join-Path $env:ProgramFiles 'Tailscale\tailscale.exe')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
if (-not (Test-Path -LiteralPath $TailscaleExecutable)) { throw 'Tailscale ist nicht installiert.' }
$state = & $TailscaleExecutable status --json | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'Tailscale-Status ist nicht lesbar.' }
$serveText = & $TailscaleExecutable serve status --json
if ($LASTEXITCODE -ne 0) { throw 'Tailscale-Serve-Status ist nicht lesbar.' }
$serve = $serveText | ConvertFrom-Json
$hostname = ([string]$state.Self.DNSName).TrimEnd('.')
$target = 'http://127.0.0.1:' + $BackendPort
Import-Module (Join-Path $PSHOME 'Modules\NetSecurity\NetSecurity.psd1')
$ruleNames = @('GameServerIndy-Tailnet-BlockOtherTCP', 'GameServerIndy-Tailnet-BlockUDP')
$firewallProfiles = @(Get-NetFirewallProfile)
$firewallReady = ($firewallProfiles.Count -ge 3 -and @($firewallProfiles | Where-Object { [string]$_.Enabled -notin @('True','1') }).Count -eq 0 -and (Get-Service -Name 'MpsSvc').Status -eq 'Running')
function Test-FamilyRule($rule, $protocol) {
    if (-not $rule -or [string]$rule.Enabled -ne 'True' -or [string]$rule.Action -ne 'Block' -or [string]$rule.Direction -ne 'Inbound' -or [string]$rule.Profile -ne 'Any' -or $rule.Group -ne 'GameServer Indy Familienzugang') { return $false }
    $interface = $rule | Get-NetFirewallInterfaceFilter
    $ports = $rule | Get-NetFirewallPortFilter
    $address = $rule | Get-NetFirewallAddressFilter
    $application = $rule | Get-NetFirewallApplicationFilter
    $service = $rule | Get-NetFirewallServiceFilter
    $interfaceType = $rule | Get-NetFirewallInterfaceTypeFilter
    $security = $rule | Get-NetFirewallSecurityFilter
    if (@($interface.InterfaceAlias).Count -ne 1 -or $interface.InterfaceAlias -ne 'Tailscale') { return $false }
    foreach($value in @($address.LocalAddress,$address.RemoteAddress,$ports.RemotePort,$application.Program,$service.Service,$security.LocalUser,$security.RemoteUser,$security.RemoteMachine)) {
        if (($value -join ',') -ne 'Any') { return $false }
    }
    if ($application.Package -and $application.Package -ne 'Any') { return $false }
    if ([string]$interfaceType.InterfaceType -notin @('Any','0') -or [string]$ports.DynamicTarget -notin @('Any','0')) { return $false }
    if ([string]$security.Authentication -notin @('NotRequired','0') -or [string]$security.Encryption -notin @('NotRequired','0')) { return $false }
    $expected = if($protocol -eq 'TCP') { '1-442,444-65535' } else { 'Any' }
    return ([string]$ports.Protocol -eq $protocol -and (($ports.LocalPort | Sort-Object) -join ',') -eq $expected)
}
if ($Mode -eq 'Configure') {
    if (-not $firewallReady) { throw 'Windows-Firewalldienst und alle Firewallprofile muessen aktiv sein. Keine automatische Umstellung.' }
    if ($state.BackendState -ne 'Running' -or -not $hostname) { throw 'Tailscale zuerst mit dem eigenen Konto anmelden.' }
    if (-not (Get-NetAdapter -Name 'Tailscale' -ErrorAction SilentlyContinue)) { throw 'Der Tailscale-Adapter wurde nicht gefunden. Keine Netzregeln geaendert.' }
    # Refuse to overwrite another application or a public Funnel configuration.
    if ($serve.AllowFunnel -and @($serve.AllowFunnel.PSObject.Properties | Where-Object Value).Count) { throw 'Vorhandenen Funnel zuerst bewusst pruefen. Keine automatische Umstellung.' }
    foreach ($property in $serve.PSObject.Properties) {
        if ($property.Name -notin @('Web','TCP','AllowFunnel','Foreground') -and $property.Value) { throw 'Unbekannte bestehende Serve-Dienste zuerst pruefen. Keine automatische Freigabe.' }
    }
    if ($serve.Foreground -and @($serve.Foreground.PSObject.Properties).Count) { throw 'Bestehende temporaere Serve-Dienste zuerst pruefen.' }
    $web = @(); $tcp = @()
    if ($serve.Web) { $web = @($serve.Web.PSObject.Properties) }
    if ($serve.TCP) { $tcp = @($serve.TCP.PSObject.Properties) }
    if ($web.Count -or $tcp.Count) {
        if ($web.Count -ne 1 -or $tcp.Count -ne 1 -or $web[0].Name -ne ($hostname + ':443') -or $tcp[0].Name -ne '443' -or $tcp[0].Value.HTTPS -ne $true -or $tcp[0].Value.TCPForward) { throw 'Andere Serve-Ports oder Dienste vorhanden. Nichts ueberschrieben oder neu freigegeben.' }
        $handlers = @($web[0].Value.Handlers.PSObject.Properties)
        if ($handlers.Count -ne 1 -or $handlers[0].Name -ne '/' -or $handlers[0].Value.Proxy -ne $target) { throw 'HTTPS/443 gehoert bereits einer anderen Serve-Konfiguration.' }
    }
    for($index=0; $index -lt 2; $index++) {
        $existing = Get-NetFirewallRule -Name $ruleNames[$index] -ErrorAction SilentlyContinue
        $protocol = if($index -eq 0) { 'TCP' } else { 'UDP' }
        if ($existing -and -not (Test-FamilyRule $existing $protocol)) { throw ('Vorhandene Schutzregel ist veraendert oder deaktiviert: ' + $ruleNames[$index] + '. Vor HTTPS-Freigabe gezielt reparieren.') }
    }
    # Scope only this virtual adapter. LAN, internet game ports and physical
    # adapters are unaffected. Serve owns tailnet TCP/443 in userspace.
    if (-not (Get-NetFirewallRule -Name $ruleNames[0] -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -Name $ruleNames[0] -DisplayName 'GameServer Indy: Tailnet nur HTTPS' -Group 'GameServer Indy Familienzugang' -InterfaceAlias 'Tailscale' -Direction Inbound -Action Block -Profile Any -Protocol TCP -LocalPort @('1-442','444-65535') | Out-Null
    }
    if (-not (Get-NetFirewallRule -Name $ruleNames[1] -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -Name $ruleNames[1] -DisplayName 'GameServer Indy: kein UDP aus dem Tailnet' -Group 'GameServer Indy Familienzugang' -InterfaceAlias 'Tailscale' -Direction Inbound -Action Block -Profile Any -Protocol UDP | Out-Null
    }
    if (-not (Test-FamilyRule (Get-NetFirewallRule -Name $ruleNames[0]) 'TCP') -or -not (Test-FamilyRule (Get-NetFirewallRule -Name $ruleNames[1]) 'UDP')) { throw 'Schutzregeln konnten nicht bestaetigt werden. Keine HTTPS-Freigabe.' }
    & $TailscaleExecutable set --unattended=true --accept-routes=false --accept-dns=false
    if ($LASTEXITCODE -ne 0) { throw 'Tailscale konnte nicht fuer den Hintergrundbetrieb eingerichtet werden.' }
    & $TailscaleExecutable serve --bg --https=443 $target
    if ($LASTEXITCODE -ne 0) { throw 'Private HTTPS-Freigabe noch nicht eingerichtet. Tailscale-Hinweis zur HTTPS-Aktivierung beachten.' }
}
$prefs = & $TailscaleExecutable debug prefs | ConvertFrom-Json
$rules = @(Get-NetFirewallRule -Name $ruleNames -ErrorAction SilentlyContinue)
$service = Get-Service -Name Tailscale
[pscustomobject]@{
    Connected = ($state.BackendState -eq 'Running')
    Hostname = $hostname
    Url = if ($hostname) { 'https://' + $hostname } else { '' }
    Unattended = [bool]$prefs.ForceDaemon
    ServiceRunning = ($service.Status -eq 'Running')
    ServiceStartType = [string]$service.StartType
    FirewallRuleCount = $rules.Count
    FirewallProtected = ($firewallReady -and (Test-FamilyRule ($rules | Where-Object Name -eq $ruleNames[0]) 'TCP') -and (Test-FamilyRule ($rules | Where-Object Name -eq $ruleNames[1]) 'UDP'))
    Backend = $target
} | ConvertTo-Json -Compress
