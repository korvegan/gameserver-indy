"""Opt-in, disposable NTFS copy/restore and junction rejection; no actual games."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from gameserver_manager.backups import plain_path
from gameserver_manager.configuration import ManagerError, local_path
from gameserver_manager.instances import GameServerManager
from test_manager import FakeBridge
from test_windows_bridge import ps_literal, windows_path


@unittest.skipUnless(os.environ.get('GAMESERVER_WINDOWS_TEST') == '1', 'Windows-Integration explizit aktivieren')
class WindowsBackupTests(unittest.TestCase):
    def test_ntfs_world_copy_restore_and_junction_rejection(self):
        powershell = 'powershell.exe'
        native_temp = subprocess.check_output([powershell, '-NoProfile', '-NonInteractive', '-Command',
                                                '[Console]::Write([IO.Path]::GetTempPath())'], text=True).strip()
        with tempfile.TemporaryDirectory(prefix='indy-backup-source-', dir=local_path(native_temp)) as source, tempfile.TemporaryDirectory(prefix='indy-backup-manager-') as root:
            install = Path(source) / 'Welt mit Leerzeichen'
            install.mkdir()
            world = install / 'savegame'
            world.mkdir()
            (install / 'enshrouded_server.exe').touch()
            m = GameServerManager(root, FakeBridge(), profile={'id': 'enshrouded', 'game': 'enshrouded',
                'label': 'NTFS Test', 'server_directory': str(install)})
            m.setup()
            (world / 'world0.ksd').write_bytes(b'NTFS original')
            m.backups.settle_seconds = 0
            identifier = m.backups.create()['backup_id']
            (world / 'world0.ksd').write_bytes(b'later')
            (world / 'extra').write_bytes(b'new')
            preview = m.backups.preview(identifier)
            m.backups.restore(identifier, preview['token'], preview['confirmation'])
            self.assertEqual((world / 'world0.ksd').read_bytes(), b'NTFS original')
            self.assertFalse((world / 'extra').exists())
            target, link = Path(source) / 'junction-target', world / 'junction'
            target.mkdir()
            command = 'New-Item -ItemType Junction -Path ' + ps_literal(windows_path(link)) + ' -Target ' + ps_literal(windows_path(target)) + ' | Out-Null'
            subprocess.run([powershell, '-NoProfile', '-NonInteractive', '-Command', command], check=True, capture_output=True)
            try:
                with self.assertRaises(ManagerError):
                    plain_path(link)
                with self.assertRaises(ManagerError):
                    m.backups.create()
            finally:
                subprocess.run(['cmd.exe', '/c', 'rmdir', windows_path(link)], cwd=local_path(native_temp), check=True, capture_output=True)
