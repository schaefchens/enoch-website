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
