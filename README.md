# YouFibre Broadband Monitor

**Find out exactly when your broadband goes down and when it comes back up, 24 hours a day, with a report you can send to your provider.**

A small ESP32 board (about £5–£10) sits on your home Wi-Fi and tests your internet connection every 10 seconds. It sends the results to a plugin on your WordPress website. The plugin keeps a permanent record, emails you when the connection drops, and shows an uptime report. You can download the report as a spreadsheet at any time.

It was built for YouFibre, but it works with **any** broadband provider.

> **Author:** Peter Mayhew · **Suggestions and problems:** [open an issue](https://github.com/peterjmayhew/YouFibre_Monitor/issues/new) · **Licence:** MIT

---

## Contents

1. [What you get](#1-what-you-get)
2. [How it works](#2-how-it-works)
3. [What you need](#3-what-you-need)
4. [Installation overview](#4-installation-overview)
5. [Step 1 – Install the WordPress plugin](#step-1--install-the-wordpress-plugin)
6. [Step 2 – Add an every-minute cron job (Plesk, cPanel or free online service)](#step-2--add-an-every-minute-cron-job)
7. [Step 3 – Set up the ESP32](#step-3--set-up-the-esp32)
8. [Step 4 – Check everything is working](#step-4--check-everything-is-working)
9. [Using it day to day](#9-using-it-day-to-day)
10. [Updating](#10-updating)
11. [Troubleshooting](#11-troubleshooting)
12. [Questions, privacy and security](#12-questions-privacy-and-security)
13. [Uninstalling](#13-uninstalling)
14. [For developers](#14-for-developers)
15. [Suggestions and contact](#15-suggestions-and-contact)

---

## 1. What you get

* **Exact outage times**: "went down Tue 3 Sep 14:02:10, came back up 14:37:55, down for 35m 45s".
* **The likely cause**: the ESP32 pings your router during an outage, so the report can tell you:
  * **Internet down, router reachable**: the fault was on the broadband provider's side.
  * **No Wi-Fi**: your router or mesh was off or rebooting.
  * **Monitor offline**: nothing reached the website, usually a power cut.
* **Emails**:
  * "**DOWN?**" when the monitor goes quiet.
  * "**Back online**" with the exact times when it returns.
  * A summary of any short outage that was too brief to trigger the first email.
* **An uptime report in WordPress**:
  * uptime % for the last 24 hours, 7, 30 and 90 days
  * a colour strip with one block per day
  * a list of every outage
  * any date range you choose
* **CSV export** for Excel or Google Sheets: outage list, daily summary, raw records or raw heartbeats.
* **Live status**:
  * a "Receiving heartbeats from the ESP32" panel that updates by itself
  * a **● Broadband: Online** indicator in the WordPress admin bar
* **Nothing is lost while you're offline.** The ESP32 saves outages in its own memory, even across a restart, and uploads them when the internet returns.
* **One-click updates** from GitHub inside WordPress.

## 2. How it works

```
 YOUR HOME                                           YOUR WEBSITE (WordPress)
┌──────────────────────────────┐                    ┌──────────────────────────────┐
│ ESP32 on your Wi-Fi           │  every 60 seconds  │ NetMon plugin                 │
│  • every 10 s: can I reach    │ ── "heartbeat" ──▶ │  • stores heartbeats          │
│    1.1.1.1 / 8.8.8.8 / 9.9.9.9│                    │  • notices when they stop     │
│  • outage? note exact times,  │  when back online  │    → emails "DOWN?"           │
│    ping the router, save to   │ ── outage list ──▶ │  • builds the uptime report   │
│    its memory                 │                    │  • CSV export                 │
└──────────────────────────────┘                    └──────────────────────────────┘
                                                     ▲ a cron job runs the checks
                                                     │ every minute (Step 2)
```

**Why two kinds of detection?** When your internet is down, the ESP32 can't reach your website, so it keeps its own exact record and sends it later. Meanwhile the website notices the heartbeats have stopped, so it can email you even when the ESP32 can't. That covers power cuts too, when the ESP32 is off as well.

## 3. What you need

| Item | Notes |
|---|---|
| **An ESP32 board** | Any ESP32 or ESP32-S3 development board, e.g. "ESP32 DevKit V1" or "ESP32-S3 DevKitC N16R8". Around £5–£10 online. |
| **A USB cable** | It must be a *data* cable, not a charge-only one, to program the board. |
| **A USB phone charger** | To power the ESP32 permanently near your router. |
| **A WordPress website** | Version 5.8 or newer, PHP 7.4 or newer. It must be hosted **outside your home** (e.g. with a hosting company), otherwise it goes down with your broadband. |
| **A Windows, Mac or Linux computer** | To program the ESP32 once, using the free Arduino IDE. |
| **2.4 GHz Wi-Fi** | ESP32 boards can't use 5 GHz. Most routers, including YouFibre's, broadcast both. |

## 4. Installation overview

Allow about 30–45 minutes the first time.

- [ ] **Step 1**: install the plugin on your WordPress site and copy the **token** it creates.
- [ ] **Step 2**: add a cron job in Plesk, cPanel or a free online service that runs WordPress's scheduled tasks every minute. This makes the "DOWN?" emails arrive on time.
- [ ] **Step 3**: put your Wi-Fi details and the token into the ESP32 program, and upload it with the Arduino IDE.
- [ ] **Step 4**: check the green **"Receiving heartbeats from the ESP32"** panel appears.

---

## Step 1 – Install the WordPress plugin

1. Go to the **[Releases page](https://github.com/peterjmayhew/YouFibre_Monitor/releases/latest)** and download **`netmon-heartbeat.zip`**.
   *Download the file named exactly `netmon-heartbeat.zip`, not "Source code".*
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Click **Choose File**, select `netmon-heartbeat.zip`, click **Install Now**, then **Activate Plugin**.
4. A new **Broadband Uptime** item appears in the left-hand menu. Open **Broadband Uptime → ESP32 Setup**. You'll need two things from this page in Step 3:
   * **`WP_API_BASE`**: your site's address for the monitor, e.g. `https://example.co.uk/wp-json/netmon/v1`
   * **`WP_TOKEN`**: a 32-character password that proves heartbeats come from *your* ESP32
5. Go to **Broadband Uptime → Settings**:
   * Check the **alert email** address.
   * Click **Send test email**. If it doesn't arrive, your site probably needs an SMTP plugin to send email reliably, e.g. "WP Mail SMTP".
6. Quick test: open `https://YOUR-SITE/wp-json/netmon/v1/ping` in a browser. You should see `{"ok":true,...}`. If you see an error, a security plugin or firewall may be blocking the WordPress REST API. See [Troubleshooting](#11-troubleshooting).

> **Upgrading from the old draft plugin (`heartbeat.php`)?** Deactivate and delete it first. Both register the same web address. The new plugin keeps the old database table and token, so existing history carries over. Use **Settings → History tools → Rebuild** to turn old heartbeat gaps into outage records.

## Step 2 – Add an every-minute cron job

WordPress only runs its scheduled tasks when someone visits your website. The monitor needs a check **every minute** so it can email you promptly when the heartbeats stop. You give it that by asking your hosting to fetch one address every minute:

```
https://YOUR-SITE/wp-cron.php?doing_wp_cron
```

Replace `YOUR-SITE` with your domain, e.g. `https://example.co.uk/wp-cron.php?doing_wp_cron`. The exact address is shown on **Broadband Uptime → ESP32 Setup**, section 3.

> Outage *records* are kept even without this step. It only affects how quickly the "DOWN?" email arrives.

### Option A – Plesk

1. Log in to Plesk. Then either:
   * go to **Websites & Domains**, find your site, and click **Scheduled Tasks** (it may be under "Dev Tools"), or
   * as the server administrator, go to **Tools & Settings → Scheduled Tasks**.
2. Click **Add Task** and fill in the form exactly like this:

   | Field | What to enter |
   |---|---|
   | **Active** | ☑ ticked |
   | **Task type** | **Fetch a URL** |
   | **URL** | `https://YOUR-SITE/wp-cron.php?doing_wp_cron` on **one line**, with no spaces |
   | **Run** | Choose **Cron style**, then type `* * * * *` (five stars separated by spaces = every minute) |
   | **Description** | `Broadband monitor – WP-Cron every minute` |
   | **Notify** | **Errors only** |
   | **Send notifications to** | **Administrator** (or your own email) |

3. Click **Run Now**. It should finish without an error.
4. Click **OK** to save.

> **Tip: use the exact domain your site uses.** If your site redirects `www.example.co.uk` to `example.co.uk` (or the other way round), use the one it ends up on. On some hosts the `www` name has an IPv6 record where the secure connection fails. If Plesk emails you connection errors, try the address without `www`.

### Option B – cPanel

1. In cPanel, open **Cron Jobs**.
2. Under **Common Settings**, choose **Once Per Minute (\* \* \* \* \*)**.
3. In **Command**, enter:
   ```
   wget -q -O /dev/null "https://YOUR-SITE/wp-cron.php?doing_wp_cron"
   ```
4. Click **Add New Cron Job**.

### Option C – free online service (any host)

If your hosting has no cron feature, use a free service such as **[cron-job.org](https://cron-job.org)**:

1. Create a free account and click **Create cronjob**.
2. Set the **URL** to `https://YOUR-SITE/wp-cron.php?doing_wp_cron`.
3. Set the schedule to **every 1 minute**.
4. Save.

### Optional: stop visitors triggering WP-Cron

Once the cron job is working, you can make your site slightly faster. Add this line to `wp-config.php`, above the line `/* That's all, stop editing! */`:

```php
define('DISABLE_WP_CRON', true);
```

Only do this **after** the cron job is running, otherwise scheduled tasks stop altogether.

**How to check:** in **Broadband Uptime → ESP32 Setup**, section 3 shows *"Last scheduled check: … (under a minute ago)"*. The yellow "WP-Cron hasn't run recently" warning also disappears from the plugin's pages.

## Step 3 – Set up the ESP32

### 3.1 Install the Arduino IDE and ESP32 support (one time only)

1. Download and install the **Arduino IDE 2** from <https://www.arduino.cc/en/software>.
2. Open it and go to **File → Preferences** (on a Mac: **Arduino IDE → Settings**).
3. In **Additional boards manager URLs**, paste the address below and click **OK**:
   ```
   https://espressif.github.io/arduino-esp32/package_esp32_index.json
   ```
4. Go to **Tools → Board → Boards Manager**, search for **esp32**, and install **"esp32 by Espressif Systems"**. Version 3.x is tested; 2.x also works.

### 3.2 Download the program

1. On this GitHub page, click the green **Code** button, then **Download ZIP**, and unzip it.
2. Open the folder **`ESP32/YouFibreMonitor`**. It contains:

   | File | What it is |
   |---|---|
   | `YouFibreMonitor.ino` | The program. Open this in the Arduino IDE. |
   | `config.h` | General settings: check timings, device name, LED pin. The defaults are fine. |
   | `secrets.example.h` | A template for your **private** settings. |

### 3.3 Add your private settings (`secrets.h`)

Your Wi-Fi password and website token go in a separate file called **`secrets.h`**. That file is excluded from GitHub, so you can share or update the code without giving away your passwords.

1. In the `YouFibreMonitor` folder, **copy** `secrets.example.h` and rename the copy to **`secrets.h`**.
   *On Windows, turn on View → File name extensions so the file doesn't end up as `secrets.h.txt`.*
2. Open `secrets.h` in Notepad (or in the Arduino IDE, where it appears as a tab). Change the text **between the quote marks** on each line:
   ```c
   #define WIFI_SSID        "YourWiFiName"        // your Wi-Fi name, exactly as on your phone
   #define WIFI_PASSWORD    "YourWiFiPassword"    // your Wi-Fi password
   #define WP_API_BASE      "https://example.co.uk/wp-json/netmon/v1"   // from ESP32 Setup
   #define WP_TOKEN         "paste-the-token-from-wordpress-here"       // from ESP32 Setup
   ```
3. Keep the `"` quote marks, then save.

If you forget this step, the Arduino IDE stops with the message *"secrets.h not found: copy secrets.example.h to secrets.h…"*.

### 3.4 Upload to the ESP32

1. Plug the ESP32 into your computer with the USB cable.
2. In the Arduino IDE, choose **File → Open** and open `YouFibreMonitor.ino`.
3. Choose your board and settings under the **Tools** menu:

   | Setting | Classic ESP32 (e.g. DevKit V1) | ESP32-S3 (e.g. DevKitC N16R8) |
   |---|---|---|
   | **Board** | ESP32 Dev Module | ESP32S3 Dev Module |
   | **USB CDC On Boot** | *(not shown)* | **Enabled**, so messages appear in the Serial Monitor |
   | **Flash Size** | leave as is | match your board, e.g. **16MB** for N16R8 |
   | **Partition Scheme** | Default | **Default 4MB with spiffs** (it must include "spiffs") |
   | **Port** | the COM port that appears when you plug it in | same |

   Not sure which you have? The chip name is printed on the metal shield, e.g. "ESP32-S3-WROOM-1".
4. Click the **Upload** button (the right-arrow icon). It takes 1–5 minutes the first time.
   * If it waits at "Connecting…", hold the board's **BOOT** button until the upload starts.
   * If no port appears on Windows, a classic ESP32 may need the **CP210x** or **CH340** USB driver.
5. Open **Tools → Serial Monitor** and set it to **115200 baud**. Within about 10 seconds you should see:
   ```
   Wi-Fi connected: 192.168.1.50, RSSI -55 dBm
   Heartbeat OK (RSSI -55 dBm, 48 ms)
   ```
6. Unplug it and move it to its permanent home: a **USB phone charger** near the router. It starts working about 10 seconds after power-up. You don't need the computer again.

## Step 4 – Check everything is working

Open **Broadband Uptime → Report** in WordPress. At the top you should see:

> ✔ **Receiving heartbeats from the ESP32** (home-esp32-1)
> Last heartbeat **23s ago** · Heartbeats in the last hour **59 of ~60 expected** · Wi-Fi -55 dBm (excellent)

Below that is a bar of 60 blocks, one for each of the last 60 minutes. A green block means a heartbeat arrived that minute. The panel refreshes itself every 20 seconds.

You'll also see **● Broadband: Online** in the dark admin bar at the top of every WordPress admin page.

**Setup is finished.**

---

## 9. Using it day to day

### The report (Broadband Uptime → Report)

* **Connection panel** shows the live status:

  | Status | Meaning |
  |---|---|
  | ✔ green | Receiving heartbeats |
  | ⚠ amber | Heartbeat late |
  | ✖ red | NO SIGNAL since … |
  | grey | Waiting for the first heartbeat |
  | red box | The ESP32 is being **turned away**, which means the token is wrong |

* **Uptime cards**: uptime % for the last 24 hours, 7, 30 and 90 days.
* **Period selector**: last 24 hours / 7 / 30 / 90 days / 12 months / this month / last month / custom dates.
* **Daily uptime strip**: one block per day. Hover over a block to see its uptime % and downtime.

  | Colour | Uptime that day |
  |---|---|
  | green | 100% |
  | light green | 99.5% or more |
  | yellow / orange | a few outages |
  | red | below 95% |
  | grey | not being monitored |

* **When it went down and came back up**: every outage with its start, end, duration and cause.
* **Ignore / Restore**: click **Ignore** on something that wasn't a real outage (e.g. you unplugged the ESP32) to leave it out of the uptime figures.

The ESP32 only counts an outage after **30 seconds** of failed checks, so tiny blips are ignored. You can change this in `config.h`: `FAILS_BEFORE_DOWN` × `CHECK_INTERVAL_S`.

### Emails

| Email | When |
|---|---|
| **[Broadband] DOWN? No heartbeat from … for 5m** | The ESP32 has been silent for 5 minutes. Change this under **Settings → "Down" alert after**. |
| **[Broadband] Back online – was down for 35m 45s** | The ESP32 is back. Includes the exact down and up times it recorded. |
| **[Broadband] Outage: internet was down for 3m 20s** | A short outage (2 minutes or more by default) that didn't trigger a DOWN email. |

You can turn emails on or off, and change both times, in **Settings**.

### Export to CSV (Broadband Uptime → Export CSV)

Choose what to export, a period (including **All time** and **custom dates**) and click **Download CSV**. The files open in Excel, Google Sheets or Numbers.

| Export | One row per | Good for |
|---|---|---|
| **Outage report** | outage: down, up, duration, cause | sending to your provider or a complaint |
| **Daily summary** | day, with a TOTAL row | monthly reviews and charts |
| **Raw outage records** | every individual record | checking the detail |
| **Raw heartbeats** | heartbeat (one a minute) | spotting IP changes or weak Wi-Fi |

Times are in your WordPress timezone (**Settings → General**); the raw exports also include UTC.

### History tools (Broadband Uptime → Settings, bottom of the page)

* **Rebuild outage history from heartbeats**
  * Scans saved heartbeats and turns every gap longer than N minutes into a "Monitor offline" outage. Useful after upgrading from the old draft plugin.
  * Click **Preview** first: it changes nothing and warns you if N is too small.
  * Safe to run more than once, and it never sends emails.
* **Clear history**
  * Permanently deletes outage records, heartbeats, or everything. "Everything" restarts the uptime figures.
  * You can limit it to data **before a date**.
  * You must tick a confirmation box first. **Export a CSV first** if you might want the data later.

### The ESP32's own status page

On any phone or computer on your home Wi-Fi, open **<http://youfibre-monitor.local>**, or the ESP32's IP address, shown on the Report tab and in the Serial Monitor.

It shows:
* live status
* Wi-Fi signal
* the last response from WordPress
* recent outages

**It works even while the internet is down.**

*Android phones often don't support `.local` addresses; use the IP address instead.*

### The ESP32's light

| Light | Meaning |
|---|---|
| Solid | All good |
| Slow blink | Internet down |
| Fast blink | No Wi-Fi |

The light pin is set in `config.h` (`STATUS_LED_PIN`, default GPIO 2). Many ESP32-S3 boards have an RGB light on a different pin instead, so yours may not light up. The monitor works fine without it.

### Showing the report on a page

Add the shortcode `[netmon_report]` to any page or post. You can add options, e.g. `[netmon_report days="90"]`. Only administrators can see it unless you tick **Settings → Public report**.

## 10. Updating

### The WordPress plugin

* **Broadband Uptime → About & Updates** shows your version and the latest version on GitHub.
  * Click **Check for updates**.
  * If a newer version exists, click **Update now**.
  * Your settings, token and history are kept.
* WordPress also checks by itself twice a day, and the update appears on the normal **Plugins** and **Dashboard → Updates** screens.
* To install updates automatically, click **Enable auto-updates** next to the plugin on the **Plugins** screen.
* The installed version is shown:
  * next to the page title
  * in the Broadband Uptime dashboard widget
  * at the bottom of the plugin's pages

### The ESP32

The ESP32 is updated by uploading the new program from the Arduino IDE:

1. Download the latest code.
2. **Keep your existing `secrets.h`**: copy it into the new `YouFibreMonitor` folder.
3. Upload as in [Step 3.4](#34-upload-to-the-esp32).

## 11. Troubleshooting

| What you see | Likely cause and fix |
|---|---|
| Panel stays grey: "Waiting for the first heartbeat" | Open the Serial Monitor and look for the error. Check `WP_API_BASE` exactly matches **ESP32 Setup**, including `https://` and no trailing `/`. |
| Red box: **"An ESP32 tried to connect but was turned away"** | The token is wrong or missing. Copy it again from **ESP32 Setup** into `secrets.h` and re-upload. |
| Serial Monitor: `Heartbeat failed: HTTP 401` | Same as above: wrong token. |
| `HTTP 403` or `HTTP 404`, or `/ping` doesn't show `{"ok":true}` | A security plugin (Wordfence, iThemes, etc.), Cloudflare or the host's firewall is blocking the WordPress REST API. Allow `/wp-json/netmon/v1/`. If your site uses "Plain" permalinks, use `https://YOUR-SITE/index.php?rest_route=/netmon/v1` as `WP_API_BASE`. |
| `HTTP -1` or `connection refused` | The ESP32 can't reach your site. Check the address. If your site redirects www↔non-www, use the final address, because the ESP32 doesn't follow redirects. |
| Serial Monitor: `Wi-Fi not connected` / fast-blinking light | Wrong Wi-Fi name or password, a 5 GHz-only network, or it's too far from the router. The name is case-sensitive. |
| Wi-Fi signal below -80 dBm ("very weak") | Move the ESP32 closer to the router, or near a mesh point. |
| "WP-Cron hasn't run recently" warning | The cron job from [Step 2](#step-2--add-an-every-minute-cron-job) isn't running. In Plesk, click **Run Now** on the task and read the error. |
| Test email doesn't arrive | Your site can't send email. Install an SMTP plugin such as "WP Mail SMTP" and configure it. |
| Heartbeat count well above 60 an hour, or values flicker | Two ESP32s are using the same `DEVICE_ID`. Unplug the old one, or give each a different name in `config.h`. |
| Arduino: `secrets.h not found` | See [Step 3.3](#33-add-your-private-settings-secretsh). |
| Arduino: stuck at "Connecting…" | Hold the **BOOT** button while it connects. Try a different USB cable (some are charge-only). |
| Nothing in the Serial Monitor on an ESP32-S3 | Set **Tools → USB CDC On Boot → Enabled** and upload again. |
| Opening the Serial Monitor restarts the ESP32 | Normal for ESP32 boards. It doesn't happen when it's powered by a charger. |
| "Check for updates" says it can't reach GitHub | Your host may block outgoing connections. Try again later, or download the zip from Releases and upload it via **Plugins → Add New → Upload** (choose "Replace current with uploaded"). |

## 12. Questions, privacy and security

**What does the ESP32 test?** Every 10 seconds, it tries to open a connection to three well-known internet addresses: Cloudflare 1.1.1.1, Google 8.8.8.8 and Quad9 9.9.9.9. If **any** of them answers, the internet is up. Using addresses rather than names means a DNS fault can't hide a working connection.

**How accurate are the times?** Outages the ESP32 records itself are accurate to about 10 seconds. "Monitor offline" periods, based on missing heartbeats, are accurate to about a minute.

**What data is sent to my website?** Only your own site receives data. Each heartbeat contains:
* the device name
* Wi-Fi signal strength
* connection time
* the ESP32's running time and firmware version
* its local IP address

Your website also records the public IP address the heartbeat came from. Nothing is sent to anyone else, and there are no analytics or tracking.

**Is it secure?**
* Data is sent over HTTPS.
* Your site only accepts heartbeats that carry your secret token. You can generate a new token under **Settings** at any time.
* The ESP32 doesn't check your site's certificate, which keeps set-up simple. The trade-off is that someone who could intercept your home network traffic could read the token; they could then only send fake heartbeats.
* Keep `secrets.h` private.

**Does it slow my broadband?** No. It uses a few kilobytes a minute.

**Can I monitor more than one location?** Yes. Give each ESP32 a different `DEVICE_ID` in `config.h`. The report and exports let you choose a device.

## 13. Uninstalling

* **Plugin:** go to **Plugins**, click **Deactivate**, then **Delete**. The database tables (`wp_netmon_heartbeats`, `wp_netmon_events`) are kept in case you reinstall. To remove the data first, use **Settings → History tools → Clear history → Everything**.
* **Cron job:** delete the scheduled task in Plesk or cPanel. If you added `DISABLE_WP_CRON`, remove it from `wp-config.php`.
* **ESP32:** unplug it.

## 14. For developers

### Repository layout

```
ESP32/YouFibreMonitor/            Arduino sketch
    YouFibreMonitor.ino           main program
    config.h                      general settings (committed)
    secrets.example.h             template for private settings (committed)
    secrets.h                     your private settings (git-ignored, never committed)
wordpress-plugin/netmon-heartbeat/
    netmon-heartbeat.php          the whole WordPress plugin (single file)
CHANGELOG.md
```

### Plugin REST API (namespace `netmon/v1`)

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /heartbeat` | `X-NetMon-Token` header (or a `token` field in the JSON body) | "I'm alive" plus device stats, every 60 s |
| `POST /events` | token | a batch of outages `{uid, type: internet\|wifi, start, end, gw, fails}`; duplicates by `uid` are ignored |
| `GET /ping` | none | health check |

### Releasing a new plugin version

1. Bump `Version:` in the plugin header **and** `const VERSION` in `netmon-heartbeat.php`.
2. Add an entry to `CHANGELOG.md`.
3. Build the zip. It must contain the folder `netmon-heartbeat/`:
   ```bash
   cd wordpress-plugin && zip -r netmon-heartbeat.zip netmon-heartbeat
   ```
4. Commit, push, and create a GitHub release. **Tag it `vX.Y.Z` and attach `netmon-heartbeat.zip`.** The in-WordPress updater looks for exactly that file name.
   ```bash
   gh release create vX.Y.Z wordpress-plugin/netmon-heartbeat.zip --title "vX.Y.Z" --notes "What changed..."
   ```

Sites running the plugin see the update within 12 hours, or straight away if someone clicks **Check for updates**.

## 15. Suggestions and contact

This project is written and maintained by **Peter Mayhew**.

* 💡 **Ideas and suggestions**, e.g. "it would be great if it could…": [open an issue](https://github.com/peterjmayhew/YouFibre_Monitor/issues/new)
* 🐞 **Problems**: [open an issue](https://github.com/peterjmayhew/YouFibre_Monitor/issues/new). Include what you see on the Report tab, and the Serial Monitor output if it's the ESP32.
* 👤 **Contact Peter**: [github.com/peterjmayhew](https://github.com/peterjmayhew)

Pull requests are welcome.

## Licence

MIT © 2026 Peter Mayhew. See [LICENSE](LICENSE).
