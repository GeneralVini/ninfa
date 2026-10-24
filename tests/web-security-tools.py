"""Fault injection for native installer, independent of network or downloaded executables."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location('web_tools',Path(__file__).resolve().parents[1]/'scripts/web-security-tools.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class NativeToolsTest(unittest.TestCase):
    def test_atomic_install_repeat_and_corruption(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t); source=root/'psalm.phar'; source.write_bytes(b'fixture not executable')
            wrapper=b'#!/bin/sh\nexec php "$(dirname "$0")/../psalm.phar" "$@"\n'
            lock={'tools':{'psalm':{'version':'fixture','release':{'url':source.as_uri(),'sha256':module.digest(source)},'files':{'psalm.phar':module.digest(source),'bin/psalm':hashlib.sha256(wrapper).hexdigest()}}}}
            base=root/'.tools'
            self.assertEqual(module.install('psalm',base,lock)['state'],'installed')
            self.assertEqual(module.install('psalm',base,lock)['state'],'unchanged')
            (base/'psalm/psalm.phar').write_bytes(b'corrupt')
            with self.assertRaisesRegex(ValueError,'sha256 mismatch'):module.install('psalm',base,lock)
            self.assertEqual((base/'psalm/psalm.phar').read_bytes(),b'corrupt')

    def test_interruption_or_hash_failure_cannot_publish(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t);source=root/'source';source.write_bytes(b'fixture')
            for failure in ['hash','interrupt']:
                lock={'tools':{'psalm':{'version':'fixture','release':{'url':source.as_uri(),'sha256':'0'*64},'files':{}}}}
                class InterruptedStream:
                    def __enter__(self):
                        self.sent=False
                        return self
                    def __exit__(self,*args):
                        return False
                    def read(self,size=-1):
                        if self.sent:
                            raise OSError('interrupted after partial payload')
                        self.sent=True
                        return b'partial transfer'
                context=patch.object(module.urllib.request,'urlopen',return_value=InterruptedStream()) if failure=='interrupt' else patch.object(module,'run',side_effect=AssertionError('must never probe'))
                with context:
                    with self.assertRaises((ValueError,OSError)):module.install('psalm',root/'.tools',lock)
                self.assertFalse((root/'.tools/psalm').exists())
                self.assertEqual(list((root/'.tools').glob('.psalm-*')),[])

    def test_archive_escape_and_unlocked_payload(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t); archive=root/'bad.zip'
            with zipfile.ZipFile(archive,'w') as f:f.writestr('../escape','bad')
            with self.assertRaisesRegex(ValueError,'unsafe'):module.unzip(archive,root/'out')
            (root/'extra').write_text('surprise')
            with self.assertRaisesRegex(ValueError,'unlocked'):module.verify(root,{'bad.zip':module.digest(archive)})

    def test_resolver_uses_cli_and_refuses_untrusted_override(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t); consumer=root/'consumer'; consumer.mkdir(); (consumer/'vendor/bin').mkdir(parents=True)
            binary=consumer/'vendor/bin/nuclei'; binary.write_text('#!/bin/sh\nexit 99\n');binary.chmod(0o755)
            import shutil
            self.assertEqual(module.resolve('nuclei',consumer,module.ROOT/'.tools',shutil.which('php')),binary)
            lock=json.loads((module.ROOT/'security/web-tools.lock.json').read_text())
            # Replace costly unrelated checks; selected consumer must not be executed.
            original=module.run
            def checked(args,**kwargs):
                if str(args[0])==str(binary):raise AssertionError('untrusted override executed')
                if str(args[0]).endswith('/zap/bin/zap'):
                    self.assertIn('-dir',args)
                    home=Path(args[args.index('-dir')+1])
                    self.assertTrue(home.parent.is_dir())
                    self.assertTrue(home.parent.name.startswith('ninfa-zap-doctor-'))
                return original(args,**kwargs)
            with patch.object(module,'verify'),patch.object(module,'run',side_effect=checked):
                d=module.doctor(consumer,module.ROOT/'.tools',lock)
            self.assertEqual(d['sources']['nuclei']['state'],'unavailable')
            self.assertIn('override',d['sources']['nuclei']['cause'])
            self.assertIn('osv',d['sources'])

    def test_version_permission_and_missing_requirement_are_independent(self):
        with tempfile.TemporaryDirectory() as t:
            base=Path(t)/'.tools';binary=base/'nuclei/bin/nuclei';binary.parent.mkdir(parents=True)
            binary.write_text('#!/bin/sh\nprintf "9.9.9\\n"\n');binary.chmod(0o755)
            lock=json.loads((module.ROOT/'security/web-tools.lock.json').read_text())
            lock['tools']['nuclei']['files']={'bin/nuclei':module.digest(binary)}
            result=module.doctor(Path(t)/'consumer',base,lock)
            self.assertIn('version mismatch',result['sources']['nuclei']['cause'])
            self.assertIn('osv',result['sources'])
            binary.chmod(0o600)
            result=module.doctor(Path(t)/'consumer',base,lock)
            self.assertEqual(result['sources']['nuclei']['state'],'unavailable')
            self.assertIn('permissão',result['sources']['nuclei']['cause'])
            original=module.run
            def missing_extensions(args,**kwargs):
                if '-r' in args:
                    import subprocess
                    return subprocess.CompletedProcess(args,0,'["8.5.4",[]]','')
                return original(args,**kwargs)
            with patch.object(module,'run',side_effect=missing_extensions):
                result=module.doctor(Path(t)/'consumer',base,lock)
            self.assertIn('extensions missing',result['sources']['php']['cause'])
            self.assertIn('runtime unavailable',result['sources']['psalm']['cause'])
            self.assertEqual(result['sources']['osv']['state'],'unknown')

    def test_consumer_destination_hidden_by_symlink_is_rejected(self):
        import subprocess
        import sys
        with tempfile.TemporaryDirectory() as t:
            root=Path(t);consumer=root/'consumer';consumer.mkdir()
            target=consumer/'.tools';target.mkdir()
            alias=root/'outside';alias.symlink_to(consumer,target_is_directory=True)
            result=subprocess.run([sys.executable,str(module.ROOT/'scripts/web-security-tools.py'),
                                   'install','psalm','--tools-dir',str(alias/'.tools'),
                                   '--project',str(consumer)],text=True,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertIn('outside the consumer',result.stderr)
            self.assertEqual(list(target.iterdir()),[])

    def test_timeout_reaps_process_group(self):
        import subprocess
        import sys
        import time
        with tempfile.TemporaryDirectory() as t:
            pidfile=Path(t)/'child.pid'
            code='import subprocess,time,pathlib; p=subprocess.Popen(["'+sys.executable+'","-c","import time;time.sleep(20)"]);pathlib.Path('+repr(str(pidfile))+').write_text(str(p.pid));time.sleep(20)'
            with self.assertRaises(subprocess.TimeoutExpired):
                module.run([sys.executable,'-c',code],timeout=0.5)
            pid=int(pidfile.read_text())
            state=Path('/proc')/str(pid)/'stat'
            if state.exists():
                self.assertEqual(state.read_text().split()[2],'Z')


if __name__=='__main__':unittest.main()
