param([string]$ExportDirectory = '', [string]$CleanupManifest = '')
$ErrorActionPreference = 'Stop'
if ($CleanupManifest) {
    $manifest = Get-Content -LiteralPath $CleanupManifest -Raw | ConvertFrom-Json -AsHashtable
    $temporary = $manifest.temporary
    if ([IO.Path]::GetDirectoryName($temporary) -ine ([IO.Path]::GetTempPath()).TrimEnd('\') -or
        [IO.Path]::GetFileName($temporary) -cnotmatch '^indy-bridge-test-[a-f0-9]{32}$') { throw 'Invalid cleanup root' }
    foreach ($candidate in @(Get-Process -Name AskaServer,enshrouded_server -ErrorAction SilentlyContinue)) {
        $path = $candidate.MainModule.FileName
        if ($manifest.hashes.Contains($path) -and (Get-FileHash -LiteralPath $path).Hash -eq $manifest.hashes[$path]) {
            $candidate.Kill(); $candidate.WaitForExit(); $candidate.Dispose()
        }
    }
    Remove-Item -LiteralPath $temporary -Recurse -Force
    exit
}
$bridge = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../windows/Bridge.ps1'))
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('indy-bridge-test-' + [guid]::NewGuid().ToString('N'))
$root = Join-Path $temporary 'state'
$owned = @()
$exported = $false
function Check($Condition, [string]$Message) { if (-not $Condition) { throw $Message } }
function Json([string]$Path, $Value) { [IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json -Depth 8 -Compress)) }
function Start-Call([string]$Server, [string]$Action, [string]$Operation = '') {
    $nonce = [guid]::NewGuid().ToString('N')
    $op = if ($Operation) { $Operation } else { $null }
    Json (Join-Path $root "request-$nonce.json") @{version=1;server=$Server;action=$Action;operation=$op}
    $child = Start-Process -FilePath (Join-Path $PSHOME 'pwsh.exe') -ArgumentList ('-NoProfile -NonInteractive -File "' + $bridge + '" -StateDirectory "' + $root + '" -Request ' + $nonce) -RedirectStandardOutput (Join-Path $root "$nonce.stdout") -RedirectStandardError (Join-Path $root "$nonce.stderr") -WindowStyle Minimized -PassThru
    return @{child=$child;nonce=$nonce}
}
function End-Call($Job) {
    Check ($Job.child.WaitForExit(60000) -and $Job.child.ExitCode -eq 0) 'Bridge invocation failed'
    $Job.child.Dispose()
    return (Get-Content -LiteralPath (Join-Path $root ('reply-' + $Job.nonce + '.json')) -Raw | ConvertFrom-Json -AsHashtable)
}
function Call([string]$Server, [string]$Action, [string]$Operation = '') {
    return End-Call (Start-Call $Server $Action $Operation)
}
function Await-File([string]$Path) {
    $deadline = [DateTime]::UtcNow.AddSeconds(10)
    while (-not [IO.File]::Exists($Path) -and [DateTime]::UtcNow -lt $deadline) { Start-Sleep -Milliseconds 50 }
    Check ([IO.File]::Exists($Path)) 'Fixture marker did not arrive'
}
function Op { return [guid]::NewGuid().ToString('N') }
try {
    [IO.Directory]::CreateDirectory($root) | Out-Null
    $fixtures = @{}
    foreach ($server in @('enshrouded','aska','foreign')) {
        $folder = Join-Path $temporary ($server + ' with spaces')
        [IO.Directory]::CreateDirectory($folder) | Out-Null
        $name = if ($server -eq 'aska') { 'AskaServer.exe' } else { 'enshrouded_server.exe' }
        $executable = Join-Path $folder $name
        & (Join-Path $env:WINDIR 'Microsoft.NET/Framework64/v4.0.30319/csc.exe') /nologo /target:exe /out:$executable (Join-Path $PSScriptRoot 'BridgeStandin.cs')
        Check ($LASTEXITCODE -eq 0) 'Standin compilation failed'
        $world = Join-Path $folder 'data/server/savegame_fixture'
        [IO.Directory]::CreateDirectory($world) | Out-Null
        [IO.File]::WriteAllText((Join-Path $world 'world.bin'), 'synthetic existing world')
        $configuration = Join-Path $folder $(if ($server -eq 'aska') { 'server properties.txt' } else { 'enshrouded_server.json' })
        if ($server -eq 'aska') { [IO.File]::WriteAllText($configuration, "save id = fixture`npassword = synthetic-private`nauthentication token = synthetic-token`n") }
        else { Json $configuration @{saveDirectory=$world} }
        $fixtures[$server] = @{executable=$executable;configuration=$configuration;world_directory=$world;save_id='fixture';stop_timeout_ms=5000}
    }
    $settings = @{version=1;owner_sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value;profiles=@{enshrouded=$fixtures.enshrouded;aska=$fixtures.aska}}
    Json (Join-Path $root 'profiles.local.json') $settings
    $hashes = @{}
    foreach ($f in $fixtures.Values) {
        foreach ($path in @($f.configuration,(Join-Path $f.world_directory 'world.bin'),$f.executable)) { $hashes[$path]=(Get-FileHash -LiteralPath $path).Hash }
    }
    $foreign = Start-Process -FilePath $fixtures.foreign.executable -WorkingDirectory ([IO.Path]::GetDirectoryName($fixtures.foreign.executable)) -WindowStyle Minimized -PassThru
    $owned += $foreign
    $before = Call 'enshrouded' 'status'
    Check ($before.process -eq 'stopped' -and $null -eq $before.ready) 'Foreign EXE name mistaken for target'
    foreach ($server in @('enshrouded','aska')) {
        $id = Op
        $result = Call $server 'start' $id
        Check ($result.outcome -eq 'succeeded' -and $result.process -eq 'running' -and $null -eq $result.ready) ('Start failed: ' + ($result | ConvertTo-Json -Compress))
        $owned += Get-Process -Id $result.identity.pid
        $again = Call $server 'start' $id
        Check ($again.identity.pid -eq $result.identity.pid) 'Same operation repeated a start'
        $newClient = Call $server 'status'
        Check ($newClient.identity.pid -eq $result.identity.pid) 'Process died with helper'
        $directory = [IO.Path]::GetDirectoryName($fixtures[$server].executable)
        Check (@([IO.File]::ReadAllLines((Join-Path $directory 'starts.txt'))).Count -eq 1) 'Duplicate game process'
        if ($server -eq 'aska') {
            Check ((Get-Content -LiteralPath (Join-Path $directory 'arguments.txt') -Raw) -ceq ("-propertiesPath`n" + $fixtures.aska.configuration)) 'ASKA configuration argument split'
            Check ((Get-Content -LiteralPath (Join-Path $directory 'steam.txt') -Raw) -ceq "1898300`n1898300") 'ASKA Steam identity missing'
        }
        $stopId = Op
        $stopped = Call $server 'stop' $stopId
        Check ($stopped.outcome -eq 'succeeded' -and $stopped.process -eq 'stopped') ('Graceful stop failed: '+($stopped|ConvertTo-Json -Compress))
        Check ([IO.File]::ReadAllText((Join-Path $directory 'saved.txt')) -eq [string]$result.identity.pid) 'No graceful save marker'
        $oldStart = Call $server 'start' $id
        Check ($oldStart.process -eq 'stopped' -and $oldStart.outcome -eq 'succeeded') 'Completed start was executed again'
    }
    $directory = [IO.Path]::GetDirectoryName($fixtures.enshrouded.executable)
    $pair = @((Start-Call 'enshrouded' 'start' (Op)), (Start-Call 'enshrouded' 'start' (Op)))
    $pairResults = @($pair | ForEach-Object { End-Call $_ })
    Check (@($pairResults | Where-Object { $_.outcome -eq 'succeeded' }).Count -ge 1) 'Concurrent starts failed'
    Check (@([IO.File]::ReadAllLines((Join-Path $directory 'starts.txt'))).Count -eq 2) 'Concurrent starts launched duplicate processes'
    $running = Call 'enshrouded' 'status'
    $owned += Get-Process -Id $running.identity.pid
    Remove-Item -LiteralPath (Join-Path $directory 'signal.txt')
    [IO.File]::WriteAllText((Join-Path $directory 'ignore-stop'), '')
    $timeoutId = Op
    $slow = Start-Call 'enshrouded' 'stop' $timeoutId
    Await-File (Join-Path $directory 'signal.txt')
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $status = Call 'enshrouded' 'status'
    Check ($watch.Elapsed.TotalSeconds -lt 3 -and $status.process -eq 'running') 'Status waited for stop lock'
    $other = Call 'aska' 'start' (Op)
    Check ($other.outcome -eq 'succeeded') 'Other game blocked by slow stop'
    $owned += Get-Process -Id $other.identity.pid
    $timedOut = End-Call $slow
    Check ($timedOut.outcome -eq 'unknown' -and $timedOut.error -eq 'stop_timeout') 'Stop timeout was presented as failure/success'
    $signals = [IO.File]::ReadAllText((Join-Path $directory 'signal.txt'))
    Check ((Call 'enshrouded' 'start' (Op)).error -eq 'server_busy') 'Unknown outcome allowed another action'
    Check ((Call 'enshrouded' 'stop' $timeoutId).outcome -eq 'unknown') 'Unknown stop was sent again'
    Check ([IO.File]::ReadAllText((Join-Path $directory 'signal.txt')) -eq $signals) 'Unknown replay sent another stop signal'
    [IO.File]::WriteAllText((Join-Path $directory 'release-stop'), '')
    $owned[-2].WaitForExit(5000) | Out-Null
    Check ((Call 'enshrouded' 'status' $timeoutId).outcome -eq 'succeeded') 'Observed exit did not reconcile unknown stop'
    $askaDirectory = [IO.Path]::GetDirectoryName($fixtures.aska.executable)
    Remove-Item -LiteralPath (Join-Path $askaDirectory 'signal.txt')
    [IO.File]::WriteAllText((Join-Path $askaDirectory 'ignore-stop'), '')
    $crashId = Op
    $crashed = Start-Call 'aska' 'stop' $crashId
    Await-File (Join-Path $askaDirectory 'signal.txt')
    $crashed.child.Kill(); $crashed.child.WaitForExit(); $crashed.child.Dispose()
    Check ((Call 'aska' 'status' $crashId).outcome -eq 'unknown') 'Helper death lost unknown receipt'
    Check ((Call 'aska' 'start' (Op)).error -eq 'server_busy') 'Helper death released unresolved game'
    [IO.File]::WriteAllText((Join-Path $askaDirectory 'release-stop'), '')
    $owned[-1].WaitForExit(5000) | Out-Null
    Check ((Call 'aska' 'status' $crashId).outcome -eq 'succeeded') 'Helper death could not reconcile target exit'
    Remove-Item -LiteralPath (Join-Path $askaDirectory 'ignore-stop'),(Join-Path $askaDirectory 'release-stop')
    Remove-Item -LiteralPath (Join-Path $directory 'release-stop'),(Join-Path $directory 'ignore-stop')
    [IO.File]::WriteAllText((Join-Path $directory 'share-console'), $fixtures.foreign.executable)
    $shared = Call 'enshrouded' 'start' (Op)
    $owned += Get-Process -Id $shared.identity.pid
    Await-File (Join-Path $directory 'peer.pid')
    $owned += Get-Process -Id ([int][IO.File]::ReadAllText((Join-Path $directory 'peer.pid')))
    $beforeSignal = [IO.File]::ReadAllText((Join-Path $directory 'signal.txt'))
    $refused = Call 'enshrouded' 'stop' (Op)
    Check ($refused.error -eq 'unsafe_console' -and $refused.process -eq 'running') 'Shared console was signalled'
    Check ([IO.File]::ReadAllText((Join-Path $directory 'signal.txt')) -eq $beforeSignal) 'Refusal sent a console signal'
    Remove-Item -LiteralPath (Join-Path $directory 'share-console')
    $foreign.Refresh(); Check (-not $foreign.HasExited) 'Foreign process was stopped'
    $bad = Call '../escape' 'status'
    Check ($bad.error -eq 'invalid_request') 'Unknown server accepted'
    $savedConfig = [IO.File]::ReadAllText($fixtures.aska.configuration)
    [IO.File]::WriteAllText($fixtures.aska.configuration, 'save id = wrong')
    $bad = Call 'aska' 'start' (Op)
    Check ($bad.error -eq 'world_mismatch') 'Wrong ASKA world accepted'
    [IO.File]::WriteAllText($fixtures.aska.configuration, $savedConfig)
    $settings.owner_sid = 'S-1-5-18'
    Json (Join-Path $root 'profiles.local.json') $settings
    Check ((Call 'aska' 'status').error -eq 'owner_mismatch') 'Wrong Windows owner accepted'
    $settings.owner_sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    Json (Join-Path $root 'profiles.local.json') $settings
    foreach ($path in $hashes.Keys) { Check ((Get-FileHash -LiteralPath $path).Hash -eq $hashes[$path]) 'Existing fixture bytes changed' }
    if ($ExportDirectory) {
        Json (Join-Path $ExportDirectory 'windows-fixture.json') @{root=$root;temporary=$temporary;pwsh=(Join-Path $PSHOME 'pwsh.exe');bridge=$bridge;hashes=$hashes}
        $exported = $true
        Write-Output 'PASS: native bridge fixtures prepared for WSL transport integration'
    } else { Write-Output 'PASS: PowerShell 7 native lifecycle, concurrency, unknown reconciliation, ASKA binding, foreign/shared-console refusal and byte preservation' }
} finally {
    # Also collect a start whose assertion failed before the reply PID was registered.
    foreach ($p in @(Get-Process -Name AskaServer,enshrouded_server -ErrorAction SilentlyContinue)) {
        if ($fixtures -and $hashes.ContainsKey($p.MainModule.FileName)) { $owned += $p }
    }
    foreach ($p in $owned) {
        $p.Refresh()
        if (-not $p.HasExited) {
            $expected = $fixtures.Values | Where-Object { $_.executable -ieq $p.MainModule.FileName }
            Check ($expected -and (Get-FileHash -LiteralPath $p.MainModule.FileName).Hash -eq $hashes[$p.MainModule.FileName]) 'Own fixture identity changed'
            # Emergency cleanup is limited to hash-proven disposable test binaries, never game installations.
            $p.Kill(); $p.WaitForExit()
        }
        $p.Dispose()
    }
    if (-not $exported -and [IO.Directory]::Exists($temporary)) { Remove-Item -LiteralPath $temporary -Recurse -Force }
}
