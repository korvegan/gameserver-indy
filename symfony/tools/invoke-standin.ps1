param(
    [Parameter(Mandatory = $true)][string]$StateDirectory,
    [Parameter(Mandatory = $true)][ValidateSet('demo-a', 'demo-b')][string]$ServerId,
    [Parameter(Mandatory = $true)][ValidateSet('Status', 'Start', 'Stop')][string]$Action,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-f0-9]{32}$')][string]$ReplyNonce
)
$ErrorActionPreference = 'Stop'
$marker = Join-Path $StateDirectory '.indy-symfony-slice'
if (-not (Test-Path -LiteralPath $marker -PathType Leaf) -or
    [IO.File]::ReadAllText($marker) -cne 'isolated-slice-v1') { throw 'Isolierter Testordner fehlt.' }
$directory = Join-Path (Join-Path $StateDirectory 'native') $ServerId
$script = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../scripts/windows-server.ps1'))
$global:LASTEXITCODE = 0
# FreeConsole/AttachConsole in the existing helper can invalidate native PHP's
# redirected stdout handle. Capture the pipeline before it reaches that handle.
$reply = & $script -Action $Action -ServerDirectory $directory -ExecutableName 'indy-symfony-standin.exe'
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
$destination = Join-Path $directory ('reply-' + $ReplyNonce + '.json')
[IO.File]::WriteAllText($destination, ($reply -join [Environment]::NewLine), (New-Object Text.UTF8Encoding($false)))
