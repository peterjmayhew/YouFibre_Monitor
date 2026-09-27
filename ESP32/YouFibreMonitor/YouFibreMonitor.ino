// =====================================================================
//  YouFibre Broadband Monitor for ESP32
//
//  * Tests the internet every 10 seconds (TCP connect to 1.1.1.1 /
//    8.8.8.8 / 9.9.9.9) and works out exactly when it goes down and
//    comes back up.
//  * While the internet is down, outages are saved to flash so they
//    survive a reboot, then uploaded to WordPress when it's back.
//  * Sends a heartbeat to WordPress every 60 s, so WordPress can also
//    spot power cuts / router-off periods when the ESP32 goes silent.
//  * Pings the router during an outage to tell "YouFibre line down"
//    apart from "router crashed".
//  * Serves a live status page on your home network.
//
//  Settings live in config.h; your Wi-Fi and WordPress token go in
//  secrets.h (copy secrets.example.h). Board: any ESP32 / ESP32-S3.
//  Needs only the libraries that come with the ESP32 Arduino core.
// =====================================================================

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <LittleFS.h>
#include <esp_task_wdt.h>
#include <time.h>
#include "ping/ping_sock.h"
#include "lwip/ip_addr.h"
#include "config.h"

#define FW_VERSION "2.0.0"

// ---------------------------------------------------------------------
//  Types & globals
// ---------------------------------------------------------------------
enum Obs : uint8_t { OBS_UP = 0, OBS_INET_DOWN = 1, OBS_WIFI_DOWN = 2 };
enum GwState : uint8_t { GW_UNKNOWN = 0, GW_OK = 1, GW_FAIL = 2 };

struct Outage {
  char     uid[12];
  uint8_t  type;        // OBS_INET_DOWN or OBS_WIFI_DOWN
  int64_t  startUp;     // seconds since boot
  int64_t  endUp;
  time_t   startEpoch;  // UTC, 0 = not yet known (clock not synced)
  time_t   endEpoch;
  uint8_t  gw;          // GwState
  uint16_t fails;       // failed checks during the outage
};

// An outage that was in progress when we deliberately rebooted survives in RTC RAM
struct RtcStash {
  uint32_t magic;
  Outage   open;
};
RTC_NOINIT_ATTR RtcStash rtcStash;
static const uint32_t STASH_MAGIC = 0x59465231;  // "YFR1"

static const char *QUEUE_FILE = "/queue.csv";
static const int   MAX_PENDING = 16;
static const int   MAX_RECENT = 15;
static const int   MAX_QUEUE_LINES = 1000;
static const int   UPLOAD_BATCH = 40;

WebServer web(80);

// check state
Obs      currentObs = OBS_WIFI_DOWN;
bool     outageOpen = false;
Outage   openOutage;
int      failStreak = 0, passStreak = 0;
Obs      failType = OBS_UP;
int64_t  firstFailUp = 0, firstPassUp = 0;
time_t   firstFailEpoch = 0, firstPassEpoch = 0;
uint32_t lastLatencyMs = 0;
int      checksSincePing = 0;

// closed outages not yet written to flash (waiting for a clock)
Outage   pending[MAX_PENDING];
int      pendingCount = 0;
// recent outages for the local status page
Outage   recent[MAX_RECENT];
int      recentCount = 0;

int      queueCount = 0;           // lines in QUEUE_FILE
bool     bootReported = false;
int64_t  lastCheckUp = -1000, lastHeartbeatUp = -1000, lastUploadUp = -1000;
int64_t  wifiLostUp = -1, lastWifiRetryUp = 0;
bool     mdnsStarted = false;
int      lastHbCode = 0, lastUploadCode = 0;
bool     lastUploadOk = true;
time_t   lastHbOkEpoch = 0;
String   lastHbError = "";
bool     fsOk = false;

// ---------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------
int64_t upSec() { return esp_timer_get_time() / 1000000LL; }
bool timeSynced() { return time(nullptr) > 1700000000; }
time_t nowEpoch() { return timeSynced() ? time(nullptr) : 0; }

