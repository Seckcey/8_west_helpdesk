#!/usr/bin/env python3
"""Root-operated scoped release window. Read JSON actions from stdin while locks remain held.

Actions: freeze, verify, capture, apply, unfreeze, close. Failure/EOF never reopens
an application. Run only from the reviewed candidate on its pinned production host.
Scratch restoration is a separate explicit Coastline operation; this process stays
alive holding the original descriptors while the operator obtains that proof.
"""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys

from tenant_ai_freeze import (Journal, read_physical, create_private, replace_recorded,
    frozen_vhost, apache_generation, unit_state, checked_command, wait_until,
    app_worker_pids, assert_system_closed, assert_scheduler_inventory, proc_identity, sha)
from tenant_ai_scratch import DUMP_FLAGS, digest, create_json, private_directory, private_file

SOURCE_FILES = ['app/db/migrations/20261004_tenant_ai.sql','deploy/tenant_ai_migration.php',
    'deploy/tenant_ai_migration_catalog.json','deploy/tenant_ai_release.php',
    'deploy/tenant_ai_writers.php','deploy/tenant_ai_operator.php','deploy/tenant_ai_freeze.py',
    'deploy/tenant_ai_freeze_profile.json','deploy/tenant_ai_window.py',
    'deploy/tenant_ai_restore.py','deploy/tenant_ai_scratch.py']


def load_profile():
    return json.loads(read_physical(Path(__file__).with_name('tenant_ai_freeze_profile.json'))[0])


def expected_file(record):
    data, actual = read_physical(Path(record['path']))
    if any(actual[key] != record[key] for key in ('sha256','uid','gid','mode','inode')):
        raise RuntimeError('reviewed resource fingerprint changed')
    return data, actual


def selected_cron(original, lines, freeze_id):
    source=original.splitlines(keepends=True)
    if len(set(lines))!=len(lines) or not lines or any(type(n) is not int or n<1 or n>len(source) for n in lines):
        raise RuntimeError('reviewed cron selection invalid')
    for number in lines:
        line=source[number-1]
        if not line.strip() or line.lstrip().startswith(b'#'):
            raise RuntimeError('selected cron writer line is no longer active')
        source[number-1]=b'# tenant-ai-freeze '+freeze_id.encode('ascii')+b' '+line
    return b''.join(source)


class Locks:
    def __init__(self, profile):
        self.profile=profile
        self.streams={}

    def __enter__(self):
        try:
            for record in self.profile['locks']:
                path=Path(record['path'])
                if path.is_symlink() or path.resolve()!=path:
                    raise RuntimeError('physical existing mutex required')
                descriptor=os.open(path,os.O_RDWR|os.O_NOFOLLOW)
                stream=os.fdopen(descriptor,'r+b',buffering=0)
                self.streams[str(path)]=stream
                info=os.fstat(descriptor)
                if (info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode))!=(record['uid'],record['gid'],record['mode']):
                    raise RuntimeError('reviewed mutex owner/mode changed')
                fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.verify()
            return self
        except BaseException:
            self.__exit__(None,None,None)
            raise

    def __exit__(self,*unused):
        for stream in reversed(list(self.streams.values())):
            stream.close()
        self.streams={}

    def verify(self):
        seen=set()
        for record in self.profile['locks']:
            path=Path(record['path'])
            if path.is_symlink() or path.resolve()!=path:
                raise RuntimeError('mutex path replaced')
            live=path.stat();held=os.fstat(self.streams[str(path)].fileno())
            identity=(live.st_dev,live.st_ino)
            if (identity!=(held.st_dev,held.st_ino) or identity in seen
                    or (live.st_uid,live.st_gid,stat.S_IMODE(live.st_mode))!=(record['uid'],record['gid'],record['mode'])):
                raise RuntimeError('held mutex differs from reviewed physical resource')
            seen.add(identity)
            with path.open('rb') as probe:
                try:
                    fcntl.flock(probe,fcntl.LOCK_SH|fcntl.LOCK_NB)
                except BlockingIOError:
                    pass
                else:
                    raise RuntimeError('mutex is not already exclusively held')
            fcntl.flock(self.streams[str(path)],fcntl.LOCK_EX|fcntl.LOCK_NB)

    def mapping(self):
        return {path:stream.fileno() for path,stream in self.streams.items()}


