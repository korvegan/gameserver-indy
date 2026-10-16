param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'launcher.local.json'),
    [string]$WslDistribution,
    [string]$WslUser,
    [string]$ProjectDirectory,
    [ValidateSet('python', 'symfony')][string]$RuntimeEngine,
    [string]$RuntimeExecutable,
    [string]$StateDirectory,
    [ValidateRange(1, 65535)][int]$Port = 8765,
    [string]$WslExecutable = (Join-Path $env:WINDIR 'System32\wsl.exe'),
    [string]$LogDirectory = (Join-Path $env:LOCALAPPDATA 'GameServerIndy\logs'),
    [switch]$Describe,
    [switch]$CheckOnly,
    [switch]$NoBrowser,
    [switch]$PassThru
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$mutex = $null
$ownsMutex = $false

try {
    $settings = @{}
    if (Test-Path -LiteralPath $ConfigPath) {
        $stored = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($property in $stored.PSObject.Properties) { $settings[$property.Name] = $property.Value }
    }
    $checkout = Split-Path -Parent $PSScriptRoot
    if ($checkout -match '^\\\\(?:wsl\.localhost|wsl\$)\\([^\\]+)\\(.+)$') {
        if (-not $settings.WslDistribution) { $settings.WslDistribution = $Matches[1] }
        if (-not $settings.ProjectDirectory) { $settings.ProjectDirectory = '/' + $Matches[2].Replace('\', '/') }
    }
    foreach ($name in @('WslDistribution', 'WslUser', 'ProjectDirectory', 'Port', 'RuntimeEngine', 'RuntimeExecutable', 'StateDirectory')) {
        if ($PSBoundParameters.ContainsKey($name)) { $settings[$name] = Get-Variable -Name $name -ValueOnly }
    }
    if (-not $settings.Port) { $settings.Port = $Port }
    if (-not $settings.RuntimeEngine) { $settings.RuntimeEngine = 'python' }
    if ($settings.RuntimeEngine -notin @('python', 'symfony')) { throw 'Unbekannte Verwaltungslaufzeit.' }
    if (-not $settings.WslDistribution -or -not $settings.ProjectDirectory) {
        throw 'WSL-Distribution und Projektpfad fehlen. Bitte install-desktop-shortcut.ps1 aus dem WSL-Projektordner ausfuehren oder beide Parameter angeben.'
    }
    if ([string]$settings.ProjectDirectory -notmatch '^/') { throw 'ProjectDirectory muss ein absoluter Linux-Pfad sein.' }
    foreach ($name in @('WslDistribution', 'WslUser', 'ProjectDirectory', 'RuntimeExecutable', 'StateDirectory')) {
        if ([string]$settings[$name] -match '["\r\n\x00]') { throw ('Ungueltiger Wert fuer ' + $name + '.') }
    }
    if ($settings.StateDirectory -and [string]$settings.StateDirectory -notmatch '^/') { throw 'StateDirectory muss ein absoluter Linux-Pfad sein.' }
    if ($settings.RuntimeEngine -eq 'symfony' -and (-not $settings.StateDirectory -or [string]$settings.RuntimeExecutable -notmatch '^/')) {
        throw 'Symfony benoetigt einen vorbereiteten absoluten Datenordner und ein absolutes PHP-Werkzeug.'
    }
    if ([int]$settings.Port -lt 1 -or [int]$settings.Port -gt 65535) { throw 'Ungueltiger Port.' }
    $managerUrl = 'http://127.0.0.1:' + [int]$settings.Port
    if ($Describe) { return [pscustomobject]$settings }

    function Test-Manager {
        try {
            $response = Invoke-WebRequest -Uri ($managerUrl + '/api/session') -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -ne 200 -or $response.Headers['Server'] -notlike 'GameServerIndy/*') { return $false }
            $isSymfony = $response.Headers['Server'] -eq 'GameServerIndy/Symfony'
            return ($isSymfony -eq ($settings.RuntimeEngine -eq 'symfony'))
        } catch { return $false }
    }

    $ready = Test-Manager
    if ($CheckOnly) { return [pscustomobject]@{ Running = $ready; Url = $managerUrl } }
    $started = $false
    $processId = $null
    if (-not $ready) {
        $mutex = New-Object System.Threading.Mutex($false, ('Local\GameServerIndy-' + $settings.Port))
        $ownsMutex = $mutex.WaitOne(65000)
        if (-not $ownsMutex) { throw 'Ein anderer Startvorgang dauert noch an. Bitte erneut versuchen.' }
        # A second click may have arrived while the first launcher was starting WSL.
        $ready = Test-Manager
        if (-not $ready) {
            $arguments = @('--distribution', [string]$settings.WslDistribution)
            if ($settings.WslUser) { $arguments += @('--user', [string]$settings.WslUser) }
            $arguments += @('--cd', [string]$settings.ProjectDirectory, '--exec')
            if ($settings.RuntimeEngine -eq 'symfony') {
                $arguments += @([string]$settings.RuntimeExecutable, 'symfony/bin/indy', [string]$settings.StateDirectory)
            } else {
                $arguments += @('python3', '-m', 'gameserver_manager', '--port', [string]$settings.Port)
                if ($settings.StateDirectory) { $arguments += @('--state-dir', [string]$settings.StateDirectory) }
            }
            # WSL parses its option names literally; quote only values with whitespace.
            $argumentLine = ($arguments | ForEach-Object {
                if ($_ -match '\s') { '"' + $_ + '"' } else { $_ }
            }) -join ' '
            New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
            $stdout = Join-Path $LogDirectory ('manager-' + $settings.Port + '.stdout.log')
            $stderr = Join-Path $LogDirectory ('manager-' + $settings.Port + '.stderr.log')
            $process = Start-Process -FilePath $WslExecutable -ArgumentList $argumentLine -WindowStyle Minimized -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
            $processId = $process.Id
            $started = $true
            $deadline = [DateTime]::UtcNow.AddSeconds(60)
            do {
                Start-Sleep -Milliseconds 400
                $ready = Test-Manager
                if ($ready) { break }
                $process.Refresh()
                if ($process.HasExited) { throw ('Die Verwaltung konnte nicht gestartet werden. Fehlerprotokoll: ' + $stderr) }
            } while ([DateTime]::UtcNow -lt $deadline)
            if (-not $ready) { throw ('Die Verwaltung antwortet noch nicht. Fehlerprotokoll: ' + $stderr) }
        }
    }
    if (-not $NoBrowser) { Start-Process $managerUrl }
    if ($PassThru) { return [pscustomobject]@{ Running = $true; Started = $started; ProcessId = $processId; Url = $managerUrl } }
} catch {
    if ($Describe -or $CheckOnly -or $NoBrowser) { throw }
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, 'GameServer Indy', 'OK', 'Error') | Out-Null
    exit 1
} finally {
    if ($ownsMutex) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
