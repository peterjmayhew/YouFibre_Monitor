<?php
// Test harness for the NetMon WordPress plugin - no WordPress or database needed.
// Minimal WordPress stubs + an in-memory fake $wpdb exercise the real plugin code:
// heartbeats, silence detection, emails, report merging, CSV export, the live
// panel, history tools and the GitHub updater (with a mocked GitHub API).
//
//   php tests/harness.php wordpress-plugin/netmon-heartbeat/netmon-heartbeat.php
//
// Prints PASS/FAIL per check and "ALL PASSED" at the end. It also writes
// *-preview.html files here (git-ignored) that you can open to eyeball the pages.
// When the plugin gains a new SQL query, teach FakeWpdb to answer it.
define('ABSPATH', __DIR__ . '/');
date_default_timezone_set('UTC');
$GLOBALS['opts'] = ['admin_email' => 'me@example.com', 'netmon_db_version' => '2', 'timezone_string' => 'Europe/London'];
$GLOBALS['mails'] = [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_generate_password($n) { return str_repeat('t', $n); }
function wp_timezone() { return new DateTimeZone('Europe/London'); }
function wp_timezone_string() { return 'Europe/London'; }
function wp_date($f, $ts) { return (new DateTime('@' . $ts))->setTimezone(wp_timezone())->format($f); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL) ?: ''; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function wp_mail($to, $sub, $body) { $GLOBALS['mails'][] = [$sub, $body]; return true; }
function home_url($p = '') { return 'https://example.co.uk' . $p; }
function site_url($p = '') { return 'https://example.co.uk/' . $p; }
function admin_url($p = '') { return 'https://example.co.uk/wp-admin/' . $p; }
function rest_url($p = '') { return 'https://example.co.uk/wp-json/' . $p; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function add_query_arg($a, $url = '') { if (!is_array($a)) return ''; return $url . (strpos($url, '?') ? '&' : '?') . http_build_query($a); }
function wp_list_pluck($l, $f) { return array_map(function ($o) use ($f) { return is_object($o) ? $o->$f : $o[$f]; }, $l); }
function wp_unslash($s) { return $s; }
function wp_nonce_url($u) { return $u . '&_wpnonce=x'; }
function selected($a, $b, $e = true) { return $a == $b ? ' selected' : ''; }
function checked($a) { if ($a) echo ' checked'; }
function current_user_can() { return true; }
function wp_json_encode($v) { return json_encode($v); }
function check_ajax_referer() { return true; }
function wp_send_json_success($d) { $GLOBALS['ajax'] = $d; }
function wp_send_json_error($d) { $GLOBALS['ajax'] = null; }
class FakeBar { public $nodes = []; function add_node($n) { $this->nodes[] = $n; } }
function check_admin_referer() { return true; }
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600);
$GLOBALS['st'] = []; $GLOBALS['gh'] = ['code' => 404, 'body' => '{"message":"Not Found"}']; $GLOBALS['gh_calls'] = 0;
function get_site_transient($k) { return $GLOBALS['st'][$k] ?? false; }
function set_site_transient($k, $v, $e = 0) { $GLOBALS['st'][$k] = $v; return true; }
function delete_site_transient($k) { unset($GLOBALS['st'][$k]); return true; }
function wp_remote_get($u, $a = []) { $GLOBALS['gh_calls']++; return $GLOBALS['gh']; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function self_admin_url($p = '') { return 'https://example.co.uk/wp-admin/' . $p; }
function wp_update_plugins() {}
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_nonce_field() { echo "<input type=hidden>"; }
function shortcode_atts($d, $a) { return array_merge($d, (array) $a); }
function register_activation_hook() {} function register_deactivation_hook() {}
function add_action() {} function add_filter() {} function add_shortcode() {}
function plugin_basename($f) { return basename($f); }
function wp_next_scheduled() { return 1; }
function register_rest_route() {}
class WP_Error { public $code, $msg, $data; function __construct($c, $m, $d) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_message() { return $this->msg; } }
class WP_REST_Response { public $data, $status; function __construct($d, $s) { $this->data = $d; $this->status = $s; } }
class WP_REST_Request { public $h, $b; function __construct($h, $b) { $this->h = $h; $this->b = $b; }
  function get_header($k) { return isset($this->h[$k]) ? $this->h[$k] : null; } function get_json_params() { return $this->b; } }

class FakeWpdb {
  public $prefix = 'wp_', $rows_affected = 0, $insert_id = 0, $args = [], $t = ['wp_netmon_heartbeats' => [], 'wp_netmon_events' => []], $id = 0;
  function get_charset_collate() { return ''; }
  function prepare($sql, ...$a) { $this->args = $a; return $sql; }
  function insert($table, $data) { $data['id'] = ++$this->id; $this->t[$table][$this->id] = (object) $data; $this->insert_id = $this->id; return 1; }
  function update($table, $data, $where) { foreach ($data as $k => $v) $this->t[$table][$where['id']]->$k = $v; return 1; }
  private function ev() { return $this->t['wp_netmon_events']; }
  private function add_ev($row) {
    foreach ($this->ev() as $r) if ($r->device_id === $row['device_id'] && $r->uid === $row['uid']) { $this->rows_affected = 0; return; }
    $row += ['gateway_ok' => null, 'failed_checks' => null, 'details' => '', 'alert_sent' => 0, 'ignored' => 0, 'ended_at' => null];
    $this->insert('wp_netmon_events', $row); $this->rows_affected = 1;
  }
  function query($sql) {
    $a = $this->args; $this->args = [];
    if (strpos($sql, "'device', %s") !== false) {
      preg_match("/%s, %s, (NULL|\d)/", $sql, $m);
      $this->add_ev(['device_id' => $a[0], 'uid' => $a[1], 'source' => 'device', 'event_type' => $a[2], 'started_at' => $a[3], 'ended_at' => $a[4],
        'gateway_ok' => $m[1] === 'NULL' ? null : $m[1], 'failed_checks' => $a[5], 'details' => $a[6], 'created_at' => $a[7]]);
    } elseif (strpos($sql, "'server', 'silent', %s, NULL") !== false) {
      $this->add_ev(['device_id' => $a[0], 'uid' => $a[1], 'source' => 'server', 'event_type' => 'silent', 'started_at' => $a[2], 'created_at' => $a[3]]);
    } elseif (strpos($sql, "'server', 'silent', %s, %s") !== false) {
      $this->add_ev(['device_id' => $a[0], 'uid' => $a[1], 'source' => 'server', 'event_type' => 'silent', 'started_at' => $a[2], 'ended_at' => $a[3], 'details' => $a[4], 'created_at' => $a[5]]);
    } elseif (preg_match('/^DELETE FROM (\w+)(?: WHERE (.*))?$/s', $sql, $m)) {
      $conds = isset($m[2]) ? explode(' AND ', $m[2]) : []; $n = 0; $i = 0; $checks = [];
      foreach ($conds as $c) { preg_match('/(\w+)\s*(=|<)\s*%s/', $c, $cm); $checks[] = [$cm[1], $cm[2], $a[$i++]]; }
      foreach ($this->t[$m[1]] as $id => $r) {
        $ok = true; foreach ($checks as $ch) { $v = $r->{$ch[0]}; if ($ch[1] === '=' ? $v !== $ch[2] : !($v < $ch[2])) $ok = false; }
        if ($ok) { unset($this->t[$m[1]][$id]); $n++; }
      }
      $this->rows_affected = $n; return $n;
    } elseif (preg_match('/SET (alert_sent|ignored)=(\d) WHERE id IN \(([\d,]+)\)/', $sql, $m)) {
      foreach (explode(',', $m[3]) as $id) $this->t['wp_netmon_events'][(int) $id]->{$m[1]} = $m[2];
    } else { echo "UNHANDLED QUERY: $sql\n"; }
  }
  function get_row($sql) {
    $a = $this->args;
    $c = array_filter($this->ev(), function ($r) use ($a) { return $r->device_id === $a[0] && $r->source === 'server' && $r->ended_at === null; });
    krsort($c); return $c ? reset($c) : null;
  }
  function get_col($sql) {
    $a = $this->args; $this->args = [];
    $r = array_filter($this->t['wp_netmon_heartbeats'], function ($r) use ($a) { return $r->device_id === $a[0] && $r->created_at >= $a[1]; });
    return array_values(array_map(function ($r) { return $r->created_at; }, $r));
  }
  function get_var($sql) {
    $a = $this->args;
    return count(array_filter($this->ev(), function ($r) use ($a) { return $r->device_id === $a[0] && $r->source === 'server' && $r->ended_at === null && $r->alert_sent == 1; }));
  }
  function get_results($sql) {
    $a = $this->args; $this->args = [];
    if (preg_match('/WHERE id IN \(([\d,]+)\)/', $sql, $m)) {
      $ids = array_map('intval', explode(',', $m[1]));
      $r = array_values(array_filter($this->ev(), function ($r) use ($ids) { return in_array($r->id, $ids); }));
    } elseif (strpos($sql, "source='device' AND ignored=0") !== false) {
      $r = array_values(array_filter($this->ev(), function ($r) use ($a) { return $r->device_id === $a[0] && $r->source === 'device' && !$r->ignored && $r->started_at < $a[1] && $r->ended_at > $a[2]; }));
    } elseif (strpos($sql, '(ended_at IS NULL OR ended_at >') !== false) {
      $r = array_values(array_filter($this->ev(), function ($r) use ($a) { return $r->device_id === $a[0] && $r->started_at < $a[1] && ($r->ended_at === null || $r->ended_at > $a[2]); }));
    } elseif (strpos($sql, 'AND id > %d ORDER BY id LIMIT 5000') !== false && count($a) === 2) {
      $r = array_values(array_filter($this->t['wp_netmon_heartbeats'], function ($r) use ($a) { return $r->device_id === $a[0] && $r->id > $a[1]; }));
      usort($r, function ($x, $y) { return $x->id - $y->id; });
      return array_slice($r, 0, 5000);
    } elseif (strpos($sql, 'FROM wp_netmon_heartbeats') !== false) {
      $r = array_values(array_filter($this->t['wp_netmon_heartbeats'], function ($r) use ($a) { return $r->device_id === $a[0] && $r->created_at >= $a[1] && $r->created_at <= $a[2] && $r->id > $a[3]; }));
      return array_slice($r, 0, 5000);
    } else { echo "UNHANDLED SELECT: $sql\n"; return []; }
    usort($r, function ($x, $y) { return strcmp($x->started_at, $y->started_at); });
    return $r;
  }
}
$wpdb = new FakeWpdb();
require $argv[1];

function check($cond, $msg) { echo ($cond ? "PASS  " : "FAIL  ") . $msg . "\n"; if (!$cond) $GLOBALS['failed'] = true; }
function hb($extra = []) {
  return NetMon::rest_heartbeat(new WP_REST_Request(['x-netmon-token' => str_repeat('t', 32)], array_merge(['device_id' => 'home-esp32-1', 'rssi' => -60, 'latency_ms' => 12, 'uptime' => 100, 'device_now' => time()], $extra)));
}
function events($evs) {
  return NetMon::rest_events(new WP_REST_Request([], ['device_id' => 'home-esp32-1', 'token' => str_repeat('t', 32), 'device_now' => time(), 'events' => $evs]));
}
$now = time();
$tok = NetMon::settings()['token'];

// --- auth
check(NetMon::rest_auth(new WP_REST_Request(['x-netmon-token' => 'bad'], [])) instanceof WP_Error, 'bad token rejected');
check(NetMon::rest_auth(new WP_REST_Request([], ['token' => $tok])) === true, 'token accepted from JSON body');

// --- Scenario A: internet outage long enough for the DOWN email
hb(); // first heartbeat, registers device
$st = get_option('netmon_state'); $st['home-esp32-1']['last_hb'] = $now - 1200; $st['home-esp32-1']['first_seen'] = $now - 10 * 86400; update_option('netmon_state', $st);
NetMon::check_devices();
check(count($mails) === 1 && strpos($mails[0][0], 'DOWN?') !== false, 'DOWN email sent after silence: ' . ($mails[0][0] ?? ''));
NetMon::check_devices();
check(count($mails) === 1, 'no duplicate DOWN email');
$r = events([['uid' => 'aaaa1111', 'type' => 'internet', 'start' => $now - 1150, 'end' => $now - 30, 'gw' => 1, 'fails' => 100]]);
check($r->data['stored'] === 1, 'device event stored');
check(count($mails) === 1, 'no separate outage email while DOWN alert is open');
$r = events([['uid' => 'aaaa1111', 'type' => 'internet', 'start' => $now - 1150, 'end' => $now - 30, 'gw' => 1, 'fails' => 100]]);
check($r->data['duplicates'] === 1, 'retry upload is de-duplicated');
hb();
check(count($mails) === 2 && strpos($mails[1][0], 'Back online') !== false, 'recovery email: ' . ($mails[1][0] ?? ''));
check(strpos($mails[1][1], 'YouFibre side') !== false, 'recovery email includes device-recorded cause');
$rep = NetMon::build_report('home-esp32-1', $now - 86400, $now + 5);
check(count($rep['incidents']) === 1, 'server silence absorbed into device outage -> 1 incident');
check($rep['incidents'][0]['duration'] === 1120, 'incident uses exact device times (1120 s): ' . $rep['incidents'][0]['duration']);

// --- Scenario B: power cut (long silence, device only saw Wi-Fi missing briefly at boot)
$wpdb->prepare('', 'home-esp32-1', 'srv-' . ($now - 7200), NetMon_dt($now - 7200), NetMon_dt($now - 3600), 'ESP32 restarted (power-on) - probably a power cut', NetMon_dt($now));
$wpdb->query("INSERT IGNORE INTO wp_netmon_events (...) VALUES (%s, %s, 'server', 'silent', %s, %s, %s, 0, 0, %s)");
$wpdb->prepare('', 'home-esp32-1', 'dev-bbbb', 'wifi', NetMon_dt($now - 3700), NetMon_dt($now - 3590), 5, '', NetMon_dt($now));
$wpdb->query("INSERT IGNORE ... VALUES (%s, %s, 'device', %s, %s, %s, NULL, %d, %s, 0, 0, %s)");
$rep = NetMon::build_report('home-esp32-1', $now - 86400, $now + 5);
check(count($rep['incidents']) === 2, 'power cut kept as its own incident');
$pc = $rep['incidents'][0];
check($pc['start'] === $now - 7200 && $pc['end'] === $now - 3590, 'power cut merged with boot Wi-Fi gap: ' . NetMon::fmt_duration($pc['duration']));
check(in_array('silent', $pc['types']) && in_array('wifi', $pc['types']), 'incident shows both causes');
check(abs(NetMon::uptime_pct($rep) - 100 * (1 - (3610 + 1120) / 86400)) < 0.0001, 'uptime % = ' . NetMon::uptime_pct($rep));

// --- Scenario C: short outage, no DOWN email -> after-the-fact outage email
$mails = [];
events([['uid' => 'cccc', 'type' => 'internet', 'start' => $now - 400, 'end' => $now - 200, 'gw' => 2, 'fails' => 20]]);
check(count($mails) === 1 && strpos($mails[0][0], 'Outage: internet was down for 3m 20s') !== false, 'short outage email: ' . ($mails[0][0] ?? ''));
events([['uid' => 'dddd', 'type' => 'internet', 'start' => $now - 190, 'end' => $now - 150, 'gw' => 1, 'fails' => 4]]);
check(count($mails) === 1, 'outage below 2-minute threshold does not email');

// --- Scenario D: cron never ran; heartbeat after a gap creates closed silence + email
$mails = [];
$st = get_option('netmon_state'); $st['home-esp32-1']['last_hb'] = $now - 900; update_option('netmon_state', $st);
hb(['boot' => true, 'reset_reason' => 'power-on']);
check(count($mails) === 1 && strpos($mails[0][1], 'power cut') !== false, 'missed-cron gap detected on heartbeat with power-cut note');

// --- Validation
$r = events([['uid' => 'x', 'type' => 'bogus', 'start' => $now - 10, 'end' => $now], ['uid' => 'y', 'type' => 'internet', 'start' => $now, 'end' => $now - 10]]);
check($r->data['rejected'] === 2, 'invalid events rejected');

// --- Clock skew correction
$r = NetMon::rest_events(new WP_REST_Request(['x-netmon-token' => $tok], ['device_id' => 'home-esp32-1', 'device_now' => $now - 3600,
  'events' => [['uid' => 'skew', 'type' => 'internet', 'start' => $now - 3600 - 100, 'end' => $now - 3600 - 50]]]));
$sk = null; foreach ($wpdb->t['wp_netmon_events'] as $e) if ($e->uid === 'dev-skew') $sk = $e;
check($sk && $sk->started_at === gmdate('Y-m-d H:i:s', $now - 100), 'device clock 1h slow is corrected');

// --- Ranges, rendering
list($f, $t, $l) = NetMon::resolve_range('last_month');
check($t - $f >= 28 * 86400 - 3600 && $t - $f <= 31 * 86400 + 3600, "last_month range: $l");
list($f, $t, $l) = NetMon::resolve_range('custom', '2026-09-01', '2026-09-10');
check($l === '1 Sep 2026 – 10 Sep 2026', "custom range label: $l");
$_GET = ['page' => 'netmon'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'When it went down and came back up') !== false && strpos($html, 'Still down') === false, 'admin report renders');
ob_start(); $_GET['tab'] = 'setup'; NetMon::admin_page(); $h2 = ob_get_clean();
check(strpos($h2, 'https://example.co.uk/wp-json/netmon/v1') !== false, 'setup tab shows API base');
ob_start(); $_GET['tab'] = 'settings'; NetMon::admin_page(); ob_get_clean();
$sc = NetMon::shortcode([]);
check(strpos($sc, 'netmon-strip') !== false, 'shortcode renders');
file_put_contents(__DIR__ . '/report-preview.html', '<html><body style="font-family:sans-serif;background:#f0f0f1;padding:20px">' . $html . '</body></html>');

// --- Ongoing outage shows as "Still down"
$st = get_option('netmon_state'); $st['home-esp32-1']['last_hb'] = time() - 700; update_option('netmon_state', $st);
$_GET = ['page' => 'netmon'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'Still down') !== false && strpos($html, 'NO SIGNAL from the ESP32') !== false, 'ongoing outage displayed');

