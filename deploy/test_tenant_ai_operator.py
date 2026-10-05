"""Opt-in Coastline container rehearsal: real MySQL/FDs/capture/DDL, fake Apache/systemd.

Run in an owned, disposable root container with /src/{id,safeharbor} read-only,
the task's /tmp directory, existing heavy-test mutex and Docker socket mounted.
No production configuration, credentials, data or provider calls are used.
"""
import fcntl
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from unittest.mock import patch


def command(args, **kwargs):
    return subprocess.run(args, check=True, stderr=subprocess.PIPE, **kwargs)


def main():
    if (os.environ.get('TENANT_AI_OPERATOR_REHEARSAL') != '1' or os.geteuid() != 0
            or socket.gethostname() != 'coastline' or not Path('/.dockerenv').is_file()):
        raise RuntimeError('explicit disposable Coastline container required')
    app=sys.argv[1]
    if app not in ('id','safeharbor'):
        raise RuntimeError('fixed rehearsal application required')
    desktop=len(sys.argv)==3 and sys.argv[2]=='desktop'
    if len(sys.argv)>3 or (len(sys.argv)==3 and (not desktop or app!='safeharbor')):
        raise RuntimeError('desktop rehearsal is Safeharbor only')
    source=Path('/src')/app
    candidate=Path(tempfile.mkdtemp(prefix='tenant-ai-candidate-'))
    shutil.copytree(source,candidate,dirs_exist_ok=True)
    for path in candidate.rglob('*'):
        if path.is_file():path.chmod(0o600)
    sys.path.insert(0,str(candidate/'deploy'))
    import tenant_ai_freeze as freeze
    import tenant_ai_window as window
    from tenant_ai_scratch import restore, create_json, digest
    from tenant_ai_restore import make_policy
    task=Path('/tmp/tenant-ai-01a106cf')
    evidence=task/('operator-'+app+'-'+os.urandom(6).hex());evidence.mkdir(mode=0o700)
    profile=json.loads((candidate/'deploy/tenant_ai_freeze_profile.json').read_text())
    # Only host/resource metadata is synthetic; application identity, canonical
    # migration, SQL writer verifier, PHP operator and lock paths remain real.
    profile['host']='coastline';profile['units']={};profile['worker_paths']=[]
    app_root=Path(tempfile.mkdtemp(prefix='tenant-ai-application-'))
    (app_root/'current/public').mkdir(parents=True)
    freeze.create_private(app_root/'current/public/index.php',b'<?php /* synthetic application backup */\n')
    profile['app_root']=str(app_root);profile['hostnames']=['synthetic.test']
    (app_root/'current/config').mkdir()
    freeze.create_private(app_root/'current/config/config.php',b"<?php return ['db'=>['name'=>'safeharbor']];\n")
    profile['probe_paths']=['/index.php'];profile['mysql_socket']='/var/run/mysqld/mysqld.sock'
    vhost=app_root/'vhost.conf'
    freeze.create_private(vhost,(f'<VirtualHost *:443>\nServerName synthetic.test\nDocumentRoot {app_root}/current/public\n'
        f'<Directory {app_root}/current/public>\nAllowOverride All\nRequire all granted\n</Directory>\n</VirtualHost>\n').encode())
    enabled=app_root/'enabled.conf';enabled.symlink_to(vhost)
    http=app_root/'http.conf';freeze.create_private(http,vhost.read_bytes().replace(b'*:443',b'*:80'))
    http_enabled=app_root/'http-enabled.conf';http_enabled.symlink_to(http)
    cron=app_root/'mixed-cron';freeze.create_private(cron,b'# Synthetic\n* * * * * root php /unrelated.php\n* * * * * root php /synthetic.php\n')
    def record(path):return {'path':str(path),**freeze.read_physical(path)[1]}
    profile['vhost']={**record(vhost),'enabled_link':str(enabled)}
    if app=='safeharbor':profile['additional_vhosts']=[{**record(http),'enabled_link':str(http_enabled)}]
    profile.pop('probe_tls_hostname',None)
    profile['crons']=[{**record(cron),'selected_lines':[3]}]
    for lock in profile['locks']:
        path=Path(lock['path']);path.parent.mkdir(parents=True,exist_ok=True)
        freeze.create_private(path,b'');path.chmod(lock['mode']);os.chown(path,lock['uid'],lock['gid'])
    (candidate/'deploy/tenant_ai_freeze_profile.json').write_text(json.dumps(profile),encoding='utf-8')
    # The child verifier runs in another interpreter. Patch only service/process
    # probes there; real file fingerprints, journal chain and SQL checks remain.
    seams=Path(tempfile.mkdtemp(prefix='tenant-ai-service-seams-'))
    (seams/'sitecustomize.py').write_text(
        'import sys\nsys.path.insert(0,'+repr(str(candidate/'deploy'))+')\n'
        'import tenant_ai_freeze as f\n'
        'f.checked_command=lambda args: "403" if args[0]=="curl" else ""\n'
        'f.probe_origin=lambda profile,closed=False: None\n'
        'f.app_worker_pids=lambda profile: []\n',encoding='utf-8')
    os.environ['PYTHONPATH']=str(seams);os.environ['PYTHONDONTWRITEBYTECODE']='1'
    name='tenant-ai-operator-db-'+app+('-desktop' if desktop else '')+'-01a106cf'
    mutex=os.fdopen(os.open('/tmp/8west-coastline-heavy-tests.lock',os.O_RDWR|os.O_NOFOLLOW),'r+b')
    fcntl.flock(mutex,fcntl.LOCK_EX|fcntl.LOCK_NB)
    available=next(int(s.split()[1]) for s in Path('/proc/meminfo').read_text().splitlines() if s.startswith('MemAvailable:'))
    if available<1152*1024:raise RuntimeError('rehearsal memory guard refused')
    if subprocess.run(['docker','inspect',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:
        raise RuntimeError('prior fixture preserved; inspect before retry')
    image=command(['docker','image','inspect','--format','{{.Id}}','mysql:8.0.46'],stdout=subprocess.PIPE).stdout.decode().strip()
    # This named socket volume was created for and mounted in this driver only.
    volume=os.environ['TENANT_AI_REHEARSAL_SOCKET_VOLUME']
    if volume!=('tenant-ai-desktop-socket-01a106cf' if desktop else 'tenant-ai-operator-socket-01a106cf'):
        raise RuntimeError('fixed owned socket volume required')
    container=command(['docker','run','-d','--name',name,'--label','com.8west.task=tenant-ai-01a106cf',
        '--network','none','--memory','512m','--memory-swap','512m','--cpus','.75',
        '-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','-e','MYSQL_ROOT_HOST=localhost',
        '-v',volume+':/var/run/mysqld',image,'--innodb-buffer-pool-size=64M','--max-connections=20',
        '--performance-schema=OFF','--event-scheduler=OFF'],stdout=subprocess.PIPE).stdout.decode().strip()
    def ready():
        for attempt in range(90):
            probe=subprocess.run(['docker','exec',container,'mysql','-uroot','-Nse','SELECT @@port'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
            if probe.returncode==0 and probe.stdout.strip()==b'3306':return
            time.sleep(1)
        raise RuntimeError('fixture database startup')
    original_run=subprocess.run
    def capture_command(args,**kwargs):
        if args[0]=='mysqldump':
            args=['docker','exec',container,'mysqldump','-uroot',*window.DUMP_FLAGS,profile['database']]
        return original_run(args,**kwargs)
    try:
        ready()
        database=profile['database']
        users=(['ewid','ewid_customer_projector','ewid_signup_worker'] if app=='id'
               else ['safeharbor','safeharbor_signup_worker','safeharbor_time_export'])
        sql='CREATE DATABASE `'+database+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;\n'
        for user in users:
            sql+="CREATE USER '"+user+"'@'localhost'; GRANT ALL ON `"+database+"`.* TO '"+user+"'@'localhost';\n"
        command(['docker','exec','-i',container,'mysql','-uroot'],input=sql.encode(),stdout=subprocess.DEVNULL)
        bootstrap='''define('TAI_MIGRATION_LIBRARY_ONLY',true);require $argv[1].'/deploy/tenant_ai_migration.php';
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$argv[2].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema=file_get_contents($argv[1].'/app/db/schema.sql');
$schema=preg_replace('/-- BEGIN TENANT AI[^\\n]*\\n[\\s\\S]+?-- END TENANT AI[^\\n]*(?:\\n|$)/','',$schema,1,$n);
if($n!==1)throw new RuntimeException('canonical block absent');
foreach(tai_migration_sql_statements($schema) as $statement)$pdo->exec($statement);
'''
        if desktop:
            bootstrap=bootstrap.replace('BEGIN TENANT AI','BEGIN DESKTOP SESSION AUTHORITY').replace('END TENANT AI','END DESKTOP SESSION AUTHORITY')
        command(['php','-r',bootstrap,str(candidate),database],stdout=subprocess.DEVNULL)
        generation={'parent':os.getpid(),'parent_start':freeze.proc_identity(os.getpid()),'children':{'99999999':'1'}}
        with patch.object(window,'apache_generation',return_value=generation),\
             patch.object(window,'probe_origin',return_value=None),\
             patch.object(freeze,'probe_origin',return_value=None),\
             patch.object(window,'checked_command',return_value=''),\
             patch.object(freeze,'checked_command',side_effect=lambda args:'403' if args[0]=='curl' else ''),\
             patch.object(window,'app_worker_pids',return_value=[]),\
             patch.object(freeze,'app_worker_pids',return_value=[]),\
             patch.object(subprocess,'run',side_effect=capture_command),\
             window.Locks(profile) as locks:
            subject=window.Window(profile,candidate,'1'*40,evidence,locks)
            subject.freeze();subject.require_closed();subject.capture();subject.capture()
            # Preserve the held release descriptors. Stop only the synthetic DB,
            # release the shared test slot, and restore in a separate server.
            command(['docker','stop','--time','30',container],stdout=subprocess.DEVNULL)
            fcntl.flock(mutex,fcntl.LOCK_UN)
            # Reviewed synthetic ID dump lines 154/155: MySQL expands the
            # implicit utf8mb4 character set on precisely these two columns.
            # Real captures require their own independently reviewed line pins.
            column_lines=[154,155] if app=='id' else [3586,3587,3588,3589,3679,3680]
            if desktop:
                # Independently inspected desktop fixture with tenant-AI already
                # installed: its attempt-state column adds one exact DDL pin.
                column_lines=[3528,3638,3639,3640,3641,3731,3732]
            if app=='id':
                lines=(evidence/'database.sql').read_bytes().splitlines()
                assert lines[153:155]==[
                    b'  `business` varchar(128) COLLATE utf8mb4_bin NOT NULL,',
                    b'  `full_name` varchar(100) COLLATE utf8mb4_bin NOT NULL,']
            else:
                lines=(evidence/'database.sql').read_bytes().splitlines()
                assert [lines[n-1] for n in column_lines]==([
                    b"  `state` enum('pending','complete','unavailable') COLLATE utf8mb4_bin NOT NULL,"] if desktop else [])+[
                    b"  `state` enum('draft','sent','expired') COLLATE utf8mb4_bin NOT NULL DEFAULT 'draft',",
                    b'  `subject` varchar(190) COLLATE utf8mb4_bin DEFAULT NULL,',
                    b'  `body` text COLLATE utf8mb4_bin,',
                    b"  `priority` enum('low','normal','high','urgent') COLLATE utf8mb4_bin NOT NULL DEFAULT 'normal',",
                    b"  `state` enum('pending','complete','unavailable') COLLATE utf8mb4_bin NOT NULL,",
                    b'  `input_text` text COLLATE utf8mb4_bin,']
            create_json(evidence/'policy.json',make_policy(evidence/'database.sql',column_lines))
            restore(evidence/'database.sql',evidence/'backup-identity.json',evidence/'policy.json',
                digest(evidence/'policy.json'),image,evidence/'restore-proof.json')
            fcntl.flock(mutex,fcntl.LOCK_EX|fcntl.LOCK_NB)
            command(['docker','start',container],stdout=subprocess.DEVNULL);ready()
            subject.require_closed()
            negatives=0
            def refuses(call):
                nonlocal negatives
                try:call()
                except RuntimeError:negatives+=1;return
                raise AssertionError('protected operator accepted an invalid state')
            if desktop:
                apply=lambda:subject.child('apply-desktop')
                verify=lambda:subject.child('verify-desktop-final')
                # Missing/changed recovery evidence must refuse before creating
                # the outer intent or either target table.
                path=evidence/'database.sql';raw=path.read_bytes();path.write_bytes(raw+b'changed')
                refuses(apply);path.write_bytes(raw)
                path=evidence/'restore-proof.json';path.rename(evidence/'held-restore.json')
                refuses(apply);(evidence/'held-restore.json').rename(path)
                assert not (evidence/'desktop-window-intent.json').exists()
                payload=candidate/'app/db/migrations/desktop_portal_sessions_v1.sql';raw=payload.read_bytes()
                payload.write_bytes(raw+b'-- changed\n');refuses(apply);payload.write_bytes(raw)
                orphan=evidence/'desktop-receipt.json';freeze.create_private(orphan,b'{}\n')
                refuses(apply);orphan.unlink()
                first=apply();assert first['desktop_final_verified'] is True
                receipt=evidence/'desktop-receipt.json';receipt_bytes=receipt.read_bytes()
                assert apply()==first and verify()==first and receipt.read_bytes()==receipt_bytes
                refuses(lambda:subject.child('apply'))
                refuses(lambda:subject.unfreeze({'accepted':True,'decision':'abort-before-ddl'}))
                refuses(lambda:subject.unfreeze({'accepted':True,'decision':'accepted-release'}))
                request={'accepted':True,'decision':'accepted-desktop-release','receipt_sha256':digest(receipt)}
                refuses(lambda:subject.unfreeze({**request,'receipt_sha256':'0'*64}))
                for name in ('desktop-window-intent.json','desktop-intent.json','desktop-receipt.json','desktop-before.sql','restore-proof.json'):
                    path=evidence/name;raw=path.read_bytes();path.write_bytes(b'{}\n')
                    refuses(verify);path.write_bytes(raw)
                outer=evidence/'desktop-window-intent.json';raw=outer.read_bytes()
                changed=json.loads(raw);changed['target']='2'*40
                outer.write_text(json.dumps(changed),encoding='utf-8');refuses(verify);outer.write_bytes(raw)
                outer.rename(evidence/'held-outer.json');refuses(verify);refuses(apply)
                (evidence/'held-outer.json').rename(outer)
                receipt.rename(evidence/'held-receipt.json');refuses(verify);refuses(apply)
                (evidence/'held-receipt.json').rename(receipt)
                table='portal_desktop_bindings'
                command(['docker','exec',container,'mysql','-uroot',database,'-e','ALTER TABLE '+table+' ADD synthetic_drift INT'],stdout=subprocess.DEVNULL)
                refuses(verify)
                command(['docker','exec',container,'mysql','-uroot',database,'-e','ALTER TABLE '+table+' DROP COLUMN synthetic_drift'],stdout=subprocess.DEVNULL)
                sql="INSERT INTO portal_desktop_handoffs VALUES(REPEAT('a',32),REPEAT('b',32),REPEAT('c',64),'approved',JSON_OBJECT('private','fixture'),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
                command(['docker','exec',container,'mysql','-uroot',database,'-e',sql],stdout=subprocess.DEVNULL)
                refuses(verify)
                command(['docker','exec',container,'mysql','-uroot',database,'-e','DELETE FROM portal_desktop_handoffs'],stdout=subprocess.DEVNULL)
                assert verify()==first
                subject.unfreeze(request)
                final=subject.child('inspect')
                assert all(not account['locked'] for account in final['accounts'].values())
                assert b'Require all granted' in vhost.read_bytes()
                assert cron.read_bytes()==b'# Synthetic\n* * * * * root php /unrelated.php\n* * * * * root php /synthetic.php\n'
                print('Safeharbor desktop: real locks, capture, independent restore, guarded helper DDL, original receipt, empty schema and explicit reopen PASS')
                print(str(negatives)+' actual desktop protected-operator refusal cases PASS')
                print('Synthetic evidence: '+str(evidence))
                return
            # Test only synthetic evidence/resources. Preserve and restore each
            # exact original before proceeding to the positive apply path.
            dump=evidence/'database.sql';dump_bytes=dump.read_bytes()
            dump.write_bytes(dump_bytes+b'-- changed synthetic evidence\n')
            refuses(lambda:subject.child('apply'));dump.write_bytes(dump_bytes)
            payload=candidate/'app/db/migrations/20261004_tenant_ai.sql';payload_bytes=payload.read_bytes()
            payload.write_bytes(payload_bytes+b'-- changed synthetic source\n')
            refuses(lambda:subject.child('apply'));payload.write_bytes(payload_bytes)
            orphan=evidence/'tenant-ai-receipt.json';freeze.create_private(orphan,b'{}\n')
            refuses(lambda:subject.child('apply'));orphan.unlink()
            table='tenant_ai_credentials' if app=='id' else 'portal_westy_ai_attempts'
            partial='''define('TAI_MIGRATION_LIBRARY_ONLY',true);require $argv[1].'/deploy/tenant_ai_migration.php';
$pdo=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname='.$argv[2].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(tai_migration_sql_statements(file_get_contents($argv[1].'/'.TAI_MIGRATION_PATH)) as $sql)
if(str_starts_with($sql,'CREATE TABLE ')){$pdo->exec($sql);break;}
'''
            command(['php','-r',partial,str(candidate),database],stdout=subprocess.DEVNULL)
            refuses(lambda:subject.child('apply'))
            command(['docker','exec',container,'mysql','-uroot',database,'-e','DROP TABLE `'+table+'`'],stdout=subprocess.DEVNULL)
            first=subject.child('apply');assert first['state']=='applied'
            second=subject.child('apply');assert second['state']=='already_applied'
            assert subject.child('verify-final')=={'final_verified':True}
            receipt_path=evidence/'tenant-ai-receipt.json';receipt_bytes=receipt_path.read_bytes()
            receipt_path.rename(evidence/'synthetic-held-receipt.json')
            refuses(lambda:subject.child('apply'))
            (evidence/'synthetic-held-receipt.json').rename(receipt_path)
            receipt_path.write_bytes(b'{}\n');refuses(lambda:subject.child('verify-final'));receipt_path.write_bytes(receipt_bytes)
            command(['docker','exec',container,'mysql','-uroot',database,'-e','ALTER TABLE `'+table+'` ADD COLUMN synthetic_drift INT NULL'],stdout=subprocess.DEVNULL)
            refuses(lambda:subject.child('verify-final'))
            command(['docker','exec',container,'mysql','-uroot',database,'-e','ALTER TABLE `'+table+'` DROP COLUMN synthetic_drift'],stdout=subprocess.DEVNULL)
            receipt=digest(evidence/'tenant-ai-receipt.json')
            subject.unfreeze({'accepted':True,'decision':'accepted-release','receipt_sha256':receipt})
            final=subject.child('inspect')
            assert all(not account['locked'] for account in final['accounts'].values())
            assert b'Require all granted' in vhost.read_bytes()
            assert cron.read_bytes()==b'# Synthetic\n* * * * * root php /unrelated.php\n* * * * * root php /synthetic.php\n'
            print(app+': actual inherited locks, writer freeze/drain, capture, independent full restore, apply/replay, final schema and explicit reopen PASS')
            print(str(negatives)+' actual protected-operator refusal cases PASS')
            print('Synthetic evidence: '+str(evidence))
    finally:
        command(['docker','rm','--force','--volumes',container],stdout=subprocess.DEVNULL)
        assert not command(['docker','ps','-aq','--filter','id='+container],stdout=subprocess.PIPE).stdout.strip()
        mutex.close()


if __name__=='__main__':main()
