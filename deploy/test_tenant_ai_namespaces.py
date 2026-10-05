"""Opt-in real distinct-mount-namespace tests with disposable Docker peers.

Run on the authorized Coastline test host with an existing immutable PHP/Python
image in TENANT_AI_NAMESPACE_TEST_IMAGE. No production mounts, host PID/network,
Docker socket mount, image pull/build, or privileged container is used.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
import uuid


IMAGE=os.environ.get('TENANT_AI_NAMESPACE_TEST_IMAGE','')


@unittest.skipUnless(IMAGE.startswith('sha256:') and shutil.which('docker'),
                     'explicit existing Coastline namespace-fixture image required')
class NamespaceTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='tenant-ai-namespace-')
        self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name)
        self.app=self.root/'app';self.other=self.root/'other-app'
        for root in (self.app,self.other):
            (root/'current/public').mkdir(parents=True)
            (root/'current/db').mkdir()
            (root/'current/router.php').write_text('<?php echo "synthetic";')
            (root/'current/db/worker.php').write_text('<?php while(true) { usleep(10000); }')
        self.name='tenant-ai-namespace-'+uuid.uuid4().hex[:12]
        self.addCleanup(self.cleanup_container)

    def docker(self,*args,check=True):
        return subprocess.run(['docker',*args],capture_output=True,text=True,timeout=45,check=check)

    def cleanup_container(self):
        for name in (self.name+'-scan',self.name):
            result=self.docker('ps','-aq','--filter','name=^/'+name+'$')
            if result.stdout.strip():
                owner=self.docker('inspect','--format','{{index .Config.Labels "com.8west.namespace-fixture"}}',name).stdout.strip()
                self.assertEqual(owner,self.name)
                self.docker('rm','--force',name)
                self.assertFalse(self.docker('ps','-aq','--filter','name=^/'+name+'$').stdout.strip())

    def start(self,scoped=False,server=True,relative=False,extra_mount=None,command=None):
        source=self.app if scoped else self.other
        args=['run','-d','--name',self.name,'--label','com.8west.namespace-fixture='+self.name,'--network','none','--memory','64m','--memory-swap','64m',
              '--cpus','.25','--pids-limit','32','--read-only','--cap-drop','ALL',
              '--mount',f'type=bind,src={source}/current,dst=/var/www/html,readonly',
              '--workdir','/var/www/html/public' if server else '/var/www/html/db']
        if extra_mount:args+=['--mount',extra_mount]
        command=command or (['php','-n','-S','0.0.0.0:8080','/var/www/html/router.php'] if server else ['php','-n','worker.php' if relative else '/var/www/html/db/worker.php'])
        self.docker(*args,IMAGE,*command)
        deadline=time.monotonic()+5
        while time.monotonic()<deadline:
            running=self.docker('inspect','--format','{{.State.Running}}',self.name).stdout.strip()
            if running=='true':return
            time.sleep(.05)
        self.fail('synthetic interpreter did not start')

    def scan(self):
        source=Path(__file__).with_name('tenant_ai_freeze.py').resolve()
        # Joining only the target's PID namespace exposes its process to our
        # different mount namespace, reproducing the host/container procfs view.
        script='''import importlib.util,json,os
from pathlib import Path
s=importlib.util.spec_from_file_location('freeze','/control.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
assert os.readlink('/proc/1/ns/mnt')!=os.readlink('/proc/self/ns/mnt')
p={'app_root':'/scope/app','worker_paths':[]}
try: print(json.dumps({'pids':m.app_worker_pids(p)}))
except Exception as e: print(json.dumps({'error':type(e).__name__,'message':str(e)}))
'''
        result=self.docker('run','--rm','--name',self.name+'-scan','--label','com.8west.namespace-fixture='+self.name,'--network','none','--pid','container:'+self.name,
            '--memory','64m','--memory-swap','64m','--cpus','.25','--pids-limit','32','--read-only',
            '--cap-drop','ALL','--cap-add','SYS_PTRACE',
            '--mount',f'type=bind,src={source},dst=/control.py,readonly',
            '--mount',f'type=bind,src={self.app},dst=/scope/app,readonly',
            '-e','PYTHONDONTWRITEBYTECODE=1',IMAGE,'python3','-c',script)
        return json.loads(result.stdout)

    def test_unrelated_php_server_option_and_docroot_do_not_block(self):
        self.start()
        self.assertEqual(self.scan(),{'pids':[]})
        self.assertEqual(self.docker('inspect','--format','{{.State.Running}}',self.name).stdout.strip(),'true')

    def test_scoped_server_bind_mount_remains_in_drain(self):
        self.start(scoped=True)
        self.assertIn('1',self.scan().get('pids',[]))

    def test_scoped_relative_container_worker_remains_in_drain(self):
        self.start(scoped=True,server=False,relative=True)
        self.assertIn('1',self.scan().get('pids',[]))

    def test_scoped_absolute_container_worker_remains_in_drain(self):
        self.start(scoped=True,server=False)
        self.assertIn('1',self.scan().get('pids',[]))

    def test_opaque_foreign_execution_mode_is_not_silently_excluded(self):
        self.start(command=['php','-n','-r','while(true) { usleep(10000); }'])
        self.assertIn('execution scope requires review',self.scan().get('message',''))

    def test_scoped_container_configuration_cannot_be_excluded(self):
        self.start(scoped=True,command=['php','-n','-d','auto_prepend_file=/var/www/html/db/worker.php','/var/www/html/router.php'])
        self.assertIn('configuration execution scope requires review',self.scan().get('message',''))

    def test_unrelated_relative_container_worker_is_proved_disjoint(self):
        self.start(server=False,relative=True)
        self.assertEqual(self.scan(),{'pids':[]})

    def test_other_script_with_scoped_file_bind_cannot_be_excluded(self):
        self.start(extra_mount=f'type=bind,src={self.app}/current/db/worker.php,dst=/scoped-worker.php,readonly')
        self.assertIn('1',self.scan().get('pids',[]))

    def test_live_foreign_deleted_docroot_refuses_exclusion(self):
        self.start();(self.other/'current/public').rmdir()
        result=self.scan()
        self.assertIn('live process path became unreadable',result.get('message',''))

    def test_foreign_script_symlink_requires_review(self):
        router=self.other/'current/router.php';router.rename(router.with_name('actual.php'))
        router.symlink_to('actual.php')
        self.start()
        self.assertIn('symlink scope requires review',self.scan().get('message',''))


if __name__=='__main__':unittest.main()