// Convert (epoch-if-known, uptime) into a real UTC epoch if possible
time_t resolveEpoch(time_t ep, int64_t up) {
  if (ep > 0) return ep;
  if (!timeSynced()) return 0;
  return time(nullptr) - (time_t)(upSec() - up);
}

void logf(const char *fmt, ...) {
  char buf[256];
  va_list ap;
  va_start(ap, fmt);
  vsnprintf(buf, sizeof(buf), fmt, ap);
  va_end(ap);
  Serial.printf("[%7lld] %s\n", (long long)upSec(), buf);
}

const char *obsName(uint8_t o) {
  switch (o) {
    case OBS_UP: return "online";
    case OBS_INET_DOWN: return "internet";
    case OBS_WIFI_DOWN: return "wifi";
  }
  return "?";
}

const char *resetReasonStr() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON:  return "power-on";
    case ESP_RST_BROWNOUT: return "brownout";
    case ESP_RST_SW:       return "software";
    case ESP_RST_PANIC:    return "crash";
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:      return "watchdog";
    case ESP_RST_EXT:      return "reset-button";
    case ESP_RST_DEEPSLEEP:return "deep-sleep";
    default:               return "other";
  }
}

String jsonEscape(const String &s) {
  String o;
  o.reserve(s.length() + 4);
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if (c == '"' || c == '\\') { o += '\\'; o += c; }
    else if ((uint8_t)c < 0x20) o += ' ';
    else o += c;
  }
  return o;
}

String fmtDuration(int64_t s) {
  if (s < 0) s = 0;
  char b[32];
  if (s >= 86400) snprintf(b, sizeof(b), "%lldd %lldh %lldm", s / 86400, (s % 86400) / 3600, (s % 3600) / 60);
  else if (s >= 3600) snprintf(b, sizeof(b), "%lldh %lldm %llds", s / 3600, (s % 3600) / 60, s % 60);
  else if (s >= 60) snprintf(b, sizeof(b), "%lldm %llds", s / 60, s % 60);
  else snprintf(b, sizeof(b), "%llds", s);
  return String(b);
}

int64_t outageSeconds(const Outage &o) {
  if (o.startEpoch > 0 && o.endEpoch > 0) return (int64_t)(o.endEpoch - o.startEpoch);
  return o.endUp - o.startUp;
}

String fmtLocal(time_t t) {
  if (t <= 0) return "unknown (clock not set)";
  struct tm tmv;
  localtime_r(&t, &tmv);
  char b[32];
  strftime(b, sizeof(b), "%a %d %b %H:%M:%S", &tmv);
  return String(b);
}

// ---------------------------------------------------------------------
//  Watchdog
// ---------------------------------------------------------------------
void setupWatchdog() {
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t cfg = {};
  cfg.timeout_ms = WATCHDOG_TIMEOUT_S * 1000;
  cfg.idle_core_mask = 0;
  cfg.trigger_panic = true;
  if (esp_task_wdt_reconfigure(&cfg) != ESP_OK) esp_task_wdt_init(&cfg);
#else
  esp_task_wdt_init(WATCHDOG_TIMEOUT_S, true);
#endif
  esp_task_wdt_add(NULL);
}
inline void feedWatchdog() { esp_task_wdt_reset(); }

// ---------------------------------------------------------------------
//  Network tests
// ---------------------------------------------------------------------
bool testInternet(uint32_t &latencyMs) {
  const IPAddress targets[] = { TARGET_1_IP, TARGET_2_IP, TARGET_3_IP };
  for (const IPAddress &ip : targets) {
    WiFiClient c;
    uint32_t t0 = millis();
    bool ok = c.connect(ip, TARGET_PORT, CONNECT_TIMEOUT_MS);
    uint32_t dt = millis() - t0;
    c.stop();
    feedWatchdog();
    if (ok) { latencyMs = dt; return true; }
  }
  return false;
}

static volatile uint32_t pingReplies = 0;
static SemaphoreHandle_t pingDone = nullptr;
static void onPingSuccess(esp_ping_handle_t, void *) { pingReplies = pingReplies + 1; }
static void onPingEnd(esp_ping_handle_t, void *) { if (pingDone) xSemaphoreGive(pingDone); }

