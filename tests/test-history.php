<?php
/**
 * Test für das Wartungsprotokoll (BW_Bridge_Log, BW_Bridge_Log_WP, BW_Bridge_History) gegen eine simulierte
 * WordPress-Umgebung (Speicher statt Datenbank, temporärer Upload-Ordner). Aufruf: php tests/test-history.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'ARRAY_A', 'ARRAY_A' );
$upl = sys_get_temp_dir() . '/bwhist_' . getmypid();
@mkdir( $upl, 0777, true );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = [ 'bw_bridge_log_db' => 1 ]; $GLOBALS['did'] = []; $GLOBALS['user'] = (object) [ 'ID' => 7, 'user_login' => 'claude', 'roles' => [ 'administrator' ] ];
function add_action( ...$a ) {} function add_filter( ...$a ) {} function register_setting( ...$a ) {} function add_options_page( ...$a ) {} function add_management_page( ...$a ) {}
function register_rest_route( ...$a ) {} function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can( $c ) { return true; } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function apply_filters( $t, $v = null, ...$a ) { return $v; } function did_action( $a ) { return ! empty( $GLOBALS['did'][ $a ] ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); } function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_get_current_user() { return $GLOBALS['user']; } function get_userdata( $id ) { return $id === $GLOBALS['user']->ID ? $GLOBALS['user'] : null; }
function wp_upload_dir( $a = null, $b = true ) { return [ 'basedir' => $GLOBALS['upl'], 'error' => false ]; } function wp_hash( $s ) { return md5( $s ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function get_post( $id ) { return null; } function rest_ensure_response( $d ) { return $d; }
class WP_Error { public $code, $msg; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
class Req { public $m, $r, $p; function __construct( $m, $r, $p = [] ) { $this->m = $m; $this->r = $r; $this->p = $p; } function get_method() { return $this->m; } function get_route() { return $this->r; } function get_param( $k ) { return $this->p[ $k ] ?? null; } }
class Resp { public $s; function __construct( $s ) { $this->s = $s; } function get_status() { return $this->s; } }

require __DIR__ . '/lib-mem-wpdb.php';
$GLOBALS['wpdb'] = new Mem_Wpdb();

require __DIR__ . '/../bw-wp-bridge.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }
function rows() { return array_values( $GLOBALS['wpdb']->rows ); }

/* ---------- IP-Kürzung ---------- */
check( 'IPv4 auf /24 gekürzt', '203.0.113.0' === BW_Bridge_Log::truncate_ip( '203.0.113.77' ) );
check( 'IPv6 auf /48 gekürzt', '2001:db8:abcd::' === BW_Bridge_Log::truncate_ip( '2001:db8:abcd:12:34::1' ) );
check( 'Ungültige IP wird leer', '' === BW_Bridge_Log::truncate_ip( 'unknown, 1.2.3.4' ) );

/* ---------- Zeiträume ---------- */
$p = BW_Bridge_Log::period( 'current', '2026-11-05', '2026-01-01', 'quarter' );
check( 'Kalenderquartal Q4', '2026-10-01' === $p['from'] && '2026-12-31' === $p['to'] && 'Q4 2026' === $p['label'] );
$p = BW_Bridge_Log::period( 'previous', '2026-11-05', '2026-01-01', 'quarter' );
check( 'Vorheriges Quartal Q3', '2026-07-01' === $p['from'] && '2026-09-30' === $p['to'] );
$p = BW_Bridge_Log::period( 'current', '2026-11-05', '2025-03-15', 'quarter' );
check( 'Vertragsquartal ab 15.03.', '2026-09-15' === $p['from'] && '2026-12-14' === $p['to'] );
$p = BW_Bridge_Log::period( 'previous', '2026-01-10', '2026-01-01', 'quarter' );
check( 'Vorquartal über Jahreswechsel', '2025-10-01' === $p['from'] && '2025-12-31' === $p['to'] );
$p = BW_Bridge_Log::period( 'year', '2026-11-05', '2025-03-15', 'quarter' );
check( 'Vertragsjahr ab 15.03.', '2026-03-15' === $p['from'] && '2027-03-14' === $p['to'] );
$p = BW_Bridge_Log::period( 'previous-year', '2026-11-05', '2024-01-01', 'month' );
check( 'Vorjahr (Kalender)', '2025-01-01' === $p['from'] && '2025-12-31' === $p['to'] && 'Jahr 2025' === $p['label'] );
$p = BW_Bridge_Log::period( 'current', '2026-02-28', '2025-08-31', 'half' );
check( 'Halbjahr mit Monatsende', '2026-02-28' === $p['from'] && '2026-08-30' === $p['to'] );

