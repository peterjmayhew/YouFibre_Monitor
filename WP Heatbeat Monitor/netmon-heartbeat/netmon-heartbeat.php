<?php
/**
 * Plugin Name:       NetMon Broadband Uptime Monitor
 * Plugin URI:        https://github.com/peterjmayhew/YouFibre_Monitor
 * Description:       Works with an ESP32 on your home Wi-Fi to monitor your broadband 24/7: records exactly when the internet went down and came back up, emails you, and produces an uptime report with CSV export.
 * Version:           2.4.0
 * Author:            Peter Mayhew
 * Author URI:        https://github.com/peterjmayhew
 * Update URI:        https://github.com/peterjmayhew/YouFibre_Monitor
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       netmon
 */

if (!defined('ABSPATH')) exit;

final class NetMon {
  const VERSION     = '2.4.0';
  const DB_VERSION  = '2';
  const OPT         = 'netmon_settings';
  const OPT_STATE   = 'netmon_state';
  const OPT_DBV     = 'netmon_db_version';
  const OPT_CRONRUN = 'netmon_cron_last_run';
  const OPT_REJECT  = 'netmon_last_rejected';
  const HB_TABLE    = 'netmon_heartbeats';
  const EV_TABLE    = 'netmon_events';
  const CRON_MINUTE = 'netmon_watchdog_event';
  const CRON_DAILY  = 'netmon_daily_event';
  const SLUG        = 'netmon';
  const GITHUB_REPO   = 'peterjmayhew/YouFibre_Monitor';
  const RELEASE_ASSET = 'netmon-heartbeat.zip';
  const T_RELEASE     = 'netmon_github_release';

  /* =================================================================
   *  Bootstrap
   * ================================================================= */

  public static function boot() {
    register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    // Must be registered at load time so the schedule exists during activation.
    add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
    add_action('plugins_loaded', [__CLASS__, 'init']);
  }

