#!/usr/bin/env python3
"""Scoped freeze primitives for the lock-holding tenant AI release operator.

Only the caller's reviewed profile is touched. Every mutation has a durable
intent first. Failure preserves closure; reopening is a separate accepted action.
This module has no standalone execution path.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time


def sha(data):
    return hashlib.sha256(data).hexdigest()


def read_physical(path, expected_mode=None):
    path = Path(path)
    if not path.is_file() or path.is_symlink() or path.resolve() != path:
        raise RuntimeError('reviewed physical file required')
    info = path.stat()
    if info.st_uid != 0 or info.st_mode & 0o022 or (expected_mode is not None and stat.S_IMODE(info.st_mode) != expected_mode):
        raise RuntimeError('protected file ownership or mode changed')
    data = path.read_bytes()
    return data, {'dev':info.st_dev,'inode':info.st_ino,'uid':info.st_uid,'gid':info.st_gid,
                  'mode':stat.S_IMODE(info.st_mode),'sha256':sha(data),'size':len(data)}


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def create_private(path, data):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    sync_directory(path.parent)


def replace_exact(path, expected, data):
    live, metadata = read_physical(path)
    if live != expected:
        raise RuntimeError('resource changed; refusing overwrite')
    temporary = path.with_name(path.name + '.tenant-ai-' + os.urandom(6).hex())
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        os.fchmod(stream.fileno(), metadata['mode'])
        os.fchown(stream.fileno(), metadata['uid'], metadata['gid'])
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    # Detect a concurrent replacement before our one rename. Locks exclude the
    # participating release operators; unexpected physical changes still refuse.
    if read_physical(path)[1] != metadata:
        raise RuntimeError('resource changed while staging replacement')
    os.replace(temporary, path)
    sync_directory(path.parent)
    return read_physical(path)[1]


class Journal:
    """An immutable initial intent plus append-only, fsynced mutation records."""
    def __init__(self, directory, intent=None):
        self.directory = Path(directory)
        info = self.directory.stat()
        if self.directory.is_symlink() or self.directory.resolve() != self.directory or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
            raise RuntimeError('physical root-only journal directory required')
        self.intent_path = self.directory / 'freeze-intent.json'
        if intent is not None:
            create_private(self.intent_path, (json.dumps(intent, sort_keys=True, separators=(',', ':')) + '\n').encode())
        self.intent = json.loads(read_physical(self.intent_path, 0o600)[0])
        self.intent_sha256 = sha(read_physical(self.intent_path, 0o600)[0])
        self.events = []
        previous = self.intent_sha256
        paths = sorted(self.directory.glob('event-*.json'))
        for number, path in enumerate(paths, 1):
            if path.name != f'event-{number:06d}.json':
                raise RuntimeError('journal sequence is incomplete')
            raw = read_physical(path, 0o600)[0]
            event = json.loads(raw)
            if event.get('sequence') != number or event.get('previous_sha256') != previous:
                raise RuntimeError('journal chain differs from original intent')
            self.events.append(event)
            previous = sha(raw)
        self.previous = previous

    def append(self, action, resource, state, detail=None):
        event = {'sequence':len(self.events)+1,'previous_sha256':self.previous,
                 'action':action,'resource':resource,'state':state,'detail':detail or {}}
        raw = (json.dumps(event, sort_keys=True, separators=(',', ':')) + '\n').encode()
        create_private(self.directory / f'event-{event["sequence"]:06d}.json', raw)
        self.events.append(event)
        self.previous = sha(raw)

    def completed(self, action, resource):
        return any(event['action'] == action and event['resource'] == resource and event['state'] == 'done'
                   for event in self.events)


def frozen_vhost(original, hostname, docroot):
    if hostname == 'safeharbor.8westit.com':
        # This deployment's reviewed template has a cache-only assets section
        # and inherits global URL aliases. Do not generalize its Apache grammar.
        if (docroot != '/srv/8west/apps/safeharbor/current/public'
                or sha(original) != '8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43'):
            raise RuntimeError('Safeharbor virtual host differs from reviewed shape')
        closed = original.replace(
            b'        AllowOverride All\n        Require all granted\n',
            b'        AllowOverride None\n        AllowOverrideList None\n        Require all denied\n')
        # Directory denial alone does not cover inherited /icons/ or status
        # URLs. This vhost-only section merges after directory/files/global
        # location authorization. Disabling overrides also excludes .htaccess If.
        return closed.replace(b'</VirtualHost>\n',
                              b'    <Location />\n        Require all denied\n    </Location>\n</VirtualHost>\n')
    text = original.decode('utf-8')
    if len(re.findall(r'^\s*ServerName ' + re.escape(hostname) + r'\s*$', text, re.M)) != 1:
        raise RuntimeError('unexpected app virtual host')
    if len(re.findall(r'^\s*DocumentRoot ' + re.escape(docroot) + r'\s*$', text, re.M)) != 1:
        raise RuntimeError('unexpected app document root')
    pattern = (r'(<Directory ' + re.escape(docroot) + r'>\s*\n\s*AllowOverride All\s*\n\s*)'
               r'Require all granted(\s*\n\s*</Directory>)')
    replaced, count = re.subn(pattern, r'\1Require all denied\2', text)
    if count != 1:
        raise RuntimeError('app access block differs from reviewed shape')
    return replaced.encode('utf-8')


def frozen_cron(original, expressions, expected_count, freeze_id):
    lines = original.splitlines(keepends=True)
    count = 0
    result = []
    for line in lines:
        active = line.strip() and not line.lstrip().startswith(b'#')
        matches = active and any(re.search(expression.encode(), line) for expression in expressions)
        if matches:
            count += 1
            line = b'# tenant-ai-freeze ' + freeze_id.encode('ascii') + b' ' + line
        result.append(line)
    if count != expected_count or count < 1:
        raise RuntimeError('cron writer lines differ from reviewed inventory')
    return b''.join(result)


def proc_identity(pid):
    try:
        raw = Path('/proc', str(pid), 'stat').read_text()
        return raw[raw.rfind(')')+2:].split()[19]
    except FileNotFoundError:
        return None


def checked_command(arguments):
    result = subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30)
    if result.returncode != 0:
        raise RuntimeError('scoped system command failed')
    return result.stdout.decode().strip()


def unit_state(name):
    raw = checked_command(['systemctl','show',name,'--property=LoadState,ActiveState,SubState,UnitFileState,FragmentPath,DropInPaths,NeedDaemonReload'])
    values = dict(line.split('=',1) for line in raw.splitlines())
    if values.get('LoadState') != 'loaded' or not values.get('FragmentPath'):
        raise RuntimeError('reviewed systemd unit is unavailable')
    if values.get('DropInPaths') or values.get('NeedDaemonReload')!='no':
        raise RuntimeError('unit drop-ins or pending daemon reload require review')
    values['fragment'] = read_physical(Path(values['FragmentPath']))[1]
    return values


def apache_generation():
    parent = int(Path('/run/apache2/apache2.pid').read_text())
    parent_start = proc_identity(parent)
    if parent_start is None:
        raise RuntimeError('Apache parent is absent')
    children = {}
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            raw = (entry / 'stat').read_text()
            fields = raw[raw.rfind(')')+2:].split()
            if int(fields[1]) == parent:
                children[entry.name] = fields[19]
        except FileNotFoundError:
            continue
    if not children:
        raise RuntimeError('Apache generation is empty')
    return {'parent':parent,'parent_start':parent_start,'children':children}


def wait_until(predicate, description, timeout=120):
    deadline = time.monotonic() + timeout
    while not predicate():
        if time.monotonic() >= deadline:
            raise RuntimeError(description + ' did not drain; preserve freeze')
        time.sleep(0.25)


def replace_recorded(journal, record, reopen=False):
    path = Path(record['path'])
    original = read_physical(journal.directory / record['original_file'], 0o600)[0]
    frozen = read_physical(journal.directory / record['frozen_file'], 0o600)[0]
    if sha(original) != record['original']['sha256'] or sha(frozen) != record['frozen_sha256']:
        raise RuntimeError('original protected resource evidence changed')
    action = 'reopen-file' if reopen else 'freeze-file'
    before, after = (frozen, original) if reopen else (original, frozen)
    live, metadata = read_physical(path)
    expected_metadata = record['original']
    if any(metadata[key] != expected_metadata[key] for key in ('uid','gid','mode')):
        raise RuntimeError('resource owner or mode changed')
    if journal.completed(action, str(path)):
        if live != after:
            raise RuntimeError('completed resource changed afterward')
        return
    pending = any(event['action'] == action and event['resource'] == str(path) and event['state'] == 'intent'
                  for event in journal.events)
    if live == after and pending:
        # A crash after the exact rename can finish only its original intent.
        journal.append(action, str(path), 'done', metadata)
        return
    if live != before:
        raise RuntimeError('unowned resource state; refusing resume')
    if not reopen and not pending and metadata != record['original']:
        raise RuntimeError('original resource inode differs from captured intent')
    if not pending:
        journal.append(action, str(path), 'intent', metadata)
    result = replace_exact(path, before, after)
    journal.append(action, str(path), 'done', result)


def assert_files_closed(journal):
    for record in journal.intent['files']:
        data, metadata = read_physical(Path(record['path']))
        if sha(data) != record['frozen_sha256'] or any(metadata[key] != record['original'][key] for key in ('uid','gid','mode')):
            raise RuntimeError('app file freeze is not intact')
        complete = [event for event in journal.events if event['action']=='freeze-file'
                    and event['resource']==record['path'] and event['state']=='done']
        if not complete or complete[-1]['detail'] != metadata:
            raise RuntimeError('live file no longer matches the recorded frozen inode')


def assert_system_closed(journal, profile):
    if Path('/proc/sys/kernel/random/boot_id').read_text().strip() != journal.intent['boot_id']:
        raise RuntimeError('host reboot invalidated the closure proof')
    assert_files_closed(journal)
    assert_scheduler_inventory(profile)
    if app_worker_pids(profile):
        raise RuntimeError('app CLI workers have not drained')
    vhost=profile['vhost']
    enabled=Path(vhost['enabled_link'])
    if not enabled.is_symlink() or str(enabled.resolve())!=vhost['path']:
        raise RuntimeError('enabled app virtual host changed')
    for name, original in journal.intent['units'].items():
        current = unit_state(name)
        if current['fragment'] != original['fragment'] or current['UnitFileState'] != original['UnitFileState']:
            raise RuntimeError('systemd unit definition or enablement changed')
        if current['ActiveState'] != 'inactive':
            raise RuntimeError('app timer or service is not drained')
    generation = journal.intent['apache']
    if proc_identity(generation['parent']) != generation['parent_start']:
        raise RuntimeError('Apache parent changed during scoped closure')
    if any(proc_identity(pid) == start for pid,start in generation['children'].items()):
        raise RuntimeError('original Apache generation has not drained')
    checked_command(['systemctl','is-active','--quiet','apache2'])
    resolves=[]
    for hostname in profile['hostnames']:
        for port in (80,443):resolves.extend(['--resolve',hostname+':'+str(port)+':127.0.0.1'])
    for hostname in profile['hostnames']:
        for path in profile['probe_paths']:
            for protocol in ('http','https'):
                status = checked_command(['curl','--silent','--show-error','--max-time','10',
                    '--location','--max-redirs','2','--proto-redir','=https',
                    '--output','/dev/null','--write-out','%{http_code}',*resolves,protocol+'://'+hostname+path])
                if status != '403':
                    raise RuntimeError('app-only origin closure not observed')


def assert_scheduler_inventory(profile):
    """New app-scoped cron/unit definitions hold the window for fresh review."""
    needles=[profile['app_root'].encode(),*(path.encode() for path in profile.get('worker_paths',[]))]
    known={item['path'] for item in profile['crons']}
    crons=[Path('/etc/crontab')]
    cron_dir=Path('/etc/cron.d')
    if cron_dir.is_dir():
        # Debian cron deliberately ignores quarantine/backup names with dots.
        crons.extend(p for p in cron_dir.iterdir() if re.fullmatch(r'[A-Za-z0-9_-]+',p.name))
    spool=Path('/var/spool/cron/crontabs')
    if spool.is_dir():crons.extend(spool.iterdir())
    for path in crons:
        if not path.exists():continue
        active=[line for line in path.read_bytes().splitlines() if line.strip() and not line.lstrip().startswith(b'#')]
        if any(needle in line for line in active for needle in needles) and str(path) not in known:
            raise RuntimeError('unreviewed application cron writer')
    known_units=set(profile['units'])
    for directory in (Path('/etc/systemd/system'),Path('/run/systemd/system'),Path('/lib/systemd/system')):
        if not directory.is_dir():continue
        for path in directory.iterdir():
            if path.suffix not in ('.service','.timer') or not path.is_file():continue
            if any(needle in path.read_bytes() for needle in needles) and path.name not in known_units:
                raise RuntimeError('unreviewed application systemd writer')


def interpreter_paths(name, arguments):
    """Return script/docroot roles, never mistake option operands for filenames.

    Unknown execution modes remain opaque. Only a complete known script role can
    support exclusion of an interpreter in a different filesystem view.
    """
    values=[os.fsdecode(raw) for raw in arguments[1:] if raw]
    paths=[];server=False;docroot=None;complete=True;configuration=False;script=False;index=0
    php=name.startswith('php')
    operands={'-c','-d','-S','-t','-f'} if php else {'-W','-X'}
    flags={'-n','-q','-e','-C'} if php else {'-B','-E','-I','-O','-OO','-s','-S','-u','-v','-x','-P'}
    while index<len(values):
        value=values[index];index+=1
        if value=='--':
            if not script and index<len(values):paths.append(values[index])
            break
        option=value[:2] if php and value[:2] in ('-c','-d') and len(value)>2 else value
        if option in operands:
            if option!=value:operand=value[2:]
            else:
                if index==len(values):return [],False,False,configuration
                operand=values[index];index+=1
            if option=='-S' and php:server=True
            elif option=='-t' and php:docroot=operand
            elif option=='-f' and php:paths.append(operand);script=True
            elif php and option in ('-c','-d'):
                # Never read INI contents. Even apparently unrelated scripts
                # can execute prepend/append, extensions or custom INI behavior.
                configuration=True
                key,separator,path=operand.partition('=')
                if option=='-d' and separator and key.strip().lower() in ('auto_prepend_file','auto_append_file') and path:
                    paths.append(path)
            continue
        if value in flags:continue
        if value.startswith('-'):
            complete=False;break
        if not script:paths.append(value)
        break  # Later arguments belong to the script.
    if server:paths.append(docroot if docroot is not None else '.')
    return paths,server,complete and bool(paths),configuration


def mount_records(raw):
    records=[]
    def decoded(value):return re.sub(r'\\([0-7]{3})',lambda m:chr(int(m[1],8)),value)
    for line in raw.splitlines():
        fields=line.split()
        if len(fields)<10 or '-' not in fields:raise RuntimeError('process mount inventory unreadable')
        separator=fields.index('-')
        if len(fields)<=separator+2:raise RuntimeError('process mount inventory malformed')
        # Namespace handles are nsfs objects, not filesystem directory roots.
        if fields[separator+1]=='nsfs':continue
        device,root,mount=fields[2],decoded(fields[3]),decoded(fields[4])
        if not re.fullmatch(r'\d+:\d+',device) or not root.startswith('/') or not mount.startswith('/'):
            raise RuntimeError('process mount inventory malformed')
        records.append((device,Path(root),Path(mount)))
    if not records:raise RuntimeError('process mount inventory empty')
    return records


def process_view(entry):
    root=(entry/'root').stat()
    cwd=(entry/'cwd').stat()
    return (os.readlink(entry/'ns/mnt'),(root.st_dev,root.st_ino),(entry/'mountinfo').read_text(),
            os.readlink(entry/'cwd'),(cwd.st_dev,cwd.st_ino))


def foreign_physical_path(entry,value,directory=False):
    """Inspect via the process root magic-link, not Path.resolve in our root.

    Hold each directory fd and reject symlinks instead of interpreting them in
    the wrong namespace. An ambiguous path refuses exclusion, never drains.
    """
    expected_cwd=None
    if not value.startswith('/'):
        cwd=os.readlink(entry/'cwd')
        if not cwd.startswith('/') or cwd.endswith(' (deleted)'):
            raise RuntimeError('live process path became unreadable during inspection')
        info=(entry/'cwd').stat();expected_cwd=(info.st_dev,info.st_ino)
        # Verify the namespace-root path really reaches the process's held cwd.
        actual=foreign_physical_path(entry,cwd,directory=True)
        if actual!=expected_cwd:raise RuntimeError('process cwd identity changed during inspection')
        value=cwd+'/'+value
    descriptors=[os.open(entry/'root',os.O_PATH|os.O_DIRECTORY)]
    try:
        for part in value.split('/'):
            if part in ('','.'):continue
            if not stat.S_ISDIR(os.fstat(descriptors[-1]).st_mode):
                raise RuntimeError('foreign process path parent is not a directory')
            if part=='..':
                if len(descriptors)>1:os.close(descriptors.pop())
                continue
            descriptor=os.open(part,os.O_PATH|os.O_NOFOLLOW,dir_fd=descriptors[-1])
            descriptors.append(descriptor)
            if stat.S_ISLNK(os.fstat(descriptor).st_mode):
                raise RuntimeError('foreign process symlink scope requires review')
        info=os.fstat(descriptors[-1])
        if not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
            raise RuntimeError('foreign process script or docroot type requires review')
        return info.st_dev,info.st_ino
    finally:
        for descriptor in reversed(descriptors):os.close(descriptor)


def namespace_app_mounts(profile,local_mounts,foreign_mounts):
    """Compare backing filesystem paths, including ancestor and file binds."""
    scopes=[Path(profile['app_root'])/'current',*[Path(p) for p in profile.get('worker_paths',[])]]
    backing=[]
    for scope in scopes:
        scope=scope.resolve()
        covering=[record for record in local_mounts if scope.is_relative_to(record[2])]
        if not covering:raise RuntimeError('application backing mount unknown')
        depth=max(len(record[2].parts) for record in covering)
        best=[record for record in covering if len(record[2].parts)==depth]
        if len(set(best))!=1:raise RuntimeError('application backing mount ambiguous')
        device,root,mount=best[0]
        backing.append((device,root/scope.relative_to(mount)))
    return any(device==other_device and (root.is_relative_to(scope) or scope.is_relative_to(root))
               for device,root,_ in foreign_mounts for other_device,scope in backing)


def app_worker_pids(profile):
    result=[]
    current=Path(profile['app_root'])/'current'
    prefixes=[str(current/'cron')+'/',str(current/'db')+'/']
    exact=profile.get('worker_paths',[])
    scoped=[*prefixes,*exact]
    launcher_names={'sh','dash','bash','flock'}
    own=process_view(Path('/proc/self'));local_mounts=mount_records(own[2])
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            before=proc_identity(entry.name)
            if before is None:continue
            arguments=(entry/'cmdline').read_bytes().split(b'\0')
            if not arguments:continue
            name=Path(os.fsdecode(arguments[0])).name
            launcher=name in launcher_names
            if not launcher and not re.fullmatch(r'(?:php|python)[0-9.]*',name):
                continue
            view=process_view(entry);foreign=view[:2]!=own[:2]
            # Shell text is matched literally, never evaluated. Retain absolute
            # app-path recognition even if a cwd was deleted or script exited.
            matched=any(prefix in os.fsdecode(raw) for raw in arguments[1:] for prefix in scoped) if launcher else False
            paths,server,complete,configuration=([],False,True,False) if launcher else interpreter_paths(name,arguments)
            def is_scoped(path):
                resolved=str(path.resolve())
                return (resolved in exact or any(resolved.startswith(prefix) for prefix in prefixes)
                        or server and (resolved==str(current) or resolved.startswith(str(current)+'/')))
            absolute=[os.fsdecode(raw) for raw in arguments[1:] if raw.startswith(b'/')]+[path for path in paths if path.startswith('/')]
            matched=matched or any(is_scoped(Path(path)) for path in absolute)
            relative=[Path(path) for path in paths if not path.startswith('/')]
            if foreign:
                # A foreign process cannot be excluded merely by its namespace,
                # -S flag, container name, or an unreadable host cwd pathname.
                foreign_mounts=mount_records(view[2])
                if view[1]==own[1]:
                    # Service mount namespaces may share the physical host root.
                    # Identical inherited mounts add no remapping; literal script/
                    # docroot scope still applies, and every changed bind is checked.
                    foreign_mounts=[record for record in foreign_mounts if record not in local_mounts]
                mounted=namespace_app_mounts(profile,local_mounts,foreign_mounts)
                if not matched:
                    if configuration:raise RuntimeError('PHP configuration execution scope requires review')
                    if not launcher and not complete:raise RuntimeError('foreign interpreter execution scope requires review')
                    for number,path in enumerate(paths):
                        foreign_physical_path(entry,path,directory=server and number==len(paths)-1)
                    if view[1]==own[1]:matched=any(is_scoped(Path(view[3])/path) for path in relative)
                matched=matched or mounted
            elif not matched:
                if configuration:raise RuntimeError('PHP configuration execution scope requires review')
                if not complete:
                    relative=[Path(os.fsdecode(raw)) for raw in arguments[1:] if raw and not raw.startswith((b'-',b'/'))]
                if relative:
                    cwd=(entry/'cwd').resolve(strict=True)
                    matched=any(is_scoped(cwd/path) for path in relative)
            after=proc_identity(entry.name)
            if after is not None and after!=before:raise RuntimeError('scoped process identity changed during inspection')
            if after is None:continue
            if process_view(entry)!=view or (entry/'cmdline').read_bytes().split(b'\0')!=arguments:
                raise RuntimeError('process filesystem or command identity changed during inspection')
            if matched:result.append(entry.name)
        except FileNotFoundError as error:
            after=proc_identity(entry.name)
            if after is None:continue
            if after!=before:raise RuntimeError('scoped process identity changed during inspection') from error
            raise RuntimeError('live process path became unreadable during inspection') from error
    if process_view(Path('/proc/self'))!=own:raise RuntimeError('scanner mount inventory changed during inspection')
    return result
