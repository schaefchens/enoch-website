#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# ============================================================
# Nextcloud Talk HPB
# ============================================================

NC_DOMAIN="${NC_DOMAIN:-wolke.schaefchens.de}"
SECOND_NC_DOMAIN="${SECOND_NC_DOMAIN:-wolke2.schaefchens.de}"
HPB_DOMAIN="hpb.schaefchens.de"
TALK_PORT="3478"

APP_DIR="/opt/nextcloud-talk-hpb"
ENV_FILE="${APP_DIR}/.env"
COMPOSE_FILE="${APP_DIR}/compose.yaml"

log() {
    printf '\n==> %s\n' "$*"
}

die() {
    printf '\nERROR: %s\n' "$*" >&2
    exit 1
}

trap 'printf "\nERROR in line %s.\n" "$LINENO" >&2' ERR


# ============================================================
# Root / OS check
# ============================================================

[[ $(uname -s) == Linux && ${EUID} -eq 0 ]] || \
    die "Run this script as root: sudo ./setup-hpb.sh"

# shellcheck disable=SC1091
source /etc/os-release

[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] || \
    die "This script expects Ubuntu 24.04 LTS."

export DEBIAN_FRONTEND=noninteractive


# ============================================================
# Base packages
# ============================================================

log "Installing base packages"

apt-get update

apt-get install -y \
    ca-certificates \
    curl \
    gnupg \
    openssl \
    dnsutils \
    python3 \
    ufw \
    debian-keyring \
    debian-archive-keyring \
    apt-transport-https


# ============================================================
# Docker Engine
# ============================================================

log "Installing/validating Docker Engine"

if ! command -v docker >/dev/null 2>&1; then

    # Remove conflicting packages only if Docker is not
    # already installed.
    apt-get remove -y \
        docker.io \
        docker-compose \
        docker-compose-v2 \
        docker-doc \
        docker-buildx \
        podman-docker \
        containerd \
        runc 2>/dev/null || true

    install -m 0755 -d /etc/apt/keyrings

    curl -fsSL \
        https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc

    chmod a+r /etc/apt/keyrings/docker.asc

    cat >/etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

    apt-get update

    apt-get install -y \
        docker-ce \
        docker-ce-cli \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin
fi

docker compose version >/dev/null 2>&1 || \
    die "Docker exists, but Docker Compose v2 is missing."

systemctl enable --now docker


# ============================================================
# Caddy
# ============================================================

log "Installing/validating Caddy"

if ! command -v caddy >/dev/null 2>&1; then

    curl -1sLf \
        'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor --yes \
            -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg

    curl -1sLf \
        'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        | tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null

    chmod o+r \
        /usr/share/keyrings/caddy-stable-archive-keyring.gpg

    chmod o+r \
        /etc/apt/sources.list.d/caddy-stable.list

    apt-get update
    apt-get install -y caddy
fi

systemctl enable caddy >/dev/null 2>&1 || true


# ============================================================
# HPB directory
# ============================================================

log "Preventing public-domain loopback DNS"
hostnamectl set-hostname "${HPB_DOMAIN%%.*}"
install -d -m 0755 /etc/cloud/cloud.cfg.d
cat >/etc/cloud/cloud.cfg.d/99-hpb-lifecycle.cfg <<EOF
preserve_hostname: true
hostname: ${HPB_DOMAIN%%.*}
fqdn: ${HPB_DOMAIN%%.*}
ssh_deletekeys: false
EOF
python3 - "$HPB_DOMAIN" <<'DNS'
import pathlib,sys,shutil
p=pathlib.Path('/etc/hosts')
original=p.read_text()
lines=[]
for line in original.splitlines():
    fields=line.split()
    if fields and fields[0].startswith('127.') and sys.argv[1] in fields[1:]:
        fields=[fields[0]]+[x for x in fields[1:] if x!=sys.argv[1]]
        line=' '.join(fields) if len(fields)>1 else ''
    lines.append(line)