// --- CSV exports
function csv($kind, $range = '30d') {
  list($f, $t) = NetMon::resolve_range($range);
  if ($f <= 0) $f = time() - 20 * 86400;
  $h = fopen('php://memory', 'w+'); NetMon::write_csv($h, $kind, ['home-esp32-1'], $f, $t); rewind($h);
  $rows = []; while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) $rows[] = $r; fclose($h); return $rows;
}
$o = csv('outages');
check($o[0][1] === 'went_down' && count($o) >= 3, 'outages CSV: ' . (count($o) - 1) . ' rows, first: ' . implode(' | ', array_slice($o[1], 1, 7)));
check(in_array('still down', array_column($o, 2)), 'outages CSV marks ongoing outage as still down');
$d = csv('daily');
$tot = end($d);
check($d[0][1] === 'date' && $tot[1] === 'TOTAL' && count($d) >= 12 && count($d) <= 14, 'daily CSV has a row per day + TOTAL: ' . implode(',', $tot));
$e = csv('events');
check(count($e) - 1 === count(array_filter($wpdb->t['wp_netmon_events'], function ($r) { return $r->started_at > gmdate('Y-m-d', time() - 31 * 86400); })), 'events CSV has every raw record (' . (count($e) - 1) . ')');
check(in_array('ESP32', array_column($e, 2)) && in_array('WordPress (missed heartbeats)', array_column($e, 2)), 'events CSV shows who recorded each');
$hbs = csv('heartbeats');
check(count($hbs) - 1 === count($wpdb->t['wp_netmon_heartbeats']) && $hbs[0][1] === 'time', 'heartbeats CSV has every heartbeat (' . (count($hbs) - 1) . ')');
$m = new ReflectionMethod('NetMon', 'csv_cell'); $m->setAccessible(true);
check($m->invoke(null, '=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && $m->invoke(null, '-60') === '-60', 'formula injection guarded, negative numbers untouched');
check(count(csv('outages', 'all')) >= 3, 'all-time range works');
$_GET = ['page' => 'netmon', 'tab' => 'export'];
ob_start(); NetMon::admin_page(); $ex = ob_get_clean();
check(strpos($ex, 'name="kind" value="daily"') !== false && strpos($ex, 'All time') !== false, 'export tab renders');
file_put_contents(__DIR__ . '/export-preview.html', '<html><body style="font-family:sans-serif;background:#f0f0f1;padding:20px">' . $ex . '</body></html>');
file_put_contents(__DIR__ . '/sample-outages.csv', implode("
", array_map(function ($r) { return implode(',', $r); }, $o)));

// --- Connection panel
$wpdb->t['wp_netmon_heartbeats'] = []; $GLOBALS['opts']['netmon_state'] = []; $wpdb->t['wp_netmon_events'] = [];
$p = NetMon::render_connection_panel('');
check(strpos($p, 'Waiting for the first heartbeat') !== false, 'panel: waiting state before any heartbeat');
NetMon::rest_auth(new WP_REST_Request(['x-netmon-token' => 'wrong'], ['device_id' => 'home-esp32-1']));
$p = NetMon::render_connection_panel('');
check(strpos($p, 'tried to connect but was turned away') !== false && strpos($p, 'does not match') !== false, 'panel: explains rejected token');
$bar = new FakeBar(); NetMon::admin_bar($bar);
check(strpos($bar->nodes[0]['title'], 'ESP32 rejected') !== false, 'admin bar: ESP32 rejected');
// an hour of heartbeats every 61 s (drift) -> no false "missing" blocks
$now = time();
for ($t = $now - 3700; $t <= $now - 5; $t += 61) $wpdb->insert('wp_netmon_heartbeats', ['created_at' => gmdate('Y-m-d H:i:s', $t), 'device_id' => 'home-esp32-1', 'boot' => 0]);
$GLOBALS['opts']['netmon_state'] = ['home-esp32-1' => ['first_seen' => $now - 3700, 'last_hb' => $now - 5, 'rssi' => -58, 'latency_ms' => 14, 'uptime' => 3700, 'ip' => '81.2.3.4', 'local_ip' => '192.168.1.50']];
$GLOBALS['opts']['netmon_last_rejected']['time'] = $now - 100; // rejected earlier, working since
$p = NetMon::render_connection_panel('home-esp32-1');
check(strpos($p, 'Receiving heartbeats from the ESP32') !== false, 'panel: receiving state');
check(substr_count($p, 'class="miss"') === 0 && substr_count($p, 'class="ok"') === 60, 'panel: 60 green minute blocks despite 61 s drift (miss=' . substr_count($p, 'class="miss"') . ')');
check(preg_match('/>(\d+) <span class="netmon-muted"[^>]*>of ~60 expected/', $p, $m) && $m[1] >= 58, 'panel: heartbeat count ' . ($m[1] ?? '?') . ' of ~60');
check(strpos($p, 'tried to connect') === false, 'panel: rejection notice cleared after success');
check(strpos($p, 'http://192.168.1.50/') !== false && strpos($p, 'excellent') !== false, 'panel: shows ESP32 page link and signal quality');
$bar = new FakeBar(); NetMon::admin_bar($bar);
check(strpos($bar->nodes[0]['title'], 'Online') !== false, 'admin bar: Online');
$_GET = ['device' => 'home-esp32-1']; NetMon::ajax_live();
check(!empty($ajax['html']) && strpos($ajax['html'], 'netmon-live') !== false, 'ajax live refresh returns panel');
// ESP32 goes silent for 15 minutes
$GLOBALS['opts']['netmon_state']['home-esp32-1']['last_hb'] = $now - 900;
$wpdb->t['wp_netmon_heartbeats'] = array_filter($wpdb->t['wp_netmon_heartbeats'], function ($r) use ($now) { return $r->created_at <= gmdate('Y-m-d H:i:s', $now - 900); });
$p = NetMon::render_connection_panel('home-esp32-1');
check(strpos($p, 'NO SIGNAL from the ESP32') !== false && substr_count($p, 'class="miss"') >= 13, 'panel: NO SIGNAL with red blocks (' . substr_count($p, 'class="miss"') . ')');
$bar = new FakeBar(); NetMon::admin_bar($bar);
check(strpos($bar->nodes[0]['title'], 'NO SIGNAL') !== false, 'admin bar: NO SIGNAL');
$GLOBALS['opts']['netmon_state']['home-esp32-1']['last_hb'] = $now - 5;
$_GET = ['page' => 'netmon']; ob_start(); NetMon::admin_page(); $html = ob_get_clean();
file_put_contents(__DIR__ . '/panel-preview.html', '<html><body style="font-family:-apple-system,Segoe UI,sans-serif;background:#f0f0f1;padding:20px;font-size:13px">' . $html . '</body></html>');

// --- History tools
$wpdb->t['wp_netmon_heartbeats'] = []; $wpdb->t['wp_netmon_events'] = [];
$GLOBALS['opts']['netmon_state'] = ['home-esp32-1' => ['last_hb' => time()]];
$now = time(); $start = $now - 3 * 86400; $t = $start;
$gapsAt = [$start + 3600 => 20 * 60, $start + 7200 => 3 * 60, $start + 86400 => 2 * 3600];
while ($t < $now) {
  $wpdb->insert('wp_netmon_heartbeats', ['created_at' => gmdate('Y-m-d H:i:s', $t), 'device_id' => 'home-esp32-1', 'boot' => 0, 'reset_reason' => '']);
  $t += 60; foreach ($gapsAt as $at => $len) if ($t >= $at && $t < $at + 60) $t += $len;
}
$r = NetMon::rebuild_history('home-esp32-1', 300, false);
check(count($r['gaps']) === 2 && count($wpdb->t['wp_netmon_events']) === 0, 'preview finds 2 gaps (>5 min), writes nothing');
check($r['median'] === 60, 'preview reports normal interval 60 s');
$mailsBefore = count($mails);
$r = NetMon::rebuild_history('home-esp32-1', 300, true);
check($r['added'] === 2, 'rebuild adds 2 outages');
$r = NetMon::rebuild_history('home-esp32-1', 300, true);
check($r['added'] === 0 && $r['already'] === 2, 'second rebuild skips existing (no duplicates)');
$st = get_option('netmon_state');
check($st['home-esp32-1']['first_seen'] === $start, 'monitoring start set to first heartbeat');
$rep = NetMon::build_report('home-esp32-1', $now - 30 * 86400, $now);
$durs = array_map(function ($i) { return $i['duration']; }, $rep['incidents']);
check($durs === [20 * 60 + 60, 2 * 3600 + 60], 'report shows rebuilt outages: ' . implode(',', $durs) . ' s');
check(count($mails) === $mailsBefore, 'no emails for rebuilt history (' . (count($mails) - $mailsBefore) . ' sent)');
// warning when threshold is too small for a slow old firmware
$wpdb->t['wp_netmon_heartbeats'] = [];
for ($t = $now - 86400; $t < $now; $t += 300) $wpdb->insert('wp_netmon_heartbeats', ['created_at' => gmdate('Y-m-d H:i:s', $t), 'device_id' => 'home-esp32-1', 'boot' => 0, 'reset_reason' => '']);
$_POST = ['netmon_action' => 'rebuild_preview', 'hist_device' => '__all', 'hist_min_gap' => '5'];
$_GET = ['page' => 'netmon', 'tab' => 'settings'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'Preview only') !== false && strpos($html, 'will flag normal gaps') !== false, 'preview warns when heartbeats were 5 min apart');
check(strpos($html, 'History tools') !== false && strpos($html, 'Clear history') !== false, 'settings tab shows history tools');
file_put_contents(__DIR__ . '/history-preview.html', '<html><body style="font-family:-apple-system,Segoe UI,sans-serif;background:#f0f0f1;padding:20px;font-size:13px">' . $html . '</body></html>');
// clear: refuses without confirmation
$evBefore = count($wpdb->t['wp_netmon_events']);
$_POST = ['netmon_action' => 'clear', 'hist_what' => 'events', 'hist_device' => '__all'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'Nothing deleted') !== false && count($wpdb->t['wp_netmon_events']) === $evBefore, 'clear refuses without confirmation tick');
// clear outage records only, before a date
$cut = (new DateTimeImmutable('@' . ($start + 43200)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d');
$_POST = ['netmon_action' => 'clear', 'hist_what' => 'events', 'hist_device' => '__all', 'hist_confirm' => '1', 'hist_before' => $cut];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(count($wpdb->t['wp_netmon_events']) === 1 && count($wpdb->t['wp_netmon_heartbeats']) > 0, 'clear outages before date: 1 of 2 outages left, heartbeats kept');
// clear everything
$_POST = ['netmon_action' => 'clear', 'hist_what' => 'all', 'hist_device' => '__all', 'hist_confirm' => '1'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
$st = get_option('netmon_state');
check(count($wpdb->t['wp_netmon_events']) === 0 && count($wpdb->t['wp_netmon_heartbeats']) === 0, 'clear everything empties both tables');
check(abs($st['home-esp32-1']['first_seen'] - time()) < 5 && !empty($st['home-esp32-1']['last_hb']), 'uptime restarts from now; device still tracked');
$_POST = [];

// --- GitHub updates
function gh_release($tag, $asset = true, $code = 200) {
  $GLOBALS['st'] = [];
  $GLOBALS['gh'] = ['code' => $code, 'body' => json_encode($code === 200 ? ['tag_name' => $tag, 'html_url' => 'https://github.com/x/releases/tag/' . $tag,
    'published_at' => '2026-10-01T10:00:00Z', 'body' => "## What's new
- **Faster** reports
- Fix [bug](https://example.com)",
    'assets' => $asset ? [['name' => 'netmon-heartbeat.zip', 'browser_download_url' => 'https://github.com/x/releases/download/' . $tag . '/netmon-heartbeat.zip']] : []] : ['message' => 'Not Found'])];
}
$file = plugin_basename('netmon-heartbeat.php');
gh_release('v9.9.0');
$u = NetMon::update_available();
check($u && $u['version'] === '9.9.0' && strpos($u['package'], 'netmon-heartbeat.zip') !== false, 'newer GitHub release detected');
$chk = NetMon::github_update_check(false, [], 'netmon-heartbeat.php', []);
check(is_array($chk) && $chk['version'] === '9.9.0' && $chk['package'], 'WordPress update filter returns the release package');
check(NetMon::github_update_check(false, [], 'other/plugin.php', []) === false, 'update filter ignores other plugins');
$calls = $GLOBALS['gh_calls']; NetMon::update_available(); NetMon::update_available();
check($GLOBALS['gh_calls'] === $calls, 'GitHub response is cached (no repeat API calls)');
$_POST = []; $_GET = ['page' => 'netmon', 'tab' => 'about'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'Update now to v9.9.0') !== false && strpos($html, 'action=upgrade-plugin') !== false, 'About tab: Update now button');
check(strpos($html, 'Update available: v9.9.0') !== false && strpos($html, '>v2.4.0<') !== false, 'header shows version and update badge');
check(strpos($html, '<strong>Faster</strong>') !== false && strpos($html, '<li>') !== false, 'release notes rendered');
check(strpos($html, 'Peter Mayhew') !== false && strpos($html, '/issues/new') !== false, 'contact / suggestions links');
check(isset($GLOBALS['st']['update_plugins']->response['netmon-heartbeat.php']), 'update registered with WordPress so Update now works');
$api = NetMon::plugins_api(false, 'plugin_information', (object) ['slug' => 'netmon-heartbeat']);
check(is_object($api) && $api->version === '9.9.0' && strpos($api->sections['changelog'], '<li>') !== false, 'View details popup data');
file_put_contents(__DIR__ . '/about-preview.html', '<html><body style="font-family:-apple-system,Segoe UI,sans-serif;background:#f0f0f1;padding:20px;font-size:13px">' . $html . '</body></html>');
gh_release('v2.4.0');
$_POST = ['netmon_action' => 'check_updates'];
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, "up to date") !== false && strpos($html, 'Update now') === false, 'same version: up to date');
gh_release('', true, 404);
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'No releases have been published') !== false, '404: explains no releases yet');
gh_release('v9.9.1', false);
ob_start(); NetMon::admin_page(); $html = ob_get_clean();
check(strpos($html, 'has no netmon-heartbeat.zip') !== false && NetMon::update_available() === null, 'release without zip is not offered');
$GLOBALS['gh'] = new WP_Error('http', 'timeout', []); $GLOBALS['st'] = [];
check(strpos(NetMon::github_release(true)['error'], 'Could not reach GitHub') !== false, 'network error handled');
$_POST = [];

echo empty($GLOBALS['failed']) ? "\nALL PASSED\n" : "\nSOME FAILED\n";
function NetMon_dt($t) { return gmdate('Y-m-d H:i:s', $t); }
