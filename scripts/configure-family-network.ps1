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
if ($Mode -eq 'Configure') {
    if ($state.BackendState -ne 'Running' -or -not $hostname) { throw 'Tailscale zuerst mit dem eigenen Konto anmelden.' }
    if (-not (Get-NetAdapter -Name 'Tailscale' -ErrorAction SilentlyContinue)) { throw 'Der Tailscale-Adapter wurde nicht gefunden. Keine Netzregeln geaendert.' }
    # Refuse to overwrite another application or a public Funnel configuration.
    if ($serve.AllowFunnel -and @($serve.AllowFunnel.PSObject.Properties | Where-Object Value).Count) { throw 'Vorhandenen Funnel zuerst bewusst pruefen. Keine automatische Umstellung.' }
    if ($serve.Web) {
        foreach ($entry in $serve.Web.PSObject.Properties) {
            if ($entry.Name -eq ($hostname + ':443')) {
                $handlers = @($entry.Value.Handlers.PSObject.Properties)
                if ($handlers.Count -ne 1 -or $handlers[0].Name -ne '/' -or $handlers[0].Value.Proxy -ne $target) {
                    throw 'Port 443 gehoert bereits einer anderen Serve-Konfiguration. Nichts ueberschrieben.'
                }
            }
        }
    }
    & $TailscaleExecutable set --unattended=true --accept-routes=false --accept-dns=false
    if ($LASTEXITCODE -ne 0) { throw 'Tailscale konnte nicht fuer den Hintergrundbetrieb eingerichtet werden.' }
    # Scope only this virtual adapter. LAN, internet game ports and physical
    # adapters are unaffected. Serve owns tailnet TCP/443 in userspace.
    if (-not (Get-NetFirewallRule -Name $ruleNames[0] -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -Name $ruleNames[0] -DisplayName 'GameServer Indy: Tailnet nur HTTPS' -Group 'GameServer Indy Familienzugang' -InterfaceAlias 'Tailscale' -Direction Inbound -Action Block -Profile Any -Protocol TCP -LocalPort @('1-442','444-65535') | Out-Null
    }
    if (-not (Get-NetFirewallRule -Name $ruleNames[1] -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -Name $ruleNames[1] -DisplayName 'GameServer Indy: kein UDP aus dem Tailnet' -Group 'GameServer Indy Familienzugang' -InterfaceAlias 'Tailscale' -Direction Inbound -Action Block -Profile Any -Protocol UDP | Out-Null
    }
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
    Backend = $target
} | ConvertTo-Json -Compress