// ICMP ping the router. Returns GW_OK if it answered at least once.
uint8_t pingGateway() {
  IPAddress gw = WiFi.gatewayIP();
  if (WiFi.status() != WL_CONNECTED || gw == IPAddress(0, 0, 0, 0)) return GW_UNKNOWN;
  if (!pingDone) pingDone = xSemaphoreCreateBinary();

  ip_addr_t target;
  if (!ipaddr_aton(gw.toString().c_str(), &target)) return GW_UNKNOWN;

  esp_ping_config_t cfg = ESP_PING_DEFAULT_CONFIG();
  cfg.target_addr = target;
  cfg.count = 3;
  cfg.interval_ms = 300;
  cfg.timeout_ms = 1000;

  esp_ping_callbacks_t cbs = {};
  cbs.on_ping_success = onPingSuccess;
  cbs.on_ping_end = onPingEnd;

  esp_ping_handle_t h;
  pingReplies = 0;
  xSemaphoreTake(pingDone, 0);  // clear any stale signal
  if (esp_ping_new_session(&cfg, &cbs, &h) != ESP_OK) return GW_UNKNOWN;
  esp_ping_start(h);
  xSemaphoreTake(pingDone, pdMS_TO_TICKS(6000));
  esp_ping_stop(h);
  esp_ping_delete_session(h);
  feedWatchdog();
  return pingReplies > 0 ? GW_OK : GW_FAIL;
}

// ---------------------------------------------------------------------
//  Outage queue (flash)
// ---------------------------------------------------------------------
int countQueueLines() {
  if (!fsOk || !LittleFS.exists(QUEUE_FILE)) return 0;
  File f = LittleFS.open(QUEUE_FILE, "r");
  if (!f) return 0;
  int n = 0;
  while (f.available()) {
    String line = f.readStringUntil('\n');
    line.trim();
    if (line.length()) n++;
  }
  f.close();
  return n;
}

void addRecent(const Outage &o) {
  if (recentCount < MAX_RECENT) recent[recentCount++] = o;
  else {
    memmove(&recent[0], &recent[1], sizeof(Outage) * (MAX_RECENT - 1));
    recent[MAX_RECENT - 1] = o;
  }
}

// Move closed outages from RAM to flash once we know the real time
void persistPending() {
  if (pendingCount == 0 || !timeSynced()) return;
  int written = 0;
  File f;
  if (fsOk) f = LittleFS.open(QUEUE_FILE, "a");
  for (int i = 0; i < pendingCount; i++) {
    Outage &o = pending[i];
    o.startEpoch = resolveEpoch(o.startEpoch, o.startUp);
    o.endEpoch = resolveEpoch(o.endEpoch, o.endUp);
    // update the matching entry on the status page too
    for (int r = 0; r < recentCount; r++)
      if (strcmp(recent[r].uid, o.uid) == 0) { recent[r].startEpoch = o.startEpoch; recent[r].endEpoch = o.endEpoch; }
    if (f && queueCount < MAX_QUEUE_LINES) {
      f.printf("%s,%u,%lld,%lld,%u,%u\n", o.uid, o.type, (long long)o.startEpoch,
               (long long)o.endEpoch, o.gw, o.fails);
      queueCount++;
      written++;
    }
  }
  if (f) f.close();
  logf("Saved %d outage(s) to flash queue (%d waiting to upload)", written, queueCount);
  pendingCount = 0;
}

void closeOutage(int64_t endUp, time_t endEpoch) {
  if (!outageOpen) return;
  openOutage.endUp = endUp;
  openOutage.endEpoch = endEpoch;
  outageOpen = false;
  logf("OUTAGE OVER (%s) lasted %s", obsName(openOutage.type), fmtDuration(outageSeconds(openOutage)).c_str());
  addRecent(openOutage);
  if (pendingCount < MAX_PENDING) pending[pendingCount++] = openOutage;
  else logf("Pending buffer full - outage dropped");
  persistPending();
}

void openNewOutage(uint8_t type, int64_t startUp, time_t startEpoch) {
  memset(&openOutage, 0, sizeof(openOutage));
  snprintf(openOutage.uid, sizeof(openOutage.uid), "%08lx", (unsigned long)esp_random());
  openOutage.type = type;
  openOutage.startUp = startUp;
  openOutage.startEpoch = startEpoch;
  openOutage.gw = GW_UNKNOWN;
  outageOpen = true;
  checksSincePing = 1000;  // ping the router on the next check
  logf("OUTAGE STARTED (%s) at %s", obsName(type), fmtLocal(startEpoch).c_str());
}

