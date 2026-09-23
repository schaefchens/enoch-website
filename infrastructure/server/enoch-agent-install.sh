#!/usr/bin/env bash
# Run on the Nextcloud Ubuntu VM. Argument: dedicated portal public key file.
set -Eeuo pipefail
[[ $(uname -s) == Linux && $EUID == 0 ]] || { echo 'Run as root on the Nextcloud Linux VM.' >&2; exit 1; }
[[ $# == 1 && -r $1 ]] || { echo 'Usage: enoch-agent-install.sh /root/enoch.pub' >&2; exit 1; }
[[ -x /usr/local/sbin/nextcloud-aio-stop-siblings ]] || { echo 'Install nextcloud_server_setup.sh first.' >&2; exit 1; }
read -r KEY_TYPE KEY_DATA _ <"$1"
[[ $KEY_TYPE == ssh-ed25519 && $KEY_DATA =~ ^[A-Za-z0-9+/=]+$ ]] || { echo 'An Ed25519 public key is required.' >&2; exit 1; }
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
            run(['systemd-run', '--no-block', '--unit=' + unit, '--property=Type=oneshot', '--property=RemainAfterExit=yes', '--property=TimeoutStartSec=25min', '/usr/local/sbin/nextcloud-aio-stop-siblings'])
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
