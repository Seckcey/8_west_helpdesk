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
        patch.object(window,'probe_origin',return_value=None).start()
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

    def partial_freeze(self):
        with patch.object(window,'assert_system_closed',side_effect=RuntimeError('late TLS/closure failure')):
            with self.assertRaises(RuntimeError):self.subject.freeze()
        self.assertFalse(self.closed);self.assertNotIn('lock-account',self.calls)

    def partial_abort(self):
        return self.subject.unfreeze({'accepted':True,'decision':'abort-pre-account'})

    def test_tls_prerequisite_failure_leaves_files_and_evidence_untouched(self):
        with patch.object(window,'probe_origin',side_effect=RuntimeError('certificate mismatch')):
            with self.assertRaises(RuntimeError):self.subject.freeze()
        self.assertEqual(list(self.evidence.iterdir()),[])
        self.assertEqual(self.vhost.read_bytes(),self.original_vhost)
        self.assertEqual(self.cron.read_bytes(),self.original_cron)
        self.assertEqual(self.calls,[])

    def test_additional_http_vhost_is_frozen_and_restored(self):
        http=self.root/'http.conf';freeze.create_private(http,self.original_vhost.replace(b'*:443',b'*:80'))
        link=self.root/'http-enabled.conf';link.symlink_to(http)
        self.profile['additional_vhosts']=[{'path':str(http),'enabled_link':str(link),**freeze.read_physical(http)[1]}]
        original=http.read_bytes()
        self.subject.freeze();self.assertIn(b'all denied',http.read_bytes())
        self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
        self.assertEqual(http.read_bytes(),original);self.assertEqual(self.vhost.read_bytes(),self.original_vhost)

    def test_preaccount_abort_after_reload_restores_without_fake_freeze_or_account_work(self):
        self.partial_freeze();result=self.partial_abort()
        self.assertEqual(result,{'reopened':True,'decision':'abort-pre-account','completed_freeze':False})
        self.assertEqual(self.vhost.read_bytes(),self.original_vhost);self.assertEqual(self.cron.read_bytes(),self.original_cron)
        self.assertFalse(self.subject.journal.completed('freeze','id'))
        self.assertFalse((self.evidence/'unfreeze-acceptance.json').exists())
        self.assertNotIn('lock-account',self.calls);self.assertNotIn('unlock-account',self.calls)
        with self.assertRaises(RuntimeError):self.subject.freeze()

    def test_partial_abort_preserves_never_replaced_resource_inode(self):
        original=window.replace_recorded;inode=self.cron.stat().st_ino
        def fail_cron(journal,record,*args,**kwargs):
            if record['path']==str(self.cron):raise RuntimeError('before second resource')
            return original(journal,record,*args,**kwargs)
        with patch.object(window,'replace_recorded',side_effect=fail_cron):
            with self.assertRaises(RuntimeError):self.subject.freeze()
        self.partial_abort()
        self.assertEqual(self.cron.stat().st_ino,inode);self.assertEqual(self.vhost.read_bytes(),self.original_vhost)

    def test_partial_abort_accepts_only_recorded_pending_freeze_rename(self):
        append=freeze.Journal.append
        def fail_done(journal,action,resource,state,detail=None):
            if action=='freeze-file' and state=='done':raise RuntimeError('after exact rename')
            return append(journal,action,resource,state,detail)
        with patch.object(freeze.Journal,'append',new=fail_done):
            with self.assertRaises(RuntimeError):self.subject.freeze()
        self.partial_abort();self.assertEqual(self.vhost.read_bytes(),self.original_vhost)

    def test_partial_abort_resumes_recorded_pending_reopen_without_unlocking(self):
        self.partial_freeze();append=self.subject.journal.append
        def fail_done(action,resource,state,detail=None):
            if action=='reopen-file' and state=='done':raise RuntimeError('after exact reopen rename')
            return append(action,resource,state,detail)
        with patch.object(self.subject.journal,'append',side_effect=fail_done):
            with self.assertRaises(RuntimeError):self.partial_abort()
        self.partial_abort();self.assertEqual(self.vhost.read_bytes(),self.original_vhost)
        self.assertEqual(self.cron.read_bytes(),self.original_cron);self.assertNotIn('unlock-account',self.calls)

    def assert_partial_refuses_without_restoring(self):
        before=self.vhost.read_bytes();cron=self.cron.read_bytes()
        with self.assertRaises(RuntimeError):self.partial_abort()
        self.assertEqual(self.vhost.read_bytes(),before);self.assertEqual(self.cron.read_bytes(),cron)
        self.assertFalse((self.evidence/'partial-unfreeze-acceptance.json').exists())
        self.assertNotIn('unlock-account',self.calls)

    def test_partial_abort_refuses_different_original_process(self):
        self.partial_freeze()
        with patch.object(self.locks,'identity',return_value={'pid':-1}):self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_replaced_real_mutex(self):
        self.partial_freeze();p=Path(self.lock_records[0]['path']);new=self.root/'new-lock'
        freeze.create_private(new,b'');os.replace(new,p)
        self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_unknown_frozen_inode(self):
        self.partial_freeze();new=self.root/'new-vhost';freeze.create_private(new,self.vhost.read_bytes())
        os.replace(new,self.vhost);self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_corrupt_original_evidence(self):
        self.partial_freeze();record=self.subject.journal.intent['files'][0]
        (self.evidence/record['original_file']).write_bytes(b'changed')
        self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_changed_account_state(self):
        self.partial_freeze();self.facts['accounts']['synthetic@localhost']['locked']=True
        self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_changed_grants(self):
        self.partial_freeze();self.facts['grants']['synthetic@localhost']='b'*64
        self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_any_account_attempt(self):
        self.partial_freeze();self.subject.journal.append('lock-account','synthetic@localhost','intent')
        self.assert_partial_refuses_without_restoring()

    def test_partial_abort_refuses_capture_or_ddl_artifact(self):
        self.partial_freeze()
        for filename in ('database.sql','capture.json','tenant-ai-intent.json','tenant-ai-receipt.json'):
            with self.subTest(filename=filename):
                p=self.evidence/filename;freeze.create_private(p,b'{}')
                self.assert_partial_refuses_without_restoring();p.unlink()

    def test_partial_abort_refuses_completed_freeze(self):
        self.subject.freeze();self.assert_partial_refuses_without_restoring()

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

    def desktop_receipt(self):
        patch.object(freeze,'probe_origin',return_value=None).start()
        self.profile['app']='safeharbor';self.subject.freeze()
        raw=b'{"contract":"synthetic-desktop-receipt"}\n'
        freeze.create_private(self.evidence/'desktop-receipt.json',raw)
        return {'accepted':True,'decision':'accepted-desktop-release','receipt_sha256':freeze.sha(raw)}

    def test_every_desktop_stage_prevents_no_ddl_abort(self):
        self.subject.freeze()
        for name in window.DESKTOP_EVIDENCE:
            with self.subTest(name=name):
                path=self.evidence/name;freeze.create_private(path,b'{}\n')
                with self.assertRaises(RuntimeError):self.subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'})
                self.assertNotIn('unlock-account',self.calls);path.unlink()

    def test_desktop_work_cannot_use_tenant_ai_acceptance(self):
        self.desktop_receipt()
        with self.assertRaises(RuntimeError):self.subject.unfreeze({'accepted':True,'decision':'accepted-release'})
        self.assertNotIn('verify-final',self.calls);self.assertNotIn('unlock-account',self.calls)

    def test_desktop_receipt_requires_original_process(self):
        request=self.desktop_receipt()
        with patch.object(self.locks,'identity',return_value={'pid':-1}):
            with self.assertRaises(RuntimeError):self.subject.unfreeze(request)
        self.assertNotIn('verify-desktop-final',self.calls);self.assertNotIn('unlock-account',self.calls)

    def test_desktop_acceptance_is_safeharbor_only(self):
        request=self.desktop_receipt();self.profile['app']='id'
        with self.assertRaises(RuntimeError):self.subject.unfreeze(request)
        self.assertNotIn('unlock-account',self.calls)

    def test_desktop_receipt_digest_is_explicit(self):
        request=self.desktop_receipt();request['receipt_sha256']='0'*64
        with self.assertRaises(RuntimeError):self.subject.unfreeze(request)
        self.assertNotIn('verify-desktop-final',self.calls);self.assertNotIn('unlock-account',self.calls)

    def test_desktop_receipt_alone_cannot_reopen(self):
        request=self.desktop_receipt()
        with self.assertRaises(RuntimeError):self.subject.unfreeze(request)
        self.assertIn('verify-desktop-final',self.calls);self.assertNotIn('unlock-account',self.calls)

    def test_desktop_verified_acceptance_restores_only_after_fresh_closure(self):
        request=self.desktop_receipt()
        original=self.child
        def verified(action,**extra):
            if action=='verify-desktop-final':self.calls.append(action);return {'desktop_final_verified':True}
            return original(action,**extra)
        with patch.object(self.subject,'child',side_effect=verified):self.subject.unfreeze(request)
        self.assertLess(self.calls.index('verify-desktop-final'),self.calls.index('unlock-account'))
        self.assertEqual(self.cron.read_bytes(),self.original_cron)
        self.assertEqual(self.vhost.read_bytes(),self.original_vhost)
        self.assertFalse(self.closed)


if __name__=='__main__':unittest.main()