p.write_text('\n'.join(lines)+'\n')
p=pathlib.Path('/etc/cloud/templates/hosts.debian.tmpl')
if p.exists():
    p.write_text(p.read_text().replace('127.0.1.1 {{fqdn}} {{hostname}}','127.0.1.1 {{hostname}}'))
DNS
resolvectl flush-caches

if [[ -d "$APP_DIR" ]]; then
    BACKUP_DIR="/var/backups/hpb-setup-$(date -u +%Y%m%dT%H%M%SZ)"
    install -d -m 0700 "$BACKUP_DIR"
    cp -a "$APP_DIR/." "$BACKUP_DIR/"
fi
log "Creating HPB configuration"

install -d -m 0700 "$APP_DIR"

touch "$ENV_FILE"
chmod 0600 "$ENV_FILE"


# ============================================================
# Environment helper
# ============================================================

set_env() {
    local key="$1"
    local value="$2"

    if grep -qE "^${key}=" "$ENV_FILE"; then
        sed -i \
            "s|^${key}=.*|${key}=${value}|" \
            "$ENV_FILE"
    else
        printf '%s=%s\n' \
            "$key" "$value" >>"$ENV_FILE"
    fi
}


ensure_secret() {
    local key="$1"

    # Existing valid secret is preserved.
    if ! grep -qE "^${key}=[0-9a-fA-F]{64}$" "$ENV_FILE"; then

        sed -i "/^${key}=/d" "$ENV_FILE"

        printf '%s=%s\n' \
            "$key" \
            "$(openssl rand -hex 32)" \
            >>"$ENV_FILE"
    fi
}


# ============================================================
# Secrets
# ============================================================

log "Creating/preserving HPB secrets"

ensure_secret TURN_SECRET
ensure_secret SIGNALING_SECRET
ensure_secret SIGNALING_SECRET_WOLKE2
ensure_secret INTERNAL_SECRET

set_env NC_DOMAIN "$NC_DOMAIN"
set_env TALK_HOST "nextcloud-talk-hpb"
set_env TURN_DOMAIN "$HPB_DOMAIN"
set_env TALK_PORT "$TALK_PORT"

set_env TZ "Europe/Berlin"
set_env AIO_LOG_LEVEL "warn"
set_env SKIP_CERT_VERIFY "false"

chmod 0600 "$ENV_FILE"


# ============================================================
# Docker Compose
# ============================================================

log "Writing Docker Compose configuration"

# Preserve the installed image on reruns; new installations pull the official image.
if docker inspect nextcloud-talk-hpb >/dev/null 2>&1; then
    IMAGE_ID="$(docker inspect --format '{{.Image}}' nextcloud-talk-hpb)"
else
    docker pull ghcr.io/nextcloud-releases/aio-talk:latest
    IMAGE_ID=ghcr.io/nextcloud-releases/aio-talk:latest
fi
HPB_IMAGE="$(docker image inspect --format '{{index .RepoDigests 0}}' "$IMAGE_ID")"
[[ -n "$HPB_IMAGE" ]] || die "Could not determine the HPB image digest."

