param(
    [ValidateSet('Status', 'Start', 'Stop')][string]$Action = 'Status',
    [Parameter(Mandatory = $true)][string]$ServerDirectory
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)

function Get-ServerProcesses {
    $target = [IO.Path]::GetFullPath((Join-Path $ServerDirectory 'enshrouded_server.exe'))
    $found = @(Get-CimInstance Win32_Process -Filter "Name = 'enshrouded_server.exe'")
    foreach ($process in $found) {
        if (-not $process.ExecutablePath) {
            throw 'Ein Serverprozess ist nicht lesbar. Bitte die Verwaltung unter demselben Windows-Benutzer starten.'
        }
        if ([string]::Equals($process.ExecutablePath, $target, [StringComparison]::OrdinalIgnoreCase)) {
            $process
        }
    }
}

try {
    $processes = @(Get-ServerProcesses)
    if ($Action -eq 'Start' -and $processes.Count -eq 0) {
        $executable = Join-Path $ServerDirectory 'enshrouded_server.exe'
        if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) { throw 'Serverprogramm nicht gefunden.' }
        Start-Process -FilePath $executable -WorkingDirectory $ServerDirectory | Out-Null
        Start-Sleep -Seconds 2
        $processes = @(Get-ServerProcesses)
        if ($processes.Count -eq 0) { throw 'Der Serverprozess wurde sofort beendet. Bitte das Serverlog prüfen.' }
    }
    if ($Action -eq 'Stop' -and $processes.Count -gt 0) {
        if ($processes.Count -ne 1) { throw 'Mehrere Prozesse für diese Installation gefunden. Bitte die Serverfenster prüfen.' }
        # This helper owns its console attachment; the manager's terminal is unaffected.
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class EnshroudedConsole {
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool FreeConsole();
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool AttachConsole(uint pid);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool SetConsoleCtrlHandler(IntPtr handler, bool add);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool GenerateConsoleCtrlEvent(uint signal, uint group);
}
'@
        $serverPid = [int]$processes[0].ProcessId
        $serverProcess = Get-Process -Id $serverPid -ErrorAction Stop
        [EnshroudedConsole]::FreeConsole() | Out-Null
        if (-not [EnshroudedConsole]::AttachConsole($serverPid)) { throw 'Serverkonsole nicht erreichbar. Bitte im Serverfenster Strg+C drücken.' }
        try {
            [EnshroudedConsole]::SetConsoleCtrlHandler([IntPtr]::Zero, $true) | Out-Null
            if (-not [EnshroudedConsole]::GenerateConsoleCtrlEvent(0, 0)) { throw 'Stoppsignal konnte nicht gesendet werden.' }
            Start-Sleep -Milliseconds 500
        } finally {
            [EnshroudedConsole]::FreeConsole() | Out-Null
        }
        if (-not $serverProcess.WaitForExit(45000)) { throw 'Der Server beendet sich noch nicht. Bitte das Serverfenster prüfen; er wurde nicht zwangsweise beendet.' }
        $processes = @(Get-ServerProcesses)
    }
    $entries = @($processes | ForEach-Object {
        @{ pid = [int]$_.ProcessId; started = $_.CreationDate.ToUniversalTime().ToString('o') }
    })
    @{ processes = $entries } | ConvertTo-Json -Compress -Depth 5
} catch {
    @{ error = $_.Exception.Message } | ConvertTo-Json -Compress
    exit 1
}
