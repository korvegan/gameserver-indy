param(
    [Parameter(Mandatory = $true)][string]$LauncherScript,
    [Parameter(Mandatory = $true)][string]$DiagnosticDirectory,
    [string]$ConfigPath = ''
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)
. (Join-Path $PSScriptRoot 'diagnostic-log.ps1')
try {
    Initialize-IndyServerLog $DiagnosticDirectory $ConfigPath
    Write-IndyServerLog -Source launcher -Text 'Serverstarter wird ausgefuehrt.'
    # WSL can inherit PowerShell 7 module paths. Load this host's DPAPI cmdlets.
    Import-Module (Join-Path $PSHOME 'Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1') -ErrorAction Stop
    $global:LASTEXITCODE = 0
    & $LauncherScript *>&1 | ForEach-Object {
        $line = [string]$_
        Write-IndyServerLog -Source launcher -Text $line
        # Write-Host from a nested script reaches this pipeline as information.
        [Console]::WriteLine((Protect-IndyLogText $line 'console'))
    }
    $code = $global:LASTEXITCODE
    Write-IndyServerLog -Source launcher -Text ('Starter beendet (Exitcode {0}).' -f $code)
    exit $code
} catch {
    if ($global:IndyLogContext) { Write-IndyServerLog -Source launcher -Text ([string]$_) }
    [Console]::Error.WriteLine('Serverstarter fehlgeschlagen. Bitte das Starterprotokoll pruefen.')
    exit 1
}
