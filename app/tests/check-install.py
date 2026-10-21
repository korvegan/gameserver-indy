"""Check release bytes/modes and preservation of private configuration."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('installer', root / 'app/tools/install.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
revision = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip()
# Test the source-controlled application tree without touching the installed launcher.
prefix = 'app/' if subprocess.run(['git', '-C', str(root), 'cat-file', '-e', revision + ':app/bin/dev'],
                                 capture_output=True).returncode == 0 else None
assert prefix, 'Commit the new app structure before this check'
with tempfile.TemporaryDirectory(prefix='indy-release-test-') as temporary:
    destination = Path(temporary)
    installer.package(root, revision, destination)
    rows = installer.git(root, 'ls-tree', '-rz', revision, 'app/').split(b'\0')
    expected = {'REVISION'}
    for row in filter(None, rows):
        entry, name = row.split(b'\t')
        mode, _, oid = entry.decode().split()
        file = destination / name.decode()
        assert file.read_bytes() == installer.git(root, 'cat-file', 'blob', oid)
        assert bool(file.stat().st_mode & 0o111) == (mode == '100755')
        expected.add(name.decode())
    assert {str(file.relative_to(destination)) for file in destination.rglob('*') if file.is_file()} == expected
    assert (destination / 'REVISION').read_text() == revision + '\n'
old = {'RuntimeEngine': 'symfony', 'StateDirectory': '/private/state', 'Port': 8765,
       'RuntimeExecutable': '/tools/php', 'WslUser': 'owner', 'WslDistribution': 'Indy', 'Other': 'preserve'}
updated = installer.new_settings(old, Path('/release/code'), revision, Path('/tools/frankenphp'))
for key in ('StateDirectory', 'Port', 'RuntimeExecutable', 'WslUser', 'WslDistribution', 'Other'):
    assert updated[key] == old[key]
assert old['RuntimeEngine'] == 'symfony' and updated['CodeCommit'] == revision
print('PASS: exact release tree, executable modes, revision and preservation of private settings; no installation')