// ---------------------------------------------------------------------
//  The check - runs every CHECK_INTERVAL_S
// ---------------------------------------------------------------------
void runCheck() {
  int64_t up = upSec();
  time_t ep = nowEpoch();

  Obs obs;
  if (WiFi.status() != WL_CONNECTED) obs = OBS_WIFI_DOWN;
  else {
    uint32_t lat = 0;
    obs = testInternet(lat) ? OBS_UP : OBS_INET_DOWN;
    if (obs == OBS_UP) lastLatencyMs = lat;
  }
  currentObs = obs;

  if (obs == OBS_UP) {
    if (failStreak > 0 && !outageOpen) logf("Blip: %d failed check(s) - too short to count", failStreak);
    failStreak = 0;
    if (outageOpen) {
      if (passStreak == 0) { firstPassUp = up; firstPassEpoch = ep; }
      passStreak++;
      if (passStreak >= PASSES_BEFORE_UP) closeOutage(firstPassUp, firstPassEpoch);
    }
    return;
  }

  // ----- a failed check -----
  passStreak = 0;
  if (outageOpen) {
    openOutage.fails++;
    if (obs != openOutage.type) {
      // e.g. internet was down, now Wi-Fi dropped too: split the record
      closeOutage(up, ep);
      openNewOutage(obs, up, ep);
    }
  } else {
    if (failStreak == 0 || obs != failType) {
      firstFailUp = up; firstFailEpoch = ep; failType = obs;
    }
    failStreak++;
    logf("Check failed (%s) %d/%d", obsName(obs), failStreak, FAILS_BEFORE_DOWN);
    if (failStreak >= FAILS_BEFORE_DOWN) {
      openNewOutage(obs, firstFailUp, firstFailEpoch);
      openOutage.fails = failStreak;
    }
  }

  // While the internet is down but Wi-Fi is up, check the router answers
  if (outageOpen && obs == OBS_INET_DOWN && ++checksSincePing >= 6) {
    checksSincePing = 0;
    uint8_t g = pingGateway();
    if (g == GW_OK) openOutage.gw = GW_OK;              // router answered at least once
    else if (g == GW_FAIL && openOutage.gw != GW_OK) openOutage.gw = GW_FAIL;
    logf("Router ping: %s", g == GW_OK ? "answered" : g == GW_FAIL ? "no reply" : "n/a");
  }
}

// ---------------------------------------------------------------------
//  WordPress
// ---------------------------------------------------------------------
int wpPost(const char *endpoint, const String &body, String &resp) {
  String url = String(WP_API_BASE) + endpoint;
  HTTPClient http;
  WiFiClientSecure secure;
  WiFiClient plain;
  bool began;
  if (url.startsWith("https://")) {
    secure.setInsecure();  // home monitor: skip certificate pinning
    began = http.begin(secure, url);
  } else {
    began = http.begin(plain, url);
  }
  if (!began) return -100;
  http.setConnectTimeout(8000);
  http.setTimeout(15000);
  http.setUserAgent("YouFibreMonitor/" FW_VERSION);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-NetMon-Token", WP_TOKEN);
  int code = http.POST(body);
  resp = code > 0 ? http.getString() : http.errorToString(code);
  http.end();
  feedWatchdog();
  return code;
}

