# Loaded only inside an isolated launcher process. Never write unredacted output.
function Initialize-IndyServerLog([string]$Directory, [string]$ConfigPath) {
    New-Item -ItemType Directory -Path $Directory -Force | Out-Null
    $values = New-Object 'System.Collections.Generic.List[string]'
    $keyPattern = '(?i)authentication[ _-]*token|auth[ _-]*token|access[ _-]*token|api[ _-]*key|password|passwd|webhook(?:[ _-]*url)?|authorization'
    if ($ConfigPath -and (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
        $document = [IO.File]::ReadAllText($ConfigPath)
        if ($document.TrimStart().StartsWith('{')) {
            function Find-IndySecrets($Item) {
                if ($null -eq $Item) { return }
                if ($Item -is [Array]) { foreach ($child in $Item) { Find-IndySecrets $child }; return }
                foreach ($property in $Item.PSObject.Properties) {
                    if ($property.Name -match $keyPattern -and $property.Value -is [string] -and $property.Value) { $values.Add($property.Value) }
                    elseif ($property.Value -is [pscustomobject] -or $property.Value -is [Array]) { Find-IndySecrets $property.Value }
                }
            }
            Find-IndySecrets ($document | ConvertFrom-Json)
        } else {
            foreach ($line in ($document -split '\r?\n')) {
                if ($line.TrimStart().StartsWith('//')) { continue }
                $match = [regex]::Match($line, '^\s*([^=]+?)\s*=\s*(.*)$')
                if ($match.Success -and $match.Groups[1].Value -match $keyPattern -and $match.Groups[2].Value.Trim()) {
                    $values.Add($match.Groups[2].Value.Trim())
                }
            }
        }
    }
    $global:IndyLogContext = @{ Directory = $Directory; Secrets = @($values | Select-Object -Unique | Sort-Object Length -Descending); KeyPattern = $keyPattern; Hidden = @{} }
}

function Protect-IndyLogText([string]$Text, [string]$Source) {
    $context = $global:IndyLogContext
    $Text = [regex]::Replace($Text, '(?i)https?://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/[^\s"''<>]+', '[ENTFERNT]')
    $output = foreach ($line in ($Text -split '\r?\n')) {
        $safe = $line
        if ($context.Hidden[$Source] -and $safe.Trim()) {
            $safe = '[ENTFERNT]'; $context.Hidden[$Source] = $false
        } else {
            $match = [regex]::Match($safe, $context.KeyPattern)
            if ($match.Success) {
                $suffix = $safe.Substring($match.Index + $match.Length)
                $separator = [regex]::Match($suffix, '[:=]')
                if ($separator.Success) {
                    $context.Hidden[$Source] = -not $suffix.Substring($separator.Index + 1).Trim().Trim([char[]]@(34, 39, 44))
                    $safe = $safe.Substring(0, $match.Index + $match.Length) + ': [ENTFERNT]'
                }
            }
            foreach ($value in $context.Secrets) { $safe = [regex]::Replace($safe, [regex]::Escape($value), '[ENTFERNT]', [Text.RegularExpressions.RegexOptions]::IgnoreCase) }
        }
        if ($safe.Length -gt 16384) { $safe = $safe.Substring(0, 16384) + ' [gekuerzt]' }
        $safe
    }
    return ($output -join [Environment]::NewLine)
}

function Write-IndyServerLog {
    param([string]$Text, [ValidateSet('server', 'launcher')][string]$Source = 'server')
    try {
        $safe = Protect-IndyLogText $Text $Source
        $path = Join-Path $global:IndyLogContext.Directory ($Source + '.log')
        if ((Test-Path -LiteralPath $path) -and (Get-Item -LiteralPath $path).Length -ge 2097152) {
            Move-Item -LiteralPath $path -Destination (Join-Path $global:IndyLogContext.Directory ($Source + '.previous.log')) -Force
        }
        [IO.File]::AppendAllText($path, ([DateTime]::UtcNow.ToString('o') + ' ' + $safe + [Environment]::NewLine), (New-Object Text.UTF8Encoding($false)))
    } catch {
        # A log write failure must not interrupt the server's output drain.
    }
}