install -d -m 0755 "${APP_DIR}/hooks"
cat >"${APP_DIR}/hooks/start-multi.sh" <<'HOOK'
#!/usr/bin/env bash
set -Eeuo pipefail
# Let the image generate its normal TURN/Janus/signaling configuration first.
exec /start.sh /hpb/apply-backends.sh "$@"
HOOK
cat >"${APP_DIR}/hooks/apply-backends.sh" <<'HOOK'
#!/usr/bin/env bash
set -Eeuo pipefail
# Replace only the single-backend sections generated by upstream /start.sh.
grep -q '^\[backend\]$' /conf/signaling.conf
grep -q '^\[backend-1\]$' /conf/signaling.conf
awk '
/^\[/ { skip = ($0 == "[backend]" || $0 == "[backend-1]") }
!skip { print }
' /conf/signaling.conf >/conf/signaling.multi.tmp
cat /hpb/backends.conf >>/conf/signaling.multi.tmp
mv /conf/signaling.multi.tmp /conf/signaling.conf
exec "$@"
HOOK
chmod 0755 "${APP_DIR}/hooks/"*.sh

python3 - "$APP_DIR" "$HPB_IMAGE" "$NC_DOMAIN" "$SECOND_NC_DOMAIN" "$HPB_DOMAIN" "$TALK_PORT" <<'CONFIG'
import json, os, pathlib, re, subprocess, sys
app, image, first, second, hpb, port = sys.argv[1:]
for domain in (first,second,hpb):
    assert re.fullmatch(r'[a-z0-9.-]+',domain) and '.' in domain, 'Invalid domain'
assert first != second, 'Backends must use different domains'
p=pathlib.Path(app)
env={}
for line in (p/'.env').read_text().splitlines():
    if '=' in line and not line.lstrip().startswith('#'):
        key,value=line.split('=',1)
        env[key]=value.strip().strip('"').strip("'")
backend='[backend]\nbackends = backend-1, backend-2\nallowall = false\ntimeout = 10\nconnectionsperhost = 32\nskipverify = false\n'
for number,domain,key in [(1,first,'SIGNALING_SECRET'),(2,second,'SIGNALING_SECRET_WOLKE2')]:
    assert re.fullmatch(r'[0-9a-fA-F]{64}',env[key]), 'Invalid signaling secret'
    backend+=f'\n[backend-{number}]\nurls = https://{domain}\nsecret = {env[key]}\nmaxstreambitrate = 1048576\nmaxscreenbitrate = 2097152\n'
(p/'hooks/backends.conf').write_text(backend)
runtime_gid=int(subprocess.check_output(['docker','run','--rm','--network','none','--entrypoint','id',image,'-g']))
os.chown(p/'hooks/backends.conf',0,runtime_gid)
os.chmod(p/'hooks/backends.conf',0o640)
cmd=json.loads(subprocess.check_output(['docker','image','inspect','--format','{{json .Config.Cmd}}',image]))
assert isinstance(cmd,list) and cmd, 'Image has no default command'
compose={'services':{'talk-hpb':{
    'image':image,'container_name':'nextcloud-talk-hpb','restart':'always',
    'env_file':['.env'], 'entrypoint':['/hpb/start-multi.sh'], 'command':cmd,
    'volumes':['./hooks:/hpb:ro'],
    'ports':['127.0.0.1:8081:8081/tcp',f'{port}:{port}/tcp',f'{port}:{port}/udp']
}}}
(p/'compose.yaml').write_text(json.dumps(compose,indent=2)+'\n')
client={'signaling_url':f'https://{hpb}', 'signaling_secret':env['SIGNALING_SECRET_WOLKE2'], 'turn_server':f'{hpb}:{port}', 'turn_secret':env['TURN_SECRET']}
(p/'wolke2-client.json').write_text(json.dumps(client,indent=2)+'\n')
os.chmod(p/'wolke2-client.json',0o600)
CONFIG

chmod 0644 "$COMPOSE_FILE"


# ============================================================
# DNS check
# ============================================================

log "Checking DNS before enabling automatic HTTPS"

SERVER_IPV4="$(
    ip -4 route get 1.1.1.1 2>/dev/null \
        | sed -n 's/.* src \([^ ]*\).*/\1/p' \
        | head -n1
)"

[[ -n "$SERVER_IPV4" ]] || \
    die "Could not determine this server's public IPv4 address."


DNS_IPV4="$(
    dig @1.1.1.1 +short A "$HPB_DOMAIN" \
        | grep -E '^[0-9]+(\.[0-9]+){3}$' \
        || true
)"

if ! grep -Fxq "$SERVER_IPV4" <<<"$DNS_IPV4"; then

    die "DNS mismatch:

${HPB_DOMAIN}

must point to:

${SERVER_IPV4}

Current A record(s):

${DNS_IPV4:-none}