class Window:
    def __init__(self,profile,candidate,target,evidence,locks):
        self.profile=profile;self.candidate=candidate;self.target=target;self.evidence=evidence;self.locks=locks
        private_directory(evidence)
        self.journal=Journal(evidence) if (evidence/'freeze-intent.json').exists() else None
        self.source_files={name:sha(read_physical(candidate/name)[0]) for name in SOURCE_FILES}
        if self.journal is not None:
            intent=self.journal.intent
            if (intent['app']!=profile['app'] or intent['target']!=target or intent['candidate']!=str(candidate)
                    or intent['source_files']!=self.source_files or intent['profile_sha256']!=sha(read_physical(candidate/'deploy/tenant_ai_freeze_profile.json')[0])):
                raise RuntimeError('original intent/candidate/profile differs; refusing resume')

    def child(self,action,**extra):
        self.locks.verify()
        if self.source_files!={name:sha(read_physical(self.candidate/name)[0]) for name in SOURCE_FILES}:
            raise RuntimeError('reviewed controls changed during window')
        request={'action':action,'lock_fds':self.locks.mapping(),**extra}
        if self.journal:
            request.update(evidence=str(self.evidence),intent_sha256=self.journal.intent_sha256)
        result=subprocess.run(['/usr/bin/php',str(self.candidate/'deploy/tenant_ai_operator.php')],
            input=json.dumps(request).encode(),stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
            pass_fds=tuple(self.locks.mapping().values()),timeout=300)
        if result.returncode or len(result.stdout)>65536:
            raise RuntimeError('protected database operator refused')
        return json.loads(result.stdout)

    def freeze(self):
        if self.journal is None:
            assert_scheduler_inventory(self.profile)
            database=self.child('inspect')
            freeze_id='tenant-ai-'+self.profile['app']+'-'+os.urandom(12).hex()
            files=[]
            for number,record in enumerate([self.profile['vhost'],*self.profile['crons']]):
                original,metadata=expected_file(record)
                if number==0:
                    enabled=Path(record['enabled_link'])
                    if not enabled.is_symlink() or str(enabled.resolve())!=record['path']:
                        raise RuntimeError('enabled vhost differs from reviewed physical file')
                    frozen=frozen_vhost(original,self.profile['hostnames'][0],self.profile['app_root']+'/current/public')
                else:
                    frozen=selected_cron(original,record['selected_lines'],freeze_id)
                before=f'resource-{number:02d}.original';after=f'resource-{number:02d}.frozen'
                create_private(self.evidence/before,original);create_private(self.evidence/after,frozen)
                files.append({'path':record['path'],'original':metadata,'original_file':before,
                              'frozen_file':after,'frozen_sha256':sha(frozen)})
            units={}
            for name,record in self.profile['units'].items():
                expected_file(record)
                current=unit_state(name)
                if current['FragmentPath']!=record['path'] or current['ActiveState'] not in ('active','inactive'):
                    raise RuntimeError('unit state needs explicit operator review')
                units[name]=current
            intent={'contract':'tenant-ai-freeze-intent-v1','app':self.profile['app'],'host':socket.gethostname(),
                'boot_id':Path('/proc/sys/kernel/random/boot_id').read_text().strip(),'freeze_id':freeze_id,
                'target':self.target,'candidate':str(self.candidate),'source_files':self.source_files,
                'profile_sha256':self.source_files['deploy/tenant_ai_freeze_profile.json'],
                'database':database,'files':files,'units':units,'apache':apache_generation()}
            self.journal=Journal(self.evidence,intent)
        if (self.evidence/'unfreeze-acceptance.json').exists():
            raise RuntimeError('this intent already entered explicit reopening')
        for record in self.journal.intent['files']:
            replace_recorded(self.journal,record)
        for name,original in self.journal.intent['units'].items():
            if name.endswith('.timer') and original['ActiveState']=='active' and not self.journal.completed('stop-timer',name):
                self.journal.append('stop-timer',name,'intent')
                checked_command(['systemctl','stop',name])
                if unit_state(name)['ActiveState']!='inactive':
                    raise RuntimeError('timer did not stop')
                self.journal.append('stop-timer',name,'done')
        if not self.journal.completed('apache-reload',self.profile['app']):
            self.journal.append('apache-reload',self.profile['app'],'intent')
            checked_command(['apache2ctl','configtest']);checked_command(['systemctl','reload','apache2'])
            self.journal.append('apache-reload',self.profile['app'],'done')
        old=self.journal.intent['apache']['children']
        wait_until(lambda:not any(proc_identity(pid)==start for pid,start in old.items()),'original Apache generation')
        wait_until(lambda:all(unit_state(name)['ActiveState']=='inactive' for name in self.profile['units']),'app services')
        wait_until(lambda:not app_worker_pids(self.profile),'app CLI workers')
        assert_system_closed(self.journal,self.profile)
        for key,original in self.journal.intent['database']['accounts'].items():
            if original['locked'] or self.journal.completed('lock-account',key):
                continue
            self.journal.append('lock-account',key,'intent')
            self.child('lock-account',account=key)
            self.journal.append('lock-account',key,'done')
        # Account lock prevents new connections but does not terminate old ones.
        # Let the existing work leave naturally; never KILL a database session.
        def drained():
            try:
                self.child('verify');return True
            except RuntimeError:
                return False
        wait_until(drained,'database writer connections')
        self.journal.append('freeze',self.profile['app'],'done')
        return {'frozen':True,'freeze_id':self.journal.intent['freeze_id'],'intent_sha256':self.journal.intent_sha256}

    def require_closed(self):
        if self.journal is None or not self.journal.completed('freeze',self.profile['app']) or (self.evidence/'unfreeze-acceptance.json').exists():
            raise RuntimeError('completed original freeze required')
        return self.child('verify')

    def artifact(self,name):
        path=self.evidence/name
        private_file(path)
        size=path.stat().st_size
        if size<1:
            raise RuntimeError('empty protected artifact')
        return {'file':name,'size':size,'sha256':digest(path)}

    def capture(self):
        self.require_closed()
        if (self.evidence/'capture.json').exists():
            capture=json.loads(read_physical(self.evidence/'capture.json',0o600)[0])
            if capture['intent_sha256']!=self.journal.intent_sha256:
                raise RuntimeError('capture belongs to another freeze')
            for name,value in capture['artifacts'].items():
                if self.artifact(name)!=value:
                    raise RuntimeError('original capture changed')
            return {'captured':True,'replayed':True}
        current=Path(self.profile['app_root'])/'current'
        if not current.is_dir() or current.is_symlink() or current.resolve()!=current:
            raise RuntimeError('reviewed physical current directory required')
        self.journal.append('capture',self.profile['app'],'intent')
        self.child('backup-accounts')
        commands={'database.sql':['mysqldump','--protocol=socket','--socket='+self.profile['mysql_socket'],'-uroot',*DUMP_FLAGS,self.profile['database']],
            'application.tar':['tar','--acls','--xattrs','--numeric-owner','--one-file-system','-cpf','-',
                               '-C',self.profile['app_root'],'current']}
        for name,command in commands.items():
            descriptor=os.open(self.evidence/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(descriptor,'wb') as stream:
                result=subprocess.run(command,stdout=stream,stderr=subprocess.DEVNULL,timeout=600)
                stream.flush();os.fsync(stream.fileno())
                if result.returncode:
                    raise RuntimeError('closed-window backup incomplete')
            self.require_closed()
        artifacts={name:self.artifact(name) for name in ('database.sql','application.tar','accounts.sql')}
        identity=self.journal.intent['database']['identity']
        create_json(self.evidence/'backup-identity.json',{'contract':'tenant-ai-backup-identity-v1','identity':identity,
            'server_version':self.journal.intent['database']['server_version'],'backup_sha256':artifacts['database.sql']['sha256']})
        create_json(self.evidence/'capture.json',{'intent_sha256':self.journal.intent_sha256,'identity':identity,'artifacts':artifacts})
        self.journal.append('capture',self.profile['app'],'done')
        return {'captured':True,'backup_sha256':artifacts['database.sql']['sha256']}

    def unfreeze(self,request):
        if self.journal is None or request.get('accepted') is not True:
            raise RuntimeError('explicit root acceptance for this intent required')
        decision=request.get('decision')
        if decision=='accepted-release':
            receipt=read_physical(self.evidence/'tenant-ai-receipt.json',0o600)[0]
            value=json.loads(receipt)
            migration_intent=read_physical(self.evidence/'tenant-ai-intent.json',0o600)[0]
            if (sha(receipt)!=request.get('receipt_sha256')
                    or value!={'contract':'tenant-ai-migration-receipt-v1','intent_sha256':sha(migration_intent),'state':'FINAL'}):
                raise RuntimeError('exact migration receipt acceptance required')
            # Read-only verification; reopening must never initiate or repair DDL.
            self.child('verify-final')
        elif decision=='abort-before-ddl':
            if (self.evidence/'tenant-ai-intent.json').exists() or (self.evidence/'tenant-ai-receipt.json').exists():
                raise RuntimeError('DDL may have started; reviewed recovery required')
        else:
            raise RuntimeError('explicit release acceptance or pre-DDL abort required')
        acceptance={'intent_sha256':self.journal.intent_sha256,'accepted':True,'decision':decision,
                    'receipt_sha256':request.get('receipt_sha256')}
        path=self.evidence/'unfreeze-acceptance.json'
        if path.exists():
            if json.loads(read_physical(path,0o600)[0])!=acceptance:
                raise RuntimeError('original acceptance differs')
        else:
            self.require_closed()
            create_json(path,acceptance)
        fresh=self.child('inspect')
        if fresh['identity']!=self.journal.intent['database']['identity'] or fresh['grants']!=self.journal.intent['database']['grants']:
            raise RuntimeError('database/grant drift prevents scoped reopening')
        # All original file evidence must be intact before reopening any writer.
        for record in self.journal.intent['files']:
            original=read_physical(self.evidence/record['original_file'],0o600)[0]
            current,metadata=read_physical(Path(record['path']))
            done=self.journal.completed('reopen-file',record['path'])
            pending=any(event['action']=='reopen-file' and event['resource']==record['path']
                        and event['state']=='intent' for event in self.journal.events)
            expected={record['original']['sha256']} if done else {record['frozen_sha256']}
            if pending:
                expected.add(record['original']['sha256'])
            if (sha(original)!=record['original']['sha256'] or sha(current) not in expected
                    or any(metadata[key]!=record['original'][key] for key in ('uid','gid','mode'))):
                raise RuntimeError('resource drift prevents scoped reopening')
        # On a resumed reopen, inspect every unit before unlocking another writer.
        for name,original in self.journal.intent['units'].items():
            current=unit_state(name)
            if current['fragment']!=original['fragment'] or current['UnitFileState']!=original['UnitFileState']:
                raise RuntimeError('unit drift prevents scoped reopening')
        for key,original in self.journal.intent['database']['accounts'].items():
            if original['locked'] or self.journal.completed('unlock-account',key):
                continue
            if not self.journal.completed('lock-account',key):
                raise RuntimeError('unowned account lock cannot be restored')
            self.journal.append('unlock-account',key,'intent')
            self.child('unlock-account',account=key)
            self.journal.append('unlock-account',key,'done')
        for record in self.journal.intent['files'][1:]:
            replace_recorded(self.journal,record,reopen=True)
        for name,original in self.journal.intent['units'].items():
            if name.endswith('.timer') and original['ActiveState']=='active' and not self.journal.completed('start-timer',name):
                current=unit_state(name)
                if current['fragment']!=original['fragment'] or current['UnitFileState']!=original['UnitFileState']:
                    raise RuntimeError('changed timer cannot be restored')
                self.journal.append('start-timer',name,'intent');checked_command(['systemctl','start',name])
                self.journal.append('start-timer',name,'done')
        replace_recorded(self.journal,self.journal.intent['files'][0],reopen=True)
        checked_command(['apache2ctl','configtest']);checked_command(['systemctl','reload','apache2'])
        checked_command(['systemctl','is-active','--quiet','apache2'])
        self.journal.append('unfreeze',self.profile['app'],'done')
        return {'reopened':True,'decision':decision}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=['run','verify-system'])
    parser.add_argument('--evidence',required=True,type=Path)
    parser.add_argument('--candidate',type=Path)
    parser.add_argument('--target')
    parser.add_argument('--intent-sha256')
    args=parser.parse_args();profile=load_profile()
    if os.geteuid()!=0 or socket.gethostname()!=profile['host']:
        raise RuntimeError('root operator on pinned production host required')
    if args.mode=='verify-system':
        journal=Journal(args.evidence)
        if journal.intent_sha256!=args.intent_sha256 or journal.intent['profile_sha256']!=sha(read_physical(Path(__file__).with_name('tenant_ai_freeze_profile.json'))[0]):
            raise RuntimeError('original system closure proof differs')
        assert_system_closed(journal,profile);return
    if args.candidate!=Path(__file__).resolve().parent.parent or not re.fullmatch(r'[0-9a-f]{40}',args.target or ''):
        raise RuntimeError('run from the exact reviewed candidate and full target SHA')
    if args.evidence==args.candidate or args.candidate in args.evidence.parents:
        raise RuntimeError('external evidence directory required')
    with Locks(profile) as locks:
        window=Window(profile,args.candidate,args.target,args.evidence,locks)
        print(json.dumps({'ready':True,'app':profile['app'],'locks_held':True}),flush=True)
        while True:
            line=sys.stdin.buffer.readline(8193)
            if not line:
                break
            if len(line)>8192:
                raise RuntimeError('bounded JSON action required')
            request=json.loads(line);action=request.get('action');locks.verify()
            if action=='close':
                break
            try:
                if action=='freeze':result=window.freeze()
                elif action=='verify':result=window.require_closed()
                elif action=='capture':result=window.capture()
                elif action=='apply':window.require_closed();result=window.child('apply')
                elif action=='unfreeze':result=window.unfreeze(request)
                else:raise RuntimeError('unknown operator action')
                print(json.dumps({'ok':True,'action':action,'result':result}),flush=True)
            except Exception as error:
                if window.journal is not None:
                    window.journal.append('failure',str(action),'recorded',{'class':type(error).__name__})
                print(json.dumps({'ok':False,'action':action,'error':'Refused; retain freeze and original evidence.'}),flush=True)
    print('Window closed. No automatic reopen was performed.',flush=True)


if __name__=='__main__':
    try:main()
    except Exception:
        print('Release window refused. Preserve existing app state and evidence.',file=sys.stderr)
        raise SystemExit(1)
