# Verification — 23 September 2026

## Lifecycle

- Confirmed both original VMs had been deleted before the test.
- Diagnosed the earlier Nextcloud stop failure: the temporary preview used a separate state directory without the restricted SSH key. The HPB operation had succeeded and created snapshot 434941203 before releasing its VM.
- Restored Nextcloud from snapshot 434940917 on CX23/fsn1 using the same persistent IPv4 and IPv6 addresses.
- Cloud-init installed the dedicated public key and restricted preparation agent on the restored VM.
- The PHP client verified the pinned SSH host identity, authenticated with the dedicated key, and received an installed-agent response.
- All enabled AIO containers became healthy, including local Collabora. Nextcloud reported installed=true, maintenance=false and needsDbUpgrade=false.
- Used the PHP lifecycle engine to prepare a clean AIO stop, power off gracefully, create and verify snapshot **435133223**, and delete the powered-off VM. The operation completed successfully.
- Both Nextcloud and HPB were left stopped. Persistent IPs and snapshots were retained. HPB was not started or stopped by the Nextcloud test.
- Further refined the preparation launcher to use `systemd-run --no-block`, so a slow clean stop cannot keep an SSH request open. Restore-time provisioning installs this version even when restoring an older snapshot.

## Application

- 26 PHP checks passed: identity/retention guards, failed stops/snapshots, snapshot provenance, lost API responses, concurrency, authorization, CSRF and scheduled-task behavior.
- 20 Python infrastructure checks passed, including four restricted-agent tests.
- HTTP integration passed using an isolated database and no real cloud token: initial setup, login/logout, roles, CSRF, cron authentication and no secrets in responses.
- The test cron acknowledged in **0.001 seconds** while its simulated downstream controller continued for three seconds.
- Browser verification confirmed the biblical wording and palette, independent service cards, Open links, and successful Copy link behavior.
- PHP/Bash/JavaScript syntax checks passed. Composer audit reported no known dependency security advisories.
- Source secret scan passed. Runtime credentials, SSH keys, databases and vendor files are ignored by Git.

## Hosting

- The Enoch SFTP root was empty before deployment. It maps to https://enoch.schaefchens.de/.
- Verified that `/_enoch/` returns HTTP 403 before uploading private configuration.
- Hosting PHP is **8.5.10** with cURL, PDO SQLite, mbstring and OpenSSL available.
- Backed up both local databases and imported the user's `pauli` account/history into the verified-empty project database. The temporary preview account was not imported. `pauli` is the initial production administrator.

- Live deployment verified at https://enoch.schaefchens.de/; the login page renders the new theme. Private environment, database and vendor paths return HTTP 403.
- Unauthenticated cron requests return HTTP 401; authenticated status requests return HTTP 200.
- A manual authenticated production tick at **05:41:53 UTC** returned HTTP 200 in **0.092 seconds**.
- The production PHP worker successfully called the existing Walk in the Spirit controller; it returned `already-destroyed`. The scheduler recorded successful completion without keeping the cron caller waiting.
- No browser account password was changed. The user's existing password hash was preserved during import.


- Automatic provider cron calls had not yet arrived at the time of initial verification. The manual tick is distinguished from an observed recurring schedule. The user was asked to check the complete authenticated POST command.

## Access, display and lifecycle update (2026-09-23)

- 39 PHP checks cover isolated lifecycle failures, identity checks, retention,
  encrypted credentials and scoped permissions. HTTP tests cover legacy setup,
  login, CSRF, German negotiation, forbidden credentials/options and simple DTOs.
- The cron response is flushed before work; isolated HTTP acknowledgement was
  0.001 seconds while a simulated downstream controller took three seconds.
- Browser inspection covers English/German, system dark mode, explicit light mode,
  simple/technical screens, member permissions and large-disk/discard warnings.
- HPB readiness uses `/api/v1/welcome`; the former `/spreed/api/v1/welcome`
  returned 404 even while the backend itself was healthy.
- The original Nextcloud image 434940917 is `nextcloud-wolke2-initial`, protected
  against deletion and labelled `lifecycle=base`. HPB's protected initial image
  remains 430318286. Snapshot contents are never modified by renaming/protection.
