param([ValidateSet('Prepare','Junction','Hardlink','ResetLinks','Remove')][string]$Mode = 'Prepare', [string]$Root)
$ErrorActionPreference = 'Stop'; Set-StrictMode -Version Latest
if ($Mode -eq 'Prepare') {
    $Root = Join-Path ([IO.Path]::GetTempPath()) ('indy-backup-php-fixture-' + [Guid]::NewGuid().ToString('N'))
    [IO.Directory]::CreateDirectory((Join-Path $Root 'World with spaces')) | Out-Null
    [IO.Directory]::CreateDirectory((Join-Path $Root 'unrelated')) | Out-Null
    [IO.File]::WriteAllText((Join-Path $Root '.indy-backup-fixture'), [IO.Path]::GetFileName($Root))
    [IO.File]::WriteAllText((Join-Path $Root 'World with spaces\world0.ksd'), 'own world')
    [IO.File]::WriteAllText((Join-Path $Root 'config.txt'), 'own config')
    @{root=$Root; world=(Join-Path $Root 'World with spaces'); config=(Join-Path $Root 'config.txt')} | ConvertTo-Json -Compress
    exit 0
}
if ([IO.Path]::GetFileName($Root) -notmatch '^indy-backup-php-fixture-[a-f0-9]{32}$' -or
    [IO.File]::ReadAllText((Join-Path $Root '.indy-backup-fixture')) -ne [IO.Path]::GetFileName($Root)) { throw 'Own marked fixture required' }
$junction = Join-Path $Root 'World with spaces\junction'; $hardlink = Join-Path $Root 'World with spaces\hardlink'
if ($Mode -eq 'Junction') { New-Item -ItemType Junction -Path $junction -Target (Join-Path $Root 'unrelated') | Out-Null }
if ($Mode -eq 'Hardlink') { New-Item -ItemType HardLink -Path $hardlink -Target (Join-Path $Root 'World with spaces\world0.ksd') | Out-Null }
if ($Mode -eq 'ResetLinks' -or $Mode -eq 'Remove') {
    if ([IO.Directory]::Exists($junction)) { [IO.Directory]::Delete($junction) }
    if ([IO.File]::Exists($hardlink)) { [IO.File]::Delete($hardlink) }
}
if ($Mode -eq 'Remove') { [IO.Directory]::Delete($Root, $true) }
