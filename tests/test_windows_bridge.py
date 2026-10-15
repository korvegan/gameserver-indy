"""Opt-in Windows integration test. Uses only a disposable stand-in server."""
import base64
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from enshrouded_manager.core import WindowsBridge


def windows_path(path):
    if os.name == "nt":
        return str(path)
    return subprocess.check_output(["wslpath", "-w", str(path)], text=True).strip()


def ps_literal(value):
    return "'" + value.replace("'", "''") + "'"


@unittest.skipUnless(os.environ.get("ENSHROUDED_WINDOWS_TEST") == "1", "Windows-Integration explizit aktivieren")
class WindowsIntegrationTests(unittest.TestCase):
    def test_start_discovery_and_graceful_stop_of_test_process(self):
        with tempfile.TemporaryDirectory(prefix="enshrouded-test-") as temporary:
            directory = Path(temporary)
            source = directory / "standin.cs"
            source.write_text('''
using System;
using System.IO;
using System.Threading;
public static class StandIn {
    public static void Main() {
        File.WriteAllText("process.pid", System.Diagnostics.Process.GetCurrentProcess().Id.ToString());
        Console.CancelKeyPress += (sender, args) => {
            args.Cancel = true;
            File.WriteAllText("graceful-stop.txt", "saved");
            Environment.Exit(0);
        };
        Thread.Sleep(90000);
    }
}
''', encoding="utf-8")
            target = directory / "enshrouded_server.exe"
            command = "$ErrorActionPreference = 'Stop'; Add-Type -Path " + ps_literal(windows_path(source)) + " -OutputAssembly " + ps_literal(windows_path(target)) + " -OutputType ConsoleApplication"
            encoded = base64.b64encode(command.encode("utf-16le")).decode()
            powershell = "powershell.exe" if os.name == "nt" else "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe"
            result = subprocess.run([powershell, "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded], capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr.decode(errors="replace"))
            bridge = WindowsBridge(windows_path(directory))
            self.assertEqual(bridge.call("Status")["processes"], [])
            try:
                started = bridge.call("Start")["processes"]
                self.assertEqual(len(started), 1)
                process_id = started[0]["pid"]
                self.assertEqual(bridge.call("Status")["processes"][0]["pid"], process_id)
                # Another installation must never adopt or stop this process.
                other = directory / "other"
                other.mkdir()
                self.assertEqual(WindowsBridge(windows_path(other)).call("Status")["processes"], [])
                self.assertEqual(bridge.call("Stop")["processes"], [])
                self.assertEqual((directory / "graceful-stop.txt").read_text(), "saved")
            finally:
                # A failed test must clean up only its own disposable process.
                marker = directory / "process.pid"
                if marker.exists():
                    process_id = int(marker.read_text())
                    subprocess.run([powershell, "-NoProfile", "-NonInteractive", "-Command", f"$p = Get-Process -Id {process_id} -ErrorAction SilentlyContinue; if ($p) {{ $p | Stop-Process -Force; $p.WaitForExit() }}"], capture_output=True, timeout=15)


if __name__ == "__main__":
    unittest.main()
