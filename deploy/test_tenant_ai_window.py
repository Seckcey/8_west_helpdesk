"""Root Linux operator rehearsal: real files/locks, synthetic service and DB seams."""
import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import tenant_ai_freeze as freeze
import tenant_ai_window as window


@unittest.skipUnless(hasattr(os,'geteuid') and os.geteuid()==0,'root Linux fixture required')
class WindowTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='tenant-ai-window-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.root.chmod(0o700)
        self.evidence=self.root/'evidence';self.evidence.mkdir(mode=0o700)
        self.candidate=self.root/'candidate';self.candidate.mkdir(mode=0o700)
        for name in window.SOURCE_FILES:
            path=self.candidate/name;path.parent.mkdir(parents=True,exist_ok=True)
            freeze.create_private(path,b'Synthetic reviewed source.\n')
        self.app=self.root/'app';(self.app/'current/public').mkdir(parents=True)
        self.vhost=self.root/'vhost.conf'
        body=(f'<VirtualHost *:443>\nServerName example.test\nDocumentRoot {self.app}/current/public\n'
              f'<Directory {self.app}/current/public>\nAllowOverride All\nRequire all granted\n</Directory>\n</VirtualHost>\n').encode()
        freeze.create_private(self.vhost,body)
        self.link=self.root/'enabled.conf';self.link.symlink_to(self.vhost)
        self.cron=self.root/'mixed-cron'
        freeze.create_private(self.cron,b'# synthetic\n* * * * * root php /unrelated/worker.php\n* * * * * root php /synthetic/worker.php\n')
        self.original_vhost=body;self.original_cron=self.cron.read_bytes()
        self.lock_records=[]
        for name in ['shared.lock','app.lock']:
            path=self.root/name;freeze.create_private(path,b'')
            self.lock_records.append({'path':str(path),'uid':0,'gid':0,'mode':0o600})
        def record(path):return {'path':str(path),**freeze.read_physical(path)[1]}
        self.profile={'app':'id','app_root':str(self.app),'hostnames':['example.test'],'probe_paths':['/login.php'],
            'vhost':{**record(self.vhost),'enabled_link':str(self.link)},
            'crons':[{**record(self.cron),'selected_lines':[3]}],'units':{},'locks':self.lock_records,'worker_paths':[]}
        self.facts={'identity':{'database':'synthetic','server_uuid':'11111111-1111-1111-1111-111111111111'},
            'server_version':'8.0.46','accounts':{'synthetic@localhost':{'user':'synthetic','host':'localhost','locked':False}},
            'grants':{'synthetic@localhost':'a'*64}}
        self.calls=[];self.closed=False
        self.locks=window.Locks(self.profile).__enter__();self.addCleanup(self.locks.__exit__,None,None,None)
        self.subject=window.Window(self.profile,self.candidate,'1'*40,self.evidence,self.locks)
        self.generation={'parent':os.getpid(),'parent_start':freeze.proc_identity(os.getpid()),'children':{'99999999':'1'}}
        self.addCleanup(patch.stopall)
        patch.object(window,'apache_generation',return_value=self.generation).start()
        patch.object(window,'checked_command',return_value='').start()
        patch.object(freeze,'checked_command',side_effect=lambda args:'403' if args[0]=='curl' else '').start()
        patch.object(window,'app_worker_pids',return_value=[]).start()
        patch.object(freeze,'app_worker_pids',return_value=[]).start()
        patch.object(self.subject,'child',side_effect=self.child).start()

    def child(self,action,**extra):
        self.locks.verify();self.calls.append(action)
        if action=='inspect':return copy.deepcopy(self.facts)
        if action=='lock-account':self.closed=True;return {'ok':True}
        if action=='unlock-account':self.closed=False;return {'ok':True}
        if action=='verify':
            freeze.assert_system_closed(self.subject.journal,self.profile)
            if not self.closed:raise RuntimeError('synthetic writer still open')
            return {'identity':self.facts['identity'],'freeze_id':self.subject.journal.intent['freeze_id'],'writers_closed':True}
        raise RuntimeError('unexpected synthetic action')

    def test_complete_freeze_verify_explicit_abort_reopen(self):
        result=self.subject.freeze();self.assertTrue(result['frozen']);self.assertTrue(self.closed)
        self.assertIn(b'Require all denied',self.vhost.read_bytes())
        self.assertIn(b'* * * * * root php /unrelated/worker.php\n',self.cron.read_bytes())
        self.subject.require_closed()
        self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertFalse(self.closed);self.assertEqual(self.vhost.read_bytes(),self.original_vhost)
        self.assertEqual(self.cron.read_bytes(),self.original_cron)

    def test_reopen_requires_explicit_acceptance(self):
        self.subject.freeze()
        with self.assertRaises(RuntimeError):self.subject.unfreeze({'decision':'abort-before-ddl'})
        self.assertTrue(self.closed);self.assertIn(b'all denied',self.vhost.read_bytes())

    def test_partial_ddl_cannot_use_pre_ddl_abort(self):
        self.subject.freeze();freeze.create_private(self.evidence/'tenant-ai-intent.json',b'{}\n')
        with self.assertRaises(RuntimeError):self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertTrue(self.closed)

    def test_changed_original_backup_refuses_before_any_reopen(self):
        self.subject.freeze()
        record=self.subject.journal.intent['files'][1]
        (self.evidence/record['original_file']).write_bytes(b'unrelated replacement\n')
        with self.assertRaises(RuntimeError):self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertNotIn('unlock-account',self.calls);self.assertTrue(self.closed)

    def test_unknown_grant_drift_refuses_reopen(self):
        self.subject.freeze();self.facts['grants']['unreviewed@localhost']='b'*64
        with self.assertRaises(RuntimeError):self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertTrue(self.closed)

    def test_preexisting_account_lock_is_preserved(self):
        self.facts['accounts']['synthetic@localhost']['locked']=True;self.closed=True
        self.subject.freeze();self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertNotIn('lock-account',self.calls);self.assertNotIn('unlock-account',self.calls);self.assertTrue(self.closed)

    def test_physical_mutex_replacement_refuses(self):
        path=Path(self.lock_records[0]['path']);path.unlink();freeze.create_private(path,b'')
        with self.assertRaises(RuntimeError):self.locks.verify()

    def test_selected_cron_requires_exact_active_lines(self):
        for numbers in ([0],[99],[1],[3,3]):
            with self.assertRaises(RuntimeError):window.selected_cron(self.original_cron,numbers,'synthetic')

    def test_reopen_resumes_exact_pending_file_rename(self):
        self.subject.freeze()
        journal=self.subject.journal
        window.create_json(self.evidence/'unfreeze-acceptance.json',{'intent_sha256':journal.intent_sha256,
            'accepted':True,'decision':'abort-before-ddl','receipt_sha256':None})
        journal.append('unlock-account','synthetic@localhost','done');self.closed=False
        record=journal.intent['files'][1]
        journal.append('reopen-file',record['path'],'intent')
        freeze.replace_exact(self.cron,self.cron.read_bytes(),self.original_cron)
        self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertEqual(self.vhost.read_bytes(),self.original_vhost)
        self.assertEqual(self.cron.read_bytes(),self.original_cron)
        self.assertNotIn('unlock-account',self.calls)

    def test_receipt_alone_does_not_reopen_without_fresh_final_schema(self):
        self.subject.freeze()
        raw=b'{"synthetic":"intent"}\n';freeze.create_private(self.evidence/'tenant-ai-intent.json',raw)
        receipt=json.dumps({'contract':'tenant-ai-migration-receipt-v1','intent_sha256':freeze.sha(raw),'state':'FINAL'}).encode()
        freeze.create_private(self.evidence/'tenant-ai-receipt.json',receipt)
        with self.assertRaises(RuntimeError):
            self.subject.unfreeze({'accepted':True,'decision':'accepted-release','receipt_sha256':freeze.sha(receipt)})
        self.assertIn('verify-final',self.calls);self.assertNotIn('unlock-account',self.calls)


if __name__=='__main__':unittest.main()