bool uploadQueue() {
  if (!fsOk || queueCount == 0) return true;
  File f = LittleFS.open(QUEUE_FILE, "r");
  if (!f) { queueCount = 0; return true; }

  String events = "";
  int sent = 0;
  String rest = "";
  while (f.available()) {
    String line = f.readStringUntil('\n');
    line.trim();
    if (!line.length()) continue;
    if (sent >= UPLOAD_BATCH) { rest += line + "\n"; continue; }
    char uid[16]; unsigned type, gw, fails; long long s, e;
    if (sscanf(line.c_str(), "%15[^,],%u,%lld,%lld,%u,%u", uid, &type, &s, &e, &gw, &fails) != 6) continue;
    if (sent) events += ",";
    events += "{\"uid\":\"" + jsonEscape(uid) + "\",\"type\":\"" + obsName(type) +
              "\",\"start\":" + String(s) + ",\"end\":" + String(e) +
              ",\"gw\":" + String(gw) + ",\"fails\":" + String(fails) + "}";
    sent++;
  }
  f.close();

  if (sent == 0) { LittleFS.remove(QUEUE_FILE); queueCount = 0; return true; }

  String body = "{\"device_id\":\"" + jsonEscape(DEVICE_ID) + "\",\"token\":\"" + jsonEscape(WP_TOKEN) +
                "\",\"device_now\":" + String((long long)time(nullptr)) + ",\"events\":[" + events + "]}";
  String resp;
  int code = wpPost("/events", body, resp);
  lastUploadCode = code;
  if (code == 200 && resp.indexOf("\"ok\":true") >= 0) {
    if (rest.length()) {
      File w = LittleFS.open(QUEUE_FILE, "w");
      if (w) { w.print(rest); w.close(); }
    } else {
      LittleFS.remove(QUEUE_FILE);
    }
    queueCount = countQueueLines();
    logf("Uploaded %d outage(s) to WordPress (%d still queued)", sent, queueCount);
    return true;
  }
  logf("Outage upload failed: HTTP %d %s", code, resp.substring(0, 120).c_str());
  return false;
}

void sendHeartbeat() {
  String body = "{\"device_id\":\"" + jsonEscape(DEVICE_ID) + "\"";
  body += ",\"token\":\"" + jsonEscape(WP_TOKEN) + "\"";
  body += ",\"device_now\":" + String((long long)time(nullptr));
  body += ",\"uptime\":" + String((long long)upSec());
  body += ",\"rssi\":" + String(WiFi.RSSI());
  body += ",\"local_ip\":\"" + WiFi.localIP().toString() + "\"";
  body += ",\"latency_ms\":" + String(lastLatencyMs);
  body += ",\"fw\":\"" FW_VERSION "\"";
  body += ",\"boot\":" + String(bootReported ? "false" : "true");
  body += ",\"reset_reason\":\"" + String(resetReasonStr()) + "\"";
  body += ",\"queue\":" + String(queueCount + pendingCount);
  body += ",\"free_heap\":" + String(ESP.getFreeHeap());
  body += ",\"wifi_ok\":1,\"inet_ok\":1}";

  String resp;
  int code = wpPost("/heartbeat", body, resp);
  lastHbCode = code;
  if (code == 200 && resp.indexOf("\"ok\":true") >= 0) {
    bootReported = true;
    lastHbOkEpoch = time(nullptr);
    lastHbError = "";
    logf("Heartbeat OK (RSSI %d dBm, %u ms)", WiFi.RSSI(), lastLatencyMs);
  } else {
    lastHbError = resp.substring(0, 150);
    logf("Heartbeat failed: HTTP %d %s", code, lastHbError.c_str());
  }
}

// ---------------------------------------------------------------------
//  Local status page
// ---------------------------------------------------------------------
String stateText() {
  if (outageOpen) {
    time_t s = resolveEpoch(openOutage.startEpoch, openOutage.startUp);
    int64_t dur = upSec() - openOutage.startUp;
    if (openOutage.startEpoch > 0 && timeSynced()) dur = time(nullptr) - openOutage.startEpoch;
    String t = openOutage.type == OBS_WIFI_DOWN ? "NO WI-FI" : "INTERNET DOWN";
    return t + " since " + fmtLocal(s) + " (" + fmtDuration(dur) + ")";
  }
  if (failStreak > 0) return "Checking... (" + String(failStreak) + " failed check(s))";
  return "ONLINE";
}

