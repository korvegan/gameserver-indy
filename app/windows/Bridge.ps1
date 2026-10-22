param(
    [Parameter(Mandatory)][string]$StateDirectory,
    [Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{32}$')][string]$Request
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = [IO.Path]::GetFullPath($StateDirectory)
$requestFile = Join-Path $root "request-$Request.json"
$replyFile = Join-Path $root "reply-$Request.json"
$reply = @{version=1;server=$null;action=$null;operation=$null;outcome='failed';error='invalid_request';
    process='unknown';ready=$null;observed_at=$null;identity=$null}
$lock = $null
$receipt = $null
$receiptFile = $null
$touched = $false

function Read-Json([string]$Path, [int]$Limit = 16384) {
    if ((Get-Item -LiteralPath $Path).Length -gt $Limit) { throw 'invalid_request' }
    $value = [IO.File]::ReadAllText($Path) | ConvertFrom-Json -AsHashtable -DateKind String
    if ($value -isnot [System.Collections.IDictionary]) { throw 'invalid_request' }
    return $value
}
function Write-Json([string]$Path, $Value) {
    $temporary = $Path + '.' + [guid]::NewGuid().ToString('N')
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes(($Value | ConvertTo-Json -Depth 8 -Compress))
        $stream = [IO.File]::Open($temporary, 'CreateNew', 'Write', 'None')
        try { $stream.Write($bytes); $stream.Flush($true) } finally { $stream.Dispose() }
        [IO.File]::Move($temporary, $Path, $true)
    } finally { if ([IO.File]::Exists($temporary)) { [IO.File]::Delete($temporary) } }
}
function Identity($Process) {
    return @{pid=$Process.Id;started=$Process.StartTime.ToUniversalTime().ToString('o')}
}
function Observe {
    $reply.process = 'unknown'; $reply.identity = $null; $reply.observed_at = $null
    $found = @()
    foreach ($candidate in [Diagnostics.Process]::GetProcessesByName([IO.Path]::GetFileNameWithoutExtension($executable))) {
        try {
            if ($candidate.HasExited) { continue }
            if ([string]::Equals([IndyNativeConsole]::Canonical($candidate.MainModule.FileName), $executable, 'OrdinalIgnoreCase')) {
                if (-not [IndyNativeConsole]::OwnedBy($candidate, $settings.owner_sid)) { throw 'owner_mismatch' }
                $found += $candidate
            }
        } catch {
            if ($_.Exception.Message -ceq 'owner_mismatch') { throw 'owner_mismatch' }
            throw 'process_unreadable'
        }
    }
    if ($found.Count -gt 1) { throw 'ambiguous_process' }
    $reply.process = if ($found.Count) { 'running' } else { 'stopped' }
    $reply.identity = if ($found.Count) { Identity $found[0] } else { $null }
    $reply.observed_at = [DateTime]::UtcNow.ToString('o')
    return $found
}
function Receipt-Path([string]$Id) { return Join-Path $root ($server + '-' + $Id + '.json') }
function Read-Receipt([string]$Id) {
    try { $record = Read-Json (Receipt-Path $Id) } catch { throw 'invalid_state' }
    if ($record.version -ne 1 -or $record.server -cne $server -or $record.operation -cne $Id -or
        $record.action -cnotin @('start','stop') -or $record.outcome -cnotin @('unknown','succeeded','failed') -or
        $record.error -isnot [string] -or ($null -ne $record.target -and
            ($record.target.pid -isnot [long] -or $record.target.pid -lt 1 -or
             $record.target.started -isnot [string] -or $record.target.started -cnotmatch '^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{7}Z$'))) { throw 'invalid_state' }
    return $record
}
function Finish([string]$Outcome, [string]$Code = '') {
    $receipt.outcome = $Outcome; $receipt.error = $Code
    Write-Json $receiptFile $receipt
    $reply.outcome = $Outcome; $reply.error = $Code
}
function Reconcile($Record, [string]$Path) {
    if ($Record.outcome -ne 'unknown' -or -not $Record.target) { return }
    $same = $reply.identity -and $reply.identity.pid -eq $Record.target.pid -and
        $reply.identity.started -ceq $Record.target.started
    if (($Record.action -eq 'start' -and $same) -or ($Record.action -eq 'stop' -and -not $same)) {
        $Record.outcome = 'succeeded'; $Record.error = ''
        Write-Json $Path $Record
    } elseif ($Record.action -eq 'start' -and $reply.process -eq 'stopped') {
        $Record.outcome = 'failed'; $Record.error = 'process_exited'
        Write-Json $Path $Record
    }
}
try {
    if (-not $IsWindows -or $PSVersionTable.PSVersion -lt [version]'7.6') { throw 'unsupported_runtime' }
    $inputData = Read-Json $requestFile 1024
    if ($inputData.Count -ne 4 -or @($inputData.Keys | Where-Object { $_ -cnotin @('version','server','action','operation') }).Count -or
        $inputData.version -isnot [long] -or $inputData.version -ne 1 -or $inputData.server -cnotin @('enshrouded','aska') -or
        $inputData.action -cnotin @('status','start','stop') -or
        ($null -ne $inputData.operation -and $inputData.operation -cnotmatch '^[a-f0-9]{32}$') -or
        ($inputData.action -ne 'status' -and -not $inputData.operation)) { throw 'invalid_request' }
    $server = $inputData.server; $action = $inputData.action
    $reply.server = $server; $reply.action = $action; $reply.operation = $inputData.operation
    $settings = Read-Json (Join-Path $root 'profiles.local.json') 65536
    if ($settings.version -ne 1 -or $settings.owner_sid -cne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { throw 'owner_mismatch' }
    $profile = $settings.profiles[$server]
    $expectedName = if ($server -eq 'aska') { 'AskaServer.exe' } else { 'enshrouded_server.exe' }
    if (-not $profile -or $profile.executable -isnot [string] -or
        [IO.Path]::GetFileName($profile.executable) -ine $expectedName -or
        -not [IO.Path]::IsPathFullyQualified($profile.executable) -or
        $profile.configuration -isnot [string] -or -not [IO.Path]::IsPathFullyQualified($profile.configuration) -or
        $profile.world_directory -isnot [string] -or -not [IO.Path]::IsPathFullyQualified($profile.world_directory) -or
        $profile.configuration.Contains('"') -or $profile.configuration.Contains("`n") -or
        $profile.stop_timeout_ms -isnot [long] -or $profile.stop_timeout_ms -lt 100 -or $profile.stop_timeout_ms -gt 45000) { throw 'invalid_profile' }
    Add-Type -Path (Join-Path $PSScriptRoot 'NativeConsole.cs')
    . (Join-Path $PSScriptRoot 'GameProfile.ps1')
    $executable = [IndyNativeConsole]::Canonical($profile.executable)
    $working = [IO.Path]::GetDirectoryName($executable)
    $processes = @(Observe)
    $reply.outcome = 'succeeded'; $reply.error = ''
    # Status never waits for a mutation's lock, and never sends a game signal.
    if ($action -ne 'status' -or $inputData.operation) {
        try { $lock = [IO.File]::Open((Join-Path $root ($server + '.lock')), 'OpenOrCreate', 'ReadWrite', 'None') }
        catch [IO.IOException] {
            if ($action -ne 'status') { $reply.outcome='busy'; $reply.error='server_busy' }
        }
    }
    $activeFile = Join-Path $root ($server + '-active.json')
    $active = $null
    if ([IO.File]::Exists($activeFile)) {
        $active = Read-Json $activeFile
        if ($active.id -cnotmatch '^[a-f0-9]{32}$') { throw 'invalid_state' }
        $activePath = Receipt-Path $active.id
        if (-not [IO.File]::Exists($activePath)) { throw 'outcome_unresolved' }
        $activeReceipt = Read-Receipt $active.id
        if ($lock -and $activeReceipt.outcome -eq 'unknown') {
            $processes = @(Observe)
            Reconcile $activeReceipt $activePath
        }
    }
    if ($inputData.operation) {
        $receiptFile = Receipt-Path $inputData.operation
        if ([IO.File]::Exists($receiptFile)) {
            $receipt = Read-Receipt $inputData.operation
            if ($receipt.server -cne $server -or ($action -ne 'status' -and $receipt.action -cne $action)) { throw 'operation_mismatch' }
            if ($lock -and $receipt.outcome -eq 'unknown') {
                $processes = @(Observe)
                Reconcile $receipt $receiptFile
            }
            $reply.outcome=$receipt.outcome; $reply.error=$receipt.error
        } elseif ($action -eq 'status') { $reply.outcome='unknown'; $reply.error='outcome_unresolved' }
    }
    if ($action -ne 'status' -and -not $receipt -and $lock) {
        if ($active -and $activeReceipt.outcome -eq 'unknown') { throw 'server_busy' }
        $processes = @(Observe)
        if ($action -eq 'start') { Assert-World $server $profile $working }
        # Persist uncertainty before the side effect; a crash must never make a retry look new.
        Write-Json $activeFile @{id=$inputData.operation}
        $receipt = @{version=1;server=$server;action=$action;operation=$inputData.operation;outcome='unknown';error='outcome_unresolved';target=$null}
        Write-Json $receiptFile $receipt
        if ($action -eq 'start' -and $processes.Count -eq 0) {
            $arguments = ''
            if ($server -eq 'aska') {
                $arguments = '-propertiesPath "' + $profile.configuration + '"'
                $env:SteamAppId = '1898300'; $env:SteamGameId = '1898300'
            }
            $touched = $true
            $target = [IndyNativeConsole]::Start($executable, $arguments, $working)
            $receipt.target = Identity $target
            Write-Json $receiptFile $receipt
            if ($target.WaitForExit(300)) { Finish 'failed' 'process_exited' } else { Finish 'succeeded' }
        } elseif ($action -eq 'stop' -and $processes.Count -eq 1) {
            $target=$processes[0]; $receipt.target=Identity $target
            Write-Json $receiptFile $receipt
            $touched = $true
            $code = [IndyNativeConsole]::Interrupt($target, $executable, $receipt.target.started)
            if ($code) { Finish 'failed' $code }
            else {
                $touched = $true
                if ($target.WaitForExit([int]$profile.stop_timeout_ms)) { Finish 'succeeded' }
                else { Finish 'unknown' 'stop_timeout' }
            }
        } else { Finish 'succeeded' }
        $null = @(Observe)
    }
} catch {
    $codes = @('invalid_request','invalid_profile','owner_mismatch','process_unreadable','ambiguous_process','world_mismatch',
        'operation_mismatch','invalid_state','outcome_unresolved','server_busy','unsupported_runtime')
    $code = if ($_.Exception.Message -cin $codes) { $_.Exception.Message } else { 'native_error' }
    $reply.outcome = if ($touched -or $code -in @('outcome_unresolved','invalid_state')) { 'unknown' } elseif ($code -eq 'server_busy') { 'busy' } else { 'failed' }
    $reply.error = $code
} finally {
    if ($lock) { $lock.Dispose() }
}
Write-Json $replyFile $reply
