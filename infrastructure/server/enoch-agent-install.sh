#!/usr/bin/env bash
# Run on the Nextcloud Ubuntu VM. Embedded swap helper mirrors prepare-snapshot.py. Argument: dedicated portal public key file.
set -Eeuo pipefail
[[ $(uname -s) == Linux && $EUID == 0 ]] || { echo 'Run as root on the Nextcloud Linux VM.' >&2; exit 1; }
[[ $# == 1 && -r $1 ]] || { echo 'Usage: enoch-agent-install.sh /root/enoch.pub' >&2; exit 1; }
[[ -x /usr/local/sbin/nextcloud-aio-stop-siblings ]] || { echo 'Install nextcloud_server_setup.sh first.' >&2; exit 1; }
read -r KEY_TYPE KEY_DATA _ <"$1"
[[ $KEY_TYPE == ssh-ed25519 && $KEY_DATA =~ ^[A-Za-z0-9+/=]+$ ]] || { echo 'An Ed25519 public key is required.' >&2; exit 1; }
cat >/usr/local/sbin/enoch-clear-swap <<'SWAP_PY'
#!/usr/bin/env python3
"""Run as root AFTER stopping applications. Never run against a live database.
Clear stale swap contents, restore usable swap, and trim free filesystem blocks.
This does not remove Docker images, application data, or swap capacity.
"""
import os
from pathlib import Path
import stat
import subprocess


def run(args):
    return subprocess.run(args, check=True, text=True, capture_output=True, timeout=600).stdout.strip()


def main():
    if os.geteuid() != 0:
        raise SystemExit('Root is required.')
    swaps = [line.split() for line in Path('/proc/swaps').read_text().splitlines()[1:]]
    for row in swaps:
        path, kind, size_kib, used_kib, priority = row
        if kind != 'file':
            continue
        if '\\' in path or not path.startswith('/') or not stat.S_ISREG(os.lstat(path).st_mode):
            raise RuntimeError('Unexpected swap file; inspect it before snapshotting.')
        memory = dict((line.split(':')[0], int(line.split()[1])) for line in Path('/proc/meminfo').read_text().splitlines())
        if memory['MemAvailable'] < int(used_kib) + 256 * 1024:
            raise RuntimeError('Not enough free memory to safely clear swap; the VM is retained.')
        uuid = run(['blkid', '-s', 'UUID', '-o', 'value', path])
        size = os.stat(path).st_size
        run(['swapoff', path])
        try:
            # Preserve a fully allocated swap file; sparse/zero-range files can be rejected by swapon.
            with open(path, 'r+b', buffering=0) as swap:
                zero = bytes(8 * 1024 * 1024)
                remaining = size
                while remaining:
                    count = swap.write(zero[:min(remaining, len(zero))])
                    remaining -= count
                os.fsync(swap.fileno())
        finally:
            run(['mkswap', '-U', uuid, path])
            args = ['swapon']
            if int(priority) >= 0:
                args += ['--priority', priority]
            run(args + [path])
        print('Cleared swap contents; swap capacity restored.')
    run(['sync'])
    trim = subprocess.run(['fstrim', '--all'], text=True, capture_output=True, timeout=600)
    print('Free blocks trimmed.' if trim.returncode == 0 else 'TRIM unavailable on one or more filesystems; disk data remains intact.')


if __name__ == '__main__':
    main()
SWAP_PY
chmod 0755 /usr/local/sbin/enoch-clear-swap
cat >/usr/local/sbin/enoch-prepare-snapshot <<'PREP'
#!/bin/bash
set -Eeuo pipefail
/usr/local/sbin/nextcloud-aio-stop-siblings
/usr/local/sbin/enoch-clear-swap
PREP
chmod 0755 /usr/local/sbin/enoch-prepare-snapshot
cat >/usr/local/sbin/enoch-aio-control <<'PY'
#!/usr/bin/env python3
"""Forced SSH command: no shell, no arbitrary arguments, no cloud credentials."""
import json, os, re, subprocess, sys
def run(args):
    return subprocess.run(args, text=True, capture_output=True, timeout=8, check=True).stdout.strip()
def emit(state):
    print(json.dumps({'state': state})); sys.exit(0)
command = os.environ.get('SSH_ORIGINAL_COMMAND', '')
if command == 'probe':
    emit('installed' if os.access('/usr/local/sbin/nextcloud-aio-stop-siblings', os.X_OK) else 'failed')
match = re.fullmatch(r'(prepare|status) ([a-f0-9]{24})', command)
if not match:
    print('Command not permitted', file=sys.stderr); sys.exit(1)
verb, token = match.groups()
unit = f'enoch-aio-prepare-{token}.service'
try:
    props = dict(line.split('=', 1) for line in run(['systemctl', 'show', unit, '--property=LoadState,ActiveState,SubState,Result']).splitlines())
    if props.get('LoadState') == 'not-found':
        if verb == 'prepare':
            run(['systemd-run', '--no-block', '--unit=' + unit, '--property=Type=oneshot', '--property=RemainAfterExit=yes', '--property=TimeoutStartSec=25min', '/usr/local/sbin/enoch-prepare-snapshot'])
            emit('stopping')
        emit('missing')
    if props.get('ActiveState') == 'failed' or props.get('Result') not in ('success', ''):
        emit('failed')
    if props.get('ActiveState') == 'active' and props.get('SubState') == 'exited':
        running = run(['docker', 'ps', '--format', '{{.Names}}']).splitlines()
        if any(name.startswith('nextcloud-aio-') and name not in ('nextcloud-aio-mastercontainer', 'nextcloud-aio-domaincheck') for name in running):
            emit('failed')
        emit('ready')
    emit('stopping')
except (subprocess.SubprocessError, ValueError):
    emit('failed')
PY
chmod 0755 /usr/local/sbin/enoch-aio-control
install -d -m 0700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 0600 /root/.ssh/authorized_keys
# Keep existing administrator keys. Reinstalling the same portal key is idempotent.
if ! grep -Fq "$KEY_DATA" /root/.ssh/authorized_keys; then
    printf 'restrict,command="/usr/local/sbin/enoch-aio-control" %s %s enoch-maintenance\n' "$KEY_TYPE" "$KEY_DATA" >>/root/.ssh/authorized_keys
fi
echo 'Restricted portal agent installed. No containers were stopped.'
