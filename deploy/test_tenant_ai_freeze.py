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
    def test_safeharbor_http_shape_also_requires_exact_reviewed_bytes(self):
        original=Path(__file__).with_name('apache-safeharbor.conf').read_bytes()
        closed=freeze.frozen_vhost(original,'safeharbor.8westit.com','/srv/8west/apps/safeharbor/current/public')
        self.assertIn(b'<VirtualHost *:80>',closed);self.assertIn(b'<Location />\n        Require all denied',closed)
        self.assertIn(b'RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]',closed)
        with self.assertRaises(RuntimeError):
            freeze.frozen_vhost(original+b'# drift\n','safeharbor.8westit.com','/srv/8west/apps/safeharbor/current/public')

    def test_probe_keeps_verified_canonical_tls_and_each_alias_host_without_following(self):
        profile={'app':'safeharbor','hostnames':['safeharbor.8westit.com','www.safeharbor.8westit.com'],
                 'probe_tls_hostname':'safeharbor.8westit.com','probe_paths':['/login.php','/portal/'],
                 'vhost':{},'additional_vhosts':[{}]}
        with patch.object(freeze,'checked_command',return_value='403') as command:
            freeze.probe_origin(profile,closed=True)
        self.assertEqual(command.call_count,8)
        for call in command.call_args_list:
            args=call.args[0]
            self.assertNotIn('--location',args);self.assertNotIn('--insecure',args);self.assertNotIn('-k',args)
            self.assertEqual(args[:4],['curl','-q','--noproxy','*'])
            if args[-1].startswith('https:'):self.assertTrue(args[-1].startswith('https://safeharbor.8westit.com/'))
        self.assertEqual(sum('Host: www.safeharbor.8westit.com' in c.args[0] for c in command.call_args_list),4)
        with patch.object(freeze,'checked_command',return_value='301\nhttps://unrelated.example/login.php'):
            with self.assertRaises(RuntimeError):freeze.probe_origin(profile,closed=True)
        with patch.object(freeze,'checked_command',side_effect=RuntimeError('TLS refused')):
            with self.assertRaises(RuntimeError):freeze.probe_origin(profile)

    def test_reviewed_safeharbor_shape_preserves_unrelated_bytes(self):
        original=Path(__file__).with_name('apache-safeharbor-le-ssl.conf').read_bytes()
        self.assertEqual(freeze.sha(original),'8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43')
        closed=freeze.frozen_vhost(original,'safeharbor.8westit.com','/srv/8west/apps/safeharbor/current/public')
        expected=original.replace(b'        AllowOverride All\n        Require all granted\n',
                                  b'        AllowOverride None\n        AllowOverrideList None\n        Require all denied\n')
        expected=expected.replace(b'</VirtualHost>\n',b'    <Location />\n        Require all denied\n    </Location>\n</VirtualHost>\n')
        self.assertEqual(closed,expected)
        self.assertIn(b'Options FollowSymLinks',closed)
        self.assertIn(b'DirectoryIndex index.php index.html',closed)

    def test_safeharbor_unknown_authorization_and_scope_refuse(self):
        original=Path(__file__).with_name('apache-safeharbor-le-ssl.conf').read_bytes()
        extras=(b'<Location />\nRequire all granted\n</Location>\n',
                b'<Directory /srv/8west/apps/safeharbor/current/public/assets>\nRequire all granted\n</Directory>\n',
                b'Alias /other /srv/unreviewed\n',b'Include /etc/unreviewed.conf\n',
                b'<If "true">\nRequire all granted\n</If>\n')
        variants=[original+original,original.replace(b'DocumentRoot ',b'DocumentRoot /other\nDocumentRoot '),
                  original.replace(b'AllowOverride All',b'AllowOverride AuthConfig'),
                  original.replace(b'Require all granted',b'Require all granted\nRequire local'),
                  original.replace(b'ServerName safeharbor.8westit.com',b'ServerName other.example')]
        variants += [original.replace(b'</VirtualHost>',extra+b'</VirtualHost>') for extra in extras]
        for altered in variants:
            with self.subTest(change=freeze.sha(altered)):
                with self.assertRaisesRegex(RuntimeError,'differs from reviewed shape'):
                    freeze.frozen_vhost(altered,'safeharbor.8westit.com','/srv/8west/apps/safeharbor/current/public')
        with self.assertRaisesRegex(RuntimeError,'differs from reviewed shape'):
            freeze.frozen_vhost(original,'safeharbor.8westit.com','/srv/8west/apps/other/current/public')

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
        # Allocate while the original inode still exists. Unlink/recreate may
        # legitimately reuse that freed inode on the runner's filesystem.
        replacement=self.root/'replacement';freeze.create_private(replacement,self.path.read_bytes())
        self.assertNotEqual(replacement.stat().st_ino,self.path.stat().st_ino)
        os.replace(replacement,self.path)
        with self.assertRaises(RuntimeError):
            freeze.assert_files_closed(self.journal)


if __name__=='__main__':
    unittest.main()