$p = BW_Bridge_Log::resolve_range( null, '2026-07-01', '2026-09-30' );
check( 'Eigener Zeitraum = Quartal → Q3 2026', 'Q3 2026' === $p['label'] && 'custom' === $p['kind'] && '2026-07-01 00:00:00' === $p['from_utc'] && '2026-09-30 23:59:59' === $p['to_utc'] );

/* ---------- Schreiben ---------- */
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
define( 'REST_REQUEST', true );
check( 'Dienstbenutzer claude wird als Claude erkannt', 'claude' === BW_Bridge_Log::via() );
$id = BW_Bridge_Log::add( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'updated', 'object_name' => 'Yoast <b>SEO</b>', 'message' => 'Plugin aktualisiert', 'details' => [ 'from' => '1.0', 'to' => '1.1' ] ] );
$r  = rows()[0];
check( 'Eintrag angelegt', $id === 1 && 'updates' === $r['area'] && 'claude' === $r['via'] && 'claude' === $r['user_login'] );
check( 'IP gekürzt gespeichert', '198.51.100.0' === $r['ip'] );
check( 'HTML aus Namen entfernt', 'Yoast SEO' === $r['object_name'] );
check( 'Details als JSON', '{"from":"1.0","to":"1.1"}' === $r['details'] );
BW_Bridge_Log::add( [ 'area' => 'nonsense', 'level' => 'kaputt', 'message' => 'x' ] );
BW_Bridge_Log::add( [ 'area' => 'updates', 'message' => 'automatisch', 'via' => 'cron' ] );
check( 'Cron-Einträge ohne Benutzer und ohne IP', '' === end( $GLOBALS['wpdb']->rows )['user_login'] && '' === end( $GLOBALS['wpdb']->rows )['ip'] );
check( 'Unbekannter Bereich/Level → changes/info', 'changes' === rows()[1]['area'] && 'info' === rows()[1]['level'] );
for ( $i = 0; $i < 5; $i++ ) {
	BW_Bridge_Log::add( [ 'area' => 'security', 'category' => 'auth', 'action' => 'login_failed', 'level' => 'warning', 'user_id' => 0, 'object_name' => 'admin', 'aggregate' => 'admin', 'via' => 'web' ] );
}
BW_Bridge_Log::add( [ 'area' => 'security', 'category' => 'auth', 'action' => 'login_failed', 'level' => 'warning', 'user_id' => 0, 'object_name' => 'root', 'aggregate' => 'root', 'via' => 'web' ] );
$agg = array_values( array_filter( rows(), fn( $r ) => 'login_failed' === $r['action'] ) );
check( 'Fehlanmeldungen zusammengefasst (2 Zeilen, Zähler 5 und 1)', 2 === count( $agg ) && 5 === $agg[0]['cnt'] && 1 === $agg[1]['cnt'] );
$GLOBALS['opts']['bw_bridge_log_db'] = 0;
check( 'Ohne Tabelle wird nichts geschrieben', false === BW_Bridge_Log::add( [ 'message' => 'x' ] ) );
$GLOBALS['opts']['bw_bridge_log_db'] = 1;