void handleRoot() {
  String color = outageOpen ? "#c0392b" : (failStreak ? "#e67e22" : "#27ae60");
  String h;
  h.reserve(4096);
  h += F("<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
         "<meta http-equiv='refresh' content='10'><title>YouFibre Monitor</title><style>"
         "body{font-family:system-ui,sans-serif;margin:0;padding:16px;background:#f4f5f7;color:#222}"
         ".card{background:#fff;border-radius:10px;padding:16px;margin-bottom:14px;box-shadow:0 1px 3px #0002}"
         "h1{font-size:20px;margin:0 0 12px}.big{font-size:22px;font-weight:700;color:#fff;padding:14px;border-radius:8px}"
         "table{border-collapse:collapse;width:100%;font-size:14px}td,th{padding:6px;border-bottom:1px solid #eee;text-align:left}"
         "</style></head><body><h1>YouFibre Broadband Monitor</h1>");
  h += "<div class='card'><div class='big' style='background:" + color + "'>" + stateText() + "</div></div>";
  h += "<div class='card'><table>";
  h += "<tr><th>Time now</th><td>" + fmtLocal(nowEpoch()) + "</td></tr>";
  h += "<tr><th>Monitor uptime</th><td>" + fmtDuration(upSec()) + " (last restart: " + resetReasonStr() + ")</td></tr>";
  h += "<tr><th>Wi-Fi</th><td>" + String(WiFi.status() == WL_CONNECTED ? "Connected to " + WiFi.SSID() + ", " + String(WiFi.RSSI()) + " dBm" : String("Not connected")) + "</td></tr>";
  h += "<tr><th>IP / router</th><td>" + WiFi.localIP().toString() + " / " + WiFi.gatewayIP().toString() + "</td></tr>";
  h += "<tr><th>Last connect time</th><td>" + String(lastLatencyMs) + " ms</td></tr>";
  h += "<tr><th>WordPress heartbeat</th><td>" + (lastHbOkEpoch ? "OK at " + fmtLocal(lastHbOkEpoch) : String("not yet")) +
       (lastHbCode != 200 && lastHbCode != 0 ? " &mdash; last attempt HTTP " + String(lastHbCode) + " " + lastHbError : String("")) + "</td></tr>";
  h += "<tr><th>Outages waiting to upload</th><td>" + String(queueCount + pendingCount) + "</td></tr>";
  h += "<tr><th>Firmware</th><td>" FW_VERSION " &middot; device " DEVICE_ID "</td></tr>";
  h += "</table></div>";

  h += "<div class='card'><b>Outages since this monitor started</b><table><tr><th>Went down</th><th>Back up</th><th>Duration</th><th>Type</th></tr>";
  if (recentCount == 0) h += "<tr><td colspan='4'>None &#127881;</td></tr>";
  for (int i = recentCount - 1; i >= 0; i--) {
    const Outage &o = recent[i];
    time_t s = resolveEpoch(o.startEpoch, o.startUp), e = resolveEpoch(o.endEpoch, o.endUp);
    String type = o.type == OBS_WIFI_DOWN ? "No Wi-Fi" : (o.gw == GW_OK ? "Internet (router OK)" : o.gw == GW_FAIL ? "Internet (router not answering)" : "Internet");
    h += "<tr><td>" + fmtLocal(s) + "</td><td>" + fmtLocal(e) + "</td><td>" + fmtDuration(outageSeconds(o)) + "</td><td>" + type + "</td></tr>";
  }
  h += "</table></div><p style='font-size:12px;color:#888'>Full history is on your WordPress site. Page refreshes every 10 s.</p></body></html>";
  web.send(200, "text/html", h);
}

void handleStatusJson() {
  String j = "{\"state\":\"" + String(outageOpen ? obsName(openOutage.type) : "online") + "\"";
  j += ",\"uptime\":" + String((long long)upSec());
  j += ",\"rssi\":" + String(WiFi.RSSI());
  j += ",\"latency_ms\":" + String(lastLatencyMs);
  j += ",\"queue\":" + String(queueCount + pendingCount);
  j += ",\"last_heartbeat_http\":" + String(lastHbCode) + "}";
  web.send(200, "application/json", j);
}

// ---------------------------------------------------------------------
//  Wi-Fi housekeeping
// ---------------------------------------------------------------------
void stashAndReboot(const char *why) {
  logf("Rebooting: %s", why);
  if (outageOpen) {
    openOutage.startEpoch = resolveEpoch(openOutage.startEpoch, openOutage.startUp);
    if (openOutage.startEpoch > 0) {
      rtcStash.open = openOutage;
      rtcStash.magic = STASH_MAGIC;
    }
  }
  persistPending();
  delay(200);
  ESP.restart();
}

