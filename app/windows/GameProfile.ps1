function Get-WindowsUserProfile {
    return [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
}

function Assert-World([string]$Server, $Profile, [string]$Working) {
    if (-not [IO.Directory]::Exists($Profile.world_directory)) { throw 'world_mismatch' }
    if ($Server -eq 'enshrouded') {
        $configuration = Read-Json $Profile.configuration 1048576
        if ([IO.Path]::GetFullPath($Profile.configuration) -ine (Join-Path $Working 'enshrouded_server.json') -or
            $configuration.saveDirectory -isnot [string]) { throw 'world_mismatch' }
        $world = [IO.Path]::GetFullPath($configuration.saveDirectory, $Working)
        if ($world.TrimEnd('\') -ine ([IO.Path]::GetFullPath($Profile.world_directory)).TrimEnd('\')) { throw 'world_mismatch' }
    } else {
        $properties = [IO.File]::ReadAllLines($Profile.configuration)
        $ids = @($properties | Where-Object { $_ -match '^\s*save id\s*=' })
        if ($Profile.save_id -notmatch '^[A-Za-z0-9_-]+$' -or $ids.Count -ne 1 -or
            $ids[0] -cnotmatch ('^\s*save id\s*=\s*' + [regex]::Escape($Profile.save_id) + '\s*$')) { throw 'world_mismatch' }
        $expected = Join-Path (Get-WindowsUserProfile) ('AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_' + $Profile.save_id)
        if ([IO.Path]::GetFullPath($Profile.world_directory).TrimEnd('\') -ine
            [IO.Path]::GetFullPath($expected).TrimEnd('\')) { throw 'world_mismatch' }
    }
}