/* ---------- Notizen ---------- */
check( 'Leere Notiz abgelehnt', is_wp_error( BW_Bridge_History::add_note( [ 'text' => '  ' ] ) ) );
check( 'Falsches Datum abgelehnt', is_wp_error( BW_Bridge_History::add_note( [ 'text' => 'x', 'date' => '05.11.2026' ] ) ) );
check( 'Datum in der Zukunft abgelehnt', is_wp_error( BW_Bridge_History::add_note( [ 'text' => 'x', 'date' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ] ) ) );
$nid  = BW_Bridge_History::add_note( [ 'text' => 'Kontaktformular-Empfänger umgestellt', 'area' => 'changes', 'minutes' => '15', 'ticket' => 'T-12', 'tags' => 'formular, dev' ] );
$note = $GLOBALS['wpdb']->rows[ $nid ];
$nd   = json_decode( $note['details'], true );
check( 'Notiz gespeichert mit Aufwand, Ticket, Tags', 'note' === $note['source'] && 15 === $nd['minutes'] && 'T-12' === $nd['ticket'] && [ 'formular', 'dev' ] === $nd['tags'] );
$back = BW_Bridge_History::add_note( [ 'text' => 'Nachgetragen', 'area' => 'seo', 'date' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ] );
check( 'Nachgetragene Notiz bekommt das Datum', 0 === strpos( $GLOBALS['wpdb']->rows[ $back ]['created'], gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) );

