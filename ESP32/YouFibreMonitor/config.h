// =====================================================================
//  YouFibre Broadband Monitor - configuration
//  Edit the values in this file, then upload the sketch to your ESP32.
// =====================================================================
#pragma once

// ---- Private settings ------------------------------------------------
// Wi-Fi name/password, WordPress address and token live in secrets.h,
// which is never uploaded to GitHub. First time: copy secrets.example.h
// to secrets.h and fill it in.
#if __has_include("secrets.h")
#include "secrets.h"
#else
#error "secrets.h not found: copy secrets.example.h to secrets.h and fill in your Wi-Fi and WordPress details"
#endif

// Name of this monitor as shown in WordPress (letters, numbers, dashes)
#define DEVICE_ID        "home-esp32-1"

// ---- Timing -----------------------------------------------------------
#define CHECK_INTERVAL_S       10   // how often to test the internet
#define FAILS_BEFORE_DOWN       3   // consecutive failed checks before an outage starts (3 x 10s = 30s)
#define PASSES_BEFORE_UP        2   // consecutive good checks before the outage is over
#define HEARTBEAT_INTERVAL_S   60   // how often to tell WordPress "I'm alive"
#define CONNECT_TIMEOUT_MS   2500   // per-target timeout for the internet test

// ---- Internet test targets --------------------------------------------
// The internet counts as UP if ANY of these accepts a TCP connection.
// IP addresses are used so a DNS fault can't hide a working connection.
#define TARGET_1_IP   IPAddress(1, 1, 1, 1)      // Cloudflare
#define TARGET_2_IP   IPAddress(8, 8, 8, 8)      // Google
#define TARGET_3_IP   IPAddress(9, 9, 9, 9)      // Quad9
#define TARGET_PORT   443

// ---- Hardware ---------------------------------------------------------
// On-board LED: solid = all good, slow blink = internet down,
// fast blink = no Wi-Fi. Set to -1 to disable.
#define STATUS_LED_PIN   2
#define STATUS_LED_ON    HIGH

// ---- Time ---------------------------------------------------------------
// Times sent to WordPress are always UTC; this is only used for the
// local status page. Default = UK time (GMT/BST).
#define LOCAL_TZ         "GMT0BST,M3.5.0/1,M10.5.0"
#define NTP_SERVER_1     "pool.ntp.org"
#define NTP_SERVER_2     "time.google.com"

// ---- Local status page ------------------------------------------------
// Browse to http://youfibre-monitor.local (or the ESP32's IP) on your
// home network to see live status - works even while the internet is down.
#define MDNS_HOSTNAME    "youfibre-monitor"

// ---- Safety -----------------------------------------------------------
#define WATCHDOG_TIMEOUT_S        90   // reboot if the program ever freezes
#define WIFI_REBOOT_AFTER_MIN     30   // reboot if Wi-Fi can't reconnect for this long
