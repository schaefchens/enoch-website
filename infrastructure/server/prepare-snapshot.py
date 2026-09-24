#!/usr/bin/env python3
"""Manage file-backed swap around an Enoch server snapshot.

``register`` records active swap files and removes their fstab entries so the
boot service is their single owner. ``prepare`` disables and deletes those
files after applications have stopped, then trims the filesystem. ``ensure``
recreates and enables the recorded swap files during boot or after an aborted
shutdown.
"""
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys


CONFIG_PATH = Path('/etc/enoch/swapfiles.json')
FSTAB_PATH = Path('/etc/fstab')
PROC_SWAPS = Path('/proc/swaps')
PROC_MEMINFO = Path('/proc/meminfo')
MEMORY_MARGIN_KIB = 256 * 1024
DISK_MARGIN_BYTES = 2 * 1024**3


def run(args):
    return subprocess.run(args, check=True, text=True, capture_output=True, timeout=600).stdout.strip()


def active_swaps():
    rows = {}
    for line in PROC_SWAPS.read_text().splitlines()[1:]:
        fields = line.split()
        if len(fields) != 5:
            raise RuntimeError('Unexpected /proc/swaps entry; inspect it before snapshotting.')
        path, kind, size_kib, used_kib, priority = fields
        rows[path] = {
            'path': path,
            'kind': kind,
            'size_kib': int(size_kib),
            'used_kib': int(used_kib),
            'priority': int(priority),
        }
    return rows


def memory_available_kib():
    values = {}
    for line in PROC_MEMINFO.read_text().splitlines():
        fields = line.replace(':', ' ').split()
        if len(fields) >= 2:
            values[fields[0]] = int(fields[1])
    if 'MemAvailable' not in values:
        raise RuntimeError('MemAvailable is missing from /proc/meminfo.')
    return values['MemAvailable']


def validate_path(path, require_file=False):
    if not path.startswith('/') or '\\' in path or '..' in Path(path).parts:
        raise RuntimeError('Unsafe swap path in configuration.')
    target = Path(path)
    if require_file or target.exists() or target.is_symlink():
        info = os.lstat(target)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
            raise RuntimeError(f'Unsafe swap file: {path}')
    return target


def validate_entry(raw):
    if not isinstance(raw, dict):
        raise RuntimeError('Invalid swap configuration entry.')
    path = str(raw.get('path', ''))
    size = raw.get('size')
    uuid = str(raw.get('uuid', ''))
    priority = raw.get('priority', -1)
    validate_path(path)
    if not isinstance(size, int) or size <= 0 or size > 1024**4:
        raise RuntimeError('Invalid swap file size in configuration.')
    if not re.fullmatch(r'[0-9A-Fa-f-]{8,}', uuid):
        raise RuntimeError('Invalid swap UUID in configuration.')
    if not isinstance(priority, int) or priority < -32768 or priority > 32767:
        raise RuntimeError('Invalid swap priority in configuration.')
    return {'path': path, 'size': size, 'uuid': uuid, 'priority': priority}


def load_config():
    if not CONFIG_PATH.exists():
        return []
    try:
        data = json.loads(CONFIG_PATH.read_text())
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError('Cannot read the managed swap configuration.') from exc
    if not isinstance(data, dict) or data.get('version') != 1 or not isinstance(data.get('files'), list):
        raise RuntimeError('Unsupported managed swap configuration.')
    entries = [validate_entry(item) for item in data['files']]
    if len({entry['path'] for entry in entries}) != len(entries):
        raise RuntimeError('Duplicate swap path in configuration.')
    return entries


def save_config(entries):
    CONFIG_PATH.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    temporary = CONFIG_PATH.with_suffix('.tmp')
    temporary.write_text(json.dumps({'version': 1, 'files': entries}, indent=2) + '\n')
    os.chmod(temporary, 0o600)
    os.replace(temporary, CONFIG_PATH)


