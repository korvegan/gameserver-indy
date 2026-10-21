"""Opt-in Windows integration test. Uses only a disposable stand-in server."""
import base64
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from gameserver_manager.core import WindowsServerBridge
from gameserver_manager.configuration import ManagerError


def windows_path(path):
    if os.name == "nt":
        return str(path)
    return subprocess.check_output(["wslpath", "-w", str(path)], text=True).strip()


def ps_literal(value):
    return "'" + value.replace("'", "''") + "'"


@unittest.skipUnless(os.environ.get("GAMESERVER_WINDOWS_TEST") == "1", "Windows-Integration explizit aktivieren")
class WindowsIntegrationTests(unittest.TestCase):
    def test_start_discovery_and_graceful_stop_of_test_process(self):
        self.check_process("enshrouded")

    def test_aska_arguments_and_graceful_stop(self):
        self.check_process("aska")

    def test_aska_external_launcher_and_graceful_stop(self):
        self.check_process("aska", external=True)

    def check_process(self, game, external=False):
        with tempfile.TemporaryDirectory(prefix="gameserver-test-") as temporary:
            directory = Path(temporary)
            source = directory / "standin.cs"
            source.write_text('''
using System;
using System.IO;
using System.Threading;
public static class StandIn {
    public static void Main() {
        File.WriteAllText("process.pid", System.Diagnostics.Process.GetCurrentProcess().Id.ToString());
        File.WriteAllLines("arguments.txt", Environment.GetCommandLineArgs());
        File.WriteAllText("steam-app.txt", Environment.GetEnvironmentVariable("SteamAppId") ?? "");
        Console.CancelKeyPress += (sender, args) => {
            args.Cancel = true;
            File.WriteAllText("graceful-stop.txt", "saved");
            Environment.Exit(0);
        };
        Thread.Sleep(90000);
    }
}
''', encoding="utf-8")
            target = directory / ("AskaServer.exe" if game == "aska" else "enshrouded_server.exe")
            command = "$ErrorActionPreference = 'Stop'; Add-Type -Path " + ps_literal(windows_path(source)) + " -OutputAssembly " + ps_literal(windows_path(target)) + " -OutputType ConsoleApplication"
            encoded = base64.b64encode(command.encode("utf-16le")).decode()
            powershell = "powershell.exe" if os.name == "nt" else "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe"
            result = subprocess.run([powershell, "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded], capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr.decode(errors="replace"))
            config = directory / "properties with spaces.txt"
            config.write_text("server name = Test\n", encoding="utf-8")
            launcher = directory / "external launcher.ps1"
            if external:
                launcher.write_text("Start-Sleep -Seconds 3\n$env:SteamAppId = '1898300'\n$p = Start-Process -PassThru -FilePath "
                                    + ps_literal(windows_path(target)) + " -WorkingDirectory " + ps_literal(windows_path(directory))
                                    + " -ArgumentList @('-propertiesPath', " + ps_literal('"' + windows_path(config) + '"')
                                    + ")\n$p.WaitForExit()\n", encoding="utf-8")
            bridge = WindowsServerBridge(windows_path(directory), game, windows_path(config),
                                         windows_path(launcher) if external else None)
            self.assertEqual(bridge.call("Status")["processes"], [])
            try:
                started = bridge.call("Start")["processes"]
                self.assertEqual(len(started), 1)
                process_id = started[0]["pid"]
                self.assertEqual(bridge.call("Status")["processes"][0]["pid"], process_id)
                if external:
                    self.assertTrue(bridge.call("Status")["launcher_running"])
                if game == "aska":
                    arguments = (directory / "arguments.txt").read_text().splitlines()
                    self.assertEqual(arguments[1:], ["-propertiesPath", windows_path(config)])
                    self.assertEqual((directory / "steam-app.txt").read_text(), "1898300")
                # Another installation must never adopt or stop this process.
                other = directory / "other"
                other.mkdir()
                self.assertEqual(WindowsServerBridge(windows_path(other), game).call("Status")["processes"], [])
                self.assertEqual(bridge.call("Stop")["processes"], [])
                self.assertEqual((directory / "graceful-stop.txt").read_text(), "saved")
            finally:
                # A failed test must clean up only its own disposable process.
                marker = directory / "process.pid"
                if marker.exists():
                    process_id = int(marker.read_text())
                    subprocess.run([powershell, "-NoProfile", "-NonInteractive", "-Command", f"$p = Get-Process -Id {process_id} -ErrorAction SilentlyContinue; if ($p) {{ $p | Stop-Process -Force; $p.WaitForExit() }}"], capture_output=True, timeout=15)

    def test_launcher_failure_logs_and_aska_hook_are_redacted_and_idempotent(self):
        with tempfile.TemporaryDirectory(prefix="gameserver-log-test-") as temporary:
            root = Path(temporary)
            (root / "AskaServer.exe").touch()
            config = root / "server properties.txt"
            config.write_text("password = native-secret-1\nauthentication token = native-secret-2\n", encoding="utf-8")
            launcher = root / "failing launcher.ps1"
            launcher.write_text("function Read-ServerMarker {\n    param([string]$Text, [hashtable]$State)\n}\n"
                                "$secret = ConvertTo-SecureString 'temporary-dpapi-test' -AsPlainText -Force\n"
                                "$encrypted = ConvertFrom-SecureString $secret\n"
                                "$restored = ConvertTo-SecureString $encrypted\n"
                                "if ($restored.Length -ne $secret.Length) { throw 'DPAPI roundtrip failed' }\n"
                                "$secret.Dispose(); $restored.Dispose()\n"
                                "Write-Host 'Native DPAPI roundtrip successful'\n"
                                "Read-ServerMarker -Text 'native-secret-1 and native-secret-2' -State @{}\n"
                                "Write-Host 'GSLTExpired: Steam login failed'\n"
                                "Write-Host 'password = rotated-secret'\nexit 7\n", encoding="utf-8")
            powershell = "powershell.exe" if os.name == "nt" else "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe"
            installer = Path(__file__).resolve().parent.parent / "scripts/enable-aska-logs.ps1"
            for _ in range(2):
                result = subprocess.run([powershell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                                         "-File", windows_path(installer), "-LauncherScript", windows_path(launcher)], capture_output=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stderr.decode(errors="replace"))
            self.assertEqual(len(list((root / "backups").glob("*.ps1"))), 1)
            # Simulate the incompatible PowerShell 7 module path inherited on Indy.
            module_root = root / "foreign modules"
            module = module_root / "Microsoft.PowerShell.Security"
            module.mkdir(parents=True)
            (module / "Microsoft.PowerShell.Security.psd1").write_text(
                "@{ ModuleVersion='99.0'; RootModule='foreign.psm1'; FunctionsToExport=@('ConvertTo-SecureString') }", encoding="utf-8")
            (module / "foreign.psm1").write_text(
                "function ConvertTo-SecureString { throw 'Incompatible security module selected' }\n"
                "Export-ModuleMember -Function ConvertTo-SecureString\n", encoding="utf-8")
            logs = root / "diagnostics"
            bridge = WindowsServerBridge(windows_path(root), "aska", windows_path(config), windows_path(launcher), windows_path(logs))
            with patch.dict(os.environ, {"PSModulePath": windows_path(module_root) + ";" + os.environ.get("PSModulePath", "")}):
                with self.assertRaisesRegex(ManagerError, "Exitcode 7"):
                    bridge.call("Start")
            server = (logs / "server.log").read_text(encoding="utf-8")
            starter = (logs / "launcher.log").read_text(encoding="utf-8")
            self.assertIn("GSLTExpired", starter)
            self.assertIn("Native DPAPI roundtrip successful", starter)
            self.assertIn("Exitcode 7", starter)
            self.assertIn("[ENTFERNT]", server)
            for secret in ("native-secret-1", "native-secret-2", "rotated-secret"):
                self.assertNotIn(secret, server + starter)
            self.assertFalse(bridge.call("Status")["launcher_running"])


if __name__ == "__main__":
    unittest.main()
