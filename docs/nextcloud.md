# Nextcloud AIO and shared Talk HPB

These scripts are for `wolke2.schaefchens.de` on Ubuntu 24.04, with 4 GB RAM, a 40 GB root disk, at least 8 GB swap, and a 20 GB upload limit. Collabora runs **locally in Nextcloud AIO**. Talk supports either the shared HPB at `hpb.schaefchens.de` or AIO's internal HPB.

## Files and where to run them

| File | Run on | Purpose |
| --- | --- | --- |
| `nextcloud_server_setup.sh` | Nextcloud Ubuntu VM, as root | Docker/AIO, swap, DNS correction, clean shutdown/start hooks, and selectable Talk configuration |
| `nextcloud_create_n_delete` | Your Mac | Create from snapshot, start, check status, cleanly stop, snapshot, and delete the Nextcloud VM |
| `highperformancebackend_server_setup.sh` | HPB Ubuntu VM, as root | Preserve the existing `wolke` integration and add an isolated `wolke2` backend |

The two server setup scripts refuse to run on macOS. The local lifecycle script uses Python 3, `hcloud`, and `~/.ssh/hetzner_abraham`. It does **not** start or stop the shared HPB. Continue using your existing `highperformancebackend_create_n_delete` for that server.

To install the three files into `/usr/local/bin` on the Mac, open a terminal in this output folder and run:

```bash
sudo install -m 0755 nextcloud_create_n_delete nextcloud_server_setup.sh highperformancebackend_server_setup.sh /usr/local/bin/
```

This copies the server installers; it does not execute them on the Mac. The previous HPB setup script was backed up in the task's `work` directory before attempting local installation.

## Normal Nextcloud lifecycle

```bash
nextcloud_create_n_delete
```

This starts/restores Nextcloud, waits for readiness, and then waits for Enter. Enter cleanly stops AIO, powers off the VM, creates and verifies a snapshot, and only then deletes the VM. Ctrl-C while waiting leaves it running.

Separate commands:

```bash
nextcloud_create_n_delete start
nextcloud_create_n_delete status
nextcloud_create_n_delete stop
nextcloud_create_n_delete start --dry-run
nextcloud_create_n_delete stop --dry-run
```

Options:

- `--type TYPE`: choose a compatible x86 VM type. Default: `cx23` (4 GB RAM, 40 GB disk). Capacity is checked before creation; there is no automatic upgrade to a more expensive type.
- `--keep-server`: snapshot and leave the VM powered off, preserving its allocated capacity. Powered-off VMs are still billed.
- `--base-snapshot ID`: explicitly restore a known base snapshot when no VM exists.
- `--base`: use the newest snapshot labelled `service=nextcloud-aio,instance=wolke2,lifecycle=base`.
- `--fresh`: create Ubuntu 24.04 for an initial installation instead of restoring a snapshot. This does not run the installer or overwrite an existing VM.
- `--skip-snapshot`: deliberately discard current changes. An available restore snapshot must exist first.
- `--retain N`: keep N managed Nextcloud snapshots; default 2. Base snapshots and snapshots belonging to HPB or other services are never pruned.

The script uses these existing persistent IPs, both with `auto_delete=false`:

- IPv4 ID `150948650`: `2.28.110.128`
- IPv6 ID `150948651`: `2a01:4f8:c17:655e::/64`

The dedicated firewall allows TCP 22, 80, 443, 3478; UDP 443, 3478; and ICMP on IPv4/IPv6. Port 3478 is prepared for a future internal Talk backend. AIO management remains on localhost port 8080.

## Install on a fresh Nextcloud VM

Copy the server installer onto the VM. For external Talk, also copy the HPB-generated `/opt/nextcloud-talk-hpb/wolke2-client.json` securely to `/root/external-talk.json` on the Nextcloud VM. This file contains secrets; keep it root-readable and out of source control.

On the Nextcloud VM:

```bash
sudo bash /root/nextcloud_server_setup.sh \
  --talk-mode external --hpb-config /root/external-talk.json
```

For an installation that uses only the internal HPB:

```bash
sudo bash /root/nextcloud_server_setup.sh --talk-mode internal
```

Complete AIO's supported one-time web setup:

```bash
ssh -i ~/.ssh/hetzner_abraham -L 8080:127.0.0.1:8080 root@wolke2.schaefchens.de
```

Open `https://127.0.0.1:8080`, use `wolke2.schaefchens.de` as the domain, select **Collabora / Nextcloud Office**, and enable the AIO Talk container only for **internal** mode. Leave ClamAV, Fulltext Search, and Talk Recording disabled on the 4 GB VM. The Talk app is retained in either mode. The installed timer applies the matching Talk settings after Nextcloud finishes initializing.

The installer does not bypass the AIO setup interface. Restoring a completed snapshot does not require this setup again.

## Change Talk mode later

On the Nextcloud VM:

```bash
sudo nextcloud-aio-set-talk-mode internal
```

Then in AIO, stop the containers, enable **Talk**, and start the containers. The helper uses AIO's own signaling and TURN credentials and replaces the external endpoints with local ones.

To switch back:

```bash
sudo nextcloud-aio-set-talk-mode external
```

Then stop containers in AIO, disable its **Talk** container, and start them. Start the separate HPB using its own lifecycle script. Existing external credentials are preserved in `/etc/nextcloud-aio/external-talk.json`.

For an immediate configuration attempt or diagnostics:

```bash
sudo nextcloud-aio-configure-talk
sudo journalctl -u nextcloud-aio-external-talk.service -n 30 --no-pager
```

Collabora remains local in both modes.

## Import the prepared accounts

The private, Git-ignored import file is
`var/imports/wolke-nextcloud-users.tsv`. Its columns cover username, new
password, display name, email, semicolon-separated groups, groups administered by
the user, quota and manager. Validate it without contacting the server:

```bash
python3 bin/import-nextcloud-users.py --validate-only
```

With Nextcloud running and healthy, import it with:

```bash
python3 bin/import-nextcloud-users.py
```

The importer is safe to resume after interruption. New accounts are created and
existing listed accounts are updated to the file's intended password and profile.
Passwords are sent through SSH standard input and process environment, never as
command arguments or output. The importer uses Nextcloud's `occ` commands for
accounts, groups, quotas and managers. Group-admin assignments use Nextcloud's
service API inside the application container; the database is not edited
directly. It verifies all listed accounts, memberships, quotas and sub-admin
assignments before reporting success.

## HPB changes

Run `highperformancebackend_server_setup.sh` **on the HPB Ubuntu VM**, not on the Mac. It keeps the installed HPB image and original `wolke` and TURN secrets on reruns. Fresh installs download the official image and record its digest.

The generated signaling configuration uses two separate backend sections, with distinct signaling secrets and room namespaces:

- `backend-1`: `https://wolke.schaefchens.de`
- `backend-2`: `https://wolke2.schaefchens.de`

An entrypoint wrapper adds these sections after the official image generates its normal configuration. It supports the installed image's default command, so it works with the older Supervisor image as well as images using dinit. The wrapper is a local extension and should be checked when intentionally upgrading the HPB image.

Snapshot the updated HPB with its existing lifecycle script so future creations retain both backends.

## DNS and shutdown details

The original fault was a cloud-init hosts entry mapping the public domain to `127.0.1.1`. Docker's DNS inherited that answer from systemd-resolved, so containers tried to reach themselves. The installer uses the short hostname `wolke2`, removes the public-domain loopback alias, and corrects the cloud-init template for future boots. Cloud-init also preserves the server's SSH host keys when resuming a snapshot on its persistent IPs, so strict SSH host verification continues to work.

The local stop command invokes AIO's supported `STOP_CONTAINERS=1` mechanism and verifies that sibling containers have stopped before requesting VM shutdown. The boot hook invokes `START_CONTAINERS=1` and checks actual container readiness. The Docker shutdown timeout is 30 minutes. No integrated Borg backups are configured; persistence follows the requested clean-shutdown snapshot workflow.

The 20 GB upload setting is a limit, not reserved disk capacity. Available space also holds Docker images, swap, the database, and user files.

Before a managed snapshot, Enoch removes its registered swap file after AIO has stopped and runs filesystem TRIM. The enabled `enoch-swapfile.service` recreates the recorded file during boot before Docker starts. If shutdown fails after preparation, a 30-minute failsafe restores swap on the still-running VM.

## Verification on 22 September 2026

- The Nextcloud installer ran successfully on the existing Ubuntu 24.04 VM. Shell syntax and all embedded Python blocks were checked.
- All 16 automated lifecycle and Talk mode tests passed. These cover failed clean stops, failed/unverified snapshots, wrong server identity, Primary IP deletion protection, and switching between internal/external endpoints and credentials.
- A clean Nextcloud stop created verified snapshot **434940917**. The existing CX23 VM was retained, powered on again, and all enabled AIO containers became healthy automatically. Docker DNS continued returning the public address after reboot.
- Local Collabora discovery and capabilities checks passed before and after the restart.
- The internal HPB passed its compatibility check before switching. The external HPB passed afterward. Both reported only the optional `changed-users` feature update warning.
- The shared HPB passed public WebSocket and UDP/TCP STUN checks. Its VM was deleted and recreated from a snapshot successfully, with both backend definitions and the original `wolke` secrets intact. The restore exposed cloud-init's default SSH key regeneration; the final scripts now preserve the host keys. Final HPB snapshot: **434940944**.

This was an installation rerun on the existing Nextcloud VM, not a fresh Ubuntu installation test. Nextcloud VM deletion/recreation was not exercised because Hetzner reported no CX23 capacity; the clean snapshot and cold restart were tested instead. Browser document editing and a live multi-party call still need an end-user check.

## Collectives and user import verification on 24 September 2026

- Restored current snapshot **435255619** as a CX23 through Enoch. Cloud-init
  installed and enabled the activity heartbeat timer. All AIO containers became
  healthy, including local Collabora.
- Installed and enabled Collectives **4.7.0** on Nextcloud **35.0.0**. Its
  integrity check passed, and the required Circles/Teams, Text, Viewer and file
  versioning apps are enabled.
- Imported all **15** prepared accounts into the new instance alongside the
  existing installer administrator. The importer verified four groups, all
  memberships, 13 quotas at 1 GB, two quotas at 10 GB, manager references and
  group-admin assignments. The retired `@der-weg-des-herrn.de` addresses are not
  present in the source file.
- A real Nextcloud login-page request was detected by the VM agent. Its
  authenticated heartbeat request to production Enoch completed successfully.
- The first post-shutdown snapshot request was not confirmed. Enoch waited through
  its ambiguity window, found no image with that job label, failed closed and kept
  the powered-off VM. A fresh retry then created and verified current snapshot
  **435571454** (9.82 GB) with parent label **435255619**, released the VM, and
  pruned only the superseded unprotected current image.
- Final cloud state: no VM allocated; protected initial snapshot **434940917** and
  current snapshot **435571454** remain.

## References

- [Nextcloud AIO](https://github.com/nextcloud/all-in-one)
- [Official AIO Compose example](https://github.com/nextcloud/all-in-one/blob/main/compose.yaml)
- [Signaling server backend configuration](https://github.com/strukturag/nextcloud-spreed-signaling/blob/master/server.conf.in)
- [Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/)
- [Cloud-init SSH host-key settings](https://docs.cloud-init.io/en/latest/reference/modules.html#ssh)
