"""Shared-state rollback uses only own temporary metadata; no game or external service."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import sqlite3
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from gameserver_manager import __main__ as cli
from gameserver_manager.configuration import ManagerError
from gameserver_manager.instances import GameServerManager
from gameserver_manager.paths import ROOT
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.runtime_state import controller_lease, state_directory, validate_prepared_state
from gameserver_manager.sessions import signing_token
from gameserver_manager.storage import Database
from gameserver_manager.access import AccessService
from gameserver_manager.whatsapp import WhatsAppService


class RuntimeStateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='indy-state-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def prepare(self):
        (self.root / '.indy-managed-state').write_text(json.dumps({'format':'gameserver-indy-state',
            'version':1,'access_layout':'shared-data'}), encoding='utf-8')
        (self.root / 'manager.local.json').write_text(json.dumps({'schema_version':2,'servers':[
            {'id':'enshrouded','game':'enshrouded','label':'Own fixture','server_directory':str(self.root/'own-install')}]}), encoding='utf-8')
        AccessService(self.root,None)
        (self.root/'data/access-settings.json').write_text('{}',encoding='utf-8')
        Database(self.root/'data/platform.sqlite3')
        WhatsAppService(SimpleNamespace(root=self.root))
        (self.root/'data/whatsapp/settings.json').write_text(json.dumps({'group_id':'','group_label':'','enabled':False,'server_ids':[],'revision':0}),encoding='utf-8')
        (self.root / 'data/session-key').write_text('a' * 43, encoding='ascii')

    def test_default_code_root_remains_compatible(self):
        self.assertEqual(state_directory(), ROOT)

    def test_explicit_state_never_initializes_missing_or_unknown_data(self):
        before = list(self.root.iterdir())
        with self.assertRaises(ManagerError):
            state_directory(str(self.root))
        self.assertEqual(list(self.root.iterdir()), before)
        self.prepare()
        marker = self.root / '.indy-managed-state'
        original = marker.read_bytes()
        for version in (True, 2, '1'):
            marker.write_text(json.dumps({'format':'gameserver-indy-state','version':version,'access_layout':'shared-data'}))
            with self.assertRaises(ManagerError):
                state_directory(str(self.root))
        marker.write_bytes(original)
        (self.root / 'data/session-key').unlink()
        with self.assertRaises(ManagerError):
            state_directory(str(self.root))
        self.assertFalse((self.root / 'data/session-key').exists())

    @unittest.skipUnless(os.name == 'posix', 'Shared controller runs under WSL/Linux.')
    def test_same_signing_key_and_cli_root_are_used_with_lease_before_composition(self):
        self.prepare()
        self.assertEqual(state_directory(str(self.root)), self.root.resolve())
        self.assertEqual(signing_token(self.root, 'local-owner'),hmac.new(('a'*43).encode(),b'local-owner',hashlib.sha256).hexdigest())
        def compose(*,root):
            self.assertEqual(root,self.root.resolve())
            with self.assertRaises(ManagerError):
                with controller_lease(root):
                    self.fail('Controller lease missing before composition.')
            return object()
        with patch.object(sys,'argv',['indy','--state-dir',str(self.root)]), patch.object(cli,'ServerRegistry',side_effect=compose), patch.object(cli,'serve') as serve:
            cli.main()
            serve.assert_called_once()
        with controller_lease(self.root):
            pass
        self.assertFalse((self.root / 'session-key').exists())

    @unittest.skipUnless(os.name == 'posix', 'POSIX controller lease.')
    def test_lease_rejects_symlinks_and_releases_after_an_exception(self):
        target=self.root/'unrelated'; target.write_text('keep')
        lock=self.root/'.indy-controller.lock'; lock.symlink_to(target)
        with self.assertRaises(ManagerError):
            with controller_lease(self.root):
                pass
        self.assertEqual(target.read_text(),'keep'); lock.unlink()
        with self.assertRaisesRegex(RuntimeError,'own failure'):
            with controller_lease(self.root):
                raise RuntimeError('own failure')
        with controller_lease(self.root):
            pass

    @unittest.skipUnless(os.name == 'posix', 'POSIX flock compatibility.')
    def test_php_and_python_contend_for_the_same_lifetime_lease(self):
        php=shutil.which(os.environ.get('SLICE_PHP','php'))
        if not php:
            self.skipTest('Optional local PHP for cross-language flock.')
        lock=str(self.root/'.indy-controller.lock')
        with controller_lease(self.root):
            result=subprocess.run([php,'-r',"$h=fopen($argv[1],'c+b'); exit(flock($h,LOCK_EX|LOCK_NB)?0:23);",lock],capture_output=True,timeout=10)
            self.assertEqual(result.returncode,23,result.stderr)
        child=subprocess.Popen([php,'-r',"$h=fopen($argv[1],'c+b'); if(!flock($h,LOCK_EX|LOCK_NB))exit(24); echo 'ready'.PHP_EOL; fflush(STDOUT); sleep(20);",lock],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            self.assertEqual(child.stdout.readline(),'ready\n')
            with self.assertRaises(ManagerError):
                with controller_lease(self.root):
                    pass
        finally:
            child.kill(); child.communicate(timeout=10)
        with controller_lease(self.root):
            pass

    def test_imported_diagnostic_binding_survives_fallback_and_is_not_exposed_as_registration_input(self):
        logs=self.root/'original-owned-logs'; logs.mkdir()
        profile={'id':'aska','game':'aska','label':'Own ASKA','server_directory':str(self.root),
            'diagnostic_directory':str(logs)}
        ServerRegistry.validate_profile(profile)
        manager=GameServerManager(self.root,bridge=object(),profile=profile,settings={})
        self.assertEqual(manager.diagnostic_directory,logs)
        self.assertNotEqual(manager.data,self.root/'original-owned-logs')
        bad=dict(profile,diagnostic_directory='relative/path')
        with self.assertRaises(ManagerError):
            ServerRegistry.validate_profile(bad)
        (self.root/'manager.local.json').write_text(json.dumps({'servers':[profile]}),encoding='utf-8')
        registry=ServerRegistry(self.root)
        registry.register({'id':'second','game':'aska','label':'Second','server_directory':str(self.root/'second'),
            'diagnostic_directory':str(logs)})
        self.assertNotIn('diagnostic_directory',registry.get('second').profile)
        self.assertEqual(registry.get('aska').diagnostic_directory,logs)

    @unittest.skipUnless(os.name == 'posix', 'Prepared shared state is WSL-only.')
    def test_explicit_cli_rejects_incomplete_databases_before_composition_without_recreating_them(self):
        self.prepare()
        for relative in ('data/access.sqlite3','data/platform.sqlite3','data/whatsapp/deliveries.sqlite3'):
            path=self.root/relative; original=path.read_bytes()
            for missing in (True,False):
                if missing:
                    path.unlink()
                else:
                    path.write_bytes(b'')
                before={p.relative_to(self.root):p.read_bytes() for p in self.root.rglob('*') if p.is_file() and p.name!='.indy-controller.lock'}
                with patch.object(sys,'argv',['indy','--state-dir',str(self.root)]), patch.object(cli,'ServerRegistry') as registry, patch.object(cli,'serve') as serve:
                    with self.assertRaises(SystemExit):
                        cli.main()
                    registry.assert_not_called(); serve.assert_not_called()
                after={p.relative_to(self.root):p.read_bytes() for p in self.root.rglob('*') if p.is_file() and p.name!='.indy-controller.lock'}
                self.assertEqual(after,before,'Invalid prepared state must not be silently initialized.')
                path.write_bytes(original)

    def test_preflight_rejects_unknown_profiles_key_schema_and_delivery_states_without_changing_claims(self):
        self.prepare(); validate_prepared_state(self.root)
        config=self.root/'manager.local.json'; original=config.read_bytes()
        config.write_text('{}')
        with self.assertRaises(ManagerError):
            validate_prepared_state(self.root)
        config.write_bytes(original)
        key=self.root/'data/session-key'; original_key=key.read_bytes(); key.write_text('invalid')
        with self.assertRaises(ManagerError):
            validate_prepared_state(self.root)
        key.write_bytes(original_key)
        settings=self.root/'data/whatsapp/settings.json'; original_settings=settings.read_bytes()
        configured=json.loads(original_settings); configured.update(group_id='12345@g.us',group_label='Own group',enabled=True,server_ids=['enshrouded'])
        settings.write_text(json.dumps(configured),encoding='utf-8')
        with self.assertRaises(ManagerError):
            validate_prepared_state(self.root)
        self.assertFalse((self.root/'data/whatsapp/credentials.json').exists())
        settings.write_bytes(original_settings)
        access=self.root/'data/access.sqlite3'
        db=sqlite3.connect(access)
        try:
            db.execute('PRAGMA user_version=99'); db.commit()
        finally:
            db.close()
        with self.assertRaises(ManagerError):
            validate_prepared_state(self.root)
        db=sqlite3.connect(access)
        try:
            db.execute('PRAGMA user_version=1'); db.commit()
        finally:
            db.close()
        deliveries=self.root/'data/whatsapp/deliveries.sqlite3'; db=sqlite3.connect(deliveries)
        try:
            for state in ('sending','sent','uncertain'):
                db.execute('INSERT INTO deliveries VALUES (?,?,?,?,?)',('enshrouded',state,'12345@g.us',state,'2026-10-16T00:00:00Z'))
            db.commit()
        finally:
            db.close()
        original=deliveries.read_bytes(); validate_prepared_state(self.root)
        self.assertEqual(deliveries.read_bytes(),original,'Preflight never reconciles sending claims.')
        db=sqlite3.connect(deliveries)
        try:
            db.execute("UPDATE deliveries SET state='invented'"); db.commit()
        finally:
            db.close()
        with self.assertRaises(ManagerError):
            validate_prepared_state(self.root)


if __name__ == '__main__':
    unittest.main()