  public static function init() {
    if (get_option(self::OPT_DBV) !== self::DB_VERSION) self::install();

    add_action('rest_api_init', [__CLASS__, 'register_routes']);
    add_action(self::CRON_MINUTE, [__CLASS__, 'cron_watchdog']);
    add_action(self::CRON_DAILY, [__CLASS__, 'cron_daily']);
    add_action('init', [__CLASS__, 'ensure_cron']);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_netmon_export', [__CLASS__, 'handle_export']);
    add_action('admin_post_netmon_ignore', [__CLASS__, 'handle_ignore']);
    add_action('wp_dashboard_setup', [__CLASS__, 'dashboard_widget']);
    add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 100);
    add_action('wp_ajax_netmon_live', [__CLASS__, 'ajax_live']);
    add_shortcode('netmon_report', [__CLASS__, 'shortcode']);
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'action_links']);
    add_filter('update_plugins_github.com', [__CLASS__, 'github_update_check'], 10, 4);
    add_filter('plugins_api', [__CLASS__, 'plugins_api'], 20, 3);
    add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
    add_action('upgrader_process_complete', [__CLASS__, 'after_upgrade'], 10, 2);
    add_filter('admin_footer_text', [__CLASS__, 'footer_text']);
  }

  public static function cron_schedules($s) {
    if (!isset($s['netmon_every_minute'])) {
      $s['netmon_every_minute'] = ['interval' => 60, 'display' => 'Every minute (NetMon)'];
    }
    return $s;
  }

  public static function activate() {
    self::install();
    self::ensure_cron();
  }

  public static function deactivate() {
    wp_clear_scheduled_hook(self::CRON_MINUTE);
    wp_clear_scheduled_hook(self::CRON_DAILY);
  }

  public static function ensure_cron() {
    if (!wp_next_scheduled(self::CRON_MINUTE)) wp_schedule_event(time() + 30, 'netmon_every_minute', self::CRON_MINUTE);
    if (!wp_next_scheduled(self::CRON_DAILY)) wp_schedule_event(time() + 3600, 'daily', self::CRON_DAILY);
  }

  public static function action_links($links) {
    array_unshift($links, '<a href="' . esc_url(self::admin_url()) . '">Report</a>');
    return $links;
  }

  /* =================================================================
   *  Database
   * ================================================================= */

  private static function hb_table() { global $wpdb; return $wpdb->prefix . self::HB_TABLE; }
  private static function ev_table() { global $wpdb; return $wpdb->prefix . self::EV_TABLE; }

  public static function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $hb = self::hb_table();
    $ev = self::ev_table();
    $charset = $wpdb->get_charset_collate();

    // v1 of this plugin stored heartbeat times in local time; v2 stores UTC.
    $legacy = (get_option(self::OPT_DBV, '') === '') && ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hb)) === $hb);

    dbDelta("CREATE TABLE $hb (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  device_id varchar(64) NOT NULL,
  ip varchar(64) NULL,
  local_ip varchar(64) NULL,
  rssi int(11) NULL,
  latency_ms int(11) NULL,
  uptime_s bigint(20) NULL,
  fw varchar(32) NULL,
  boot tinyint(1) NOT NULL DEFAULT 0,
  reset_reason varchar(32) NULL,
  queue_len int(11) NULL,
  wifi_ok tinyint(1) NOT NULL DEFAULT 1,
  inet_ok tinyint(1) NOT NULL DEFAULT 1,
  note varchar(255) NULL,
  PRIMARY KEY  (id),
  KEY device_id (device_id),
  KEY created_at (created_at)
) $charset;");

    dbDelta("CREATE TABLE $ev (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  device_id varchar(64) NOT NULL,
  uid varchar(40) NOT NULL,
  source varchar(10) NOT NULL,
  event_type varchar(20) NOT NULL,
  started_at datetime NOT NULL,
  ended_at datetime NULL,
  gateway_ok tinyint(1) NULL,
  failed_checks int(11) NULL,
  details varchar(255) NULL,
  alert_sent tinyint(1) NOT NULL DEFAULT 0,
  ignored tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY device_uid (device_id,uid),
  KEY started_at (started_at),
  KEY ended_at (ended_at)
) $charset;");

    if ($legacy) {
      $offset = wp_timezone()->getOffset(new DateTime('now', new DateTimeZone('UTC')));
      if ($offset) {
        $wpdb->query($wpdb->prepare("UPDATE $hb SET created_at = DATE_SUB(created_at, INTERVAL %d SECOND)", $offset));
      }
      // Remember when each existing device was first seen
      $state = self::state();
      $rows = $wpdb->get_results("SELECT device_id, MIN(created_at) AS f FROM $hb GROUP BY device_id");
      foreach ((array) $rows as $r) {
        if (!isset($state[$r->device_id])) $state[$r->device_id] = ['first_seen' => self::ts($r->f)];
      }
      update_option(self::OPT_STATE, $state, false);
    }

    $s = get_option(self::OPT);
    if (!is_array($s)) add_option(self::OPT, self::settings(), '', 'no');

    update_option(self::OPT_DBV, self::DB_VERSION);
  }

  /* =================================================================
   *  Settings / state helpers
   * ================================================================= */

  public static function settings() {
    $defaults = [
      'token'                     => '',
      'timeout_minutes'           => 5,
      'alert_email'               => get_option('admin_email'),
      'alerts_enabled'            => 1,
      'email_device_outages'      => 1,
      'device_outage_min_minutes' => 2,
      'retention_days'            => 90,
      'public_report'             => 0,
    ];
    $s = get_option(self::OPT, []);
    if (!is_array($s)) $s = [];
    $s = array_merge($defaults, $s);
    if (empty($s['token'])) {
      $s['token'] = wp_generate_password(32, false, false);
      update_option(self::OPT, $s, false);
    }
    return $s;
  }

  private static function save_settings($s) { update_option(self::OPT, $s, false); }

  private static function state() {
    $st = get_option(self::OPT_STATE, []);
    return is_array($st) ? $st : [];
  }

  private static function timeout_s() {
    $s = self::settings();
    return max(2, (int) $s['timeout_minutes']) * 60;
  }

  /** MySQL UTC datetime -> unix timestamp */
  private static function ts($dt) {
    if (!$dt) return 0;
    $t = strtotime($dt . ' UTC');
    return $t ? $t : 0;
  }

  /** unix timestamp -> MySQL UTC datetime */
  private static function dt($ts) { return gmdate('Y-m-d H:i:s', (int) $ts); }

  public static function fmt_time($ts, $fmt = 'D j M Y, H:i:s') { return $ts ? wp_date($fmt, (int) $ts) : '—'; }

  public static function fmt_duration($s) {
    $s = max(0, (int) round($s));
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    if ($d) return sprintf('%dd %dh %dm', $d, $h, $m);
    if ($h) return sprintf('%dh %dm', $h, $m);
    if ($m) return sprintf('%dm %ds', $m, $sec);
    return sprintf('%ds', $sec);
  }

  private static function clean_device($v) {
    $v = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $v);
    $v = substr($v, 0, 64);
    return $v !== '' ? $v : 'unknown';
  }

  private static function admin_url($args = []) {
    return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
  }

  private static function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
      if (!empty($_SERVER[$k])) {
        $ip = trim(explode(',', wp_unslash($_SERVER[$k]))[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
      }
    }
    return '';
  }

  /* =================================================================
   *  REST API (called by the ESP32)
   * ================================================================= */

  public static function register_routes() {
    register_rest_route('netmon/v1', '/heartbeat', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'rest_heartbeat'],
      'permission_callback' => [__CLASS__, 'rest_auth'],
    ]);
    register_rest_route('netmon/v1', '/events', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'rest_events'],
      'permission_callback' => [__CLASS__, 'rest_auth'],
    ]);
    register_rest_route('netmon/v1', '/ping', [
      'methods'             => 'GET',
      'callback'            => function () { return ['ok' => true, 'plugin' => self::VERSION, 'server_time' => time()]; },
      'permission_callback' => '__return_true',
    ]);
  }

  public static function rest_auth(WP_REST_Request $req) {
    $s = self::settings();
    $tok = (string) $req->get_header('x-netmon-token');
    if ($tok === '') {
      // Fallback for hosts that strip custom headers
      $p = $req->get_json_params();
      if (is_array($p) && isset($p['token'])) $tok = (string) $p['token'];
    }
    if ($tok === '' || !hash_equals((string) $s['token'], $tok)) {
      // Remember the attempt so the admin screens can explain why nothing is arriving
      $p = $req->get_json_params();
      update_option(self::OPT_REJECT, [
        'time'   => time(),
        'ip'     => self::client_ip(),
        'device' => is_array($p) && isset($p['device_id']) ? self::clean_device($p['device_id']) : '',
        'why'    => $tok === '' ? 'no token was sent' : 'the token does not match (WP_TOKEN in secrets.h is wrong)',
      ], false);
      return new WP_Error('netmon_unauthorized', 'Invalid or missing token', ['status' => 401]);
    }
    return true;
  }

  public static function rest_heartbeat(WP_REST_Request $req) {
    global $wpdb;
    $now = time();
    $b = $req->get_json_params();
    if (!is_array($b)) $b = [];

    $device = self::clean_device(isset($b['device_id']) ? $b['device_id'] : '');
    $boot = !empty($b['boot']) && $b['boot'] !== 'false';
    $reset_reason = sanitize_text_field(isset($b['reset_reason']) ? $b['reset_reason'] : '');
    $ip = self::client_ip();

    $state = self::state();
    $prev = isset($state[$device]) && is_array($state[$device]) ? $state[$device] : [];
    $prev_hb = isset($prev['last_hb']) ? (int) $prev['last_hb'] : 0;

    $notes = [];
    if (!empty($b['note'])) $notes[] = sanitize_text_field($b['note']);
    if ($boot) $notes[] = 'ESP32 restarted (' . ($reset_reason ?: 'unknown') . ')';
    if (!empty($prev['ip']) && $ip && $prev['ip'] !== $ip) $notes[] = 'Public IP changed from ' . $prev['ip'];

    $wpdb->insert(self::hb_table(), [
      'created_at'   => self::dt($now),
      'device_id'    => $device,
      'ip'           => $ip,
      'local_ip'     => sanitize_text_field(isset($b['local_ip']) ? $b['local_ip'] : ''),
      'rssi'         => isset($b['rssi']) ? (int) $b['rssi'] : null,
      'latency_ms'   => isset($b['latency_ms']) ? (int) $b['latency_ms'] : null,
      'uptime_s'     => isset($b['uptime']) ? (int) $b['uptime'] : null,
      'fw'           => substr(sanitize_text_field(isset($b['fw']) ? $b['fw'] : ''), 0, 32),
      'boot'         => $boot ? 1 : 0,
      'reset_reason' => substr($reset_reason, 0, 32),
      'queue_len'    => isset($b['queue']) ? (int) $b['queue'] : null,
      'wifi_ok'      => !isset($b['wifi_ok']) || !empty($b['wifi_ok']) ? 1 : 0,
      'inet_ok'      => !isset($b['inet_ok']) || !empty($b['inet_ok']) ? 1 : 0,
      'note'         => substr(implode('; ', $notes), 0, 255),
    ]);

    $state[$device] = array_merge($prev, [
      'last_hb'    => $now,
      'first_seen' => !empty($prev['first_seen']) ? (int) $prev['first_seen'] : $now,
      'rssi'       => isset($b['rssi']) ? (int) $b['rssi'] : null,
      'latency_ms' => isset($b['latency_ms']) ? (int) $b['latency_ms'] : null,
      'uptime'     => isset($b['uptime']) ? (int) $b['uptime'] : null,
      'fw'         => sanitize_text_field(isset($b['fw']) ? $b['fw'] : ''),
      'ip'         => $ip,
      'local_ip'   => sanitize_text_field(isset($b['local_ip']) ? $b['local_ip'] : ''),
      'queue'      => isset($b['queue']) ? (int) $b['queue'] : 0,
      'skew'       => !empty($b['device_now']) ? $now - (int) $b['device_now'] : null,
    ]);
    if ($boot) {
      $state[$device]['last_boot'] = $now;
      $state[$device]['last_reset_reason'] = $reset_reason;
    }
    update_option(self::OPT_STATE, $state, false);

    self::heartbeat_closes_silence($device, $prev_hb, $now, $boot, $reset_reason);

    return new WP_REST_Response(['ok' => true, 'server_time' => $now], 200);
  }

  public static function rest_events(WP_REST_Request $req) {
    global $wpdb;
    $now = time();
    $b = $req->get_json_params();
    if (!is_array($b) || !isset($b['events']) || !is_array($b['events'])) {
      return new WP_REST_Response(['ok' => false, 'error' => 'no events'], 400);
    }
    $device = self::clean_device(isset($b['device_id']) ? $b['device_id'] : '');

    // If the ESP32's clock is clearly wrong, shift its times onto ours
    $skew = !empty($b['device_now']) ? $now - (int) $b['device_now'] : 0;
    if (abs($skew) < 120) $skew = 0;

    $ev = self::ev_table();
    $stored = 0; $dupes = 0; $bad = 0; $new = [];
    foreach (array_slice($b['events'], 0, 100) as $e) {
      if (!is_array($e)) { $bad++; continue; }
      $uid   = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', isset($e['uid']) ? (string) $e['uid'] : ''), 0, 40);
      $type  = isset($e['type']) ? (string) $e['type'] : '';
      $start = (int) (isset($e['start']) ? $e['start'] : 0) + $skew;
      $end   = (int) (isset($e['end']) ? $e['end'] : 0) + $skew;
      if ($uid === '' || !in_array($type, ['internet', 'wifi'], true) || $start < 1500000000
          || $end < $start || $end > $now + 300 || $start < $now - 400 * 86400) { $bad++; continue; }
      $end = min($end, $now);
      $gw = isset($e['gw']) ? (int) $e['gw'] : 0;
      $gateway_ok = $gw === 1 ? 1 : ($gw === 2 ? 0 : null);

      $sql = "INSERT IGNORE INTO $ev (device_id, uid, source, event_type, started_at, ended_at, gateway_ok, failed_checks, details, alert_sent, ignored, created_at)
              VALUES (%s, %s, 'device', %s, %s, %s, " . ($gateway_ok === null ? 'NULL' : (int) $gateway_ok) . ", %d, %s, 0, 0, %s)";
      $wpdb->query($wpdb->prepare($sql, $device, 'dev-' . $uid, $type, self::dt($start), self::dt($end),
        isset($e['fails']) ? (int) $e['fails'] : 0, '', self::dt($now)));
      if ($wpdb->rows_affected === 1) { $stored++; $new[] = (int) $wpdb->insert_id; } else { $dupes++; }
    }

    if ($new) self::email_device_outages($device, $new);

    return new WP_REST_Response(['ok' => true, 'stored' => $stored, 'duplicates' => $dupes, 'rejected' => $bad], 200);
  }

  /* =================================================================
   *  Silence detection (ESP32 stopped talking to us)
   * ================================================================= */

  private static function open_silence($device) {
    global $wpdb;
    $ev = self::ev_table();
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $ev WHERE device_id=%s AND source='server' AND ended_at IS NULL ORDER BY id DESC LIMIT 1", $device));
  }

  /** Runs every minute via WP-Cron (and whenever the report is viewed). */
  public static function check_devices() {
    global $wpdb;
    $s = self::settings();
    $timeout = self::timeout_s();
    $now = time();
    $ev = self::ev_table();

    foreach (self::state() as $device => $st) {
      if (empty($st['last_hb'])) continue;
      $last = (int) $st['last_hb'];
      if ($now - $last <= $timeout) continue;

      $open = self::open_silence($device);
      if (!$open) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $ev (device_id, uid, source, event_type, started_at, ended_at, details, alert_sent, ignored, created_at)
           VALUES (%s, %s, 'server', 'silent', %s, NULL, '', 0, 0, %s)",
          $device, 'srv-' . $last, self::dt($last), self::dt($now)));
        $open = self::open_silence($device);
      }
      if ($open && !(int) $open->alert_sent && !empty($s['alerts_enabled'])) {
        $sent = self::send_mail(
          sprintf('[Broadband] DOWN? No heartbeat from %s for %s', $device, self::fmt_duration($now - $last)),
          "Your broadband monitor ($device) has stopped checking in.\n\n"
          . 'Last heard from: ' . self::fmt_time($last) . "\n"
          . 'Silent for:      ' . self::fmt_duration($now - $last) . "\n\n"
          . "This usually means your YouFibre broadband is down. It can also be a power cut,\n"
          . "the router being switched off, or the ESP32 losing power/Wi-Fi.\n\n"
          . "You'll get another email when it's back, with the exact down/up times.\n\n"
          . 'Report: ' . self::admin_url() . "\n"
        );
        if ($sent) $wpdb->update($ev, ['alert_sent' => 1], ['id' => $open->id]);
      }
    }
  }

  private static function heartbeat_closes_silence($device, $prev_hb, $now, $boot, $reset_reason) {
    global $wpdb;
    $ev = self::ev_table();
    $boot_note = '';
    if ($boot) {
      $boot_note = 'ESP32 restarted (' . ($reset_reason ?: 'unknown') . ')';
      if (in_array($reset_reason, ['power-on', 'brownout'], true)) $boot_note .= ' - probably a power cut';
    }

    $open = self::open_silence($device);
    if ($open) {
      $details = trim(implode('; ', array_filter([$open->details, $boot_note])));
      $wpdb->update($ev, ['ended_at' => self::dt($now), 'details' => substr($details, 0, 255)], ['id' => $open->id]);
      self::email_recovery($device, self::ts($open->started_at), $now, $details, (bool) (int) $open->alert_sent);
      return;
    }

    // Cron didn't run while we were silent (WP-Cron only runs when the site gets visits)
    if ($prev_hb && ($now - $prev_hb) > self::timeout_s()) {
      $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $ev (device_id, uid, source, event_type, started_at, ended_at, details, alert_sent, ignored, created_at)
         VALUES (%s, %s, 'server', 'silent', %s, %s, %s, 0, 0, %s)",
        $device, 'srv-' . $prev_hb, self::dt($prev_hb), self::dt($now), substr($boot_note, 0, 255), self::dt($now)));
      self::email_recovery($device, $prev_hb, $now, $boot_note, false);
    }
  }

  /* =================================================================
   *  Emails
   * ================================================================= */

  private static function send_mail($subject, $body) {
    $s = self::settings();
    $to = sanitize_email($s['alert_email']);
    if (!$to || empty($s['alerts_enabled'])) return false;
    return wp_mail($to, $subject, $body);
  }

  private static function device_event_lines($rows) {
    $out = [];
    foreach ($rows as $r) {
      $s = self::ts($r->started_at); $e = self::ts($r->ended_at);
      $out[] = sprintf("  %s  ->  %s   (%s)  %s",
        self::fmt_time($s), self::fmt_time($e, 'H:i:s'), self::fmt_duration($e - $s), self::cause_text($r->event_type, $r->gateway_ok));
    }
    return implode("\n", $out);
  }

  private static function email_recovery($device, $start, $end, $details, $alerted) {
    global $wpdb;
    $s = self::settings();
    if (empty($s['alerts_enabled'])) return;
    $ev = self::ev_table();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $ev WHERE device_id=%s AND source='device' AND ignored=0 AND started_at < %s AND ended_at > %s ORDER BY started_at",
      $device, self::dt($end + 120), self::dt($start - 120)));

    if (!$alerted && $rows) {
      $unsent = array_filter($rows, function ($r) { return !(int) $r->alert_sent; });
      if (!$unsent) return; // already told the user about this one
    }

    $body = "Your broadband monitor ($device) is back in touch.\n\n"
      . 'Last heartbeat before the gap: ' . self::fmt_time($start) . "\n"
      . 'First heartbeat after:         ' . self::fmt_time($end) . "\n"
      . 'Gap:                           ' . self::fmt_duration($end - $start) . "\n";
    if ($details) $body .= "\nNote: $details\n";
    if ($rows) {
      $body .= "\nThe ESP32 recorded these exact times:\n" . self::device_event_lines($rows) . "\n";
    } else {
      $body .= "\nThe ESP32 didn't record an internet outage during the gap, so it was probably\n"
             . "a power cut, the router/ESP32 being off, or the website being unreachable.\n";
    }
    $body .= "\nReport: " . self::admin_url() . "\n";

    $down = $rows ? (self::ts(end($rows)->ended_at) - self::ts($rows[0]->started_at)) : ($end - $start);
    if (self::send_mail(sprintf('[Broadband] Back online - was down for %s', self::fmt_duration($down)), $body) && $rows) {
      $ids = implode(',', array_map('intval', wp_list_pluck($rows, 'id')));
      $wpdb->query("UPDATE $ev SET alert_sent=1 WHERE id IN ($ids)");
    }
  }

  private static function email_device_outages($device, $ids) {
    global $wpdb;
    $s = self::settings();
    if (empty($s['alerts_enabled']) || empty($s['email_device_outages'])) return;
    $ev = self::ev_table();

    // A "DOWN" email is out and the recovery email will include these details
    $alerted = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $ev WHERE device_id=%s AND source='server' AND ended_at IS NULL AND alert_sent=1", $device));
    if ($alerted) return;

    $min = max(0, (int) $s['device_outage_min_minutes']) * 60;
    $id_list = implode(',', array_map('intval', $ids));
    $rows = $wpdb->get_results("SELECT * FROM $ev WHERE id IN ($id_list) ORDER BY started_at");
    $rows = array_values(array_filter($rows, function ($r) use ($min) {
      return (self::ts($r->ended_at) - self::ts($r->started_at)) >= $min;
    }));
    if (!$rows) return;

    $total = 0;
    foreach ($rows as $r) $total += self::ts($r->ended_at) - self::ts($r->started_at);
    $body = "Your broadband monitor ($device) has reported " . count($rows) . " outage(s) now that it's back online:\n\n"
      . self::device_event_lines($rows) . "\n\nTotal: " . self::fmt_duration($total) . "\n\nReport: " . self::admin_url() . "\n";
    if (self::send_mail(sprintf('[Broadband] Outage: internet was down for %s', self::fmt_duration($total)), $body)) {
      $ids = implode(',', array_map('intval', wp_list_pluck($rows, 'id')));
      $wpdb->query("UPDATE $ev SET alert_sent=1 WHERE id IN ($ids)");
    }
  }

  /* =================================================================
   *  Cron
   * ================================================================= */

  public static function cron_watchdog() {
    update_option(self::OPT_CRONRUN, time(), false);
    self::check_devices();
  }

  public static function cron_daily() {
    global $wpdb;
    $s = self::settings();
    $days = max(7, (int) $s['retention_days']);
    $hb = self::hb_table();
    $wpdb->query($wpdb->prepare("DELETE FROM $hb WHERE created_at < %s", self::dt(time() - $days * 86400)));
  }

  /* =================================================================
   *  History tools: rebuild outages from heartbeats, clear history
   * ================================================================= */

  /**
   * Find gaps longer than $min_gap seconds between saved heartbeats.
   * With $write = true each gap is stored as a "silent" outage. The uid
   * matches live detection, so gaps already recorded are skipped.
   */
  public static function rebuild_history($device, $min_gap, $write) {
    global $wpdb;
    $hb = self::hb_table();
    $ev = self::ev_table();
    $res = ['device' => $device, 'heartbeats' => 0, 'gaps' => [], 'added' => 0, 'already' => 0, 'median' => 0, 'first' => 0, 'last' => 0];
    $last_id = 0; $prev = 0; $intervals = [];
    do {
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, created_at, boot, reset_reason FROM $hb WHERE device_id=%s AND id > %d ORDER BY id LIMIT 5000", $device, $last_id));
      foreach ($rows as $r) {
        $last_id = (int) $r->id;
        $t = self::ts($r->created_at);
        $res['heartbeats']++;
        if (!$res['first']) $res['first'] = $t;
        if ($prev) {
          $gap = $t - $prev;
          if ($gap > 0) $intervals[] = $gap;
          if ($gap > $min_gap) {
            $note = 'Rebuilt from heartbeat history';
            if (!empty($r->boot)) $note .= '; ESP32 restarted (' . ($r->reset_reason ?: 'unknown') . ')';
            $res['gaps'][] = [$prev, $t, $note];
          }
        }
        $prev = max($prev, $t);
      }
    } while (count($rows) === 5000);
    $res['last'] = $prev;

    if ($intervals) {
      sort($intervals);
      $res['median'] = $intervals[intdiv(count($intervals), 2)];
    }

    if ($write) {
      foreach ($res['gaps'] as $g) {
        // alert_sent=1: history only, never email about it
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $ev (device_id, uid, source, event_type, started_at, ended_at, details, alert_sent, ignored, created_at)
           VALUES (%s, %s, 'server', 'silent', %s, %s, %s, 1, 0, %s)",
          $device, 'srv-' . $g[0], self::dt($g[0]), self::dt($g[1]), $g[2], self::dt(time())));
        if ($wpdb->rows_affected === 1) $res['added']++; else $res['already']++;
      }
      // Make sure the report's monitoring period starts at the first heartbeat
      if ($res['first']) {
        $state = self::state();
        if (!isset($state[$device])) $state[$device] = [];
        $fs = !empty($state[$device]['first_seen']) ? (int) $state[$device]['first_seen'] : 0;
        if (!$fs || $res['first'] < $fs) $state[$device]['first_seen'] = $res['first'];
        update_option(self::OPT_STATE, $state, false);
      }
    }
    return $res;
  }

  /**
   * Permanently delete history. $what: events | heartbeats | all.
   * $device '' = every device. $before 0 = everything, else only data before that time.
   */
  public static function clear_history($what, $device, $before) {
    global $wpdb;
    $out = ['events' => 0, 'heartbeats' => 0];
    $targets = [];
    if ($what === 'events' || $what === 'all') $targets['events'] = [self::ev_table(), 'started_at'];
    if ($what === 'heartbeats' || $what === 'all') $targets['heartbeats'] = [self::hb_table(), 'created_at'];

    foreach ($targets as $key => $t) {
      list($table, $col) = $t;
      $where = []; $args = [];
      if ($device !== '') { $where[] = 'device_id=%s'; $args[] = $device; }
      if ($before) { $where[] = "$col < %s"; $args[] = self::dt($before); }
      $sql = "DELETE FROM $table" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
      $n = $args ? $wpdb->query($wpdb->prepare($sql, ...$args)) : $wpdb->query($sql);
      $out[$key] = (int) $n;
    }

    // "Everything" also restarts the uptime figures from the cut-off (or from now)
    if ($what === 'all') {
      $state = self::state();
      $start = $before ? $before : time();
      foreach ($state as $d => $st) {
        if ($device !== '' && $d !== $device) continue;
        $fs = !empty($st['first_seen']) ? (int) $st['first_seen'] : 0;
        $state[$d]['first_seen'] = $before ? max($fs, $start) : $start;
      }
      update_option(self::OPT_STATE, $state, false);
    }
    return $out;
  }

  private static function history_devices_from_post() {
    $d = isset($_POST['hist_device']) ? wp_unslash($_POST['hist_device']) : '__all';
    return $d === '__all' ? self::devices() : [self::clean_device($d)];
  }

  private static function process_history($action) {
    $s = self::settings();
    if ($action === 'rebuild_preview' || $action === 'rebuild') {
      $min = max(2, min(1440, (int) (isset($_POST['hist_min_gap']) ? $_POST['hist_min_gap'] : $s['timeout_minutes']))) * 60;
      $write = $action === 'rebuild';
      $h = '';
      foreach (self::history_devices_from_post() as $device) {
        $r = self::rebuild_history($device, $min, $write);
        $total = 0; $big = $r['gaps'];
        foreach ($big as $g) $total += $g[1] - $g[0];
        usort($big, function ($a, $b) { return ($b[1] - $b[0]) - ($a[1] - $a[0]); });
        $h .= '<p><strong>' . esc_html($device) . ':</strong> scanned ' . number_format($r['heartbeats']) . ' heartbeats'
            . ($r['first'] ? ' from ' . esc_html(self::fmt_time($r['first'], 'j M Y H:i')) . ' to ' . esc_html(self::fmt_time($r['last'], 'j M Y H:i')) : '')
            . '. Normal gap between heartbeats: <strong>' . esc_html(self::fmt_duration($r['median'])) . '</strong>. '
            . 'Found <strong>' . count($r['gaps']) . '</strong> gap(s) longer than ' . esc_html(self::fmt_duration($min)) . ', totalling <strong>' . esc_html(self::fmt_duration($total)) . '</strong>.';
        if ($write) $h .= ' Added <strong>' . (int) $r['added'] . '</strong> outage(s); ' . (int) $r['already'] . ' were already recorded.';
        $h .= '</p>';
        if ($r['median'] && $r['median'] * 3 > $min) {
          $h .= '<p><strong>Warning:</strong> heartbeats were normally ' . esc_html(self::fmt_duration($r['median'])) . ' apart, so a ' . esc_html(self::fmt_duration($min))
              . ' threshold will flag normal gaps as outages. Use at least ' . (int) ceil($r['median'] * 3 / 60) . ' minutes.</p>';
        }
        if ($big) {
          $h .= '<table class="netmon-table" style="max-width:700px;margin-bottom:10px"><thead><tr><th>Last heartbeat before gap</th><th>First heartbeat after</th><th>Gap</th></tr></thead><tbody>';
          foreach (array_slice($big, 0, 10) as $g) {
            $h .= '<tr><td>' . esc_html(self::fmt_time($g[0])) . '</td><td>' . esc_html(self::fmt_time($g[1])) . '</td><td class="netmon-dur">' . esc_html(self::fmt_duration($g[1] - $g[0])) . '</td></tr>';
          }
          $h .= '</tbody></table>' . (count($big) > 10 ? '<p class="netmon-muted">Showing the 10 longest.</p>' : '');
        }
      }
      if (!$h) $h = '<p>No devices to scan.</p>';
      $title = $write ? 'Outage history rebuilt.' : 'Preview only - nothing has been changed. Click "Rebuild history" to save these.';
      return '<div class="notice ' . ($write ? 'notice-success' : 'notice-info') . '"><p><strong>' . $title . '</strong></p>' . $h
           . ($write ? '<p><a href="' . esc_url(self::admin_url()) . '">View the report</a></p>' : '') . '</div>';
    }

    if ($action === 'clear') {
      if (empty($_POST['hist_confirm'])) {
        return '<div class="notice notice-error"><p>Nothing deleted - tick "I understand this permanently deletes data" first.</p></div>';
      }
      $what = isset($_POST['hist_what']) ? sanitize_key($_POST['hist_what']) : '';
      if (!in_array($what, ['events', 'heartbeats', 'all'], true)) return '';
      $dev = isset($_POST['hist_device']) ? wp_unslash($_POST['hist_device']) : '__all';
      $device = $dev === '__all' ? '' : self::clean_device($dev);
      $before = 0;
      if (!empty($_POST['hist_before'])) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', sanitize_text_field(wp_unslash($_POST['hist_before'])), wp_timezone());
        if ($d) $before = $d->getTimestamp();
      }
      $r = self::clear_history($what, $device, $before);
      return '<div class="notice notice-success"><p>Deleted ' . number_format($r['events']) . ' outage record(s) and ' . number_format($r['heartbeats']) . ' heartbeat(s)'
           . ($device ? ' for ' . esc_html($device) : ' for all devices') . ($before ? ' before ' . esc_html(wp_date('j M Y', $before)) : '') . '.'
           . ($what === 'all' ? ' Uptime figures now start from ' . esc_html(self::fmt_time($before ? $before : time(), 'j M Y H:i')) . '.' : '') . '</p></div>';
    }
    return '';
  }

  private static function render_history_tools() {
    $s = self::settings();
    $devices = self::devices();
    $dev_select = function () use ($devices) {
      if (count($devices) <= 1) return '<input type="hidden" name="hist_device" value="__all">';
      $o = '<p><label>Device <select name="hist_device"><option value="__all">All devices</option>';
      foreach ($devices as $d) $o .= '<option value="' . esc_attr($d) . '">' . esc_html($d) . '</option>';
      return $o . '</select></label></p>';
    };
    ob_start();
    ?>
    <h2 id="history">History tools</h2>

    <div class="netmon-card" style="max-width:820px;margin-bottom:14px">
      <h3 style="margin-top:0">Rebuild outage history from heartbeats</h3>
      <p>Scans every saved heartbeat and records each gap longer than the time below as a <em>Monitor offline</em> outage.
         Use this for history recorded before this version (for example by the old draft plugin), which only saved heartbeats.
         No emails are sent, and it's safe to run more than once because gaps that are already recorded are skipped.</p>
      <form method="post">
        <?php wp_nonce_field('netmon_settings'); echo $dev_select(); ?>
        <p><label>Count gaps longer than <input type="number" name="hist_min_gap" min="2" max="1440" value="<?php echo (int) $s['timeout_minutes']; ?>" class="small-text"> minutes as outages</label></p>
        <p><button class="button" name="netmon_action" value="rebuild_preview">Preview</button>
           <button class="button button-primary" name="netmon_action" value="rebuild">Rebuild history</button></p>
      </form>
    </div>

    <div class="netmon-card" style="max-width:820px;border-color:#d63638">
      <h3 style="margin-top:0;color:#b32d2e">Clear history</h3>
      <form method="post" onsubmit="return confirm('Permanently delete this history? This cannot be undone. Tip: download a CSV from the Export tab first.');">
        <?php wp_nonce_field('netmon_settings'); ?>
        <p><label><input type="radio" name="hist_what" value="events" checked> <strong>Outage records only</strong> &mdash; removes outages from the report; heartbeats are kept (you can rebuild from them)</label><br>
           <label><input type="radio" name="hist_what" value="heartbeats"> <strong>Heartbeats only</strong> &mdash; removes the raw heartbeat log; outages are kept</label><br>
           <label><input type="radio" name="hist_what" value="all"> <strong>Everything</strong> &mdash; outages and heartbeats, and the uptime figures restart from the cut-off date (or from now)</label></p>
        <?php echo $dev_select(); ?>
        <p><label>Only delete data before <input type="date" name="hist_before"></label> <span class="netmon-muted">(leave empty to delete all of it)</span></p>
        <p><label><input type="checkbox" name="hist_confirm" value="1"> I understand this permanently deletes data</label></p>
        <p><button class="button button-link-delete" style="border:1px solid #d63638;padding:0 12px;border-radius:3px" name="netmon_action" value="clear">Clear history</button>
           &nbsp;<a href="<?php echo esc_url(self::admin_url(['tab' => 'export'])); ?>">Export a CSV backup first</a></p>
      </form>
    </div>
    <?php
    return ob_get_clean();
  }

  /* =================================================================
   *  Updates from GitHub
   *  Releases are published at github.com/peterjmayhew/YouFibre_Monitor
   *  with netmon-heartbeat.zip attached. WordPress's own updater does the
   *  install; we just tell it where the new version is.
   * ================================================================= */

  /** Latest release info from GitHub (cached for 6 hours). */
  public static function github_release($force = false) {
    if (!$force) {
      $c = get_site_transient(self::T_RELEASE);
      if (is_array($c)) return $c;
    }
    $rel = [
      'checked' => time(), 'version' => '', 'package' => '', 'notes' => '', 'published' => 0, 'error' => '',
      'url' => 'https://github.com/' . self::GITHUB_REPO . '/releases',
    ];
    $resp = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', [
      'timeout' => 15,
      'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'NetMon-WordPress/' . self::VERSION],
    ]);
    if (is_wp_error($resp)) {
      $rel['error'] = 'Could not reach GitHub: ' . $resp->get_error_message();
    } else {
      $code = (int) wp_remote_retrieve_response_code($resp);
      $body = json_decode((string) wp_remote_retrieve_body($resp), true);
      if ($code === 404) {
        $rel['error'] = 'No releases have been published on GitHub yet.';
      } elseif ($code !== 200 || !is_array($body)) {
        $rel['error'] = 'GitHub returned HTTP ' . $code . (is_array($body) && !empty($body['message']) ? ' (' . $body['message'] . ')' : '');
      } else {
        $rel['version']   = ltrim((string) (isset($body['tag_name']) ? $body['tag_name'] : ''), 'vV');
        $rel['notes']     = (string) (isset($body['body']) ? $body['body'] : '');
        $rel['url']       = (string) (!empty($body['html_url']) ? $body['html_url'] : $rel['url']);
        $rel['published'] = !empty($body['published_at']) ? (int) strtotime($body['published_at']) : 0;
        foreach ((array) (isset($body['assets']) ? $body['assets'] : []) as $a) {
          if (is_array($a) && isset($a['name']) && $a['name'] === self::RELEASE_ASSET) $rel['package'] = (string) $a['browser_download_url'];
        }
        if (!$rel['package']) $rel['error'] = 'The latest release (' . $rel['version'] . ') has no ' . self::RELEASE_ASSET . ' attached.';
      }
    }
    set_site_transient(self::T_RELEASE, $rel, $rel['error'] ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
    return $rel;
  }

  /** Release info if it's newer than what's installed, else null. */
  public static function update_available() {
    $r = self::github_release();
    return ($r['version'] && $r['package'] && version_compare($r['version'], self::VERSION, '>')) ? $r : null;
  }

  /** Hooked to update_plugins_github.com (WordPress 5.8+, via the Update URI header). */
  public static function github_update_check($update, $plugin_data, $plugin_file, $locales = []) {
    if ($plugin_file !== plugin_basename(__FILE__)) return $update;
    $r = self::github_release();
    if (!$r['version'] || !$r['package']) return $update;
    return [
      'id'           => 'github.com/' . self::GITHUB_REPO,
      'slug'         => 'netmon-heartbeat',
      'plugin'       => $plugin_file,
      'version'      => $r['version'],
      'url'          => 'https://github.com/' . self::GITHUB_REPO,
      'package'      => $r['package'],
      'requires_php' => '7.4',
    ];
  }

  /** Make sure WordPress's update list knows about our release, so "Update now" works straight away. */
  private static function ensure_update_registered($r) {
    $file = plugin_basename(__FILE__);
    $t = get_site_transient('update_plugins');
    if (!is_object($t)) $t = new stdClass();
    if (!isset($t->response) || !is_array($t->response)) $t->response = [];
    if (isset($t->response[$file]->new_version) && $t->response[$file]->new_version === $r['version']) return;
    $t->response[$file] = (object) [
      'id' => 'github.com/' . self::GITHUB_REPO, 'slug' => 'netmon-heartbeat', 'plugin' => $file,
      'new_version' => $r['version'], 'url' => 'https://github.com/' . self::GITHUB_REPO, 'package' => $r['package'],
    ];
    if (isset($t->no_update[$file])) unset($t->no_update[$file]);
    set_site_transient('update_plugins', $t);
  }

  public static function update_url() {
    $file = plugin_basename(__FILE__);
    return wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($file)), 'upgrade-plugin_' . $file);
  }

  /** "View details" popup on the Plugins screen. */
  public static function plugins_api($res, $action, $args) {
    if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'netmon-heartbeat') return $res;
    $r = self::github_release();
    $repo = 'https://github.com/' . self::GITHUB_REPO;
    return (object) [
      'name'          => 'NetMon Broadband Uptime Monitor',
      'slug'          => 'netmon-heartbeat',
      'version'       => $r['version'] ? $r['version'] : self::VERSION,
      'author'        => '<a href="https://github.com/peterjmayhew">Peter Mayhew</a>',
      'homepage'      => $repo,
      'requires'      => '5.8',
      'requires_php'  => '7.4',
      'download_link' => $r['package'],
      'last_updated'  => $r['published'] ? gmdate('Y-m-d H:i:s', $r['published']) : '',
      'sections'      => [
        'description' => '<p>Works with an ESP32 on your home Wi-Fi to monitor your broadband 24/7. It records exactly when the internet went down and came back up, emails you, and produces an uptime report with CSV export.</p>'
                       . '<p>Full guide: <a href="' . esc_url($repo . '#readme') . '">' . esc_html($repo) . '</a></p>',
        'changelog'   => $r['notes'] ? self::md_to_html($r['notes']) : '<p>See <a href="' . esc_url($repo . '/blob/main/CHANGELOG.md') . '">CHANGELOG.md</a>.</p>',
      ],
    ];
  }

  /** If the unzipped folder isn't called netmon-heartbeat, rename it so the plugin stays active. */
  public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) return $source;
    $want = trailingslashit($remote_source) . 'netmon-heartbeat/';
    if (trailingslashit($source) === $want) return $source;
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move($source, $want, true)) return $want;
    return $source;
  }

  public static function after_upgrade($upgrader, $extra) {
    if (!empty($extra['plugins']) && in_array(plugin_basename(__FILE__), (array) $extra['plugins'], true)) delete_site_transient(self::T_RELEASE);
  }

  /** Tiny Markdown subset for release notes: headings, bullets, bold, links. */
  private static function md_to_html($md) {
    $out = ''; $in_list = false;
    foreach (preg_split('/\r?\n/', (string) $md) as $line) {
      $line = rtrim($line);
      $txt = esc_html(ltrim(preg_replace('/^(#+|[-*])\s*/', '', $line)));
      $txt = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $txt);
      $txt = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $txt);
      $is_li = (bool) preg_match('/^\s*[-*]\s+/', $line);
      if ($in_list && !$is_li) { $out .= '</ul>'; $in_list = false; }
      if ($line === '') continue;
      if (preg_match('/^#+\s/', $line)) $out .= '<h4>' . $txt . '</h4>';
      elseif ($is_li) { if (!$in_list) { $out .= '<ul>'; $in_list = true; } $out .= '<li>' . $txt . '</li>'; }
      else $out .= '<p>' . $txt . '</p>';
    }
    return $out . ($in_list ? '</ul>' : '');
  }

  private static function process_about() {
    if (empty($_POST['netmon_action']) || $_POST['netmon_action'] !== 'check_updates') return '';
    check_admin_referer('netmon_about');
    if (!current_user_can('update_plugins')) return '';
    $r = self::github_release(true);
    delete_site_transient('update_plugins');
    if (function_exists('wp_update_plugins')) wp_update_plugins();
    if ($r['error']) return '<div class="notice notice-error"><p><strong>Couldn\'t check for updates.</strong> ' . esc_html($r['error']) . '</p></div>';
    if (version_compare($r['version'], self::VERSION, '>')) {
      return '<div class="notice notice-warning"><p><strong>Version ' . esc_html($r['version']) . ' is available.</strong> See below to update.</p></div>';
    }
    return '<div class="notice notice-success"><p><strong>You\'re up to date.</strong> Version ' . esc_html(self::VERSION) . ' is the latest release on GitHub.</p></div>';
  }

  private static function tab_about() {
    $repo = 'https://github.com/' . self::GITHUB_REPO;
    $r = self::github_release();
    $new = self::update_available();
    if ($new) self::ensure_update_registered($new);
    ?>
    <div class="netmon-card" style="max-width:860px;margin-top:14px">
      <h2 style="margin-top:0">NetMon Broadband Uptime Monitor <span class="netmon-ver">v<?php echo esc_html(self::VERSION); ?></span></h2>
      <?php if ($new): ?>
        <p style="font-size:15px"><strong style="color:#b26200">Version <?php echo esc_html($new['version']); ?> is available</strong>
          <?php if ($new['published']) echo '(released ' . esc_html(self::fmt_time($new['published'], 'j M Y')) . ')'; ?>.
          Your settings, token and history are kept when you update.</p>
        <?php if (current_user_can('update_plugins')): ?>
          <p><a class="button button-primary button-hero" href="<?php echo esc_url(self::update_url()); ?>">Update now to v<?php echo esc_html($new['version']); ?></a></p>
        <?php endif; ?>
        <?php if ($new['notes']): ?><div class="netmon-notes"><h3>What's new</h3><?php echo self::md_to_html($new['notes']); ?></div><?php endif; ?>
      <?php elseif ($r['error']): ?>
        <p><strong>Couldn't check GitHub:</strong> <?php echo esc_html($r['error']); ?></p>
      <?php else: ?>
        <p style="font-size:15px;color:#1e8e3e"><strong>&#10004; You're up to date.</strong>
          <?php if ($r['version']) echo 'Latest release on GitHub: v' . esc_html($r['version']) . ($r['published'] ? ' (' . esc_html(self::fmt_time($r['published'], 'j M Y')) . ')' : '') . '.'; ?></p>
      <?php endif; ?>
      <form method="post" style="margin-top:10px">
        <?php wp_nonce_field('netmon_about'); ?>
        <button class="button" name="netmon_action" value="check_updates">Check for updates</button>
        <span class="netmon-muted">&nbsp;Last checked <?php echo esc_html(self::fmt_duration(time() - (int) $r['checked'])); ?> ago.
          WordPress also checks automatically twice a day; you can turn on <em>Enable auto-updates</em> for this plugin on the Plugins page.</span>
      </form>
    </div>

    <div class="netmon-card" style="max-width:860px;margin-top:14px">
      <h2 style="margin-top:0">What is this?</h2>
      <p>An ESP32 on your home Wi-Fi tests your broadband every 10 seconds, 24/7. It records exactly when the internet went down and came back up,
         keeps outages in its memory while you're offline, and sends them here as soon as the connection returns. It also sends a heartbeat every minute,
         so this site notices if it goes silent (for example in a power cut) and emails you.
         The result is a clear record of every outage that you can export as CSV, which is useful as evidence for your broadband provider.</p>
      <h2>Help &amp; links</h2>
      <ul style="list-style:disc;margin-left:20px">
        <li><a href="<?php echo esc_url($repo . '#readme'); ?>" target="_blank">Full guide</a>: installation (including Plesk), setup, using the reports, troubleshooting</li>
        <li><a href="<?php echo esc_url($repo . '/blob/main/CHANGELOG.md'); ?>" target="_blank">Changelog</a>: what changed in each version</li>
        <li><a href="<?php echo esc_url($repo . '/releases'); ?>" target="_blank">All releases</a>: download any version of the plugin</li>
        <li><a href="<?php echo esc_url($repo . '/tree/main/ESP32/YouFibreMonitor'); ?>" target="_blank">ESP32 firmware</a>: the ESP32 is updated separately, by uploading from the Arduino IDE</li>
      </ul>
      <h2>Suggestions, questions or problems?</h2>
      <p>This project is written and maintained by <strong>Peter Mayhew</strong>. Ideas and bug reports are very welcome:</p>
      <p><a class="button" href="<?php echo esc_url($repo . '/issues/new'); ?>" target="_blank">Suggest an improvement or report a problem</a>
         &nbsp;<a class="button" href="https://github.com/peterjmayhew" target="_blank">Contact Peter Mayhew on GitHub</a></p>
    </div>
    <?php
  }

  public static function footer_text($text) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || strpos((string) $screen->id, self::SLUG) === false) return $text;
    return 'NetMon Broadband Uptime Monitor v' . esc_html(self::VERSION) . ' by Peter Mayhew &middot; <a href="https://github.com/' . self::GITHUB_REPO . '" target="_blank">GitHub</a>'
         . ' &middot; <a href="' . esc_url(self::admin_url(['tab' => 'about'])) . '">Check for updates</a>';
  }

  private static function version_badge() {
    $new = self::update_available();
    return '<span class="netmon-ver">v' . esc_html(self::VERSION) . '</span>'
      . ($new ? ' <a class="netmon-ver netmon-ver-new" href="' . esc_url(self::admin_url(['tab' => 'about'])) . '">Update available: v' . esc_html($new['version']) . '</a>' : '');
  }

  /* =================================================================
   *  Report engine
   * ================================================================= */

  public static function cause_text($type, $gateway_ok = null) {
    switch ($type) {
      case 'internet':
        if ($gateway_ok === '1' || $gateway_ok === 1) return 'Internet down - router was reachable, so the fault was on the YouFibre side';
        if ($gateway_ok === '0' || $gateway_ok === 0) return 'Internet down - router was not answering (router crashed or rebooting?)';
        return 'Internet down';
      case 'wifi':
        return 'No Wi-Fi - router/mesh off or rebooting';
      case 'silent':
        return 'Monitor offline - power cut, router off, or ESP32 unplugged';
    }
    return $type;
  }

  private static function badge($type) {
    $map = ['internet' => ['Internet', '#d93025'], 'wifi' => ['Wi-Fi', '#e37400'], 'silent' => ['Monitor offline', '#5f6368']];
    $b = isset($map[$type]) ? $map[$type] : [$type, '#5f6368'];
    return '<span class="netmon-badge" style="background:' . esc_attr($b[1]) . '">' . esc_html($b[0]) . '</span>';
  }

  /** Resolve a named range into [from_ts, to_ts, label] */
  public static function resolve_range($range, $from_s = '', $to_s = '') {
    $tz = wp_timezone();
    $now = time();
    $today = new DateTimeImmutable('today', $tz);
    switch ($range) {
      case '24h':  return [$now - 86400, $now, 'Last 24 hours'];
      case '7d':   return [$now - 7 * 86400, $now, 'Last 7 days'];
      case '90d':  return [$now - 90 * 86400, $now, 'Last 90 days'];
      case '365d': return [$now - 365 * 86400, $now, 'Last 12 months'];
      case 'all':  return [0, $now, 'All time'];
      case 'this_month':
        return [$today->modify('first day of this month')->getTimestamp(), $now, 'This month'];
      case 'last_month':
        $a = $today->modify('first day of last month');
        return [$a->getTimestamp(), $today->modify('first day of this month')->getTimestamp(), $a->format('F Y')];
      case 'custom':
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $from_s, $tz);
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $to_s, $tz);
        if ($f && $t && $t >= $f) {
          return [$f->getTimestamp(), min($now, $t->modify('+1 day')->getTimestamp()), $f->format('j M Y') . ' – ' . $t->format('j M Y')];
        }
        // fall through
      default:
        return [$now - 30 * 86400, $now, 'Last 30 days'];
    }
  }

  /**
   * Build incidents (merged outages) for a device and time range.
   * Device-reported outages have exact times; server "silent" periods are
   * only used when the ESP32 didn't cover them itself (e.g. power cuts).
   */
  public static function build_report($device, $from, $to, $show_ignored = false) {
    global $wpdb;
    $now = time();
    $to = min($to, $now);
    $state = self::state();
    $st = isset($state[$device]) ? $state[$device] : [];
    $first = !empty($st['first_seen']) ? (int) $st['first_seen'] : 0;
    $ev = self::ev_table();

    $rep = [
      'device' => $device, 'from' => $from, 'to' => $to, 'first_seen' => $first,
      'monitored' => 0, 'down' => 0, 'incidents' => [], 'ignored_count' => 0, 'days' => [],
    ];
    if (!$first) return $rep;
    $mfrom = max($from, $first);
    if ($mfrom >= $to) return $rep;
    $rep['monitored'] = $to - $mfrom;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $ev WHERE device_id=%s AND started_at < %s AND (ended_at IS NULL OR ended_at > %s) ORDER BY started_at",
      $device, self::dt($to), self::dt($mfrom)));

    $dev = []; $srv = [];
    foreach ($rows as $r) {
      if ((int) $r->ignored && !$show_ignored) { $rep['ignored_count']++; continue; }
      $s = max(self::ts($r->started_at), $mfrom);
      $e = min($r->ended_at ? self::ts($r->ended_at) : $now, $to);
      if ($e <= $s) continue;
      $item = ['s' => $s, 'e' => $e, 'row' => $r, 'ongoing' => !$r->ended_at];
      if ($r->source === 'device') $dev[] = $item; else $srv[] = $item;
    }

    // Drop server silences that the ESP32's own records mostly explain
    $parts = $dev;
    foreach ($srv as $x) {
      $cov = 0;
      foreach ($dev as $d) $cov += max(0, min($x['e'], $d['e']) - max($x['s'], $d['s']));
      if ($x['ongoing'] || $cov < 0.5 * ($x['e'] - $x['s'])) $parts[] = $x;
    }
    usort($parts, function ($a, $b) { return $a['s'] - $b['s']; });

    // Merge overlapping / touching intervals into incidents
    $inc = [];
    foreach ($parts as $p) {
      $n = count($inc);
      if ($n && $p['s'] <= $inc[$n - 1]['end'] + 1) {
        $inc[$n - 1]['end'] = max($inc[$n - 1]['end'], $p['e']);
        $inc[$n - 1]['ongoing'] = $inc[$n - 1]['ongoing'] || $p['ongoing'];
        $inc[$n - 1]['rows'][] = $p['row'];
      } else {
        $inc[] = ['start' => $p['s'], 'end' => $p['e'], 'ongoing' => $p['ongoing'], 'rows' => [$p['row']]];
      }
    }

    $down = 0;
    foreach ($inc as &$i) {
      $i['duration'] = $i['end'] - $i['start'];
      $down += $i['duration'];
      $types = []; $notes = [];
      foreach ($i['rows'] as $r) {
        $types[$r->event_type] = true;
        $txt = self::cause_text($r->event_type, $r->gateway_ok);
        $notes[$txt] = true;
        if ($r->details) $notes[$r->details] = true;
      }
      $i['types'] = array_keys($types);
      $i['notes'] = array_keys($notes);
      $i['ids'] = array_map('intval', wp_list_pluck($i['rows'], 'id'));
      $i['ignored'] = (bool) array_filter($i['rows'], function ($r) { return (int) $r->ignored; });
      if ($i['ignored']) $down -= $i['duration'];
    }
    unset($i);
    $rep['incidents'] = $inc;
    $rep['down'] = max(0, $down);

    // Per-day breakdown in the site's time zone
    $tz = wp_timezone();
    $day = (new DateTimeImmutable('@' . $mfrom))->setTimezone($tz)->setTime(0, 0);
    $guard = 0;
    while ($day->getTimestamp() < $to && $guard++ < 4000) {
      $next = $day->modify('+1 day');
      $ds = max($day->getTimestamp(), $mfrom);
      $de = min($next->getTimestamp(), $to);
      $dd = 0; $cnt = 0;
      foreach ($inc as $i) {
        if ($i['ignored']) continue;
        $o = min($de, $i['end']) - max($ds, $i['start']);
        if ($o > 0) { $dd += $o; $cnt++; }
      }
      $rep['days'][] = ['date' => $day, 'monitored' => max(0, $de - $ds), 'down' => $dd, 'count' => $cnt];
      $day = $next;
    }
    return $rep;
  }

  public static function uptime_pct($rep) {
    if ($rep['monitored'] <= 0) return null;
    return max(0, 100 * (1 - $rep['down'] / $rep['monitored']));
  }

  private static function fmt_pct($p) {
    if ($p === null) return 'n/a';
    if ($p >= 100) return '100%';
    return number_format(floor($p * 1000) / 1000, 3) . '%';
  }

  /** Current status of a device */
  public static function device_status($device) {
    $state = self::state();
    $st = isset($state[$device]) ? $state[$device] : [];
    $last = !empty($st['last_hb']) ? (int) $st['last_hb'] : 0;
    $age = $last ? time() - $last : null;
    $timeout = self::timeout_s();
    if (!$last) return ['code' => 'never', 'label' => 'Waiting for first heartbeat', 'color' => '#5f6368', 'st' => $st, 'age' => null];
    if ($age <= max(180, $timeout / 2)) return ['code' => 'up', 'label' => 'Online', 'color' => '#1e8e3e', 'st' => $st, 'age' => $age];
    if ($age <= $timeout) return ['code' => 'late', 'label' => 'Heartbeat late', 'color' => '#e37400', 'st' => $st, 'age' => $age];
    return ['code' => 'down', 'label' => 'OFFLINE since ' . self::fmt_time($last), 'color' => '#d93025', 'st' => $st, 'age' => $age];
  }

  /* =================================================================
   *  Rendering (shared by admin page, dashboard widget and shortcode)
   * ================================================================= */

  private static function css() {
    return '<style>
.netmon-wrap{max-width:1200px}
.netmon-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:14px 0}
.netmon-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}
.netmon-card .k{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
.netmon-card .v{font-size:24px;font-weight:600;margin-top:4px;line-height:1.2}
.netmon-card .s{font-size:12px;color:#646970;margin-top:4px}
.netmon-status{border-radius:8px;padding:16px 18px;color:#fff;font-size:18px;font-weight:600;margin:14px 0;display:flex;gap:18px;flex-wrap:wrap;align-items:center}
.netmon-status small{font-weight:400;font-size:13px;opacity:.95}
.netmon-strip{display:flex;gap:2px;margin:8px 0 2px;flex-wrap:wrap}
.netmon-strip span{flex:1 0 8px;min-width:8px;max-width:22px;height:34px;border-radius:2px;display:block}
.netmon-strip-legend{display:flex;justify-content:space-between;font-size:12px;color:#646970}
.netmon-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde}
.netmon-table th,.netmon-table td{padding:8px 10px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top;font-size:13px}
.netmon-table th{background:#f6f7f7;font-weight:600}
.netmon-table tr.ongoing td{background:#fce8e6}
.netmon-table tr.ignored td{opacity:.5;text-decoration:line-through}
.netmon-badge{display:inline-block;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;margin:0 4px 2px 0}
.netmon-note{color:#646970;font-size:12px;margin-top:3px}
.netmon-dur{font-weight:600;white-space:nowrap}
.netmon-filter{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.netmon-section{margin-top:22px}
.netmon-section h2{margin-bottom:6px}
.netmon-muted{color:#646970}
.netmon-live{background:#fff;border:1px solid #dcdcde;border-left:6px solid var(--nm);border-radius:8px;padding:16px 18px;margin:14px 0}
.netmon-live-head{display:flex;gap:14px;align-items:center}
.netmon-dot{width:44px;height:44px;border-radius:50%;background:var(--nm);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;flex:none}
.netmon-dot-up{animation:netmon-pulse 2s infinite}
@keyframes netmon-pulse{0%{box-shadow:0 0 0 0 rgba(30,142,62,.55)}70%{box-shadow:0 0 0 14px rgba(30,142,62,0)}100%{box-shadow:0 0 0 0 rgba(30,142,62,0)}}
.netmon-live-title{font-size:20px;font-weight:600;color:var(--nm);line-height:1.3}
.netmon-live-sub{color:#50575e;margin-top:3px}
.netmon-live-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:14px}
.netmon-live .k{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em}
.netmon-live .v{font-size:20px;font-weight:600;margin-top:2px}
.netmon-live .s{font-size:12px;color:#646970}
.netmon-minutes{display:flex;gap:2px;margin:6px 0 2px}
.netmon-minutes span{flex:1;height:22px;border-radius:2px;background:#dcdcde}
.netmon-minutes span.ok,.nm-key.ok{background:#1e8e3e}
.netmon-minutes span.miss,.nm-key.miss{background:#d93025}
.nm-key{display:inline-block;width:10px;height:10px;border-radius:2px;vertical-align:middle}
.netmon-live-foot{font-size:12px;color:#8c8f94;margin-top:10px}
.netmon-ver{display:inline-block;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e;border-radius:10px;padding:2px 9px;vertical-align:middle;text-decoration:none}
.netmon-ver-new{background:#fcf0e3;color:#b26200}
.netmon-notes{background:#f6f7f7;border-radius:6px;padding:4px 16px;margin:10px 0}
.netmon-code{background:#1d2327;color:#f0f0f1;padding:14px;border-radius:6px;overflow:auto;font-family:Menlo,Consolas,monospace;font-size:13px;white-space:pre}
</style>';
  }

  private static function day_color($d) {
    if ($d['monitored'] <= 0) return '#dcdcde';
    $p = 100 * (1 - $d['down'] / $d['monitored']);
    if ($d['down'] <= 0) return '#1e8e3e';
    if ($p >= 99.5) return '#81c995';
    if ($p >= 98) return '#fbbc04';
    if ($p >= 95) return '#f29900';
    return '#d93025';
  }

  private static function render_status($device) {
    $ds = self::device_status($device);
    $st = $ds['st'];
    $h = '<div class="netmon-status" style="background:' . esc_attr($ds['color']) . '"><div>' . esc_html($device) . ': ' . esc_html($ds['label']) . '</div>';
    if ($ds['age'] !== null) {
      $h .= '<small>Last heartbeat ' . esc_html(self::fmt_duration($ds['age'])) . ' ago';
      if (isset($st['rssi'])) $h .= ' · Wi-Fi ' . (int) $st['rssi'] . ' dBm';
      if (isset($st['latency_ms'])) $h .= ' · connect ' . (int) $st['latency_ms'] . ' ms';
      if (!empty($st['ip'])) $h .= ' · public IP ' . esc_html($st['ip']);
      if (!empty($st['fw'])) $h .= ' · fw ' . esc_html($st['fw']);
      $h .= '</small>';
    }
    return $h . '</div>';
  }

  /* ---------------- Live "is the ESP32 talking to us?" panel ---------------- */

  /** Timestamps of heartbeats received in the last $seconds */
  private static function recent_heartbeats($device, $seconds = 3690) {
    global $wpdb;
    $hb = self::hb_table();
    $rows = $wpdb->get_col($wpdb->prepare("SELECT created_at FROM $hb WHERE device_id=%s AND created_at >= %s", $device, self::dt(time() - $seconds)));
    return array_map([__CLASS__, 'ts'], (array) $rows);
  }

  /** A recent rejected connection attempt worth telling the user about, or null */
  private static function recent_rejection() {
    $r = get_option(self::OPT_REJECT);
    if (!is_array($r) || empty($r['time']) || time() - (int) $r['time'] > 86400) return null;
    foreach (self::state() as $st) {
      if (!empty($st['last_hb']) && (int) $st['last_hb'] > (int) $r['time']) return null; // it has worked since
    }
    return $r;
  }

  private static function render_rejection_notice() {
    $r = self::recent_rejection();
    if (!$r) return '';
    return '<div class="notice notice-error inline" style="margin:10px 0"><p><strong>An ESP32 tried to connect but was turned away.</strong> '
      . esc_html(self::fmt_time($r['time'])) . ' (' . esc_html(self::fmt_duration(time() - (int) $r['time'])) . ' ago)'
      . (!empty($r['device']) ? ', device <code>' . esc_html($r['device']) . '</code>' : '')
      . (!empty($r['ip']) ? ', from ' . esc_html($r['ip']) : '') . ' - reason: <strong>' . esc_html($r['why']) . '</strong>.<br>'
      . 'Copy the token from the <a href="' . esc_url(self::admin_url(['tab' => 'setup'])) . '">ESP32 Setup</a> tab into <code>WP_TOKEN</code> in secrets.h on the ESP32 and upload the sketch again.</p></div>';
  }

  public static function render_connection_panel($device) {
    $ds = self::device_status($device);
    $st = $ds['st'];
    $now = time();
    $last = !empty($st['last_hb']) ? (int) $st['last_hb'] : 0;
    $beats = ($device && $last) ? self::recent_heartbeats($device) : [];
    $count = count(array_filter($beats, function ($t) use ($now) { return $t >= $now - 3600; }));
    $first = !empty($st['first_seen']) ? (int) $st['first_seen'] : $now;
    $expected = (int) max(1, min(60, floor(($now - max($first, $now - 3600)) / 60)));

    switch ($ds['code']) {
      case 'up':
        $icon = '&#10004;'; $head = 'Receiving heartbeats from the ESP32';
        $sub = 'WordPress and your ESP32 are talking. A new heartbeat arrives every minute.';
        break;
      case 'late':
        $icon = '&#9888;'; $head = 'Heartbeat late';
        $sub = 'Nothing received for a few minutes. If this continues it will be counted as an outage.';
        break;
      case 'down':
        $icon = '&#10006;'; $head = 'NO SIGNAL from the ESP32 since ' . self::fmt_time($last, 'H:i \o\n D j M');
        $sub = 'Your broadband is probably down, or there is a power cut, or the ESP32 is unplugged. It will report the exact times when it reconnects.';
        break;
      default:
        $icon = '&#8987;'; $head = 'Waiting for the first heartbeat from the ESP32';
        $sub = 'Nothing has been received yet. Check the ESP32 Setup tab, then power up the ESP32 - it should appear here within a minute.';
    }

    $h = '<div id="netmon-live" class="netmon-live" data-device="' . esc_attr($device) . '" data-last="' . $last . '" data-now="' . $now
       . '" style="--nm:' . esc_attr($ds['color']) . '">'
       . '<div class="netmon-live-head"><span class="netmon-dot netmon-dot-' . esc_attr($ds['code']) . '">' . $icon . '</span><div>'
       . '<div class="netmon-live-title">' . esc_html($head) . ($device ? ' <span class="netmon-muted" style="font-weight:400;font-size:14px">(' . esc_html($device) . ')</span>' : '') . '</div>'
       . '<div class="netmon-live-sub">' . esc_html($sub) . '</div></div></div>';

    $h .= '<div class="netmon-live-grid">';
    $h .= '<div><div class="k">Last heartbeat</div><div class="v">' . ($last ? '<span class="netmon-ago">' . esc_html(self::fmt_duration($now - $last)) . '</span> ago' : 'never')
        . '</div><div class="s">' . esc_html($last ? self::fmt_time($last) : '—') . '</div></div>';
    $h .= '<div><div class="k">Heartbeats in the last hour</div><div class="v">' . (int) $count . ' <span class="netmon-muted" style="font-size:14px">of ~' . $expected . ' expected</span></div>'
        . '<div class="s">one per minute while online</div></div>';
    if ($last) {
      $h .= '<div><div class="k">ESP32 Wi-Fi signal</div><div class="v">' . (isset($st['rssi']) ? (int) $st['rssi'] . ' dBm' : '—') . '</div><div class="s">'
          . esc_html(isset($st['rssi']) ? self::rssi_text((int) $st['rssi']) : '') . '</div></div>';
      $h .= '<div><div class="k">Connect time</div><div class="v">' . (isset($st['latency_ms']) ? (int) $st['latency_ms'] . ' ms' : '—') . '</div><div class="s">to 1.1.1.1 / 8.8.8.8</div></div>';
      $h .= '<div><div class="k">ESP32 running for</div><div class="v">' . (isset($st['uptime']) ? esc_html(self::fmt_duration($st['uptime'])) : '—') . '</div><div class="s">'
          . (!empty($st['last_reset_reason']) ? 'last restart: ' . esc_html($st['last_reset_reason']) : '') . '</div></div>';
      $h .= '<div><div class="k">Home public IP</div><div class="v" style="font-size:16px">' . esc_html(!empty($st['ip']) ? $st['ip'] : '—') . '</div><div class="s">'
          . (!empty($st['local_ip']) ? 'ESP32 status page: <a href="http://' . esc_attr($st['local_ip']) . '/" target="_blank">' . esc_html($st['local_ip']) . '</a> (home network only)' : '') . '</div></div>';
    }
    $h .= '</div>';

    // Last 60 minutes, one block per minute
    $h .= '<div class="k" style="margin-top:14px">Last 60 minutes &mdash; one block per minute</div><div class="netmon-minutes">';
    for ($i = 0; $i < 60; $i++) {
      $t = $now - (60 - $i) * 60;
      // Heartbeats come every ~60 s, so allow 30 s either side to avoid false gaps
      $n = count(array_filter($beats, function ($b) use ($t) { return $b >= $t - 30 && $b < $t + 90; }));
      $cls = $n ? 'ok' : ($t + 60 < $first ? 'na' : 'miss');
      $h .= '<span class="' . $cls . '" title="' . esc_attr(self::fmt_time($t, 'H:i') . ($n ? ' - heartbeat received' : ' - nothing received')) . '"></span>';
    }
    $h .= '</div><div class="netmon-strip-legend"><span>' . esc_html(self::fmt_time($now - 3600, 'H:i')) . '</span>'
        . '<span><i class="nm-key ok"></i> received &nbsp; <i class="nm-key miss"></i> missing</span><span>now</span></div>';
    $h .= self::render_rejection_notice();
    $h .= '<div class="netmon-live-foot">Updates automatically every 20 seconds &middot; last checked <span class="netmon-checked">just now</span></div></div>';
    return $h;
  }

  private static function rssi_text($r) {
    if ($r >= -60) return 'excellent';
    if ($r >= -70) return 'good';
    if ($r >= -80) return 'weak - consider moving the ESP32 closer';
    return 'very weak - move the ESP32 closer to the router';
  }

  private static function live_script() {
    $url = wp_nonce_url(admin_url('admin-ajax.php?action=netmon_live'), 'netmon_live');
    return '<script>(function(){
  var url=' . wp_json_encode($url) . ', t0=Date.now();
  function fmt(s){s=Math.max(0,Math.round(s));var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),x=s%60;
    return d?d+"d "+h+"h "+m+"m":h?h+"h "+m+"m":m?m+"m "+x+"s":x+"s";}
  function tick(){var el=document.getElementById("netmon-live");if(!el)return;
    var c=el.querySelector(".netmon-checked");if(c)c.textContent=Math.round((Date.now()-t0)/1000)+"s ago";
    var last=+el.dataset.last;if(!last)return;
    var a=el.querySelector(".netmon-ago");if(a)a.textContent=fmt(+el.dataset.now+(Date.now()-t0)/1000-last);}
  function refresh(){var el=document.getElementById("netmon-live");if(!el)return;
    fetch(url.replace(/&amp;/g,"&")+"&device="+encodeURIComponent(el.dataset.device),{credentials:"same-origin"})
      .then(function(r){return r.json();}).then(function(j){
        if(!j||!j.success)return;var tmp=document.createElement("div");tmp.innerHTML=j.data.html;
        el.parentNode.replaceChild(tmp.firstElementChild,el);t0=Date.now();
        var bar=document.querySelector("#wp-admin-bar-netmon > .ab-item");if(bar&&j.data.bar)bar.innerHTML=j.data.bar;
      }).catch(function(){});}
  setInterval(tick,1000);setInterval(refresh,20000);})();</script>';
  }

  public static function ajax_live() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('netmon_live');
    $device = isset($_GET['device']) ? self::clean_device(wp_unslash($_GET['device'])) : '';
    if (!in_array($device, self::devices(), true)) $device = '';
    wp_send_json_success(['html' => self::render_connection_panel($device), 'bar' => self::admin_bar_title()]);
  }

  /* ---------------- Admin bar indicator (every admin page) ---------------- */

  private static function admin_bar_title() {
    $rank = ['never' => 0, 'up' => 1, 'late' => 2, 'down' => 3];
    $worst = null;
    foreach (self::devices() as $d) {
      $ds = self::device_status($d);
      if (!$worst || $rank[$ds['code']] > $rank[$worst['code']]) $worst = $ds;
    }
    if (!$worst) {
      return self::recent_rejection()
        ? '<span style="color:#f86368;font-size:16px">&#9679;</span> Broadband: ESP32 rejected'
        : '<span style="color:#a7aaad;font-size:16px">&#9679;</span> Broadband: waiting for ESP32';
    }
    $txt = ['never' => 'waiting for ESP32', 'up' => 'Online', 'late' => 'heartbeat late', 'down' => 'NO SIGNAL'][$worst['code']];
    $col = ['never' => '#a7aaad', 'up' => '#46d160', 'late' => '#f0b849', 'down' => '#f86368'][$worst['code']];
    return '<span style="color:' . $col . ';font-size:16px">&#9679;</span> Broadband: ' . esc_html($txt);
  }

  public static function admin_bar($bar) {
    if (!current_user_can('manage_options')) return;
    $bar->add_node(['id' => 'netmon', 'title' => self::admin_bar_title(), 'href' => self::admin_url()]);
  }

  private static function render_summary_cards($device) {
    $h = '<div class="netmon-cards">';
    foreach (['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $k => $lbl) {
      list($f, $t) = self::resolve_range($k);
      $r = self::build_report($device, $f, $t);
      $cnt = count(array_filter($r['incidents'], function ($i) { return !$i['ignored']; }));
      $h .= '<div class="netmon-card"><div class="k">' . esc_html($lbl) . '</div><div class="v">' . esc_html(self::fmt_pct(self::uptime_pct($r)))
          . '</div><div class="s">' . $cnt . ' outage' . ($cnt === 1 ? '' : 's') . ' · ' . esc_html(self::fmt_duration($r['down'])) . ' down</div></div>';
    }
    return $h . '</div>';
  }

  private static function render_report_body($rep, $admin_actions = false, $show_ignored = false) {
    $inc = $rep['incidents'];
    $real = array_values(array_filter($inc, function ($i) { return !$i['ignored']; }));
    $longest = 0;
    foreach ($real as $i) $longest = max($longest, $i['duration']);
    $pct = self::uptime_pct($rep);

    $h = '<div class="netmon-cards">'
      . '<div class="netmon-card"><div class="k">Uptime</div><div class="v">' . esc_html(self::fmt_pct($pct)) . '</div><div class="s">of ' . esc_html(self::fmt_duration($rep['monitored'])) . ' monitored</div></div>'
      . '<div class="netmon-card"><div class="k">Outages</div><div class="v">' . count($real) . '</div><div class="s">&ge; 30 s each</div></div>'
      . '<div class="netmon-card"><div class="k">Total downtime</div><div class="v">' . esc_html(self::fmt_duration($rep['down'])) . '</div></div>'
      . '<div class="netmon-card"><div class="k">Longest outage</div><div class="v">' . esc_html(self::fmt_duration($longest)) . '</div></div>'
      . '</div>';

    if (!$rep['first_seen']) {
      return $h . '<p class="netmon-muted">No data yet - waiting for the ESP32 to send its first heartbeat.</p>';
    }

    // Day strip
    $days = array_slice($rep['days'], -93);
    if ($days) {
      $h .= '<div class="netmon-section"><strong>Daily uptime</strong><div class="netmon-strip">';
      foreach ($days as $d) {
        $p = $d['monitored'] > 0 ? 100 * (1 - $d['down'] / $d['monitored']) : null;
        $tip = $d['date']->format('D j M Y') . ': ' . ($p === null ? 'not monitored' : self::fmt_pct($p) . ' up'
             . ($d['down'] ? ', ' . self::fmt_duration($d['down']) . ' down in ' . $d['count'] . ' outage' . ($d['count'] === 1 ? '' : 's') : ''));
        $h .= '<span title="' . esc_attr($tip) . '" style="background:' . esc_attr(self::day_color($d)) . '"></span>';
      }
      $h .= '</div><div class="netmon-strip-legend"><span>' . esc_html($days[0]['date']->format('j M')) . '</span><span>hover a day for details</span><span>'
          . esc_html(end($days)['date']->format('j M')) . '</span></div></div>';
    }

    // Incident table
    $h .= '<div class="netmon-section"><h2>When it went down and came back up</h2>';
    if (!$inc) {
      $h .= '<p>No outages in this period. &#127881;</p>';
    } else {
      $h .= '<table class="netmon-table"><thead><tr><th>#</th><th>Went down</th><th>Came back up</th><th>Down for</th><th>What happened</th>'
          . ($admin_actions ? '<th></th>' : '') . '</tr></thead><tbody>';
      $n = count($inc);
      foreach (array_reverse($inc) as $idx => $i) {
        $cls = $i['ongoing'] ? 'ongoing' : ($i['ignored'] ? 'ignored' : '');
        $h .= '<tr class="' . $cls . '"><td>' . ($n - $idx) . '</td>'
            . '<td>' . esc_html(self::fmt_time($i['start'])) . '</td>'
            . '<td>' . ($i['ongoing'] ? '<strong>Still down</strong>' : esc_html(self::fmt_time($i['end']))) . '</td>'
            . '<td class="netmon-dur">' . esc_html(self::fmt_duration($i['duration'])) . '</td><td>';
        foreach ($i['types'] as $t) $h .= self::badge($t);
        foreach ($i['notes'] as $note) $h .= '<div class="netmon-note">' . esc_html($note) . '</div>';
        $h .= '</td>';
        if ($admin_actions) {
          $url = wp_nonce_url(add_query_arg([
            'action' => 'netmon_ignore', 'ids' => implode(',', $i['ids']), 'undo' => $i['ignored'] ? 1 : 0,
            'back' => rawurlencode(wp_unslash(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '')),
          ], admin_url('admin-post.php')), 'netmon_ignore');
          $h .= '<td><a href="' . esc_url($url) . '" title="Exclude from uptime (e.g. you unplugged the monitor)">' . ($i['ignored'] ? 'Restore' : 'Ignore') . '</a></td>';
        }
        $h .= '</tr>';
      }
      $h .= '</tbody></table>';
    }
    if ($rep['ignored_count'] && !$show_ignored && $admin_actions) {
      $h .= '<p class="netmon-muted">' . (int) $rep['ignored_count'] . ' ignored record(s) hidden. <a href="' . esc_url(add_query_arg('show_ignored', 1)) . '">Show them</a></p>';
    }
    $h .= '</div>';

    // Days with downtime
    $bad = array_filter($rep['days'], function ($d) { return $d['down'] > 0; });
    if ($bad) {
      $h .= '<div class="netmon-section"><h2>Days with downtime</h2><table class="netmon-table"><thead><tr><th>Day</th><th>Outages</th><th>Downtime</th><th>Uptime</th></tr></thead><tbody>';
      foreach (array_reverse($bad) as $d) {
        $h .= '<tr><td>' . esc_html($d['date']->format('D j M Y')) . '</td><td>' . (int) $d['count'] . '</td><td class="netmon-dur">'
            . esc_html(self::fmt_duration($d['down'])) . '</td><td>' . esc_html(self::fmt_pct(100 * (1 - $d['down'] / max(1, $d['monitored'])))) . '</td></tr>';
      }
      $h .= '</tbody></table></div>';
    }
    return $h;
  }

  /* =================================================================
   *  Admin
   * ================================================================= */

  public static function admin_menu() {
    add_menu_page('Broadband Uptime', 'Broadband Uptime', 'manage_options', self::SLUG, [__CLASS__, 'admin_page'], 'dashicons-chart-area', 81);
  }

  private static function devices() {
    $d = array_keys(self::state());
    sort($d);
    return $d;
  }

  public static function admin_page() {
    if (!current_user_can('manage_options')) return;
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'report';
    $tabs = ['report' => 'Report', 'export' => 'Export CSV', 'heartbeats' => 'Heartbeats', 'settings' => 'Settings', 'setup' => 'ESP32 Setup', 'about' => 'About & Updates'];
    if (!isset($tabs[$tab])) $tab = 'report';

    $notice = '';
    if ($tab === 'settings') $notice = self::process_settings();
    if ($tab === 'about') $notice = self::process_about();
    self::check_devices();

    echo self::css();
    echo '<div class="wrap netmon-wrap"><h1>Broadband Uptime ' . self::version_badge() . '</h1>' . $notice;
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $k => $label) {
      echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::admin_url(['tab' => $k])) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    self::cron_health_notice();

    switch ($tab) {
      case 'export':     self::tab_export(); break;
      case 'about':      self::tab_about(); break;
      case 'heartbeats': self::tab_heartbeats(); break;
      case 'settings':   self::tab_settings(); break;
      case 'setup':      self::tab_setup(); break;
      default:           self::tab_report();
    }
    echo '</div>';
  }

  private static function current_device() {
    $devices = self::devices();
    $d = isset($_GET['device']) ? self::clean_device(wp_unslash($_GET['device'])) : '';
    if ($d && in_array($d, $devices, true)) return $d;
    return $devices ? $devices[0] : '';
  }

  private static function cron_health_notice() {
    $last = (int) get_option(self::OPT_CRONRUN, 0);
    if (!self::devices()) return;
    if (!$last || time() - $last > 15 * 60) {
      echo '<div class="notice notice-warning"><p><strong>WP-Cron hasn\'t run recently</strong> ('
         . ($last ? esc_html(self::fmt_duration(time() - $last)) . ' ago' : 'never') . '). "Down" emails depend on it. '
         . 'Outages are still recorded, but for prompt alerts set up a real cron job - see the <a href="'
         . esc_url(self::admin_url(['tab' => 'setup'])) . '#cron">ESP32 Setup</a> tab.</p></div>';
    }
  }

  private static function tab_report() {
    $device = self::current_device();
    echo self::render_connection_panel($device) . self::live_script();
    if (!$device) {
      echo '<p>Follow the <a href="' . esc_url(self::admin_url(['tab' => 'setup'])) . '">ESP32 Setup</a> steps; this page updates by itself when the first heartbeat arrives.</p>';
      return;
    }
    $range = isset($_GET['range']) ? sanitize_key($_GET['range']) : '30d';
    $from_s = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $to_s = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
    $show_ignored = !empty($_GET['show_ignored']);
    list($from, $to, $label) = self::resolve_range($range, $from_s, $to_s);

    echo self::render_summary_cards($device);

    // Filter form
    $ranges = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '365d' => 'Last 12 months',
               'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom dates…'];
    echo '<form method="get" class="netmon-filter"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
    $devices = self::devices();
    if (count($devices) > 1) {
      echo '<label>Device <select name="device">';
      foreach ($devices as $d) echo '<option value="' . esc_attr($d) . '"' . selected($d, $device, false) . '>' . esc_html($d) . '</option>';
      echo '</select></label>';
    } else {
      echo '<input type="hidden" name="device" value="' . esc_attr($device) . '">';
    }
    echo '<label>Period <select name="range" onchange="document.getElementById(\'netmon-custom\').style.display=this.value===\'custom\'?\'inline\':\'none\'">';
    foreach ($ranges as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected($k, $range, false) . '>' . esc_html($l) . '</option>';
    echo '</select></label><span id="netmon-custom" style="display:' . ($range === 'custom' ? 'inline' : 'none') . '">'
       . ' from <input type="date" name="from" value="' . esc_attr($from_s) . '"> to <input type="date" name="to" value="' . esc_attr($to_s) . '"></span>';
    if ($show_ignored) echo '<label><input type="checkbox" name="show_ignored" value="1" checked> show ignored</label>';
    echo '<button class="button button-primary">Show report</button>';
    $csv = wp_nonce_url(add_query_arg(['action' => 'netmon_export', 'kind' => 'outages', 'device' => $device, 'range' => $range, 'from' => $from_s, 'to' => $to_s], admin_url('admin-post.php')), 'netmon_export');
    echo '<a class="button" href="' . esc_url($csv) . '">Download CSV</a>';
    echo '<span class="netmon-muted">' . esc_html($label) . ': ' . esc_html(self::fmt_time($from, 'j M Y H:i')) . ' → ' . esc_html(self::fmt_time($to, 'j M Y H:i')) . '</span>';
    echo '</form>';

    $rep = self::build_report($device, $from, $to, $show_ignored);
    echo self::render_report_body($rep, true, $show_ignored);
    echo '<p class="netmon-muted" style="margin-top:18px">Times shown in ' . esc_html(wp_timezone_string()) . '. The ESP32 counts an outage after 30 s of failed checks and records exact times; '
       . '"Monitor offline" periods come from missing heartbeats (e.g. a power cut) and are accurate to about a minute.</p>';
  }

  private static function tab_export() {
    $devices = self::devices();
    if (!$devices) {
      echo '<p>Nothing to export yet - no heartbeats have been received.</p>';
      return;
    }
    $ranges = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '365d' => 'Last 12 months',
               'this_month' => 'This month', 'last_month' => 'Last month', 'all' => 'All time', 'custom' => 'Custom dates…'];
    $today = wp_date('Y-m-d', time());
    ?>
    <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:820px">
      <input type="hidden" name="action" value="netmon_export">
      <?php wp_nonce_field('netmon_export', '_wpnonce', false); ?>
      <h2>What to export</h2>
      <?php $first = true; foreach (self::export_kinds() as $k => $info): ?>
        <label class="netmon-card" style="display:block;margin-bottom:8px;cursor:pointer">
          <input type="radio" name="kind" value="<?php echo esc_attr($k); ?>" <?php checked($first); $first = false; ?>>
          <strong><?php echo esc_html($info[0]); ?></strong>
          <div class="netmon-muted" style="margin:4px 0 0 24px"><?php echo esc_html($info[1]); ?></div>
        </label>
      <?php endforeach; ?>

      <h2>Period</h2>
      <p>
        <select name="range" onchange="document.getElementById('netmon-ex-custom').style.display=this.value==='custom'?'inline':'none'">
          <?php foreach ($ranges as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected($k, '30d', false) . '>' . esc_html($l) . '</option>'; ?>
        </select>
        <span id="netmon-ex-custom" style="display:none">
          from <input type="date" name="from" value="<?php echo esc_attr(wp_date('Y-m-01', time())); ?>">
          to <input type="date" name="to" value="<?php echo esc_attr($today); ?>">
        </span>
      </p>

      <?php if (count($devices) > 1): ?>
        <h2>Device</h2>
        <p><select name="device"><option value="__all">All devices</option>
          <?php foreach ($devices as $d) echo '<option value="' . esc_attr($d) . '">' . esc_html($d) . '</option>'; ?>
        </select></p>
      <?php else: ?>
        <input type="hidden" name="device" value="<?php echo esc_attr($devices[0]); ?>">
      <?php endif; ?>

      <p><button class="button button-primary button-hero">Download CSV</button></p>
      <p class="netmon-muted">Opens in Excel, Google Sheets or Numbers. Times are in <?php echo esc_html(wp_timezone_string()); ?>
        (set under Settings &rarr; General); raw exports also include UTC times.</p>
    </form>
    <?php
  }

  private static function tab_heartbeats() {
    global $wpdb;
    $device = self::current_device();
    $hb = self::hb_table();
    $rows = $device ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $hb WHERE device_id=%s ORDER BY id DESC LIMIT 300", $device)) : [];
    echo '<p>Latest 300 heartbeats for <strong>' . esc_html($device ?: '—') . '</strong>. The ESP32 sends one every minute while the internet is up.</p>';
    if ($device) {
      $csv = wp_nonce_url(add_query_arg(['action' => 'netmon_export', 'kind' => 'heartbeats', 'device' => $device, 'range' => '30d'], admin_url('admin-post.php')), 'netmon_export');
      echo '<p><a class="button" href="' . esc_url($csv) . '">Download last 30 days as CSV</a> <a href="' . esc_url(self::admin_url(['tab' => 'export'])) . '">More export options</a></p>';
    }
    echo '<table class="netmon-table"><thead><tr><th>Time</th><th>Public IP</th><th>Local IP</th><th>Wi-Fi</th><th>Connect</th><th>ESP32 uptime</th><th>Queued</th><th>FW</th><th>Note</th></tr></thead><tbody>';
    $prev = null;
    foreach ($rows as $r) {
      $t = self::ts($r->created_at);
      if ($prev !== null && $prev - $t > self::timeout_s()) {
        echo '<tr class="ongoing"><td colspan="9"><strong>Gap of ' . esc_html(self::fmt_duration($prev - $t)) . '</strong></td></tr>';
      }
      $prev = $t;
      echo '<tr><td>' . esc_html(self::fmt_time($t)) . '</td><td>' . esc_html($r->ip) . '</td><td>' . esc_html($r->local_ip) . '</td><td>'
         . ($r->rssi !== null ? (int) $r->rssi . ' dBm' : '') . '</td><td>' . ($r->latency_ms !== null ? (int) $r->latency_ms . ' ms' : '') . '</td><td>'
         . ($r->uptime_s !== null ? esc_html(self::fmt_duration($r->uptime_s)) : '') . '</td><td>' . esc_html($r->queue_len) . '</td><td>'
         . esc_html($r->fw) . '</td><td>' . esc_html($r->note) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="9">No heartbeats yet.</td></tr>';
    echo '</tbody></table>';
  }

  private static function process_settings() {
    if (empty($_POST['netmon_action'])) return '';
    check_admin_referer('netmon_settings');
    $s = self::settings();
    $action = sanitize_key($_POST['netmon_action']);

    if ($action === 'save') {
      $s['alert_email']               = sanitize_email(wp_unslash(isset($_POST['alert_email']) ? $_POST['alert_email'] : ''));
      $s['alerts_enabled']            = empty($_POST['alerts_enabled']) ? 0 : 1;
      $s['timeout_minutes']           = max(2, min(120, (int) (isset($_POST['timeout_minutes']) ? $_POST['timeout_minutes'] : 5)));
      $s['email_device_outages']      = empty($_POST['email_device_outages']) ? 0 : 1;
      $s['device_outage_min_minutes'] = max(0, min(600, (int) (isset($_POST['device_outage_min_minutes']) ? $_POST['device_outage_min_minutes'] : 2)));
      $s['retention_days']            = max(7, min(3650, (int) (isset($_POST['retention_days']) ? $_POST['retention_days'] : 90)));
      $s['public_report']             = empty($_POST['public_report']) ? 0 : 1;
      self::save_settings($s);
      return '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    if ($action === 'regen') {
      $s['token'] = wp_generate_password(32, false, false);
      self::save_settings($s);
      return '<div class="notice notice-warning"><p>New token generated. Update <code>WP_TOKEN</code> in secrets.h on the ESP32 and re-upload the sketch.</p></div>';
    }
    if ($action === 'test_email') {
      $ok = self::send_mail('[Broadband] Test email', "This is a test from the NetMon Broadband Uptime Monitor on " . home_url() . ".\n");
      return $ok ? '<div class="notice notice-success"><p>Test email sent to ' . esc_html($s['alert_email']) . '.</p></div>'
                 : '<div class="notice notice-error"><p>Test email failed. Check alerts are enabled and that your site can send email (an SMTP plugin is often needed).</p></div>';
    }
    if (in_array($action, ['rebuild_preview', 'rebuild', 'clear'], true)) return self::process_history($action);
    if ($action === 'forget' && !empty($_POST['device'])) {
      $d = self::clean_device(wp_unslash($_POST['device']));
      $state = self::state();
      unset($state[$d]);
      update_option(self::OPT_STATE, $state, false);
      global $wpdb;
      $ev = self::ev_table();
      $wpdb->query($wpdb->prepare("UPDATE $ev SET ended_at=%s WHERE device_id=%s AND ended_at IS NULL", self::dt(time()), $d));
      return '<div class="notice notice-success"><p>Stopped tracking ' . esc_html($d) . '. (Its history is kept; it will reappear if it sends another heartbeat.)</p></div>';
    }
    return '';
  }

  private static function tab_settings() {
    $s = self::settings();
    ?>
    <form method="post">
      <?php wp_nonce_field('netmon_settings'); ?>
      <table class="form-table" role="presentation">
        <tr><th scope="row">Email alerts</th><td>
          <label><input type="checkbox" name="alerts_enabled" value="1" <?php checked($s['alerts_enabled']); ?>> Send email alerts</label><br>
          <input type="email" name="alert_email" value="<?php echo esc_attr($s['alert_email']); ?>" class="regular-text" placeholder="you@example.com">
        </td></tr>
        <tr><th scope="row">"Down" alert after</th><td>
          <input type="number" min="2" max="120" name="timeout_minutes" value="<?php echo (int) $s['timeout_minutes']; ?>" class="small-text"> minutes without a heartbeat
          <p class="description">You get a "DOWN?" email when the ESP32 has been silent this long, and a "Back online" email with exact times when it returns.</p>
        </td></tr>
        <tr><th scope="row">Short outage emails</th><td>
          <label><input type="checkbox" name="email_device_outages" value="1" <?php checked($s['email_device_outages']); ?>> Email me when the ESP32 reports an outage lasting at least</label>
          <input type="number" min="0" max="600" name="device_outage_min_minutes" value="<?php echo (int) $s['device_outage_min_minutes']; ?>" class="small-text"> minutes
          <p class="description">Catches outages too short to trigger the "DOWN?" email. Sent when the ESP32 gets back online.</p>
        </td></tr>
        <tr><th scope="row">Keep heartbeats for</th><td>
          <input type="number" min="7" max="3650" name="retention_days" value="<?php echo (int) $s['retention_days']; ?>" class="small-text"> days
          <p class="description">Raw heartbeats only. Outage records are kept forever.</p>
        </td></tr>
        <tr><th scope="row">Public report</th><td>
          <label><input type="checkbox" name="public_report" value="1" <?php checked($s['public_report']); ?>> Let anyone view the <code>[netmon_report]</code> shortcode</label>
          <p class="description">Off = only administrators see it when logged in.</p>
        </td></tr>
      </table>
      <p><button class="button button-primary" name="netmon_action" value="save">Save settings</button>
         <button class="button" name="netmon_action" value="test_email">Send test email</button></p>

      <h2>ESP32 token</h2>
      <p>The ESP32 must send this token. It's also shown on the ESP32 Setup tab.</p>
      <p><code style="font-size:14px"><?php echo esc_html($s['token']); ?></code></p>
      <p><button class="button" name="netmon_action" value="regen" onclick="return confirm('Generate a new token? The ESP32 will stop being accepted until you update secrets.h.');">Generate new token</button></p>
    </form>

    <h2>Devices</h2>
    <table class="netmon-table" style="max-width:800px"><thead><tr><th>Device</th><th>First seen</th><th>Last heartbeat</th><th></th></tr></thead><tbody>
    <?php foreach (self::state() as $d => $st): ?>
      <tr><td><?php echo esc_html($d); ?></td>
        <td><?php echo esc_html(self::fmt_time(isset($st['first_seen']) ? $st['first_seen'] : 0)); ?></td>
        <td><?php echo esc_html(self::fmt_time(isset($st['last_hb']) ? $st['last_hb'] : 0)); ?></td>
        <td><form method="post" onsubmit="return confirm('Stop tracking this device? Its history is kept.');"><?php wp_nonce_field('netmon_settings'); ?>
          <input type="hidden" name="device" value="<?php echo esc_attr($d); ?>"><button class="button-link-delete" name="netmon_action" value="forget">Stop tracking</button></form></td></tr>
    <?php endforeach; if (!self::state()) echo '<tr><td colspan="4">None yet.</td></tr>'; ?>
    </tbody></table>
    <?php
    echo self::render_history_tools();
  }

  private static function tab_setup() {
    $s = self::settings();
    $base = untrailingslashit(rest_url('netmon/v1'));
    $last = (int) get_option(self::OPT_CRONRUN, 0);
    ?>
    <h2>1. Put these in the ESP32 <code>secrets.h</code></h2>
    <p>In the <code>YouFibreMonitor</code> sketch folder, copy <code>secrets.example.h</code> to a new file called <code>secrets.h</code>, then set these two lines:</p>
    <div class="netmon-code">#define WP_API_BASE      "<?php echo esc_html($base); ?>"
#define WP_TOKEN         "<?php echo esc_html($s['token']); ?>"</div>
    <p>Also set <code>WIFI_SSID</code> and <code>WIFI_PASSWORD</code> in the same file, then upload the sketch from the Arduino IDE. Within a minute the Report tab will show <em>Receiving heartbeats</em>.
       <code>secrets.h</code> is never uploaded to GitHub, so your password and token stay private.</p>
    <p>Full step-by-step guide: <a href="https://github.com/<?php echo esc_attr(self::GITHUB_REPO); ?>#readme" target="_blank">github.com/<?php echo esc_html(self::GITHUB_REPO); ?></a></p>

    <h2>2. Check it's working</h2>
    <ul style="list-style:disc;margin-left:20px">
      <li>Open <a href="<?php echo esc_url($base . '/ping'); ?>" target="_blank"><?php echo esc_html($base . '/ping'); ?></a> - you should see <code>{"ok":true,...}</code>. If not, a security plugin or firewall may be blocking the REST API.</li>
      <li>On a phone or PC on your home network, open <code>http://youfibre-monitor.local</code> (or the ESP32's IP shown in the Arduino Serial Monitor) for the ESP32's own live status page. It shows the last WordPress response, e.g. HTTP 401 means the token is wrong.</li>
      <li>Use <em>Settings &rarr; Send test email</em> to confirm your site can send email.</li>
    </ul>

    <h2 id="cron">3. Make alerts reliable (recommended)</h2>
    <p>WordPress only runs its scheduled tasks when someone visits the site. Outage <em>records</em> don't depend on this, but prompt "DOWN?" emails do.
       Last scheduled check: <strong><?php echo $last ? esc_html(self::fmt_time($last)) . ' (' . esc_html(self::fmt_duration(time() - $last)) . ' ago)' : 'never'; ?></strong>.</p>
    <p>In your hosting control panel (cPanel &rarr; Cron Jobs, or similar) add a job that runs every minute:</p>
    <div class="netmon-code">* * * * * wget -q -O /dev/null "<?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?>"</div>
    <p>Or use a free external service such as cron-job.org to fetch <code><?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?></code> every minute.
       (Tip: the monitor's heartbeats don't come from your home while the internet is down, so an external pinger is what makes the "DOWN?" email arrive on time.)</p>

    <h2>4. Show the report on a page (optional)</h2>
    <p>Add the shortcode <code>[netmon_report]</code> to any page or post. Options: <code>[netmon_report days="90" device="home-esp32-1"]</code>.
       Only administrators can see it unless you tick <em>Public report</em> in Settings.</p>
    <?php
  }

  /* =================================================================
   *  Actions: CSV export, ignore
   * ================================================================= */

  public static function export_kinds() {
    return [
      'outages'    => ['Outage report', 'One row per outage: when it went down, when it came back up, how long, and why. Best for sending to YouFibre.'],
      'daily'      => ['Daily summary', 'One row per day: number of outages, downtime and uptime %, with a total row at the end.'],
      'events'     => ['Raw outage records', 'Every individual record from the ESP32 and from missed heartbeats, before merging. Includes ignored records.'],
      'heartbeats' => ['Raw heartbeats', 'Every heartbeat received (one a minute): public IP, Wi-Fi signal, connect time, ESP32 uptime. Large files.'],
    ];
  }

  /** Stop spreadsheet apps treating text as a formula */
  private static function csv_cell($v) {
    if (is_string($v) && $v !== '' && !is_numeric($v) && strpos('=+-@', $v[0]) !== false) return "'" . $v;
    return $v;
  }

  private static function csv_row($out, $row) { fputcsv($out, array_map([__CLASS__, 'csv_cell'], $row), ',', '"', ''); }

  /** Write a CSV export for the given devices and range to a stream. */
  public static function write_csv($out, $kind, $devices, $from, $to) {
    global $wpdb;
    $t = function ($ts) { return $ts ? self::fmt_time($ts, 'Y-m-d H:i:s') : ''; };

    switch ($kind) {
      case 'daily':
        self::csv_row($out, ['device', 'date', 'day', 'hours_monitored', 'outages', 'downtime_seconds', 'downtime_minutes', 'downtime', 'uptime_percent']);
        foreach ($devices as $device) {
          $rep = self::build_report($device, $from, $to);
          foreach ($rep['days'] as $d) {
            self::csv_row($out, [$device, $d['date']->format('Y-m-d'), $d['date']->format('D'), round($d['monitored'] / 3600, 2), $d['count'],
              $d['down'], round($d['down'] / 60, 2), self::fmt_duration($d['down']),
              $d['monitored'] > 0 ? round(100 * (1 - $d['down'] / $d['monitored']), 4) : '']);
          }
          $real = count(array_filter($rep['incidents'], function ($i) { return !$i['ignored']; }));
          $pct = self::uptime_pct($rep);
          self::csv_row($out, [$device, 'TOTAL', '', round($rep['monitored'] / 3600, 2), $real, $rep['down'], round($rep['down'] / 60, 2),
            self::fmt_duration($rep['down']), $pct === null ? '' : round($pct, 4)]);
        }
        break;

      case 'events':
        $ev = self::ev_table();
        self::csv_row($out, ['device', 'record_id', 'recorded_by', 'type', 'started', 'ended', 'duration_seconds', 'duration', 'router_reachable',
          'failed_checks', 'details', 'ignored', 'emailed', 'started_utc', 'ended_utc']);
        foreach ($devices as $device) {
          $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $ev WHERE device_id=%s AND started_at < %s AND (ended_at IS NULL OR ended_at > %s) ORDER BY started_at",
            $device, self::dt($to), self::dt($from)));
          foreach ($rows as $r) {
            $s = self::ts($r->started_at);
            $e = $r->ended_at ? self::ts($r->ended_at) : 0;
            $dur = ($e ? $e : time()) - $s;
            self::csv_row($out, [$device, $r->id, $r->source === 'device' ? 'ESP32' : 'WordPress (missed heartbeats)', $r->event_type,
              $t($s), $e ? $t($e) : 'still down', $dur, self::fmt_duration($dur),
              $r->gateway_ok === null ? 'unknown' : ((int) $r->gateway_ok ? 'yes' : 'no'),
              $r->failed_checks, trim(self::cause_text($r->event_type, $r->gateway_ok) . ($r->details ? '; ' . $r->details : '')),
              (int) $r->ignored ? 'yes' : 'no', (int) $r->alert_sent ? 'yes' : 'no', $r->started_at, (string) $r->ended_at]);
          }
        }
        break;

      case 'heartbeats':
        $hb = self::hb_table();
        self::csv_row($out, ['device', 'time', 'public_ip', 'local_ip', 'wifi_rssi_dbm', 'connect_ms', 'esp32_uptime_seconds', 'queued_outages',
          'firmware', 'esp32_restarted', 'reset_reason', 'note', 'time_utc']);
        foreach ($devices as $device) {
          $last_id = 0;
          do { // stream in chunks so big ranges don't exhaust memory
            $rows = $wpdb->get_results($wpdb->prepare(
              "SELECT * FROM $hb WHERE device_id=%s AND created_at >= %s AND created_at <= %s AND id > %d ORDER BY id LIMIT 5000",
              $device, self::dt($from), self::dt($to), $last_id));
            foreach ($rows as $r) {
              $last_id = (int) $r->id;
              self::csv_row($out, [$device, $t(self::ts($r->created_at)), $r->ip, $r->local_ip, $r->rssi, $r->latency_ms, $r->uptime_s,
                $r->queue_len, $r->fw, (int) $r->boot ? 'yes' : 'no', $r->reset_reason, $r->note, $r->created_at]);
            }
            if (function_exists('ob_get_level') && ob_get_level() > 0) @ob_flush();
            flush();
          } while (count($rows) === 5000);
        }
        break;

      default: // outages
        self::csv_row($out, ['device', 'went_down', 'came_back_up', 'duration_seconds', 'duration_minutes', 'duration', 'cause', 'details', 'still_down']);
        foreach ($devices as $device) {
          $rep = self::build_report($device, $from, $to);
          foreach ($rep['incidents'] as $i) {
            $causes = array_map(function ($ty) {
              return ['internet' => 'Internet', 'wifi' => 'Wi-Fi', 'silent' => 'Monitor offline'][$ty] ?? $ty;
            }, $i['types']);
            self::csv_row($out, [$device, $t($i['start']), $i['ongoing'] ? 'still down' : $t($i['end']), $i['duration'],
              round($i['duration'] / 60, 2), self::fmt_duration($i['duration']), implode(' + ', $causes), implode(' | ', $i['notes']),
              $i['ongoing'] ? 'yes' : 'no']);
          }
        }
    }
  }

  public static function handle_export() {
    if (!current_user_can('manage_options')) wp_die('Not allowed', 403);
    check_admin_referer('netmon_export');
    $kind = isset($_REQUEST['kind']) ? sanitize_key($_REQUEST['kind']) : 'outages';
    if (!isset(self::export_kinds()[$kind])) $kind = 'outages';
    $dev = isset($_REQUEST['device']) ? wp_unslash($_REQUEST['device']) : '';
    $devices = ($dev === '__all' || $dev === '') ? self::devices() : [self::clean_device($dev)];
    list($from, $to) = self::resolve_range(
      isset($_REQUEST['range']) ? sanitize_key($_REQUEST['range']) : '30d',
      isset($_REQUEST['from']) ? sanitize_text_field(wp_unslash($_REQUEST['from'])) : '',
      isset($_REQUEST['to']) ? sanitize_text_field(wp_unslash($_REQUEST['to'])) : '');
    if ($from <= 0) { // "All time": start at the earliest device
      $state = self::state();
      $firsts = array_filter(array_map(function ($d) use ($state) { return isset($state[$d]['first_seen']) ? (int) $state[$d]['first_seen'] : 0; }, $devices));
      $from = $firsts ? min($firsts) : $to - 86400;
    }

    if (function_exists('set_time_limit')) @set_time_limit(300);
    while (ob_get_level() > 0) ob_end_clean();
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    $name = 'broadband-' . $kind . '-' . (count($devices) === 1 ? $devices[0] : 'all-devices') . '-' . wp_date('Y-m-d', $from) . '-to-' . wp_date('Y-m-d', $to) . '.csv';
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows characters correctly
    self::write_csv($out, $kind, $devices, $from, $to);
    fclose($out);
    exit;
  }

  public static function handle_ignore() {
    if (!current_user_can('manage_options')) wp_die('Not allowed', 403);
    check_admin_referer('netmon_ignore');
    global $wpdb;
    $ids = array_filter(array_map('intval', explode(',', isset($_GET['ids']) ? (string) wp_unslash($_GET['ids']) : '')));
    if ($ids) {
      $ev = self::ev_table();
      $val = empty($_GET['undo']) ? 1 : 0;
      $wpdb->query("UPDATE $ev SET ignored=$val WHERE id IN (" . implode(',', $ids) . ')');
    }
    $back = isset($_GET['back']) ? rawurldecode(wp_unslash($_GET['back'])) : '';
    wp_safe_redirect($back ? $back : self::admin_url());
    exit;
  }

  /* =================================================================
   *  Dashboard widget & shortcode
   * ================================================================= */

  public static function dashboard_widget() {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget('netmon_widget', 'Broadband Uptime', function () {
      $devices = self::devices();
      echo self::css() . self::render_rejection_notice();
      if (!$devices) { echo '<p>No heartbeats yet. <a href="' . esc_url(self::admin_url(['tab' => 'setup'])) . '">Set up the ESP32</a>.</p>'; return; }
      foreach ($devices as $d) {
        echo self::render_status($d);
        list($f, $t) = self::resolve_range('30d');
        $rep = self::build_report($d, $f, $t);
        $real = array_values(array_filter($rep['incidents'], function ($i) { return !$i['ignored']; }));
        echo '<p><strong>30-day uptime: ' . esc_html(self::fmt_pct(self::uptime_pct($rep))) . '</strong> · ' . count($real) . ' outage(s), '
           . esc_html(self::fmt_duration($rep['down'])) . ' total</p>';
        if ($real) {
          $l = end($real);
          echo '<p>Last outage: ' . esc_html(self::fmt_time($l['start'])) . ' for ' . esc_html(self::fmt_duration($l['duration'])) . '</p>';
        }
      }
      echo '<p><a class="button" href="' . esc_url(self::admin_url()) . '">Full report</a></p>';
      $new = self::update_available();
      echo '<p class="netmon-muted" style="border-top:1px solid #f0f0f1;padding-top:8px;margin-bottom:0">NetMon plugin <strong>v' . esc_html(self::VERSION) . '</strong> &middot; '
         . ($new ? '<a href="' . esc_url(self::admin_url(['tab' => 'about'])) . '"><strong>Update available: v' . esc_html($new['version']) . '</strong></a>'
                 : '<a href="' . esc_url(self::admin_url(['tab' => 'about'])) . '">Check for updates</a>') . '</p>';
    });
  }

  public static function shortcode($atts) {
    $atts = shortcode_atts(['days' => 30, 'device' => ''], $atts, 'netmon_report');
    $s = self::settings();
    if (empty($s['public_report']) && !current_user_can('manage_options')) {
      return '<p>This broadband report is private.</p>';
    }
    $devices = self::devices();
    $device = $atts['device'] ? self::clean_device($atts['device']) : ($devices ? $devices[0] : '');
    if (!$device) return '<p>No broadband data yet.</p>';
    $days = max(1, min(365, (int) $atts['days']));
    $rep = self::build_report($device, time() - $days * 86400, time());
    return self::css() . '<div class="netmon-wrap">' . self::render_status($device)
      . '<h3>Last ' . $days . ' days</h3>' . self::render_report_body($rep, false) . '</div>';
  }
}

NetMon::boot();
