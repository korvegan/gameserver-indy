$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$launcher = Join-Path $project 'scripts\start-manager.ps1'
$installer = Join-Path $project 'scripts\install-desktop-shortcut.ps1'
$temporary = Join-Path $env:TEMP ('gameserver-launcher-' + [Guid]::NewGuid().ToString('N'))
$testProcess = $null
New-Item -ItemType Directory -Path $temporary | Out-Null

function Assert-True([bool]$condition, [string]$message) {
    if (-not $condition) { throw $message }
}

try {
    $parameters = @{ WslDistribution = 'Test Distro'; WslUser = 'testuser'; ProjectDirectory = '/home/test/project with spaces' }
    $resolved = & $launcher @parameters -Describe
    Assert-True ($resolved.ProjectDirectory -eq $parameters.ProjectDirectory) 'Project directory was changed.'
    Assert-True ($resolved.RuntimeEngine -eq 'python') 'Legacy launcher no longer defaults to Python.'
    $rejected = $false
    try { & $launcher -WslDistribution Test -ProjectDirectory 'relative/path' -Describe | Out-Null } catch { $rejected = $true }
    Assert-True $rejected 'A relative project directory was accepted.'
    $rejected = $false
    try { & $launcher @parameters -RuntimeEngine symfony -Describe | Out-Null } catch { $rejected = $true }
    Assert-True $rejected 'Symfony accepted missing private runtime/state paths.'
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
    $runtime = @{ RuntimeEngine = 'symfony'; RuntimeExecutable = '/opt/private php/php'; StateDirectory = '/srv/private state' }
    & $installer @parameters @runtime -Port 18888 -ApplicationDirectory $application -DesktopDirectory $desktop | Out-Null
    & $installer -ApplicationDirectory $application -DesktopDirectory $desktop | Out-Null
    $preserved = Get-Content (Join-Path $application 'launcher.local.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-True ($preserved.RuntimeEngine -eq 'symfony' -and $preserved.RuntimeExecutable -eq $runtime.RuntimeExecutable -and $preserved.StateDirectory -eq $runtime.StateDirectory) 'Reinstall discarded the selected runtime or shared state.'
    Assert-True ($preserved.Port -eq 18888) 'Reinstall reset the configured listener port.'
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
        if (Environment.GetEnvironmentVariable("GAMESERVER_TEST_FAIL_START") == "1") {
            Console.Error.WriteLine("Expected test startup failure.");
            return;
        }
        File.WriteAllLines(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "arguments.txt"), args);
        File.WriteAllText(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "command-line.txt"), Environment.CommandLine);
        int portIndex = Array.IndexOf(args, "--port");
        int port = Int32.Parse(portIndex >= 0 ? args[portIndex + 1] : Environment.GetEnvironmentVariable("GAMESERVER_TEST_PORT"));
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
                string server = Array.IndexOf(args, "symfony/bin/indy") >= 0 ? "GameServerIndy/Symfony" : "GameServerIndy/0.2";
                if (server == "GameServerIndy/Symfony") { server = "FrankenPHP Caddy\r\nServer: " + server; }
                string overrideFile = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "server-header.txt");
                if (File.Exists(overrideFile)) { server = File.ReadAllText(overrideFile); }
                byte[] response = Encoding.ASCII.GetBytes("HTTP/1.1 200 OK\r\nServer: " + server + "\r\nContent-Type: application/json\r\nContent-Length: " + body.Length + "\r\nConnection: close\r\n\r\n" + body);
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
    $other = $arguments.Clone()
    foreach ($name in $runtime.Keys) { $other[$name] = $runtime[$name] }
    Assert-True (-not (& $launcher @other -CheckOnly).Running) 'Symfony readiness incorrectly adopted a Python listener.'
    Write-Output 'PASS: cold start, argument preservation, readiness, reuse without duplicate process.'
    Stop-Process -Id $testProcess -Force
    Wait-Process -Id $testProcess -ErrorAction SilentlyContinue
    $testProcess = $null
    $env:GAMESERVER_TEST_PORT = [string]$port
    foreach ($name in $runtime.Keys) { $arguments[$name] = $runtime[$name] }
    $started = & $launcher @arguments -NoBrowser -PassThru
    $testProcess = $started.ProcessId
    Assert-True ($started.Started -and $started.Running) 'Symfony launcher standin did not start.'
    $received = @(Get-Content -LiteralPath (Join-Path $temporary 'arguments.txt'))
    Assert-True ($received[7] -eq $runtime.RuntimeExecutable -and $received[8] -eq 'symfony/bin/indy' -and $received[9] -eq $runtime.StateDirectory) 'Symfony tool, entrypoint or state arguments were changed or split.'
    $reused = & $launcher @arguments -NoBrowser -PassThru
    Assert-True (-not $reused.Started) 'Symfony readiness launched a duplicate.'
    $pythonCheck = $arguments.Clone()
    $pythonCheck.RuntimeEngine = 'python'
    Assert-True (-not (& $launcher @pythonCheck -CheckOnly).Running) 'Python readiness adopted a combined Symfony header.'
    $headerOverride = Join-Path $temporary 'server-header.txt'
    [IO.File]::WriteAllText($headerOverride, "GameServerIndy/0.2, GameServerIndy/Symfony")
    Assert-True (-not (& $launcher @arguments -CheckOnly).Running) 'Ambiguous manager identities were accepted.'
    [IO.File]::WriteAllText($headerOverride, 'OtherServer/1.0')
    Assert-True (-not (& $launcher @arguments -CheckOnly).Running) 'An unrelated HTTP server was accepted.'
    Remove-Item -LiteralPath $headerOverride
    Stop-Process -Id $testProcess -Force
    Wait-Process -Id $testProcess -ErrorAction SilentlyContinue
    $testProcess = $null
    $arguments.RuntimeEngine = 'python'
    $started = & $launcher @arguments -NoBrowser -PassThru
    $testProcess = $started.ProcessId
    $received = @(Get-Content -LiteralPath (Join-Path $temporary 'arguments.txt'))
    $stateIndex = [Array]::IndexOf($received, '--state-dir')
    Assert-True ($received[7] -eq 'python3' -and $stateIndex -ge 0 -and $received[$stateIndex + 1] -eq $runtime.StateDirectory) 'Python fallback did not use exactly the same shared state.'
    Stop-Process -Id $testProcess -Force
    Wait-Process -Id $testProcess -ErrorAction SilentlyContinue
    $testProcess = $null
    Write-Output 'PASS: Symfony entrypoint, private paths with spaces, readiness/reuse and Python fallback against the same state.'
    $env:GAMESERVER_TEST_FAIL_START = '1'
    $rejected = $false
    try { & $launcher @arguments -NoBrowser | Out-Null } catch { $rejected = $_.Exception.Message.Contains('stderr.log') }
    Assert-True $rejected 'An exited manager was not reported with a diagnostic log.'
    Write-Output 'PASS: startup failure reports the diagnostic log.'
} finally {
    Remove-Item Env:\GAMESERVER_TEST_FAIL_START -ErrorAction SilentlyContinue
    Remove-Item Env:\GAMESERVER_TEST_PORT -ErrorAction SilentlyContinue
    if ($testProcess) { Stop-Process -Id $testProcess -Force -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $temporary -Recurse -Force
}
