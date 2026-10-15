# Read-only discovery on the Windows game host, never the WSL virtual adapter.
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$ErrorActionPreference = 'Stop'
$addresses = @()
try {
    $routes = @(Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0')
    $addresses = @($routes | ForEach-Object {
        Get-NetIPAddress -InterfaceIndex $_.InterfaceIndex -AddressFamily IPv4 |
            Where-Object { $_.AddressState -eq 'Preferred' } |
            ForEach-Object { $_.IPAddress }
    } | Sort-Object -Unique)
} catch { $addresses = @() }
$publicAddress = $null
try {
    $publicAddress = [string](Invoke-RestMethod -Uri 'https://api.ipify.org' -TimeoutSec 6 -UseBasicParsing)
} catch { $publicAddress = $null }
@{lan = @($addresses); public = $publicAddress} | ConvertTo-Json -Compress -Depth 3
