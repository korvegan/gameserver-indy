param([Parameter(Mandatory = $true)][string]$StateDirectory)
$ErrorActionPreference = 'Stop'
$marker = Join-Path $StateDirectory '.indy-symfony-slice'
if (-not (Test-Path -LiteralPath $marker) -or [IO.File]::ReadAllText($marker) -cne 'isolated-slice-v1') { throw 'Own runtime marker missing.' }
$root = Join-Path ([IO.Path]::GetTempPath()) ('indy-managed-fixture-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($root) | Out-Null
$profiles = @()
$source = Join-Path $PSScriptRoot '../tests/Fixtures/Standin.cs'
$compiled = $null
foreach ($id in @('fixture-a', 'fixture-b', 'fixture-aska')) {
    $directory = Join-Path $root ($id + ' with spaces')
    [IO.Directory]::CreateDirectory($directory) | Out-Null
    $game = if ($id -eq 'fixture-aska') { 'aska' } else { 'enshrouded' }
    $name = if ($game -eq 'aska') { 'AskaServer.exe' } else { 'enshrouded_server.exe' }
    $target = Join-Path $directory $name
    if ($compiled) { [IO.File]::Copy($compiled, $target) }
    else { Add-Type -Path $source -OutputAssembly $target -OutputType ConsoleApplication; $compiled = $target }
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { $hash = [BitConverter]::ToString($algorithm.ComputeHash([IO.File]::ReadAllBytes($target))).Replace('-', '').ToLowerInvariant() }
    finally { $algorithm.Dispose() }
    $profile = @{ id = $id; game = $game; label = ('Own native ' + $id); server_directory = $directory }
    if ($game -eq 'aska') {
        $external = Join-Path $root 'external configuration'
        [IO.Directory]::CreateDirectory($external) | Out-Null
        $profile.config_path = Join-Path $external 'server properties.txt'
        $profile.launcher_script = Join-Path $directory 'own-starter.ps1'
        $starter = @'
$ErrorActionPreference = 'Stop'
$env:SteamAppId = '1898300'
$env:SteamGameId = '1898300'
$process = Start-Process -FilePath (Join-Path $PSScriptRoot 'AskaServer.exe') -WorkingDirectory $PSScriptRoot -PassThru
Write-Output 'The session is now open!'
$process.WaitForExit()
'@
        [IO.File]::WriteAllText($profile.launcher_script, $starter, (New-Object Text.UTF8Encoding($true)))
    }
    $profiles += @{ profile = $profile; executable = $target; sha256 = $hash }
}
$manifest = Join-Path $StateDirectory 'native/managed-fixtures.json'
$bytes = @{ root = $root; fixtures = $profiles } | ConvertTo-Json -Depth 8
[IO.File]::WriteAllText($manifest, $bytes, (New-Object Text.UTF8Encoding($false)))
[IO.File]::WriteAllText((Join-Path $root 'own-fixtures.json'), $bytes, (New-Object Text.UTF8Encoding($false)))