Fix DNS and run this script again."
fi


# ============================================================
# IPv6 DNS check
# ============================================================

DNS_IPV6="$(
    dig +short AAAA "$HPB_DOMAIN" \
        | grep ':' \
        || true
)"

if [[ -n "$DNS_IPV6" ]]; then

    SERVER_IPV6="$(
        ip -6 route get 2606:4700:4700::1111 2>/dev/null \
            | sed -n 's/.* src \([^ ]*\).*/\1/p' \
            | head -n1 \
            || true
    )"

    if [[ -z "$SERVER_IPV6" ]] || \
       ! grep -Fxiq "$SERVER_IPV6" <<<"$DNS_IPV6"; then

        die "AAAA mismatch.

${HPB_DOMAIN} currently has:

${DNS_IPV6}

but this server does not use that IPv6 address.

Remove/fix the AAAA record and run this script again."
    fi
fi


# ============================================================
# UFW firewall
# ============================================================

log "Configuring host firewall"

SSH_PORT="${SSH_PORT:-}"

# Detect current SSH server port.
if [[ -z "$SSH_PORT" && -n "${SSH_CONNECTION:-}" ]]; then
    SSH_PORT="$(awk '{print $4}' <<<"$SSH_CONNECTION")"
fi

SSH_PORT="${SSH_PORT:-22}"

[[ "$SSH_PORT" =~ ^[0-9]+$ ]] || \
    die "Invalid SSH_PORT: $SSH_PORT"


ufw default deny incoming
ufw default allow outgoing

ufw allow "${SSH_PORT}/tcp" \
    comment 'SSH'

ufw allow 80/tcp \
    comment 'Caddy HTTP ACME'

ufw allow 443/tcp \
    comment 'Caddy HTTPS'

ufw allow "${TALK_PORT}/tcp" \
    comment 'Nextcloud Talk TURN TCP'

ufw allow "${TALK_PORT}/udp" \
    comment 'Nextcloud Talk TURN UDP'

ufw --force enable


# ============================================================
# Caddy config
# ============================================================

log "Configuring Caddy"

# Preserve original file once.
if [[ -f /etc/caddy/Caddyfile ]] && \
   [[ ! -f "${APP_DIR}/Caddyfile.before-hpb" ]]; then

    cp -a \
        /etc/caddy/Caddyfile \
        "${APP_DIR}/Caddyfile.before-hpb"
fi


cat >/etc/caddy/Caddyfile <<EOF
${HPB_DOMAIN} {
    reverse_proxy 127.0.0.1:8081
}
EOF


caddy validate \
    --config /etc/caddy/Caddyfile


# ============================================================
# Start HPB
# ============================================================

log "Pulling Nextcloud Talk HPB"

cd "$APP_DIR"

docker compose config --quiet


log "Starting Nextcloud Talk HPB"

docker compose up -d


# ============================================================
# Wait for local signaling
# ============================================================

log "Waiting for local signaling endpoint"

LOCAL_OK=0

for _ in $(seq 1 60); do

    if curl -fsS \
        --max-time 3 \
        http://127.0.0.1:8081/api/v1/welcome \
        | grep -q 'nextcloud-spreed-signaling'
    then
        LOCAL_OK=1
        break
    fi

    sleep 2
done


if [[ "$LOCAL_OK" -ne 1 ]]; then

    docker logs \
        --tail=120 \
        nextcloud-talk-hpb \
        || true

    die "HPB did not become ready on port 8081."
fi


# ============================================================
# Start Caddy / HTTPS
# ============================================================

log "Starting Caddy"

systemctl enable --now caddy
systemctl reload caddy


# ============================================================
# Wait for HTTPS / certificate
# ============================================================

log "Waiting for HTTPS and TLS certificate"

HTTPS_OK=0

for _ in $(seq 1 60); do

    if curl -fsS \
        --max-time 5 \
        "https://${HPB_DOMAIN}/api/v1/welcome" \
        | grep -q 'nextcloud-spreed-signaling'
    then
        HTTPS_OK=1
        break
    fi

    sleep 2