def remove_managed_fstab_entries(paths):
    if not paths or not FSTAB_PATH.exists():
        return
    original = FSTAB_PATH.read_text()
    output = []
    changed = False
    for line in original.splitlines(keepends=True):
        fields = line.strip().split()
        if fields and not fields[0].startswith('#') and fields[0] in paths and len(fields) >= 3 and fields[2] == 'swap':
            output.append(f'# {fields[0]} is managed by enoch-swapfile.service\n')
            changed = True
        else:
            output.append(line)
    if changed:
        temporary = FSTAB_PATH.with_suffix('.enoch.tmp')
        temporary.write_text(''.join(output))
        os.chmod(temporary, os.stat(FSTAB_PATH).st_mode & 0o777)
        os.replace(temporary, FSTAB_PATH)


def register():
    entries = {entry['path']: entry for entry in load_config()}
    for row in active_swaps().values():
        if row['kind'] != 'file':
            continue
        target = validate_path(row['path'], require_file=True)
        uuid = run(['blkid', '-s', 'UUID', '-o', 'value', row['path']])
        entry = validate_entry({
            'path': row['path'],
            'size': target.stat().st_size,
            'uuid': uuid,
            'priority': row['priority'],
        })
        entries[entry['path']] = entry
    ordered = [entries[path] for path in sorted(entries)]
    save_config(ordered)
    remove_managed_fstab_entries(set(entries))
    print(f'Registered {len(ordered)} managed swap file(s).')


def ensure():
    entries = load_config()
    active = active_swaps()
    for entry in entries:
        target = validate_path(entry['path'])
        if not target.exists():
            free = shutil.disk_usage(target.parent).free
            if free < entry['size'] + DISK_MARGIN_BYTES:
                raise RuntimeError(f'Insufficient free disk space to recreate {target}.')
            run(['fallocate', '-l', str(entry['size']), entry['path']])
            os.chmod(target, 0o600)
            run(['mkswap', '-U', entry['uuid'], entry['path']])
        else:
            validate_path(entry['path'], require_file=True)
            if target.stat().st_size != entry['size']:
                raise RuntimeError(f'Unexpected size for managed swap file: {target}')
        if entry['path'] not in active:
            args = ['swapon']
            if entry['priority'] >= 0:
                args += ['--priority', str(entry['priority'])]
            run(args + [entry['path']])
    print(f'Ensured {len(entries)} managed swap file(s).')


def prepare():
    if not CONFIG_PATH.exists():
        register()
    entries = load_config()
    managed = {entry['path']: entry for entry in entries}
    active = active_swaps()
    unexpected = [path for path, row in active.items() if row['kind'] == 'file' and path not in managed]
    if unexpected:
        raise RuntimeError('An unmanaged swap file is active; inspect it before snapshotting.')
    used_kib = sum(row['used_kib'] for path, row in active.items() if path in managed)
    if used_kib and memory_available_kib() < used_kib + MEMORY_MARGIN_KIB:
        raise RuntimeError('Not enough free memory to disable swap safely; the VM is retained.')
    try:
        for entry in entries:
            if entry['path'] in active:
                run(['swapoff', entry['path']])
        for entry in entries:
            target = validate_path(entry['path'])
            if target.exists() or target.is_symlink():
                validate_path(entry['path'], require_file=True)
                target.unlink()
    except (OSError, subprocess.SubprocessError):
        ensure()
        raise
    run(['sync'])
    trim = subprocess.run(['fstrim', '--all'], text=True, capture_output=True, timeout=600)
    if trim.returncode == 0:
        print(f'Removed {len(entries)} managed swap file(s); free blocks trimmed.')
    else:
        print(f'Removed {len(entries)} managed swap file(s); TRIM unavailable on one or more filesystems.')


def main(action=None):
    if os.geteuid() != 0:
        raise SystemExit('Root is required.')
    action = action or (sys.argv[1] if len(sys.argv) == 2 else 'prepare')
    if action == 'register':
        register()
    elif action == 'ensure':
        ensure()
    elif action == 'prepare':
        prepare()
    else:
        raise SystemExit('Usage: enoch-swapfile register|ensure|prepare')


if __name__ == '__main__':
    main()
