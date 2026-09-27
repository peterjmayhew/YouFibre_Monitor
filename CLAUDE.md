# CLAUDE.md – project handbook (YouFibre Broadband Monitor)

These are notes for picking the project up again: for Claude Code, which loads this file automatically, or a human maintainer. The user-facing guide is [README.md](README.md). Private deployment details are in `CLAUDE.local.md`, which is git-ignored.

## What this is

An ESP32 on the home Wi-Fi tests the internet every 10 s. It sends a heartbeat every 60 s to a WordPress plugin, and sends exact outage records once it's back online. The plugin detects silences, emails alerts, and shows an uptime report ("went down / came back up") with CSV export.

- Maintainer: Peter Mayhew.
- Repo: https://github.com/peterjmayhew/YouFibre_Monitor (MIT).

## Layout

```
ESP32/YouFibreMonitor/
  YouFibreMonitor.ino     firmware (single file)
  config.h                timings, device ID, LED pin, NTP/TZ (committed)
  secrets.example.h       template (committed)
  secrets.h               Wi-Fi, WP_API_BASE, WP_TOKEN (GIT-IGNORED, never commit/print)
wordpress-plugin/netmon-heartbeat/netmon-heartbeat.php   whole plugin, one class `NetMon`
tests/harness.php         plugin test harness (fake WP + fake $wpdb), 74 checks
tools/check-secrets.py    pre-push leak scan (uses secrets.h + git-ignored tools/private-patterns.txt)
tools/serialwatch.py      read ESP32 serial until "Heartbeat OK" (pyserial)
blog/                     ready-to-paste WordPress blog post
README.md                 full user guide     CHANGELOG.md   versions
```

## Current versions

| Part | Version |
|---|---|
| Plugin | **2.4.0** (released as GitHub release `v2.4.0`) |
| Firmware | `FW_VERSION` **2.0.0** |
| Plugin DB schema | `DB_VERSION` **2** |

## Firmware design (YouFibreMonitor.ino)

- **Internet check**: each check tries a TCP connect to 1.1.1.1, 8.8.8.8 and 9.9.9.9 on port 443, with a 2.5 s timeout each. Any success = UP. IP addresses are used, not hostnames, so a DNS fault can't hide a working link.
- **Debounce**: `FAILS_BEFORE_DOWN`=3 failed checks, i.e. 30 s, before an outage opens. The outage start is backdated to the first failed check. `PASSES_BEFORE_UP`=2; the end time is the first good check.
- **Outage types**: `internet` (Wi-Fi up, no internet) and `wifi`. A change of type splits the record.
- **Router ping**: every 6th check during an internet outage, the ESP32 ICMP-pings the router gateway via `ping/ping_sock.h`. `gw` records 1 = router answered, 2 = no reply.
- **Time**: NTP, stored as UTC epoch. Before the clock syncs, events carry uptime seconds (`esp_timer`), and `resolveEpoch()` converts them once synced.
- **Queue**: closed outages are written to LittleFS `/queue.csv` once the time is known. `POST /events` sends batches of 40, before the heartbeat. They're deleted only after HTTP 200 plus `"ok":true`.
- **Deliberate reboots**: an outage in progress is stashed in `RTC_NOINIT` memory before a deliberate reboot (Wi-Fi down 30 min, or low heap).
- **Watchdog**: the task watchdog is set to 90 s. Core 3.x uses `esp_task_wdt_reconfigure`; 2.x is handled with `#if`.
- **Status page**: a local web server at `/` and `/status.json`, reachable as mDNS `youfibre-monitor.local`.
- **LED**: GPIO 2. Solid = OK, slow blink = internet down, fast blink = no Wi-Fi. S3 boards usually have an RGB LED elsewhere, so it may be dark.
- **TLS**: `WiFiClientSecure::setInsecure()` is deliberate, for simplicity. The token is also sent in the JSON body, because some hosts strip custom headers.

## Plugin design (netmon-heartbeat.php)

- **Tables**:
  - `{prefix}netmon_heartbeats`: raw heartbeat log, pruned after `retention_days`.
  - `{prefix}netmon_events`: outages. `source` = device|server, `event_type` = internet|wifi|silent, `UNIQUE(device_id, uid)`.
- **Times**: all DB times are **UTC**. Display uses `wp_date()` in the site timezone.
- **Options**:
  - `netmon_settings`
  - `netmon_state` (per device: last_hb, first_seen, rssi, …)
  - `netmon_db_version`
  - `netmon_cron_last_run`
  - `netmon_last_rejected`
  - site transient `netmon_github_release`
- **REST** `netmon/v1`: `POST /heartbeat` and `POST /events` (token in the `X-NetMon-Token` header or a `token` field in the body), `GET /ping` (public).
- **Silence detection**:
  - A cron job every minute (`netmon_watchdog_event`) opens a `silent` event with `uid srv-<last_hb>` when the last heartbeat is older than `timeout_minutes`, and emails "DOWN?".
  - The next heartbeat closes it and emails "Back online".
  - If cron missed the gap, the heartbeat creates a closed silent event itself.
  - The same uid scheme is used by the history rebuild, so rows de-duplicate.
