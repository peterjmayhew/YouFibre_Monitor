# Changelog

## WordPress plugin

### 2.4.0 – 27 Sep 2026
- **Updates from GitHub**:
  - new **About & Updates** tab with **Check for updates** and **Update now** buttons
  - updates also appear on the normal WordPress Plugins and Updates screens
  - works with WordPress auto-updates
- **Version number** shown next to the page title, in the dashboard widget and in the page footer.
- **"What is this?", help links and contact details** (Peter Mayhew) added to the About tab.
- ESP32 setup instructions now use `secrets.h`, so private settings are never uploaded to GitHub.
- Licence set to MIT, matching the repository.

### 2.3.0
- **History tools** in Settings:
  - **Rebuild outage history from heartbeats**, with a preview. It turns gaps in old heartbeat logs into outage records.
  - **Clear history**: outage records, heartbeats or everything, optionally before a date, with a confirmation step.

### 2.2.0
- **Live connection panel** on the Report tab. It shows "Receiving heartbeats from the ESP32", the last heartbeat time counting up, heartbeats in the last hour, a bar of the last 60 minutes, Wi-Fi signal, connect time and public IP. It refreshes every 20 seconds.
- **Admin bar indicator** on every admin page: ● Broadband: Online / heartbeat late / NO SIGNAL / ESP32 rejected.
- **Rejected connection warning** when an ESP32 reaches the site with a wrong or missing token.

### 2.1.0
- **Export CSV** tab offering outage report, daily summary, raw outage records and raw heartbeats.
  - Any period, including All time and custom dates.
  - One device or all devices.
  - Excel-friendly files.

### 2.0.0
- Rewrite of the original draft plugin:
  - receives exact outage records from the ESP32 as well as heartbeats
  - merges them into a clear "went down / came back up" report with the likely cause
  - uptime %, daily strip, email alerts ("DOWN?", "Back online", short-outage summaries), ignore/restore
- Fixes from the draft: the cron job is now scheduled correctly, all times are stored in UTC, and CSV export works.

## ESP32 firmware

### 2.0.0
- Tests the internet every 10 s against 1.1.1.1, 8.8.8.8 and 9.9.9.9. An outage counts after 30 s.
- Saves outages to flash so they survive a restart, and uploads them when back online.
- Pings the router during outages, to tell a broadband fault apart from a router problem.
- Heartbeat every 60 s; local status page at `http://youfibre-monitor.local`; status LED; watchdog.
- Private settings moved into `secrets.h`, which is never committed. See `secrets.example.h`.
