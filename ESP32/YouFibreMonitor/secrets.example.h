// =====================================================================
//  YouFibre Broadband Monitor - private settings TEMPLATE
//
//  1. Copy this file and name the copy  secrets.h  (same folder).
//  2. Fill in the four values below in secrets.h.
//  secrets.h is listed in .gitignore, so your Wi-Fi password and token
//  are never uploaded to GitHub.
// =====================================================================
#pragma once

// Your home Wi-Fi (the ESP32 only supports 2.4 GHz networks)
#define WIFI_SSID        "YourWiFiName"
#define WIFI_PASSWORD    "YourWiFiPassword"

// Copy these two from WordPress:  Broadband Uptime > ESP32 Setup
// No trailing slash. Plain-permalink sites use:
//   "https://example.co.uk/index.php?rest_route=/netmon/v1"
#define WP_API_BASE      "https://example.co.uk/wp-json/netmon/v1"
#define WP_TOKEN         "paste-the-token-from-wordpress-here"
