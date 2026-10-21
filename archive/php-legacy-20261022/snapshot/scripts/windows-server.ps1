param(
    [ValidateSet('Status', 'Start', 'Stop')][string]$Action = 'Status',
    [Parameter(Mandatory = $true)][string]$ServerDirectory,
    [Parameter(Mandatory = $true)][ValidatePattern('^[A-Za-z0-9_. -]+\.exe$')][string]$ExecutableName,
    [ValidateRange(0, 2147483647)][int]$SteamAppId = 0,
    [ValidatePattern('^[-A-Za-z0-9_]*$')][string]$ConfigArgument = '',
    [string]$ConfigPath = '',
    [string]$LauncherScript = '',
    [string]$DiagnosticDirectory = '',
    [string]$MetricsCachePath = '',
    [string]$RuntimeFencePath = '',
    [string]$RuntimeEpoch = ''
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)

if (-not ('GameServerPath' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.Text;
using System.Runtime.InteropServices;
public static class GameServerPath {
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    public static extern uint GetLongPathName(string path, StringBuilder buffer, uint capacity);
}
'@
}

function Get-CanonicalPath([string]$path) {
    $full = [IO.Path]::GetFullPath($path)
    $buffer = New-Object System.Text.StringBuilder(32768)
    $length = [GameServerPath]::GetLongPathName($full, $buffer, $buffer.Capacity)
    if ($length -gt 0 -and $length -lt $buffer.Capacity) { return $buffer.ToString() }
    return $full
}

function Assert-RuntimeFence {
    # Direct isolated diagnostics may omit the fence; managed actions supply both values.
    if (-not $RuntimeFencePath -and -not $RuntimeEpoch) { return }
    if (-not $RuntimeFencePath -or $RuntimeEpoch -notmatch '^[a-f0-9]{32}$') { throw 'Runtime fence is incomplete.' }
    $fence = [IO.File]::ReadAllText($RuntimeFencePath) | ConvertFrom-Json
    if ($fence.active -isnot [bool] -or -not $fence.active -or $fence.epoch -cne $RuntimeEpoch) {
        throw 'Runtime was stopped or replaced. No new process action was performed.'
    }
}

function Get-ServerProcesses {
    $target = Get-CanonicalPath (Join-Path $ServerDirectory $ExecutableName)
    $found = @(Get-CimInstance Win32_Process -Filter "Name = '$ExecutableName'")
    foreach ($process in $found) {
        if (-not $process.ExecutablePath) {
            throw 'Ein Serverprozess ist nicht lesbar. Bitte die Verwaltung unter demselben Windows-Benutzer starten.'
        }
        $executablePath = Get-CanonicalPath $process.ExecutablePath
        if ([string]::Equals($executablePath, $target, [StringComparison]::OrdinalIgnoreCase)) {
            $process
        }
    }
}

function Get-LauncherProcess {
    if (-not $DiagnosticDirectory) { return $null }
    $stateFile = Join-Path $DiagnosticDirectory 'launcher-state.json'
    if (-not (Test-Path -LiteralPath $stateFile)) { return $null }
    try {
        $record = Get-Content -LiteralPath $stateFile -Raw -Encoding UTF8 | ConvertFrom-Json
        $candidate = Get-Process -Id ([int]$record.pid) -ErrorAction SilentlyContinue
        if ($candidate -and $candidate.ProcessName -eq 'powershell' -and
            [Math]::Abs(($candidate.StartTime.ToUniversalTime() - [DateTime]::Parse($record.started).ToUniversalTime()).TotalSeconds) -lt 1) {
            return $candidate
        }
    } catch { throw 'Der gespeicherte Starterstatus ist nicht lesbar. Bitte das Starterprotokoll pruefen.' }
    return $null
}

try {
    if ($LauncherScript -and -not $DiagnosticDirectory) { $DiagnosticDirectory = Join-Path $ServerDirectory '.gameserver-indy-logs' }
    $processes = @(Get-ServerProcesses)
    $launcher = Get-LauncherProcess
    if ($Action -eq 'Start' -and $processes.Count -eq 0 -and -not $launcher) {
        $executable = Join-Path $ServerDirectory $ExecutableName
        if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) { throw 'Serverprogramm nicht gefunden.' }
        if ($LauncherScript) {
            if (-not (Test-Path -LiteralPath $LauncherScript -PathType Leaf)) { throw 'Der vorhandene Serverstarter fehlt.' }
            New-Item -ItemType Directory -Path $DiagnosticDirectory -Force | Out-Null
            $runner = Join-Path $PSScriptRoot 'run-server-launcher.ps1'
            $arguments = @('-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'RemoteSigned', '-File', ('"{0}"' -f $runner), '-LauncherScript', ('"{0}"' -f $LauncherScript), '-DiagnosticDirectory', ('"{0}"' -f $DiagnosticDirectory))
            if ($ConfigPath) { $arguments += @('-ConfigPath', ('"{0}"' -f $ConfigPath)) }
            Assert-RuntimeFence
            $launcher = Start-Process -FilePath "$PSHOME\powershell.exe" -WorkingDirectory $ServerDirectory -ArgumentList $arguments -PassThru
            @{ pid = $launcher.Id; started = $launcher.StartTime.ToUniversalTime().ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $DiagnosticDirectory 'launcher-state.json') -Encoding UTF8
            $deadline = [DateTime]::UtcNow.AddSeconds(15)
            do {
                Start-Sleep -Milliseconds 200
                $processes = @(Get-ServerProcesses)
                $launcher.Refresh()
            } while ($processes.Count -eq 0 -and -not $launcher.HasExited -and [DateTime]::UtcNow -lt $deadline)
            if ($processes.Count -eq 0 -and $launcher.HasExited) { throw ('Serverstarter beendet (Exitcode ' + $launcher.ExitCode + '). Details stehen im Starterprotokoll.') }
        } else {
            if ($SteamAppId) { $env:SteamAppId = [string]$SteamAppId; $env:SteamGameId = [string]$SteamAppId }
            if ($ConfigArgument) {
                if (-not $ConfigPath -or -not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) { throw 'Serverkonfiguration fehlt.' }
                Assert-RuntimeFence
                Start-Process -FilePath $executable -WorkingDirectory $ServerDirectory -ArgumentList @($ConfigArgument, ('"{0}"' -f $ConfigPath)) | Out-Null
            } else {
                Assert-RuntimeFence
                Start-Process -FilePath $executable -WorkingDirectory $ServerDirectory | Out-Null
            }
            Start-Sleep -Seconds 2
            $processes = @(Get-ServerProcesses)
            if ($processes.Count -eq 0) { throw 'Der Serverprozess wurde sofort beendet. Bitte das Serverlog prüfen.' }
        }
    }
    if ($Action -eq 'Stop' -and $processes.Count -gt 0) {
        if ($processes.Count -ne 1) { throw 'Mehrere Prozesse für diese Installation gefunden. Bitte die Serverfenster prüfen.' }
        # This helper owns its console attachment; the manager's terminal is unaffected.
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class GameServerConsole {
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool FreeConsole();
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool AttachConsole(uint pid);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool SetConsoleCtrlHandler(IntPtr handler, bool add);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool GenerateConsoleCtrlEvent(uint signal, uint group);
}
'@
        $serverPid = [int]$processes[0].ProcessId
        $serverProcess = Get-Process -Id $serverPid -ErrorAction Stop
        $started = $processes[0].CreationDate.ToUniversalTime()
        if ([Math]::Abs(($serverProcess.StartTime.ToUniversalTime() - $started).TotalMilliseconds) -gt 10) {
            throw 'The process identity changed before stop. No signal was sent.'
        }
        $currentPath = Get-CanonicalPath $serverProcess.MainModule.FileName
        $expectedPath = Get-CanonicalPath (Join-Path $ServerDirectory $ExecutableName)
        if (-not [string]::Equals($currentPath, $expectedPath, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'The process executable changed before stop. No signal was sent.'
        }
        Assert-RuntimeFence
        [GameServerConsole]::FreeConsole() | Out-Null
        if (-not [GameServerConsole]::AttachConsole($serverPid)) { throw 'Serverkonsole nicht erreichbar. Bitte im Serverfenster Strg+C drücken.' }
        try {
            [GameServerConsole]::SetConsoleCtrlHandler([IntPtr]::Zero, $true) | Out-Null
            if (-not [GameServerConsole]::GenerateConsoleCtrlEvent(0, 0)) { throw 'Stoppsignal konnte nicht gesendet werden.' }
            Start-Sleep -Milliseconds 500
        } finally {
            [GameServerConsole]::FreeConsole() | Out-Null
        }
        if (-not $serverProcess.WaitForExit(45000)) { throw 'Der Server beendet sich noch nicht. Bitte das Serverfenster prüfen; er wurde nicht zwangsweise beendet.' }
        if ($launcher) { $launcher.WaitForExit(5000) | Out-Null }
        $processes = @(Get-ServerProcesses)
    }
    $entries = @($processes | ForEach-Object {
        @{ pid = [int]$_.ProcessId; started = $_.CreationDate.ToUniversalTime().ToString('o')
           cpu_seconds = ([double]$_.KernelModeTime + [double]$_.UserModeTime) / 10000000
           memory_bytes = [long]$_.WorkingSetSize
           io_bytes = [double]$_.ReadTransferCount + [double]$_.WriteTransferCount }
    })
    $hostData = $null
    try {
      if ($Action -eq 'Status') {
        if ($MetricsCachePath -and [IO.File]::Exists($MetricsCachePath) -and (Get-Item -LiteralPath $MetricsCachePath).Length -le 4096) {
            try {
                $cachedMetrics = [IO.File]::ReadAllText($MetricsCachePath) | ConvertFrom-Json
                $age = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() / 1000 - [double]$cachedMetrics.observed_at
                if ($age -ge 0 -and $age -lt 8) { $hostData = $cachedMetrics }
            } catch { }
        }
        if ($null -eq $hostData) {
        $processors = @(Get-CimInstance Win32_Processor)
        $systemMemory = Get-CimInstance Win32_OperatingSystem
        $loads = @($processors | Where-Object { $null -ne $_.LoadPercentage } | ForEach-Object { [double]$_.LoadPercentage })
        $hostData = @{ logical_processors = [Environment]::ProcessorCount
            observed_at = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() / 1000
            cpu_percent = $(if ($loads.Count -gt 0) { ($loads | Measure-Object -Average).Average } else { $null })
            memory_used_bytes = ([long]$systemMemory.TotalVisibleMemorySize - [long]$systemMemory.FreePhysicalMemory) * 1024
            memory_total_bytes = [long]$systemMemory.TotalVisibleMemorySize * 1024 }
          if ($MetricsCachePath) {
            try {
                [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($MetricsCachePath)) | Out-Null
                [IO.File]::WriteAllText($MetricsCachePath, ($hostData | ConvertTo-Json -Compress))
            } catch { } # A missed/corrupt optional cache is sampled again, never used as zero.
          }
        }
      }
    } catch { } # Optional measurements never turn a successful game action into a failure.
    $launcher = Get-LauncherProcess
    @{ processes = $entries; launcher_running = [bool]$launcher; user_profile = [Environment]::GetFolderPath('UserProfile'); host_metrics = $hostData } | ConvertTo-Json -Compress -Depth 5
} catch {
    @{ error = $_.Exception.Message } | ConvertTo-Json -Compress
    exit 1
}
