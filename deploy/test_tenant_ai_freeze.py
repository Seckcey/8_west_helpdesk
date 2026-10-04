"""Root Linux filesystem rehearsal with synthetic content only; no live services."""
import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import tenant_ai_freeze as freeze

VHOST = b'''<VirtualHost *:443>
ServerName id.8westit.com
DocumentRoot /srv/8west/apps/ewid/current/public
<Directory /srv/8west/apps/ewid/current/public>
AllowOverride All
Require all granted
</Directory>
</VirtualHost>
'''
CRON = b'''# synthetic mixed cron
* * * * * root php /srv/8west/apps/safeharbor/current/cron/a.php
* * * * * root php /srv/8west/apps/ewid/current/db/a.php
* * * * * root php /srv/8west/apps/milepost/current/cron/a.php
'''


class PureProfileTests(unittest.TestCase):
    def test_vhost_exact_scope(self):
        closed = freeze.frozen_vhost(VHOST,'id.8westit.com','/srv/8west/apps/ewid/current/public')
        self.assertEqual(closed,VHOST.replace(b'Require all granted',b'Require all denied'))
        for invalid in (VHOST+VHOST,VHOST.replace(b'id.8westit.com',b'other.example'),
                        VHOST.replace(b'AllowOverride All',b'AllowOverride None')):
            with self.assertRaises(RuntimeError):
                freeze.frozen_vhost(invalid,'id.8westit.com','/srv/8west/apps/ewid/current/public')

    def test_mixed_cron_preserves_every_unrelated_byte(self):
        result = freeze.frozen_cron(CRON,[r'/srv/8west/apps/ewid/current'],1,'synthetic-freeze-123')
        expected = CRON.replace(b'* * * * * root php /srv/8west/apps/ewid',
                                b'# tenant-ai-freeze synthetic-freeze-123 * * * * * root php /srv/8west/apps/ewid')
        self.assertEqual(result,expected)
        with self.assertRaises(RuntimeError):
            freeze.frozen_cron(CRON,[r'/srv/8west/apps/ewid/current'],2,'synthetic-freeze-123')


@unittest.skipUnless(hasattr(os,'geteuid') and os.geteuid()==0,'real root-owned Linux fixture required')
class JournalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='tenant-ai-freeze-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.root.chmod(0o700)
        self.path = self.root/'live.conf'
        freeze.create_private(self.path,VHOST)
        closed=VHOST.replace(b'Require all granted',b'Require all denied')
        freeze.create_private(self.root/'original.bin',VHOST)
        freeze.create_private(self.root/'frozen.bin',closed)
        self.record={'path':str(self.path),'original_file':'original.bin','frozen_file':'frozen.bin',
                     'original':freeze.read_physical(self.path)[1],'frozen_sha256':freeze.sha(closed)}
        self.journal=freeze.Journal(self.root,{'files':[self.record]})

    def test_freeze_and_separate_reopen_preserve_mode_and_bytes(self):
        freeze.replace_recorded(self.journal,self.record)
        freeze.assert_files_closed(self.journal)
        self.assertEqual(self.path.stat().st_mode&0o777,0o600)
        self.assertNotEqual(self.path.read_bytes(),VHOST)
        # Reading/resuming the journal cannot reopen it.
        resumed=freeze.Journal(self.root)
        freeze.assert_files_closed(resumed)
        freeze.replace_recorded(resumed,self.record,reopen=True)
        self.assertEqual(self.path.read_bytes(),VHOST)

    def test_failed_completion_keeps_closed_and_resumes_original_intent(self):
        real=self.journal.append
        def fail_after_rename(action,resource,state,detail=None):
            if state=='done':
                raise RuntimeError('synthetic power loss after rename')
            return real(action,resource,state,detail)
        with patch.object(self.journal,'append',side_effect=fail_after_rename):
            with self.assertRaises(RuntimeError):
                freeze.replace_recorded(self.journal,self.record)
        self.assertIn(b'Require all denied',self.path.read_bytes())
        resumed=freeze.Journal(self.root)
        freeze.replace_recorded(resumed,self.record)
        freeze.assert_files_closed(resumed)

    def test_unrecorded_frozen_state_is_refused(self):
        self.path.write_bytes(self.path.read_bytes().replace(b'all granted',b'all denied'))
        with self.assertRaises(RuntimeError):
            freeze.replace_recorded(self.journal,self.record)

    def test_unrelated_edit_is_never_overwritten(self):
        freeze.replace_recorded(self.journal,self.record)
        changed=self.path.read_bytes()+b'# later independent edit\n'
        self.path.write_bytes(changed)
        with self.assertRaises(RuntimeError):
            freeze.replace_recorded(self.journal,self.record,reopen=True)
        self.assertEqual(self.path.read_bytes(),changed)

    def test_original_evidence_change_is_refused(self):
        (self.root/'original.bin').write_bytes(VHOST+b'# changed\n')
        with self.assertRaises(RuntimeError):
            freeze.replace_recorded(self.journal,self.record)

    def test_missing_chain_record_is_refused(self):
        self.journal.append('one','synthetic','intent')
        self.journal.append('one','synthetic','done')
        (self.root/'event-000001.json').unlink()
        with self.assertRaises(RuntimeError):
            freeze.Journal(self.root)

    def test_chain_tamper_is_refused(self):
        self.journal.append('one','synthetic','intent')
        path=self.root/'event-000001.json'
        value=json.loads(path.read_text());value['previous_sha256']='0'*64
        path.write_text(json.dumps(value))
        with self.assertRaises(RuntimeError):
            freeze.Journal(self.root)

    def test_source_symlink_and_group_writable_file_refuse(self):
        linked=self.root/'linked';linked.symlink_to(self.path)
        with self.assertRaises(RuntimeError):
            freeze.read_physical(linked)
        self.path.chmod(0o660)
        with self.assertRaises(RuntimeError):
            freeze.replace_recorded(self.journal,self.record)

    def test_replaced_frozen_inode_invalidates_proof(self):
        freeze.replace_recorded(self.journal,self.record)
        data=self.path.read_bytes();self.path.unlink();freeze.create_private(self.path,data)
        with self.assertRaises(RuntimeError):
            freeze.assert_files_closed(self.journal)


if __name__=='__main__':
    unittest.main()