void manageWifi() {
  int64_t up = upSec();
  if (WiFi.status() == WL_CONNECTED) {
    if (wifiLostUp >= 0) logf("Wi-Fi connected: %s, RSSI %d dBm", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    wifiLostUp = -1;
    if (!mdnsStarted && MDNS.begin(MDNS_HOSTNAME)) {
      MDNS.addService("http", "tcp", 80);
      mdnsStarted = true;
    }
    return;
  }
  if (wifiLostUp < 0) { wifiLostUp = up; lastWifiRetryUp = up; logf("Wi-Fi not connected"); }
  if (up - lastWifiRetryUp >= 30) {
    lastWifiRetryUp = up;
    logf("Retrying Wi-Fi...");
    WiFi.disconnect();
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  }
  if (up - wifiLostUp >= (int64_t)WIFI_REBOOT_AFTER_MIN * 60) stashAndReboot("Wi-Fi could not reconnect");
}

// ---------------------------------------------------------------------
//  LED
// ---------------------------------------------------------------------
void updateLed() {
  if (STATUS_LED_PIN < 0) return;
  bool on;
  uint32_t m = millis();
  if (WiFi.status() != WL_CONNECTED) on = (m / 150) % 2;        // fast blink: no Wi-Fi
  else if (outageOpen) on = (m / 700) % 2;                        // slow blink: internet down
  else on = true;                                                 // solid: all good
  digitalWrite(STATUS_LED_PIN, on ? STATUS_LED_ON : !STATUS_LED_ON);
}

// ---------------------------------------------------------------------
//  Setup / loop
// ---------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.println();
  logf("YouFibre Monitor " FW_VERSION " starting (reset reason: %s)", resetReasonStr());

  if (STATUS_LED_PIN >= 0) pinMode(STATUS_LED_PIN, OUTPUT);
  setupWatchdog();

  fsOk = LittleFS.begin(true);
  if (!fsOk) logf("WARNING: LittleFS failed - outages can't be saved across reboots");
  queueCount = countQueueLines();
  if (queueCount) logf("%d outage(s) waiting in flash from before the restart", queueCount);

  // Restore an outage that was in progress when we rebooted on purpose
  esp_reset_reason_t rr = esp_reset_reason();
  if (rtcStash.magic == STASH_MAGIC && rr != ESP_RST_POWERON && rr != ESP_RST_BROWNOUT) {
    openOutage = rtcStash.open;
    openOutage.startUp = 0;
    outageOpen = true;
    logf("Resumed outage that started %lld", (long long)openOutage.startEpoch);
  }
  rtcStash.magic = 0;

  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setHostname(MDNS_HOSTNAME);
  WiFi.setAutoReconnect(true);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  configTzTime(LOCAL_TZ, NTP_SERVER_1, NTP_SERVER_2);

  web.on("/", handleRoot);
  web.on("/status.json", handleStatusJson);
  web.begin();

  // Give Wi-Fi a moment on first boot before the first check
  for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) { delay(250); feedWatchdog(); }
}

void loop() {
  feedWatchdog();
  web.handleClient();
  manageWifi();

  int64_t up = upSec();
  if (up - lastCheckUp >= CHECK_INTERVAL_S) {
    lastCheckUp = up;
    runCheck();
  }

  // When we're online: save, upload, heartbeat
  if (!outageOpen && currentObs == OBS_UP && timeSynced()) {
    persistPending();
    // Outages go before the heartbeat so WordPress has the details when it
    // sees we're back. After a failed upload, wait a minute before retrying.
    if (queueCount > 0 && (lastUploadOk || up - lastUploadUp >= 60)) {
      lastUploadUp = up;
      lastUploadOk = uploadQueue();
    }
    if (up - lastHeartbeatUp >= HEARTBEAT_INTERVAL_S) {
      lastHeartbeatUp = up;
      sendHeartbeat();
    }
  }

  // Low memory is the usual cause of long-term ESP32 flakiness - start fresh
  if (ESP.getFreeHeap() < 25000) stashAndReboot("low memory");

  updateLed();
  delay(20);
}