- **Emails, avoiding double emails**:
  - `alert_sent` marks events that have already been emailed.
  - Device events arriving while a DOWN alert is open are left for the recovery email to report.
  - The rebuild tool writes `alert_sent=1`, so it never emails.
- **Report merge** (`build_report`):
  - Device events are authoritative. A server silence is dropped when at least 50% of it is covered by device events, **unless it's still ongoing**.
  - Overlapping or touching intervals are then merged into incidents.
  - Ignored events are excluded from downtime.
- **Clock skew**: an event's times are corrected only when `|server_now - device_now| > 120 s`.
- **Live panel**: the 60 one-minute blocks allow ±30 s, because heartbeats drift (~61 s period). Without that, it shows false red blocks.
- **Updater**:
  - The `Update URI: https://github.com/peterjmayhew/YouFibre_Monitor` header lets the `update_plugins_github.com` filter answer (WP 5.8+).
  - It reads `releases/latest` and needs the asset **`netmon-heartbeat.zip`** containing the folder `netmon-heartbeat/`.
  - `ensure_update_registered()` injects the update into the `update_plugins` transient, so "Update now" works immediately.
  - `upgrader_source_selection` renames the unzipped folder if it's wrong.
- **Admin tabs**: Report, Export CSV, Heartbeats, Settings (including History tools), ESP32 Setup, About & Updates. There's also a dashboard widget, an admin-bar node and the `[netmon_report]` shortcode.
- **Upgrades**: `init()` runs `install()` (`dbDelta`) whenever `netmon_db_version` differs. The v1 draft stored local times, and the upgrade converts them to UTC.

## Build, flash, test

- **arduino-cli**, bundled with Arduino IDE 2 on Windows:
  `"%LOCALAPPDATA%/Programs/Arduino IDE/resources/app/lib/backend/resources/arduino-cli.exe"` with `--config-file ~/.arduinoIDE/arduino-cli.yaml`. The ESP32 core 3.3.5 is installed.
- **FQBN**:
  - ESP32-S3: `esp32:esp32:esp32s3:CDCOnBoot=cdc,FlashSize=16M`
  - Classic ESP32: `esp32:esp32:esp32`
- **Compile and upload**: `arduino-cli compile --upload -p COMx --fqbn … .`. The first build takes ~10 min on this PC. Run it in the background.
- **Serial**: opening or closing the port resets an S3, so don't leave a serial reader attached. Use `python tools/serialwatch.py COM3 150`.
- **Plugin tests**:
  ```
  php -l wordpress-plugin/netmon-heartbeat/netmon-heartbeat.php
  php tests/harness.php wordpress-plugin/netmon-heartbeat/netmon-heartbeat.php
  ```
  The harness must print `ALL PASSED`. Add checks for new features, and teach `FakeWpdb` any new SQL. PHP 8.4 is installed; the plugin targets PHP 7.4+, so avoid `match`, `?->` and `str_contains`.
- On this Windows PC, `curl` needs `--ssl-no-revoke`, and often `-4`.

## Releasing a plugin update

1. Bump **both** the `Version:` header and `const VERSION`. Add a CHANGELOG entry.
2. Run `php -l`, then the harness (it must print `ALL PASSED`).
3. Zip with the top-level folder:
   ```
   cd wordpress-plugin && zip -r netmon-heartbeat.zip netmon-heartbeat
   ```
   Python's `zipfile` works if `zip` is missing. `*.zip` is git-ignored.
4. Run `python tools/check-secrets.py`. It **must** print SAFE. Then commit and push.
5. Create the release:
   ```
   gh release create vX.Y.Z wordpress-plugin/netmon-heartbeat.zip --title … --notes-file …
   ```
   The release notes (Markdown) are shown in the plugin's About tab.
6. Verify: `releases/latest` shows the new tag and the asset, and the downloaded zip matches.

## Conventions and gotchas

- **Never** print, commit or paste the contents of `secrets.h`. Scan before every push.
- Git commits in this repo use a repo-local identity: "Peter Mayhew" with the GitHub noreply address. The global git config is a placeholder, and the personal email must not be published.
- The repo root is inside OneDrive. That works, but don't run git operations while OneDrive is mid-sync on the same files.
- Hosts may cache `GET /wp-json/netmon/v1/ping`. Add a `?nc=<random>` query when checking a live site.
- Some hosts have broken IPv6 TLS on the `www.` name. Use the site's canonical, final address for `WP_API_BASE` and cron URLs, because the ESP32 doesn't follow redirects.
- WP-Cron only runs on visits, so users need an every-minute cron job (Plesk "Fetch a URL", cPanel `wget`, or cron-job.org).

## Ideas / backlog (not started)

- RGB status LED support for ESP32-S3 boards (`rgbLedWrite`), with a configurable pin.
- The plugin could show "newer ESP32 firmware available" by comparing the heartbeat's `fw` with the latest release.
- OTA firmware updates for the ESP32.
- A separate outage type for DNS failures (TCP OK but DNS lookups fail).
- Optional periodic speed/latency trend chart. Hourly outage pattern chart.
- `uninstall.php` with an opt-in data wipe.
- Optionally add the maintainer's contact email to the README/About tab, but only if Peter asks.