/* ---------- REST-Schreibzugriffe ---------- */
check( 'GET wird nicht protokolliert', ! BW_Bridge_Log_WP::should_log_rest( new Req( 'GET', '/bw-bridge/v1/elementor/12' ), new Resp( 200 ) ) );
check( 'Bridge-POST wird protokolliert', BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/bw-bridge/v1/elementor/12' ), new Resp( 200 ) ) );
check( 'Probelauf nicht', ! BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/bw-bridge/v1/elementor/12', [ 'dry_run' => true ] ), new Resp( 200 ) ) );
check( 'Fehler nicht', ! BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/bw-bridge/v1/elementor/12' ), new Resp( 403 ) ) );
check( 'Eigene Notiz-Route nicht', ! BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/bw-bridge/v1/history/notes' ), new Resp( 200 ) ) );
check( 'wp/v2 ohne Anwendungspasswort nicht', ! BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/wp/v2/pages/5' ), new Resp( 200 ) ) );
$GLOBALS['did']['application_password_did_authenticate'] = 1;
check( 'wp/v2 mit Anwendungspasswort ja', BW_Bridge_Log_WP::should_log_rest( new Req( 'POST', '/wp/v2/pages/5' ), new Resp( 200 ) ) );
check( 'Routenmuster', '/wp/v2/pages/{id}' === BW_Bridge_Log_WP::route_pattern( '/wp/v2/pages/5' ) && '/bw-bridge/v1/elementor/{id}/texts' === BW_Bridge_Log_WP::route_pattern( '/bw-bridge/v1/elementor/12/texts' ) );
$before = count( rows() );
BW_Bridge_Log_WP::on_rest_write( new Resp( 200 ), null, new Req( 'POST', '/bw-bridge/v1/elementor/12/texts', [ 'id' => 12 ] ) );
BW_Bridge_Log_WP::on_rest_write( new Resp( 200 ), null, new Req( 'POST', '/bw-bridge/v1/elementor/12/texts', [ 'id' => 12 ] ) );
$last = array_values( array_filter( rows(), fn( $r ) => 'bridge' === $r['category'] ) );
check( 'Zwei gleiche Bridge-Aufrufe → ein Eintrag mit Zähler 2', $before + 1 === count( rows() ) && 2 === $last[0]['cnt'] && 'bridge' === $last[0]['source'] );

/* ---------- Einstellungen ---------- */
BW_Bridge_Log_WP::on_option( 'blog_public', '1', '0' );
$opt = array_values( array_filter( rows(), fn( $r ) => 'settings' === $r['category'] ) );
check( 'Indexierung abgeschaltet → SEO-Warnung', 1 === count( $opt ) && 'seo' === $opt[0]['area'] && 'warning' === $opt[0]['level'] );
BW_Bridge_Log_WP::on_option( 'some_cache_option', 'a', 'b' );
check( 'Unwichtige Optionen werden nicht protokolliert', 1 === count( array_filter( rows(), fn( $r ) => 'settings' === $r['category'] ) ) );

/* ---------- Lesen, Archiv, Aufbewahrung ---------- */
$old = gmdate( 'Y-m-d H:i:s', time() - 430 * DAY_IN_SECONDS );
$GLOBALS['wpdb']->insert( '', BW_Bridge_Log::build_row( [ 'area' => 'updates', 'category' => 'core', 'action' => 'updated', 'message' => 'Altes Update', 'via' => 'cron', 'created' => $old ] ) );
$GLOBALS['wpdb']->insert( '', BW_Bridge_Log::build_row( [ 'area' => 'seo', 'category' => 'settings', 'action' => 'changed', 'message' => 'Alte SEO-Änderung', 'via' => 'cron', 'created' => $old ] ) );
$n_before = count( rows() );
$moved    = BW_Bridge_Log::archive_old();
check( 'Einträge älter als 365 Tage ins Archiv verschoben', 2 === $moved && $n_before - 2 === count( rows() ) );
$dir = BW_Bridge_Log::archive_dir( false );
check( 'Archivordner gesperrt (.htaccess)', is_file( $dir . '/.htaccess' ) && is_file( $dir . '/' . substr( $old, 0, 7 ) . '.jsonl.gz' ) );
// zweites Anhängen an dieselbe Monatsdatei (eigenes gzip-Mitglied)
BW_Bridge_Log::append_archive( $dir, substr( $old, 0, 7 ), [ BW_Bridge_Log::normalize( BW_Bridge_Log::build_row( [ 'area' => 'security', 'message' => 'Nachzügler', 'via' => 'cron', 'created' => $old ] ) + [ 'id' => 999 ] ) ] );
$arch = BW_Bridge_Log::read_archive( [ 'from' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ] );
check( 'Archiv lesbar, auch angehängte Teile (3 Einträge)', 3 === count( $arch ) && ! empty( $arch[0]['archived'] ) );
check( 'Archiv-Filter nach Bereich', 1 === count( BW_Bridge_Log::read_archive( [ 'area' => 'seo' ] ) ) );
$q = BW_Bridge_Log::query( [ 'from' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ), 'limit' => 500 ] );
check( 'Abfrage liest Datenbank und Archiv zusammen', $q['total'] === count( rows() ) + 3 && ! empty( end( $q['items'] )['archived'] ) );
$q = BW_Bridge_Log::query( [ 'area' => 'updates', 'limit' => 500 ] );
check( 'Abfrage nach Bereich', 3 === $q['total'] && 'updates' === $q['items'][0]['area'] && array_filter( $q['items'], fn( $i ) => is_array( $i['details'] ) ) );
check( 'Neueste zuerst', $q['items'][0]['created'] >= $q['items'][1]['created'] );
$GLOBALS['opts']['bw_bridge_log_keep_days'] = 380;
$gone = BW_Bridge_Log::purge_expired();
check( 'Abgelaufene Archivmonate gelöscht (Monat vollständig abgelaufen)', $gone >= 1 && ! is_file( $dir . '/' . substr( $old, 0, 7 ) . '.jsonl.gz' ) );
$GLOBALS['opts']['bw_bridge_log_keep_days'] = 100;
check( 'Aufbewahrung nie kürzer als die Tage in der Datenbank', 365 === BW_Bridge_Log::keep_days() );
$GLOBALS['opts']['bw_bridge_log_keep_days'] = 0;
check( '0 = unbegrenzt', 0 === BW_Bridge_Log::keep_days() && 0 === BW_Bridge_Log::purge_expired() );

/* ---------- Berichtsdaten ---------- */
$items = [
	[ 'id' => 1, 'created' => '2026-10-02 08:00:00', 'area' => 'updates', 'category' => 'plugin', 'action' => 'updated', 'level' => 'info', 'source' => 'wp', 'via' => 'claude', 'user_login' => 'claude', 'object_name' => 'Yoast SEO', 'message' => 'Plugin aktualisiert: Yoast SEO 1 → 2', 'details' => [ 'from' => '1', 'to' => '2' ], 'cnt' => 1 ],
	[ 'id' => 2, 'created' => '2026-10-03 08:00:00', 'area' => 'updates', 'category' => 'plugin', 'action' => 'installed', 'level' => 'notice', 'source' => 'wp', 'via' => 'backend', 'user_login' => 'anna', 'object_name' => 'WP Mail SMTP', 'message' => 'Plugin installiert', 'details' => null, 'cnt' => 1 ],
	[ 'id' => 3, 'created' => '2026-10-05 08:00:00', 'area' => 'security', 'category' => 'auth', 'action' => 'login_failed', 'level' => 'warning', 'source' => 'wp', 'via' => 'web', 'user_login' => '', 'object_name' => 'admin', 'message' => '', 'details' => null, 'cnt' => 40 ],
	[ 'id' => 4, 'created' => '2026-10-06 08:00:00', 'area' => 'security', 'category' => 'auth', 'action' => 'login_failed', 'level' => 'warning', 'source' => 'wp', 'via' => 'web', 'user_login' => '', 'object_name' => 'admin', 'message' => '', 'details' => null, 'cnt' => 10 ],
	[ 'id' => 5, 'created' => '2026-10-06 09:00:00', 'area' => 'security', 'category' => 'auth', 'action' => 'login', 'level' => 'notice', 'source' => 'wp', 'via' => 'web', 'user_login' => 'anna', 'object_name' => 'anna', 'message' => '', 'details' => null, 'cnt' => 3 ],
	[ 'id' => 6, 'created' => '2026-10-07 09:00:00', 'area' => 'security', 'category' => 'user', 'action' => 'created', 'level' => 'warning', 'source' => 'wp', 'via' => 'backend', 'user_login' => 'anna', 'object_name' => 'max', 'message' => 'Benutzer max angelegt', 'details' => null, 'cnt' => 1 ],
	[ 'id' => 7, 'created' => '2026-10-08 09:00:00', 'area' => 'changes', 'category' => 'content', 'action' => 'edited', 'level' => 'info', 'source' => 'wp', 'via' => 'backend', 'user_login' => 'anna', 'object_type' => 'page', 'object_id' => '12', 'object_name' => 'Kontakt', 'message' => '', 'details' => null, 'cnt' => 4 ],
	[ 'id' => 8, 'created' => '2026-10-20 09:00:00', 'area' => 'changes', 'category' => 'content', 'action' => 'edited', 'level' => 'info', 'source' => 'wp', 'via' => 'claude', 'user_login' => 'claude', 'object_type' => 'page', 'object_id' => '12', 'object_name' => 'Kontakt', 'message' => '', 'details' => null, 'cnt' => 2 ],
	[ 'id' => 9, 'created' => '2026-10-21 09:00:00', 'area' => 'seo', 'category' => 'settings', 'action' => 'changed', 'level' => 'warning', 'source' => 'wp', 'via' => 'backend', 'user_login' => 'anna', 'object_name' => 'blog_public', 'message' => 'Einstellung blog_public', 'details' => [ 'from' => '1', 'to' => '0' ], 'cnt' => 1 ],
	[ 'id' => 10, 'created' => '2026-10-22 09:00:00', 'area' => 'seo', 'category' => 'note', 'action' => 'note', 'level' => 'info', 'source' => 'note', 'via' => 'claude', 'user_login' => 'claude', 'object_name' => '', 'message' => 'Meta-Beschreibungen ergänzt', 'details' => [ 'text' => 'Meta-Beschreibungen ergänzt', 'minutes' => 45, 'ticket' => 'T-1' ], 'cnt' => 1 ],
	[ 'id' => 11, 'created' => '2026-10-23 09:00:00', 'area' => 'system', 'category' => 'audit', 'action' => 'snapshot', 'level' => 'info', 'source' => 'wp', 'via' => 'cron', 'user_login' => '', 'object_name' => '', 'message' => 'Prüfung', 'details' => [ 'counts' => [ 'security' => [ 'fail' => 1 ] ] ], 'cnt' => 1 ],
];
$rep = BW_Bridge_History::build( $items, [ 'kind' => 'current', 'from' => '2026-10-01', 'to' => '2026-12-31', 'label' => 'Q4 2026', 'rhythm' => 'quarter', 'from_utc' => 'x' ] );
$k   = $rep['kpis'];
check( 'Kennzahlen Updates/Neuinstallationen', 1 === $k['updates'] && 1 === $k['installs'] );
check( 'Fehlgeschlagene Anmeldungen summiert (50, Spitze 05.10.)', 50 === $k['failed_logins'] && 50 === $rep['sections']['security']['failed_logins']['total'] && '2026-10-05' === $rep['sections']['security']['failed_logins']['peak_day'] && [ 'admin' => 50 ] === $rep['sections']['security']['failed_logins']['top_names'] );
check( 'Anmeldungen je Benutzer', 3 === $rep['sections']['security']['logins']['anna']['count'] );
check( 'Sicherheitsereignisse ohne Anmeldungen', 1 === $k['security_events'] && 'Benutzer max angelegt' === $rep['sections']['security']['events'][0]['message'] );
check( 'Inhalte zu einem Objekt zusammengefasst', 1 === count( $rep['sections']['changes']['objects'] ) && 6 === $rep['sections']['changes']['objects'][0]['count'] && [ 'backend', 'claude' ] === $rep['sections']['changes']['objects'][0]['via'] );
check( 'Notizen mit Aufwand', 1 === $k['notes'] && 45 === $k['minutes'] && 'T-1' === $rep['notes'][0]['ticket'] );
check( 'SEO-Bereich: Einstellung + Notiz', 1 === $k['seo'] && 2 === $rep['by_area']['seo'] );
check( 'Über Claude (ohne An-/Abmeldungen)', 4 === $k['claude'] && ! isset( $rep['by_via']['web'] ) );
check( 'Zeitleiste in Wochen, Q4 hat 14 Wochen', 'week' === $rep['timeline']['unit'] && 14 === count( $rep['timeline']['buckets'] ) );
$sum = array_sum( array_map( fn( $b ) => $b['updates'] + $b['security'] + $b['seo'] + $b['changes'], $rep['timeline']['buckets'] ) );
check( 'Zeitleiste ohne System und An-/Abmeldungen', $sum === array_sum( $rep['by_area'] ) && 11 === $sum );
check( 'Schnappschuss erkannt', 1 === $rep['audit_snapshots']['count'] );
$rep = BW_Bridge_History::build( [], [ 'kind' => 'year', 'from' => '2026-01-01', 'to' => '2026-12-31', 'label' => 'Jahr 2026', 'rhythm' => 'year' ] );
check( 'Jahr in Monaten (12), leer ohne Fehler', 'month' === $rep['timeline']['unit'] && 12 === count( $rep['timeline']['buckets'] ) && 0 === array_sum( $rep['kpis'] ) );

/* ---------- Grafik ---------- */
check( 'Achsen-Obergrenze rund', 4 === BW_Bridge_Maintenance::nice_max( 3 ) && 8 === BW_Bridge_Maintenance::nice_max( 7 ) && 20 === BW_Bridge_Maintenance::nice_max( 13 ) && 400 === BW_Bridge_Maintenance::nice_max( 320 ) );

array_map( 'unlink', glob( $upl . '/*/*' ) ?: [] ); array_map( 'unlink', glob( $upl . '/*/.htaccess' ) ?: [] ); @array_map( 'rmdir', glob( $upl . '/*' ) ?: [] ); @rmdir( $upl );
echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
