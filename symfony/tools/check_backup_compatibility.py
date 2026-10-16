"""Both directions of the existing ZIP contract, on disposable local files only."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / 'tests'))
from gameserver_manager.instances import GameServerManager
from test_manager import FakeBridge


with tempfile.TemporaryDirectory(prefix='indy-backup-parity-') as directory:
    root = Path(directory)
    install = root / 'Own install'
    install.mkdir()
    (install / 'enshrouded_server.exe').write_bytes(b'non-executable placeholder')
    manager = GameServerManager(root / 'legacy', FakeBridge(), profile={
        'id': 'fixture', 'game': 'enshrouded', 'label': 'Parity', 'server_directory': str(install)})
    manager.root.mkdir()
    manager.setup()
    world = install / 'savegame'
    (world / 'empty').mkdir(parents=True)
    (world / 'world0.ksd').write_bytes(b'legacy world\x00\xff')
    manager.backups.settle_seconds = 0
    identifier = manager.backups.create()['backup_id']
    archive = manager.backups.folder / identifier
    original_archive = archive.with_suffix('.zip').read_bytes()
    original_metadata = archive.with_suffix('.json').read_bytes()
    original_config = manager.config_path.read_bytes()
    (world / 'world0.ksd').write_bytes(b'later world')
    (world / 'new-file').write_bytes(b'newer file')
    (root / 'fixture.json').write_text(json.dumps({'marker': root.name, 'install': str(install),
        'archive': str(archive), 'id': identifier}), encoding='utf-8')
    result = subprocess.run([os.environ.get('SLICE_PHP', 'php'), str(Path(__file__).with_name('backup_compatibility.php')), str(root)],
        check=True, capture_output=True, text=True)
    outcome = json.loads(result.stdout)
    assert (world / 'world0.ksd').read_bytes() == b'legacy world\x00\xff'
    assert not (world / 'new-file').exists()
    assert manager.config_path.read_bytes() == original_config
    assert archive.with_suffix('.zip').read_bytes() == original_archive
    assert archive.with_suffix('.json').read_bytes() == original_metadata
    php_archive = Path(outcome['archive'])
    metadata = json.loads(php_archive.with_suffix('.json').read_text(encoding='utf-8'))
    manager.backups._inspect(php_archive.with_suffix('.zip'), metadata['manifest'])
    for extension in ('.zip', '.json'):
        shutil.copy2(php_archive.with_suffix(extension), manager.backups.folder / (outcome['id'] + extension))
    preview = manager.backups.preview(outcome['id'])
    (world / 'world0.ksd').write_bytes(b'later again')
    manager.backups.restore(outcome['id'], preview['token'], preview['confirmation'])
    assert (world / 'world0.ksd').read_bytes() == b'legacy world\x00\xff'
    assert manager.config_path.read_bytes() == original_config
print('Python → Symfony → Python: ZIP/manifest/hash/binding/exact config/empty folder/restore verified on disposable data.')
