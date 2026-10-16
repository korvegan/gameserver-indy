param([Parameter(Mandatory=$true)][string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
# Only called by slice:init after checking its isolated runtime marker.
if (-not (Test-Path -LiteralPath (Join-Path (Split-Path $OutputDirectory -Parent) '.indy-symfony-slice'))) {
    throw 'Isolated runtime marker missing.'
}
$source = Join-Path $PSScriptRoot '../tests/Fixtures/Standin.cs'
$compiled = $null
foreach ($id in @('demo-a', 'demo-b')) {
    $directory = Join-Path $OutputDirectory $id
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
    $target = Join-Path $directory 'indy-symfony-standin.exe'
    $checksum = Join-Path $directory 'fixture.sha256'
    if (-not (Test-Path -LiteralPath $target)) {
        if ($compiled) { [IO.File]::Copy($compiled, $target) }
        else { Add-Type -Path $source -OutputAssembly $target -OutputType ConsoleApplication }
    }
    $compiled = $target
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { $hash = [BitConverter]::ToString($algorithm.ComputeHash([IO.File]::ReadAllBytes($target))).Replace('-', '').ToLowerInvariant() }
    finally { $algorithm.Dispose() }
    if (Test-Path -LiteralPath $checksum) {
        if (([IO.File]::ReadAllText($checksum)).Trim() -ne $hash) { throw 'Existing stand-in executable was changed.' }
    } else { [IO.File]::WriteAllText($checksum, $hash) }
}