done


if [[ "$HTTPS_OK" -ne 1 ]]; then

    journalctl \
        -u caddy \
        --no-pager \
        -n 100 \
        || true

    die "HTTPS is not ready.

Check:

1. ${HPB_DOMAIN} DNS
2. Hetzner Firewall TCP 80
3. Hetzner Firewall TCP 443"
fi


# ============================================================
# Nextcloud reachability
# ============================================================

log "Checking Nextcloud reachability"

if ! curl -fsS \
    --max-time 10 \
    "https://${NC_DOMAIN}/status.php" \
    >/dev/null
then

    printf \
        'WARNING: Could not fetch https://%s/status.php from this server.\n' \
        "$NC_DOMAIN" \
        >&2
fi


# ============================================================
# Secrets are retained only in root-readable configuration files.

# Certificate information
# ============================================================

log "TLS certificate"

echo \
    | openssl s_client \
        -connect "${HPB_DOMAIN}:443" \
        -servername "$HPB_DOMAIN" \
        2>/dev/null \
    | openssl x509 \
        -noout \
        -issuer \
        -subject \
        -dates \
    || true


# ============================================================
# Result
# ============================================================

cat <<EOF


============================================================
NEXTCLOUD TALK HPB SETUP COMPLETE (TWO ISOLATED BACKENDS)
============================================================

HPB:

  https://${HPB_DOMAIN}


Second Nextcloud: https://${SECOND_NC_DOMAIN}
Copy ${APP_DIR}/wolke2-client.json securely to the Nextcloud VM.
Pass its path to nextcloud_server_setup.sh --hpb-config.
Do not print this file or put it in source control.

NEXTCLOUD TALK
Settings
-> Administration
-> Talk
-> High-performance backend

URL:

  https://${HPB_DOMAIN}

Shared secret:

  Existing wolke secret preserved in ${ENV_FILE}


------------------------------------------------------------
TURN
------------------------------------------------------------

Mode:

  turn:

Server:

  ${HPB_DOMAIN}:${TALK_PORT}

Secret:

  Existing TURN secret preserved in ${ENV_FILE}

Protocols:

  UDP and TCP


Optional STUN server:

  ${HPB_DOMAIN}:${TALK_PORT}


------------------------------------------------------------
FILES
------------------------------------------------------------

Docker Compose:

  ${COMPOSE_FILE}

Secrets:

  ${ENV_FILE}

Caddy:

  /etc/caddy/Caddyfile


------------------------------------------------------------
STATUS COMMANDS
------------------------------------------------------------

HPB:

  curl https://${HPB_DOMAIN}/api/v1/welcome

Docker:

  docker ps

Logs:

  docker logs --tail=100 nextcloud-talk-hpb

Caddy:

  systemctl status caddy --no-pager

Caddy logs:

  journalctl -u caddy -n 100 --no-pager


------------------------------------------------------------
HETZNER CLOUD FIREWALL
------------------------------------------------------------

Required inbound:

  TCP ${SSH_PORT}     SSH
  TCP 80              ACME / HTTP
  TCP 443             HPB HTTPS / WebSocket
  TCP ${TALK_PORT}    TURN
  UDP ${TALK_PORT}    TURN


------------------------------------------------------------
SNAPSHOT LIFECYCLE
------------------------------------------------------------

DO NOT run:

  docker compose down

before creating the lifecycle snapshot.

Instead:

  1. Shut down Ubuntu normally.
  2. Create a new Hetzner snapshot.
  3. Wait until the snapshot is complete.
  4. Delete the server.
  5. Keep the Primary IPv4.

When a new server is created from the snapshot:

  - Docker starts automatically.
  - nextcloud-talk-hpb starts automatically.
  - Caddy starts automatically.
  - Caddy checks/renews TLS automatically.
  - Existing ACME state and certificates are preserved
    inside the snapshot.

============================================================

EOF
