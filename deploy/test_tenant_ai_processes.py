"""Real Linux scheduler launcher/child discovery, without live app or DB access."""
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

import tenant_ai_freeze as freeze


def wait_for(predicate):
    deadline=time.monotonic()+5
    while time.monotonic()<deadline:
        if predicate():return
        time.sleep(.01)
    raise AssertionError('synthetic process readiness timeout')


@unittest.skipUnless(Path('/proc/self/stat').is_file() and shutil.which('php') and shutil.which('flock'),
                     'Linux PHP/flock process fixture required')
class ProcessTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='tenant-ai-process-')
        self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.app=self.root/'app'
        (self.app/'current/db').mkdir(parents=True)
        self.profile={'app_root':str(self.app),'worker_paths':[]}
        self.worker=self.app/'current/db/worker.php'
        self.worker.write_text('<?php file_put_contents($argv[1],(string)getmypid()); '
                               'while(!file_exists($argv[2])) { usleep(10000); }')
        self.ready=self.root/'ready';self.release=self.root/'release'

    def launch(self,paused=False,worker=None,cwd=None,php_options=()):
        command=[shutil.which('flock'),'-n',str(self.root/'worker.lock'),shutil.which('php'),
                 *php_options,str(worker or self.worker),str(self.ready),str(self.release)]
        if paused:command=['/bin/sh','-c','kill -STOP $$; exec "$@"','synthetic-cron',*command]
        process=subprocess.Popen(command,start_new_session=True,cwd=cwd)
        def finish():
            self.release.touch()
            if process.poll() is None:
                os.killpg(process.pid,signal.SIGCONT)
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid,signal.SIGTERM);process.wait(timeout=5)
        self.addCleanup(finish)
        if paused:wait_for(lambda:'State:\tT' in Path(f'/proc/{process.pid}/status').read_text())
        else:wait_for(self.ready.exists)
        return process

    def test_paused_pre_exec_shell_is_not_reported_drained(self):
        process=self.launch(paused=True);identity=freeze.proc_identity(process.pid)
        self.assertIn(str(process.pid),freeze.app_worker_pids(self.profile))
        self.assertEqual(freeze.proc_identity(process.pid),identity)
        self.assertFalse(self.ready.exists())

    def test_flock_parent_and_php_child_remain_scoped_until_exit(self):
        process=self.launch();child=self.ready.read_text()
        self.assertEqual(set(freeze.app_worker_pids(self.profile)),{str(process.pid),child})
        self.release.touch();self.assertEqual(process.wait(timeout=5),0)
        self.assertEqual(freeze.app_worker_pids(self.profile),[])

    def test_unrelated_launcher_and_child_are_preserved(self):
        other=self.root/'other-app/current/db/worker.php';other.parent.mkdir(parents=True)
        other.write_bytes(self.worker.read_bytes())
        process=self.launch(paused=True,worker=other)
        self.assertNotIn(str(process.pid),freeze.app_worker_pids(self.profile))
        os.kill(process.pid,signal.SIGCONT);wait_for(self.ready.exists)
        self.assertEqual(freeze.app_worker_pids(self.profile),[])
        self.assertIsNone(process.poll())

    def test_absolute_scoped_worker_with_deleted_cwd_remains_visible(self):
        directory=self.root/'gone';directory.mkdir()
        process=self.launch(cwd=directory);child=self.ready.read_text()
        directory.rmdir()
        self.assertIsNotNone(freeze.proc_identity(child))
        self.assertIn(child,freeze.app_worker_pids(self.profile))
        self.assertIsNone(process.poll())

    def test_relative_worker_with_deleted_cwd_refuses_inspection(self):
        directory=self.root/'gone';directory.mkdir()
        process=self.launch(worker='../app/current/db/worker.php',cwd=directory)
        directory.rmdir()
        with self.assertRaisesRegex(RuntimeError,'live process path became unreadable'):
            freeze.app_worker_pids(self.profile)
        self.assertIsNone(process.poll())

    def test_absolute_unrelated_worker_with_deleted_cwd_is_preserved(self):
        other=self.root/'other-app/current/db/worker.php';other.parent.mkdir(parents=True)
        other.write_bytes(self.worker.read_bytes())
        directory=self.root/'gone';directory.mkdir()
        process=self.launch(worker=other,cwd=directory);directory.rmdir()
        self.assertEqual(freeze.app_worker_pids(self.profile),[])
        self.assertIsNone(process.poll())

    def test_process_vanished_during_lookup_is_allowed_to_drain(self):
        process=self.launch();original=Path.read_bytes;visited=False
        def vanished(path):
            nonlocal visited
            if str(path)==f'/proc/{process.pid}/cmdline':
                visited=True;self.release.touch();process.wait(timeout=5)
                self.assertIsNone(freeze.proc_identity(process.pid))
                raise FileNotFoundError('synthetic proc lookup after actual exit')
            return original(path)
        with patch.object(Path,'read_bytes',vanished):
            self.assertEqual(freeze.app_worker_pids(self.profile),[])
        self.assertTrue(visited)

    def test_changed_pid_start_identity_refuses_scoped_snapshot(self):
        process=self.launch(paused=True);original=freeze.proc_identity;calls=0
        def changed(pid):
            nonlocal calls
            if str(pid)==str(process.pid):
                calls+=1;return 'original-start' if calls==1 else 'replacement-start'
            return original(pid)
        with patch.object(freeze,'proc_identity',side_effect=changed):
            with self.assertRaisesRegex(RuntimeError,'process identity changed'):
                freeze.app_worker_pids(self.profile)

    def test_unreadable_scoped_process_is_not_silently_drained(self):
        process=self.launch(paused=True);original=Path.read_bytes
        def unreadable(path):
            if str(path)==f'/proc/{process.pid}/cmdline':raise PermissionError('synthetic denied proc read')
            return original(path)
        with patch.object(Path,'read_bytes',unreadable):
            with self.assertRaises(PermissionError):freeze.app_worker_pids(self.profile)

    def server(self,scoped=True):
        root=self.app/'current' if scoped else self.root/'unrelated'
        public=root/'public';public.mkdir(parents=True,exist_ok=True)
        router=root/'router.php';router.write_text('<?php echo "synthetic";')
        log=self.root/'server.log'
        with log.open('wb') as output:
            process=subprocess.Popen([shutil.which('php'),'-n','-S','127.0.0.1:0',str(router)],
                cwd=public,stdout=output,stderr=output,start_new_session=True)
        def finish():
            if process.poll() is None:process.terminate();process.wait(timeout=5)
        self.addCleanup(finish)
        wait_for(lambda:b'Development Server' in log.read_bytes() or process.poll() is not None)
        self.assertIsNone(process.poll())
        return process

    def test_host_server_docroot_keeps_scoped_process_in_drain(self):
        process=self.server()
        self.assertIn(str(process.pid),freeze.app_worker_pids(self.profile))

    def test_unrelated_host_server_endpoint_is_not_a_relative_script(self):
        process=self.server(scoped=False)
        self.assertEqual(freeze.app_worker_pids(self.profile),[])
        self.assertIsNone(process.poll())

    def test_host_root_service_namespace_preserves_script_scope(self):
        # The live host has service mount namespaces with the same physical
        # root. Retain real process/root/cwd/mount data, varying only ns identity.
        process=self.launch();child=self.ready.read_text();original=freeze.process_view
        def service_view(entry):
            view=original(entry)
            return ('mnt:[synthetic-service]',*view[1:]) if entry.name==child else view
        with patch.object(freeze,'process_view',side_effect=service_view):
            self.assertIn(child,freeze.app_worker_pids(self.profile))

    def test_unrelated_host_root_service_namespace_can_be_excluded(self):
        process=self.server(scoped=False);original=freeze.process_view
        def service_view(entry):
            view=original(entry)
            return ('mnt:[synthetic-service]',*view[1:]) if entry.name==str(process.pid) else view
        with patch.object(freeze,'process_view',side_effect=service_view):
            self.assertEqual(freeze.app_worker_pids(self.profile),[])
        self.assertIsNone(process.poll())

    def test_network_namespace_handles_do_not_hide_scoped_worker(self):
        process=self.launch();child=self.ready.read_text();original=Path.read_text
        # Actual Linux nsfs mountinfo root grammar observed on the target host.
        handle='99999 23 0:5 net:[4026532774] /run/docker/netns/synthetic rw - nsfs nsfs rw\n'
        def with_handle(path,*args,**kwargs):
            value=original(path,*args,**kwargs)
            return value+handle if path.name=='mountinfo' else value
        with patch.object(Path,'read_text',with_handle):
            self.assertIn(child,freeze.app_worker_pids(self.profile))

    def test_changed_mount_inventory_refuses_live_process_exclusion(self):
        process=self.server(scoped=False);original=Path.read_text;reads=0
        def changed(path,*args,**kwargs):
            nonlocal reads
            value=original(path,*args,**kwargs)
            if str(path)==f'/proc/{process.pid}/mountinfo':
                reads+=1
                if reads>1:value+='99999 23 0:5 net:[4026532774] /run/docker/netns/new rw - nsfs nsfs rw\n'
            return value
        with patch.object(Path,'read_text',changed):
            with self.assertRaisesRegex(RuntimeError,'filesystem or command identity changed'):
                freeze.app_worker_pids(self.profile)

    def configuration_worker(self,options):
        other=self.root/'other.php';other.write_text('<?php /* synthetic main */')
        process=self.launch(worker=other,php_options=options)
        child=self.ready.read_text();original=freeze.process_view
        def service_view(entry):
            view=original(entry)
            return ('mnt:[synthetic-service]',*view[1:]) if entry.name==child else view
        return process,child,service_view

    def test_real_scoped_prepend_in_shared_root_namespace_is_drained(self):
        process,child,view=self.configuration_worker(['-d','auto_prepend_file='+str(self.worker)])
        with patch.object(freeze,'process_view',side_effect=view):
            self.assertIn(child,freeze.app_worker_pids(self.profile))

    def test_real_scoped_append_with_compact_option_is_drained(self):
        process,child,view=self.configuration_worker(['-dauto_append_file='+str(self.worker)])
        with patch.object(freeze,'process_view',side_effect=view):
            self.assertIn(child,freeze.app_worker_pids(self.profile))

    def test_custom_ini_execution_scope_is_not_read_or_excluded(self):
        config=self.root/'custom.ini';config.write_text('auto_prepend_file='+str(self.worker)+'\n')
        process,child,view=self.configuration_worker(['-c',str(config)])
        with patch.object(freeze,'process_view',side_effect=view):
            with self.assertRaisesRegex(RuntimeError,'configuration execution scope requires review'):
                freeze.app_worker_pids(self.profile)


if __name__=='__main__':unittest.main()
