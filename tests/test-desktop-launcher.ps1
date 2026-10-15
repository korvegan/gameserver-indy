$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$launcher = Join-Path $project 'scripts\start-manager.ps1'
$installer = Join-Path $project 'scripts\install-desktop-shortcut.ps1'
$temporary = Join-Path $env:TEMP ('enshrouded-launcher-' + [Guid]::NewGuid().ToString('N'))
$testProcess = $null
New-Item -ItemType Directory -Path $temporary | Out-Null

function Assert-True([bool]$condition, [string]$message) {
    if (-not $condition) { throw $message }
}

try {
    $parameters = @{ WslDistribution = 'Test Distro'; WslUser = 'testuser'; ProjectDirectory = '/home/test/project with spaces' }
    $resolved = & $launcher @parameters -Describe
    Assert-True ($resolved.ProjectDirectory -eq $parameters.ProjectDirectory) 'Project directory was changed.'
    $rejected = $false
    try { & $launcher -WslDistribution Test -ProjectDirectory 'relative/path' -Describe | Out-Null } catch { $rejected = $true }
    Assert-True $rejected 'A relative project directory was accepted.'
    $application = Join-Path $temporary 'App Data'
    $desktop = Join-Path $temporary 'Desktop'
    $first = & $installer @parameters -ApplicationDirectory $application -DesktopDirectory $desktop
    $second = & $installer @parameters -ApplicationDirectory $application -DesktopDirectory $desktop
    Assert-True ($first.Shortcut -eq $second.Shortcut) 'Repeated installation created another shortcut.'
    $installedLauncher = Join-Path $application 'Start-Manager.ps1'
    $installedBytes = [Convert]::ToBase64String([IO.File]::ReadAllBytes($installedLauncher))
    $sourceBytes = [Convert]::ToBase64String([IO.File]::ReadAllBytes($launcher))
    Assert-True ($installedBytes -eq $sourceBytes) 'Installed launcher differs from source.'
    $settings = Get-Content (Join-Path $application 'launcher.local.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-True ($settings.WslDistribution -eq 'Test Distro') 'Distribution was not saved.'
    Assert-True ($settings.ProjectDirectory -eq $parameters.ProjectDirectory) 'Project directory was not saved.'
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($first.Shortcut)
    Assert-True ($shortcut.Arguments.Contains('-File "' + $installedLauncher + '"')) 'Shortcut does not quote its path.'
    $shortcut.Arguments = '-NoProfile -Command Write-Output unrelated'
    $shortcut.Save()
    $rejected = $false
    try { & $installer @parameters -ApplicationDirectory $application -DesktopDirectory $desktop | Out-Null } catch { $rejected = $true }
    Assert-True $rejected 'An unrelated shortcut would be overwritten.'
    Write-Output 'PASS: settings, paths with spaces, repeat installation, shortcut readback, preservation of unrelated shortcuts.'

    # This temporary HTTP program stands in for wsl.exe; no real WSL/game process starts.
    $source = Join-Path $temporary 'standin.cs'
    @'
using System;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
public static class StandIn {
    public static void Main(string[] args) {
        if (Environment.GetEnvironmentVariable("ENSHROUDED_TEST_FAIL_START") == "1") {
            Console.Error.WriteLine("Expected test startup failure.");
            return;
        }
        File.WriteAllLines(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "arguments.txt"), args);
        File.WriteAllText(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "command-line.txt"), Environment.CommandLine);
        int port = Int32.Parse(args[Array.IndexOf(args, "--port") + 1]);
        var timer = new Timer(state => Environment.Exit(0), null, 90000, Timeout.Infinite);
        var listener = new TcpListener(IPAddress.Loopback, port);
        listener.Start();
        while (true) {
            using (var client = listener.AcceptTcpClient())
            using (var stream = client.GetStream()) {
                stream.ReadTimeout = 2000;
                var buffer = new byte[8192];
                stream.Read(buffer, 0, buffer.Length);
                string body = "{\"token\":\"test\"}";
                byte[] response = Encoding.ASCII.GetBytes("HTTP/1.1 200 OK\r\nServer: EnshroudedIndy/0.1\r\nContent-Type: application/json\r\nContent-Length: " + body.Length + "\r\nConnection: close\r\n\r\n" + body);
                stream.Write(response, 0, response.Length);
            }
            GC.KeepAlive(timer);
        }
    }
}
'@ | Set-Content -LiteralPath $source -Encoding UTF8
    $executable = Join-Path $temporary 'fake wsl.exe'
    Add-Type -Path $source -OutputAssembly $executable -OutputType ConsoleApplication
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $port = $listener.LocalEndpoint.Port
    $listener.Stop()
    $arguments = $parameters.Clone()
    $arguments.Port = $port
    $arguments.WslExecutable = $executable
    $arguments.LogDirectory = Join-Path $temporary 'Logs'
    $check = & $launcher @arguments -CheckOnly
    Assert-True (-not $check.Running) 'Test port was unexpectedly in use.'
    $started = & $launcher @arguments -NoBrowser -PassThru
    $testProcess = $started.ProcessId
    Assert-True ($started.Running -and $started.Started) 'Cold start failed.'
    $received = @(Get-Content -LiteralPath (Join-Path $temporary 'arguments.txt'))
    Assert-True ($received[1] -eq 'Test Distro') 'A distribution name with spaces was split.'
    Assert-True ($received[5] -eq $parameters.ProjectDirectory) 'A project path with spaces was split.'
    $commandLine = Get-Content -LiteralPath (Join-Path $temporary 'command-line.txt') -Raw
    Assert-True (-not $commandLine.Contains('"--distribution"')) 'WSL options must not be quoted.'
    $reused = & $launcher @arguments -NoBrowser -PassThru
    Assert-True ($reused.Running -and -not $reused.Started) 'A running manager was launched again.'
    Write-Output 'PASS: cold start, argument preservation, readiness, reuse without duplicate process.'
    Stop-Process -Id $testProcess -Force
    Wait-Process -Id $testProcess -ErrorAction SilentlyContinue
    $testProcess = $null
    $env:ENSHROUDED_TEST_FAIL_START = '1'
    $rejected = $false
    try { & $launcher @arguments -NoBrowser | Out-Null } catch { $rejected = $_.Exception.Message.Contains('stderr.log') }
    Assert-True $rejected 'An exited manager was not reported with a diagnostic log.'
    Write-Output 'PASS: startup failure reports the diagnostic log.'
} finally {
    Remove-Item Env:\ENSHROUDED_TEST_FAIL_START -ErrorAction SilentlyContinue
    if ($testProcess) { Stop-Process -Id $testProcess -Force -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
