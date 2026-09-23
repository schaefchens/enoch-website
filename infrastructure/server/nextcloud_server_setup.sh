#!/usr/bin/env bash
# Copy to the Nextcloud VM and run there as root. Never runs on macOS.
set -Eeuo pipefail
umask 027

DOMAIN=${DOMAIN:-wolke2.schaefchens.de}
HOSTNAME_SHORT=${HOSTNAME_SHORT:-wolke2}
SWAP_GB=${SWAP_GB:-8}
HPB_CONFIG=""
TALK_MODE=""
APP_DIR=/opt/nextcloud-aio
log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
while (($#)); do
    case "$1" in
        --hpb-config) [[ $# -ge 2 ]] || die 'Missing config path'; HPB_CONFIG=$2; shift 2;;
        --talk-mode) [[ $# -ge 2 ]] || die 'Missing Talk mode'; TALK_MODE=$2; shift 2;;
        -h|--help) printf 'Run ON the Ubuntu 24.04 VM: sudo bash %s [--talk-mode external|internal] [--hpb-config /root/external-talk.json]\n' "$0"; exit 0;;
        *) die "Unknown argument: $1";;
    esac
done
[[ $(uname -s) == Linux && $EUID == 0 ]] || die 'Run this on the Ubuntu server as root, not on your Mac.'
source /etc/os-release
[[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || die 'Ubuntu 24.04 LTS is required.'
[[ $DOMAIN =~ ^[a-z0-9.-]+$ && $DOMAIN == *.* ]] || die 'Invalid DOMAIN'
[[ $HOSTNAME_SHORT =~ ^[a-z0-9][a-z0-9-]*$ ]] || die 'Use a short hostname, without dots.'
[[ $SWAP_GB =~ ^[1-9][0-9]*$ ]] || die 'Invalid SWAP_GB'
[[ -z $HPB_CONFIG || -f $HPB_CONFIG ]] || die 'HPB configuration file is missing.'
if [[ -z $TALK_MODE && -f /etc/nextcloud-aio/talk-mode ]]; then TALK_MODE=$(cat /etc/nextcloud-aio/talk-mode); fi
TALK_MODE=${TALK_MODE:-external}
[[ $TALK_MODE == internal || $TALK_MODE == external ]] || die 'Talk mode must be internal or external.'
trap 'printf "Installation failed at line %s. Existing data has not been removed.\n" "$LINENO" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

log 'Installing system prerequisites'
apt-get update
apt-get install -y ca-certificates curl gnupg openssl python3 dnsutils util-linux

log 'Keeping the public domain out of loopback DNS'
hostnamectl set-hostname "$HOSTNAME_SHORT"
install -d -m 0755 /etc/cloud/cloud.cfg.d
cat >/etc/cloud/cloud.cfg.d/99-nextcloud-hostname.cfg <<EOF
# Keep this hostname through snapshot restores.
preserve_hostname: true
hostname: ${HOSTNAME_SHORT}
fqdn: ${HOSTNAME_SHORT}
# Restores resume this same server identity on its persistent Primary IPs.
ssh_deletekeys: false
EOF
DOMAIN="$DOMAIN" HOSTNAME_SHORT="$HOSTNAME_SHORT" python3 - <<'PY'
import os, pathlib, shutil, ipaddress
domain, short = os.environ['DOMAIN'], os.environ['HOSTNAME_SHORT']
p = pathlib.Path('/etc/hosts')
original = p.read_text()
lines = []
for line in original.splitlines():
    fields = line.split()
    try:
        loopback = bool(fields) and ipaddress.ip_address(fields[0]).is_loopback
    except ValueError:
        loopback = False
    if loopback and domain in fields[1:]:
        fields = [fields[0]] + [x for x in fields[1:] if x != domain]
        line = ' '.join(fields) if len(fields) > 1 else ''
    lines.append(line)
if not any(short in line.split()[1:] for line in lines):
    lines.append('127.0.1.1 ' + short)
updated = '\n'.join(lines) + '\n'
if updated != original:
    backup = p.with_name('hosts.before-nextcloud')
    if not backup.exists(): shutil.copy2(p, backup)
    p.write_text(updated)
p = pathlib.Path('/etc/cloud/templates/hosts.debian.tmpl')
if p.exists():
    original = p.read_text()
    updated = original.replace('127.0.1.1 {{fqdn}} {{hostname}}', '127.0.1.1 {{hostname}}')
    if updated != original:
        backup = p.with_name('hosts.debian.tmpl.before-nextcloud')
        if not backup.exists(): shutil.copy2(p, backup)
        p.write_text(updated)
PY
resolvectl flush-caches
DOMAIN="$DOMAIN" python3 - <<'PY'
import os, socket, ipaddress
addresses = {a[4][0] for a in socket.getaddrinfo(os.environ['DOMAIN'],443)}
if any(ipaddress.ip_address(a).is_loopback for a in addresses):
    raise SystemExit('Public domain still resolves to loopback; fix DNS before continuing.')
print('Domain resolves to: ' + ', '.join(sorted(addresses)))
PY

log "Ensuring at least ${SWAP_GB} GiB swap"
CURRENT_SWAP_KB=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)
# mkswap reserves a header page; an 8 GiB file reports slightly less than 8 GiB.
if ((CURRENT_SWAP_KB + 1024 < SWAP_GB * 1024 * 1024)); then
    [[ ! -e /swapfile-nextcloud ]] || die '/swapfile-nextcloud already exists; inspect it before extending swap.'
    ADD_SWAP_GB=$(((SWAP_GB * 1024 * 1024 - CURRENT_SWAP_KB - 1024 + 1024 * 1024 - 1) / (1024 * 1024)))
    FREE_KB=$(df -Pk / | awk 'NR==2 {print $4}')
    ((FREE_KB > (ADD_SWAP_GB + 5) * 1024 * 1024)) || die 'Insufficient disk space for swap plus installation.'
    fallocate -l "${ADD_SWAP_GB}G" /swapfile-nextcloud
    chmod 0600 /swapfile-nextcloud
    mkswap /swapfile-nextcloud
    swapon /swapfile-nextcloud
    grep -q '^/swapfile-nextcloud ' /etc/fstab || printf '/swapfile-nextcloud none swap sw 0 0\n' >>/etc/fstab
fi
printf 'vm.swappiness=20\n' >/etc/sysctl.d/90-nextcloud-swap.conf
sysctl -q -p /etc/sysctl.d/90-nextcloud-swap.conf

log 'Installing Docker Engine from the official repository'
if ! command -v docker >/dev/null; then
    if dpkg-query -W -f='${Status}\n' docker.io 2>/dev/null | grep -q 'install ok installed'; then
        die 'An existing distribution Docker installation needs migration first.'
    fi
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod 0644 /etc/apt/keyrings/docker.asc
    cat >/etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: noble
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
[[ $(command -v docker) != /snap/* ]] || die 'Snap Docker is not supported by Nextcloud AIO.'
docker compose version >/dev/null
install -d -m 0755 /etc/docker
DOCKER_CHANGED=$(python3 - <<'PY'
import pathlib, json, shutil
p = pathlib.Path('/etc/docker/daemon.json')
old = json.loads(p.read_text()) if p.exists() else {}
new = json.loads(json.dumps(old))
new.setdefault('log-driver','json-file')
if new['log-driver'] == 'json-file':
    new.setdefault('log-opts',{}).update({'max-size':'10m','max-file':'3'})
new.setdefault('default-network-opts',{}).setdefault('bridge',{})['com.docker.network.enable_ipv6']='true'
if new != old:
    if p.exists() and not p.with_suffix('.json.before-nextcloud').exists():
        shutil.copy2(p, p.with_suffix('.json.before-nextcloud'))
    p.write_text(json.dumps(new,indent=2)+'\n')
    print('yes')
PY
)
dockerd --validate --config-file=/etc/docker/daemon.json
install -d -m 0755 /etc/systemd/system/docker.service.d
printf '[Service]\nTimeoutStopSec=1800\n' >/etc/systemd/system/docker.service.d/nextcloud-timeout.conf
systemctl daemon-reload
systemctl enable --now docker
if [[ $DOCKER_CHANGED == yes ]]; then systemctl restart docker; fi

log 'Writing the official AIO mastercontainer deployment'
install -d -m 0750 "$APP_DIR" /etc/nextcloud-aio
if [[ -f $APP_DIR/compose.yaml && ! -f $APP_DIR/compose.yaml.before-nextcloud-setup ]]; then
    cp -a "$APP_DIR/compose.yaml" "$APP_DIR/compose.yaml.before-nextcloud-setup"
fi
cat >"$APP_DIR/compose.yaml" <<'YAML'
name: nextcloud-aio
services:
  nextcloud-aio-mastercontainer:
    image: ghcr.io/nextcloud-releases/all-in-one:latest
    init: true
    restart: always
    container_name: nextcloud-aio-mastercontainer
    network_mode: bridge
    ports:
      - "80:80"
      - "127.0.0.1:8080:8080"
    volumes:
      - nextcloud_aio_mastercontainer:/mnt/docker-aio-config
      - /var/run/docker.sock:/var/run/docker.sock:ro
    environment:
      AIO_DISABLE_BACKUP_SECTION: "true"
      NEXTCLOUD_UPLOAD_LIMIT: "20G"
      NEXTCLOUD_MAX_TIME: "10800"
      NEXTCLOUD_MEMORY_LIMIT: "512M"
      NEXTCLOUD_STARTUP_APPS: "deck twofactor_totp tasks calendar contacts notes spreed"
      NEXTCLOUD_KEEP_DISABLED_APPS: "true"
      TALK_PORT: "3478"
volumes:
  nextcloud_aio_mastercontainer:
    name: nextcloud_aio_mastercontainer
YAML

cat >/usr/local/sbin/nextcloud-aio-stop-siblings <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
docker inspect nextcloud-aio-nextcloud >/dev/null 2>&1 || exit 0
if [[ $(docker inspect --format '{{.State.Running}}' nextcloud-aio-mastercontainer) != true ]]; then
    echo 'AIO mastercontainer is not running; cannot ensure a clean stop.' >&2
    exit 1
fi
timeout 20m docker exec --env STOP_CONTAINERS=1 nextcloud-aio-mastercontainer /daily-backup.sh
REMAINING=$(docker ps --format '{{.Names}}' | grep '^nextcloud-aio-' | grep -Ev '^nextcloud-aio-(mastercontainer|domaincheck)$' || true)
[[ -z $REMAINING ]] || { printf 'AIO containers are still running:\n%s\n' "$REMAINING" >&2; exit 1; }
BASH
cat >/usr/local/sbin/nextcloud-aio-start-siblings <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
for ((i=0; i<120; i++)); do
    if [[ $(docker inspect --format '{{.State.Health.Status}}' nextcloud-aio-mastercontainer 2>/dev/null || true) == healthy ]]; then break; fi
    sleep 2
done
[[ $(docker inspect --format '{{.State.Health.Status}}' nextcloud-aio-mastercontainer) == healthy ]]
docker inspect nextcloud-aio-nextcloud >/dev/null 2>&1 || exit 0
timeout 20m docker exec --env START_CONTAINERS=1 nextcloud-aio-mastercontainer /daily-backup.sh
# AIO's wrapper can return zero even when its PHP start command failed.
for name in nextcloud-aio-database nextcloud-aio-redis nextcloud-aio-nextcloud nextcloud-aio-apache; do
    [[ $(docker inspect --format '{{.State.Running}}' "$name") == true ]] || { echo "$name did not start. Inspect AIO logs." >&2; exit 1; }
done
for ((i=0; i<180; i++)); do
    if [[ $(docker inspect --format '{{.State.Health.Status}}' nextcloud-aio-apache) == healthy && $(docker inspect --format '{{.State.Health.Status}}' nextcloud-aio-nextcloud) == healthy ]]; then exit 0; fi
    sleep 5
done
echo 'Nextcloud did not become healthy after startup.' >&2
exit 1
BASH
chmod 0755 /usr/local/sbin/nextcloud-aio-{start,stop}-siblings
cat >/etc/systemd/system/nextcloud-aio-lifecycle.service <<'UNIT'
[Unit]
Description=Nextcloud AIO clean shutdown and snapshot restore startup
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target
Before=shutdown.target reboot.target halt.target
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/nextcloud-aio-start-siblings
ExecStop=/usr/local/sbin/nextcloud-aio-stop-siblings
TimeoutStartSec=25min
TimeoutStopSec=25min
[Install]
WantedBy=multi-user.target
UNIT

cat >/usr/local/sbin/nextcloud-aio-configure-talk <<'PY'
#!/usr/bin/env python3
import hashlib, json, pathlib, subprocess, sys
config = pathlib.Path('/etc/nextcloud-aio/external-talk.json')
mode_path = pathlib.Path('/etc/nextcloud-aio/talk-mode')
mode = mode_path.read_text().strip() if mode_path.exists() else 'external'
if mode not in ('external','internal'): raise SystemExit('Invalid Talk mode')
occ = ['docker','exec','--user','www-data','nextcloud-aio-nextcloud','php','occ']
p = subprocess.run(occ+['status','--output=json'],capture_output=True,text=True)
if p.returncode: sys.exit(0)  # Initial AIO web setup has not finished yet.
s = json.loads(p.stdout)
if not s.get('installed') or s.get('maintenance') or s.get('needsDbUpgrade'): sys.exit(0)
env=dict(v.split('=',1) for v in json.loads(subprocess.check_output(['docker','inspect','--format','{{json .Config.Env}}','nextcloud-aio-nextcloud'])))
if (env.get('TALK_ENABLED') == 'yes') != (mode == 'internal'):
    print('Waiting: '+('enable' if mode == 'internal' else 'disable')+' the built-in Talk backend in the AIO interface.')
    sys.exit(0)
if mode == 'external':
    if not config.exists(): sys.exit(0)
    c=json.loads(config.read_bytes())
else:
    domain=env['NC_DOMAIN']
    c={'signaling_url':'https://'+domain+'/standalone-signaling',
       'signaling_secret':env['SIGNALING_SECRET'],
       'turn_server':(env.get('TURN_DOMAIN') or domain)+':'+env.get('TALK_PORT','3478'),
       'turn_secret':env['TURN_SECRET']}
for key in ('signaling_url','signaling_secret','turn_server','turn_secret'):
    if not c.get(key): raise SystemExit('Missing HPB setting: '+key)
if not c['signaling_url'].startswith('https://'): raise SystemExit('HPB URL must use HTTPS')
marker = pathlib.Path('/etc/nextcloud-aio/external-talk.applied')
fingerprint = hashlib.sha256((mode+json.dumps(c,sort_keys=True)).encode()).hexdigest()
if marker.exists() and marker.read_text().strip()==fingerprint: sys.exit(0)
apps=json.loads(subprocess.check_output(occ+['app:list','--output=json']))
if 'spreed' not in apps.get('enabled',{}):
    command='app:enable' if 'spreed' in apps.get('disabled',{}) else 'app:install'
    subprocess.run(occ+[command,'spreed'],check=True)
values={
    'signaling_servers':json.dumps({'servers':[{'server':c['signaling_url'].rstrip('/')+'/','verify':True}],'secret':c['signaling_secret']}),
    'turn_servers':json.dumps([{'schemes':'turn','server':c['turn_server'],'secret':c['turn_secret'],'protocols':'udp,tcp'}]),
    'stun_servers':json.dumps([c['turn_server']]),
}
# Secrets travel on stdin, never in process arguments or terminal output.
cmd=['docker','exec','-i','--user','www-data','nextcloud-aio-nextcloud','php','occ','config:import']
p=subprocess.run(cmd,input=json.dumps({'apps':{'spreed':values}}),text=True,capture_output=True)
if p.returncode: raise SystemExit('Talk configuration import failed; inspect Nextcloud logs.')
marker.write_text(fingerprint+'\n')
print('Talk configured for '+mode+' HPB: '+c['signaling_url'])
PY
chmod 0755 /usr/local/sbin/nextcloud-aio-configure-talk
printf '%s\n' "$TALK_MODE" >/etc/nextcloud-aio/talk-mode
cat >/usr/local/sbin/nextcloud-aio-set-talk-mode <<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ ${1:-} == internal || ${1:-} == external ]] || { echo 'Usage: nextcloud-aio-set-talk-mode internal|external' >&2; exit 1; }
if [[ $1 == external && ! -s /etc/nextcloud-aio/external-talk.json ]]; then
    echo 'Supply /etc/nextcloud-aio/external-talk.json first.' >&2; exit 1
fi
printf '%s\n' "$1" >/etc/nextcloud-aio/talk-mode
rm -f /etc/nextcloud-aio/external-talk.applied
if [[ $1 == internal ]]; then
    echo 'In AIO, stop containers, enable Talk, and start containers. TCP/UDP 3478 must be allowed.'
else
    echo 'In AIO, stop containers, disable Talk, and start containers. Start the separate HPB independently.'
fi
/usr/local/sbin/nextcloud-aio-configure-talk
echo 'The timer applies matching credentials automatically once the selected AIO mode is running.'
BASH
chmod 0755 /usr/local/sbin/nextcloud-aio-set-talk-mode
if [[ -n $HPB_CONFIG ]]; then
    python3 - "$HPB_CONFIG" <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
assert all(isinstance(c.get(k),str) and c[k] for k in ('signaling_url','signaling_secret','turn_server','turn_secret'))
assert c['signaling_url'].startswith('https://')
PY
    if [[ $(readlink -f "$HPB_CONFIG") != /etc/nextcloud-aio/external-talk.json ]]; then
        install -m 0600 "$HPB_CONFIG" /etc/nextcloud-aio/external-talk.json
    fi
fi
cat >/etc/systemd/system/nextcloud-aio-external-talk.service <<'UNIT'
[Unit]
Description=Apply the selected internal or external Talk settings
After=docker.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/nextcloud-aio-configure-talk
TimeoutStartSec=10min
UNIT
cat >/etc/systemd/system/nextcloud-aio-external-talk.timer <<'UNIT'
[Unit]
Description=Wait for AIO initialization before configuring external Talk
[Timer]
OnBootSec=60
OnUnitInactiveSec=60
Unit=nextcloud-aio-external-talk.service
[Install]
WantedBy=timers.target
UNIT

docker compose -f "$APP_DIR/compose.yaml" config --quiet
docker compose -f "$APP_DIR/compose.yaml" up -d
systemctl daemon-reload
systemctl enable --now nextcloud-aio-lifecycle.service nextcloud-aio-external-talk.timer
systemctl start nextcloud-aio-external-talk.service
cat <<EOF

Host setup complete. Domain: ${DOMAIN}
Swap: ${SWAP_GB} GiB minimum. Upload limit: 20 GiB. Data remains on the root disk.

One-time official AIO setup (if not already completed):
  ssh -i ~/.ssh/hetzner_abraham -L 8080:127.0.0.1:8080 root@${DOMAIN}
  Open https://127.0.0.1:8080 and configure ${DOMAIN}.
  Enable Collabora / Nextcloud Office (not EuroOffice).
  Selected Talk mode: ${TALK_MODE}. Enable AIO Talk only for internal mode.
  Leave ClamAV, Fulltext Search and Talk Recording disabled on this 4 GB VM.

With --hpb-config, Talk is configured automatically once AIO finishes installing.
Without it, supply /etc/nextcloud-aio/external-talk.json and run:
  /usr/local/sbin/nextcloud-aio-configure-talk

Switch later: nextcloud-aio-set-talk-mode internal  (or external), then follow its AIO steps.
Hetzner firewall: TCP 22,80,443,3478; UDP 443,3478; ICMP (IPv4 and IPv6).
The AIO management port remains bound to localhost. Local TURN is available in internal mode.
Use the local nextcloud_create_n_delete script for snapshot shutdowns.
EOF
