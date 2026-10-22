using System;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using Microsoft.Win32.SafeHandles;
using System.Security.Principal;

public static class IndyNativeConsole
{
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern uint GetFinalPathNameByHandle(SafeFileHandle file, StringBuilder path, uint size, uint flags);
    [DllImport("kernel32.dll", SetLastError = true)] private static extern bool FreeConsole();
    [DllImport("kernel32.dll", SetLastError = true)] private static extern bool AttachConsole(uint pid);
    [DllImport("kernel32.dll", SetLastError = true)] private static extern bool SetConsoleCtrlHandler(IntPtr handler, bool add);
    [DllImport("kernel32.dll", SetLastError = true)] private static extern bool GenerateConsoleCtrlEvent(uint signal, uint group);
    [DllImport("kernel32.dll", SetLastError = true)] private static extern uint GetConsoleProcessList([Out] uint[] processes, uint size);
    [DllImport("advapi32.dll", SetLastError = true)] private static extern bool OpenProcessToken(IntPtr process, uint access, out IntPtr token);
    [DllImport("kernel32.dll")] private static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CreateProcess(string application, StringBuilder command, IntPtr processAttributes,
        IntPtr threadAttributes, bool inheritHandles, uint flags, IntPtr environment, string directory,
        ref StartupInfo startup, out ProcessInfo process);
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct StartupInfo
    {
        public int size; public string reserved, desktop, title;
        public uint x, y, xSize, ySize, xChars, yChars, fill, flags;
        public short show, reservedSize; public IntPtr reservedData, input, output, error;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct ProcessInfo { public IntPtr process, thread; public uint pid, tid; }

    public static Process Start(string executable, string arguments, string directory)
    {
        var startup = new StartupInfo { size = Marshal.SizeOf<StartupInfo>(), flags = 1, show = 7 };
        ProcessInfo created;
        // Start-Process inherits handles on Windows; under WSL that can keep the transport alive.
        if (!CreateProcess(executable, new StringBuilder("\"" + executable + "\" " + arguments),
            IntPtr.Zero, IntPtr.Zero, false, 0x10, IntPtr.Zero, directory, ref startup, out created))
            throw new IOException("native_error");
        try
        {
            var target = Process.GetProcessById((int)created.pid);
            var handle = target.Handle;
            return target;
        }
        finally { CloseHandle(created.thread); CloseHandle(created.process); }
    }

    public static bool OwnedBy(Process target, string sid)
    {
        IntPtr token;
        if (!OpenProcessToken(target.Handle, 8, out token)) throw new IOException("process_unreadable");
        try { using (var identity = new WindowsIdentity(token)) return identity.User.Value == sid; }
        finally { CloseHandle(token); }
    }

    public static string Canonical(string path)
    {
        using (var file = new FileStream(Path.GetFullPath(path), FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
        {
            var buffer = new StringBuilder(32768);
            uint size = GetFinalPathNameByHandle(file.SafeFileHandle, buffer, (uint)buffer.Capacity, 0);
            if (size == 0 || size >= buffer.Capacity) throw new IOException("process_unreadable");
            string result = buffer.ToString();
            return result.StartsWith(@"\\?\UNC\", StringComparison.OrdinalIgnoreCase)
                ? @"\\" + result.Substring(8) : result.StartsWith(@"\\?\") ? result.Substring(4) : result;
        }
    }

    public static string Interrupt(Process target, string executable, string started)
    {
        // Keeping the handle open and checking HasExited prevents signalling a reused PID.
        var handle = target.Handle;
        if (target.HasExited || target.StartTime.ToUniversalTime().ToString("o") != started
            || !String.Equals(Canonical(target.MainModule.FileName), executable, StringComparison.OrdinalIgnoreCase)
            || !OwnedBy(target, WindowsIdentity.GetCurrent().User.Value))
            return "process_changed";
        FreeConsole();
        if (!AttachConsole((uint)target.Id)) return "console_unavailable";
        try
        {
            if (!SetConsoleCtrlHandler(IntPtr.Zero, true)) return "console_unavailable";
            var members = new uint[64];
            uint count = GetConsoleProcessList(members, (uint)members.Length);
            if (count == 0 || count > members.Length) return "unsafe_console";
            uint self = (uint)Process.GetCurrentProcess().Id;
            for (int i = 0; i < count; ++i)
                if (members[i] != target.Id && members[i] != self) return "unsafe_console";
            if (target.HasExited) return "process_changed";
            // CTRL+C is a broadcast: only signal a console containing our target and this helper.
            if (!GenerateConsoleCtrlEvent(0, 0)) return "console_unavailable";
            Thread.Sleep(200);
            return null;
        }
        finally { FreeConsole(); }
    }
}
