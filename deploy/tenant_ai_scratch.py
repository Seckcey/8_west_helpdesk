#!/usr/bin/env python3
"""Explicit root-only full MySQL scratch restore on Coastline; never production.

The source dump stays in the root-private evidence directory. No SQL, row data,
credentials, or subprocess stderr is printed. This command does not freeze or
reopen the production app, install source, change grants, or run migrations.
"""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import time

from tenant_ai_restore import make_policy, compare_complete_export

DUMP_FLAGS = ['--single-transaction', '--skip-comments', '--skip-dump-date', '--routines',
              '--triggers', '--events', '--hex-blob', '--no-tablespaces',
              '--set-gtid-purged=OFF', '--order-by-primary', '--databases']


def private_directory(path):
    if path.resolve() != path or not path.is_dir() or path.is_symlink():
        raise RuntimeError('physical evidence directory required')
    info = path.stat()
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('root-only evidence directory required')


def private_file(path):
    private_directory(path.parent)
    if path.resolve() != path or not path.is_file() or path.is_symlink():
        raise RuntimeError('physical evidence file required')
    info = path.stat()
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
        raise RuntimeError('root-only evidence file required')


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def create_json(path, value):
    with path.open('xb') as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write((json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode())
        stream.flush()
        os.fsync(stream.fileno())
    descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def run(args, *, input_file=None, output_file=None, capture=False):
    result = subprocess.run(args, stdin=input_file, stdout=subprocess.PIPE if capture else output_file,
                            stderr=subprocess.DEVNULL, timeout=600, check=False)
    if result.returncode:
        raise RuntimeError('scratch database operation failed; private evidence retained')
    return result.stdout.decode().strip() if capture else None


def restore(backup, identity_path, policy_path, policy_sha256, image, output):
    if os.geteuid() != 0 or socket.gethostname() != 'coastline':
        raise RuntimeError('scratch restore runs only as root on Coastline')
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', image):
        raise RuntimeError('locally inspected immutable MySQL image ID required')
    if not re.fullmatch(r'[0-9a-f]{64}', policy_sha256):
        raise RuntimeError('reviewed per-capture policy digest required')
    for path in (backup, identity_path, policy_path):
        private_file(path)
    private_directory(output.parent)
    if any(path.parent != output.parent for path in (backup, identity_path, policy_path)):
        raise RuntimeError('one protected evidence directory required')
    if output.exists() or output.is_symlink():
        raise RuntimeError('restore proof already exists; refusing replacement')
    if digest(policy_path) != policy_sha256:
        raise RuntimeError('restore policy differs from reviewed invocation')
    identity_document = json.loads(identity_path.read_text())
    identity = identity_document['identity']
    if (identity_document.get('contract') != 'tenant-ai-backup-identity-v1'
            or not re.fullmatch(r'[a-zA-Z0-9_]{1,64}', identity['database'])
            or not re.fullmatch(r'[0-9a-f-]{36}', identity['server_uuid'])
            or identity_document['backup_sha256'] != digest(backup)):
        raise RuntimeError('captured backup identity mismatch')
    policy = json.loads(policy_path.read_text())
    # Validate the complete captured input before starting even an isolated DB.
    from tenant_ai_restore import verify_policy
    verify_policy(backup, policy)
    if run(['docker', 'image', 'inspect', '--format', '{{.Id}}', image], capture=True) != image:
        raise RuntimeError('scratch image identity mismatch')
    lock_path = Path('/tmp/8west-coastline-heavy-tests.lock')
    if lock_path.is_symlink() or not lock_path.is_file():
        raise RuntimeError('test mutex must be physical')
    # Do not recreate or truncate the shared task mutex (including when its
    # established owner is the ordinary Coastline operator).
    with os.fdopen(os.open(lock_path,os.O_RDWR|os.O_NOFOLLOW),'r+b',buffering=0) as mutex:
        fcntl.flock(mutex, fcntl.LOCK_EX | fcntl.LOCK_NB)
        available = next(int(line.split()[1]) for line in Path('/proc/meminfo').read_text().splitlines()
                         if line.startswith('MemAvailable:'))
        if available < 1152 * 1024:
            raise RuntimeError('scratch memory guard refused')
        nonce = os.urandom(8).hex()
        name = 'tenant-ai-scratch-' + nonce
        restored = output.parent / ('restored-' + nonce + '.sql')
        container = None
        comparison = None
        try:
            container = run(['docker', 'run', '-d', '--name', name,
                '--label', 'com.8west.tenant-ai-scratch=' + nonce, '--network', 'none',
                '--memory', '512m', '--memory-swap', '512m', '--cpus', '.75',
                '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', image,
                '--innodb-buffer-pool-size=64M', '--max-connections=10', '--performance-schema=OFF',
                '--event-scheduler=OFF'], capture=True)
            if not re.fullmatch(r'[0-9a-f]{64}', container):
                raise RuntimeError('scratch container identity unavailable')
            for attempt in range(90):
                # The image starts a temporary socket-only server for initial
                # setup. Wait for the final server rather than that first ping.
                ready = subprocess.run(['docker', 'exec', container, 'mysql', '-uroot', '-Nse', 'SELECT @@port'],
                                       stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10)
                if ready.returncode == 0 and ready.stdout.strip() == b'3306':
                    break
                time.sleep(1)
            else:
                raise RuntimeError('scratch database did not become ready')
            version = run(['docker', 'exec', container, 'mysql', '-uroot', '-Nse', 'SELECT @@version'], capture=True)
            if version != identity_document['server_version']:
                raise RuntimeError('scratch server version differs from capture')
            with backup.open('rb') as source:
                run(['docker', 'exec', '-i', container, 'mysql', '-uroot', '--binary-mode'], input_file=source,
                    output_file=subprocess.DEVNULL)
            with restored.open('xb') as destination:
                os.fchmod(destination.fileno(), 0o600)
                run(['docker', 'exec', container, 'mysqldump', '-uroot', *DUMP_FLAGS, identity['database']], output_file=destination)
                destination.flush()
                os.fsync(destination.fileno())
            comparison = compare_complete_export(backup, restored, policy)
            if digest(policy_path) != policy_sha256:
                raise RuntimeError('policy changed during scratch restore')
        finally:
            if container is not None:
                actual = run(['docker', 'inspect', '--format', '{{.Id}} {{index .Config.Labels "com.8west.tenant-ai-scratch"}}', name], capture=True)
                if actual != container + ' ' + nonce:
                    raise RuntimeError('scratch cleanup identity mismatch; operator attention required')
                run(['docker', 'rm', '--force', '--volumes', container], output_file=subprocess.DEVNULL)
                # A daemon error must not masquerade as successful removal.
                remaining = run(['docker', 'ps', '-aq', '--no-trunc', '--filter', 'id=' + container], capture=True)
                if remaining:
                    raise RuntimeError('scratch container cleanup not verified')
        if comparison is None:
            raise RuntimeError('complete restore evidence absent')
        create_json(output, {'contract':'tenant-ai-scratch-restore-v1','identity':identity,
            'backup_sha256':comparison['source_sha256'],'schema_equal':True,'rows_equal':True,
            'scratch_removed':True,'mysql_image':image,'server_version':version,
            'policy_sha256':policy_sha256,'complete_export':comparison})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    policy = commands.add_parser('propose-policy', help='Explicit column pins produce a proposal for review; no automatic approval')
    policy.add_argument('backup', type=Path)
    policy.add_argument('output', type=Path)
    policy.add_argument('--column-line', type=int, action='append', default=[])
    command = commands.add_parser('restore')
    for name in ('backup','identity','policy','output'):
        command.add_argument('--' + name, required=True, type=Path)
    command.add_argument('--policy-sha256', required=True)
    command.add_argument('--mysql-image', required=True)
    args = parser.parse_args()
    if args.command == 'propose-policy':
        if os.geteuid() != 0:
            raise RuntimeError('root operator required')
        private_file(args.backup)
        private_directory(args.output.parent)
        create_json(args.output, make_policy(args.backup, args.column_line))
        print('Policy proposal created. Review and pin its SHA256 before restore.')
    else:
        restore(args.backup, args.identity, args.policy, args.policy_sha256, args.mysql_image, args.output)
        print('Complete scratch restore matched; owned container and volume removed.')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('Scratch restore refused or failed; preserve private evidence and production freeze.', file=__import__('sys').stderr)
        raise SystemExit(1)
