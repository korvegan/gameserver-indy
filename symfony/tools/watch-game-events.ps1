param([Parameter(Mandatory=$true)][string]$ConfigurationPath)
$ErrorActionPreference = 'Stop'
$configuration = Get-Content -LiteralPath $ConfigurationPath -Raw | ConvertFrom-Json
# One read-only native event source. Closing the owner's input pipe ends it, including on owner death.
Add-Type -ReferencedAssemblies System.Management -TypeDefinition @'
using System;
using System.IO;
using System.Management;
using System.Collections.Generic;
using System.Collections.Concurrent;
using System.Threading.Tasks;
public sealed class IndyGameEvents : IDisposable {
    public readonly BlockingCollection<string> Events = new BlockingCollection<string>();
    readonly List<IDisposable> watchers = new List<IDisposable>();
    public void Put(string message) { try { Events.Add(message); } catch (InvalidOperationException) {} }
    public void ParentPipe() { Task.Run(() => { try { Console.In.ReadToEnd(); } finally { Events.CompleteAdding(); } }); }
    public void File(string path, string token) {
        path = Path.GetFullPath(path);
        string folder = Path.GetDirectoryName(path); bool direct = Directory.Exists(folder);
        while (!Directory.Exists(folder)) { string parent = Path.GetDirectoryName(folder); if (parent == null || parent == folder) throw new IOException(); folder = parent; }
        var watcher = new FileSystemWatcher(folder, direct ? Path.GetFileName(path) : "*");
        watcher.IncludeSubdirectories = !direct;
        watcher.NotifyFilter = NotifyFilters.FileName | NotifyFilters.DirectoryName | NotifyFilters.LastWrite | NotifyFilters.Size;
        FileSystemEventHandler changed = (s, e) => { if (String.Equals(e.FullPath, path, StringComparison.OrdinalIgnoreCase) || path.StartsWith(e.FullPath + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)) Put(token); };
        watcher.Changed += changed; watcher.Created += changed; watcher.Deleted += changed;
        watcher.Renamed += (s, e) => { if (String.Equals(e.FullPath, path, StringComparison.OrdinalIgnoreCase) || String.Equals(e.OldFullPath, path, StringComparison.OrdinalIgnoreCase)) Put(token); };
        watcher.Error += (s, e) => Put("rescan:");
        watchers.Add(watcher); watcher.EnableRaisingEvents = true;
    }
    public void Processes(string[] names) {
        string where = String.Join(" OR ", Array.ConvertAll(names, n => "ProcessName='" + n + "'"));
        foreach (string trace in new [] {"Win32_ProcessStartTrace", "Win32_ProcessStopTrace"}) {
            var watcher = new ManagementEventWatcher(new WqlEventQuery("SELECT * FROM " + trace + " WHERE " + where));
            watcher.EventArrived += (s, e) => Put("process:" + Convert.ToString(e.NewEvent["ProcessName"]));
            watcher.Stopped += (s, e) => Put("rescan:");
            watchers.Add(watcher); watcher.Start();
        }
    }
    public void Dispose() { foreach (var watcher in watchers) watcher.Dispose(); }
}
'@
$inbox = New-Object IndyGameEvents
$degraded = $false
try {
    foreach ($profile in $configuration.profiles) {
        if ($profile.executable -notmatch '^[a-zA-Z0-9_. -]+\.exe$') { throw 'Ungültiger Prozessfilter.' }
        foreach ($entry in @(@{ path=$profile.log; kind='log' }, @{ path=$profile.launcher; kind='log' }, @{ path=$profile.config; kind='config' })) {
            if ($entry.path) { try { $inbox.File($entry.path, ($entry.kind + ':' + $profile.id)) } catch { $degraded = $true } }
        }
    }
    $names = @($configuration.profiles | ForEach-Object { $_.executable } | Select-Object -Unique)
    if ($names.Count) { try { $inbox.Processes([string[]]$names) } catch { $degraded = $true } }
    $inbox.ParentPipe()
    [Console]::Out.WriteLine((@{ kind='ready'; degraded=$degraded } | ConvertTo-Json -Compress))
    [Console]::Out.Flush()
    foreach ($item in $inbox.Events.GetConsumingEnumerable()) {
        $kind, $value = $item.Split(':', 2)
        if ($kind -eq 'process') {
            foreach ($profile in $configuration.profiles) {
                if ($profile.executable -ieq $value) { [Console]::Out.WriteLine((@{kind='process'; server_id=$profile.id} | ConvertTo-Json -Compress)) }
            }
        } else { [Console]::Out.WriteLine((@{kind=$kind; server_id=$value} | ConvertTo-Json -Compress)) }
        [Console]::Out.Flush()
    }
} finally { $inbox.Dispose() }
