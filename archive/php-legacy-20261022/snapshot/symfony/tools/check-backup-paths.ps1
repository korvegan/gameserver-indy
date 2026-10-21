param([Parameter(Mandatory=$true)][string]$RequestPath)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
try {
    $request = [IO.File]::ReadAllText($RequestPath) | ConvertFrom-Json
    function Require-SingleFile([string]$Path) {
        if ($null -eq ('IndyBackupFileInfo' -as [type])) {
            Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class IndyBackupFileInfo {
    [StructLayout(LayoutKind.Sequential)] struct Info {
        public uint Attributes; public System.Runtime.InteropServices.ComTypes.FILETIME Created, Accessed, Written;
        public uint Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
    }
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool GetFileInformationByHandle(SafeFileHandle handle, out Info info);
    public static void RequireSingleLink(string path) {
        using (var input = new System.IO.FileStream(path, System.IO.FileMode.Open, System.IO.FileAccess.Read, System.IO.FileShare.ReadWrite | System.IO.FileShare.Delete)) {
            Info info;
            if (!GetFileInformationByHandle(input.SafeFileHandle, out info) || info.Links != 1 || (info.Attributes & 0x400) != 0)
                throw new System.IO.IOException("Not a plain single-link file");
        }
    }
}
'@
        }
        [IndyBackupFileInfo]::RequireSingleLink($Path)
    }
    function Require-Plain([string]$Path) {
        $current = [IO.Path]::GetFullPath($Path)
        while ($true) {
            try {
                $attributes = [IO.File]::GetAttributes($current)
                if (($attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse point' }
                if (($attributes -band [IO.FileAttributes]::Directory) -eq 0) { Require-SingleFile $current }
            } catch [IO.FileNotFoundException] { } catch [IO.DirectoryNotFoundException] { }
            $parent = [IO.Path]::GetDirectoryName($current)
            if ([string]::IsNullOrEmpty($parent) -or $parent -eq $current) { break }
            $current = $parent
        }
    }
    foreach ($path in $request.paths) { Require-Plain $path }
    if ($null -ne $request.tree -and [IO.Directory]::Exists($request.tree)) {
        $stack = New-Object 'System.Collections.Generic.Stack[string]'; $stack.Push($request.tree); $count = 0
        while ($stack.Count -gt 0) {
            foreach ($path in [IO.Directory]::EnumerateFileSystemEntries($stack.Pop())) {
                $count++; if ($count -gt 100000) { throw 'File limit' }
                Require-Plain $path
                if (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::Directory) -ne 0) { $stack.Push($path) }
            }
        }
    }
    [Console]::Write('plain')
} catch { [Console]::Error.WriteLine('Backup file validation failed.'); exit 1 }
