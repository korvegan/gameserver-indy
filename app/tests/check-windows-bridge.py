"""Run the bridge only against its compiled Windows fixtures, using installed PowerShell 7."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
pwsh = os.environ.get('INDY_PWSH') or shutil.which('pwsh.exe')
if not pwsh:
    raise SystemExit('Set INDY_PWSH to the WSL-accessible path of the installed pwsh.exe (PowerShell 7.6+).')
if not os.environ.get('SLICE_FRANKENPHP'):
    raise SystemExit('Set SLICE_FRANKENPHP to the existing FrankenPHP executable for the isolated HTTP/native check.')
result = subprocess.check_output([pwsh, '-NoProfile', '-NonInteractive', '-Command', '[IO.Path]::GetTempPath()'], text=True).strip()
windows_temp = Path(subprocess.check_output(['wslpath', '-u', result], text=True).strip())
with tempfile.TemporaryDirectory(prefix='indy-bridge-code-', dir=windows_temp) as temporary:
    stage = Path(temporary)
    for name in ('windows/Bridge.ps1', 'windows/NativeConsole.cs', 'windows/GameProfile.ps1', 'windows/Start-Manager.ps1',
                 'windows/Update-Startup.ps1', 'tests/check-launcher.ps1', 'tests/check-startup.ps1', 'tests/check-bridge.ps1', 'tests/BridgeStandin.cs'):
        destination = stage / 'app' / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(root / 'app' / name, destination)
    script = subprocess.check_output(['wslpath', '-w', str(stage / 'app/tests/check-bridge.ps1')], text=True).strip()
    stage_windows = subprocess.check_output(['wslpath', '-w', str(stage)], text=True).strip()
    manifest_file = stage / 'windows-fixture.json'
    command = [pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', script]
    try:
        for check in ('check-launcher.ps1','check-startup.ps1'):
            check_script = subprocess.check_output(['wslpath','-w',str(stage / 'app/tests' / check)],text=True).strip()
            subprocess.run([pwsh,'-NoLogo','-NoProfile','-NonInteractive','-File',check_script],check=True)
        subprocess.run([*command, '-ExportDirectory', stage_windows], check=True)
        manifest = json.loads(manifest_file.read_text(encoding='utf-8-sig'))
        manifest['pwsh_wsl'] = pwsh
        manifest_file.write_text(json.dumps(manifest), encoding='utf-8')
        php = os.environ.get('SLICE_PHP') or shutil.which('php')
        if not php:
            raise SystemExit('Set SLICE_PHP to the installed PHP CLI.')
        subprocess.run([php, str(root / 'app/tests/check-bridge.php'), '--windows', str(manifest_file)], check=True)
        subprocess.run(['node', str(root / 'app/tests/check-server-windows.cjs'), str(manifest_file)], check=True)
    finally:
        if manifest_file.exists():
            manifest_windows = subprocess.check_output(['wslpath', '-w', str(manifest_file)], text=True).strip()
            subprocess.run([*command, '-CleanupManifest', manifest_windows], check=True)
