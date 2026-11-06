<?php
/**
 * Test für den E-Mail-Bericht (BW_Bridge_Report_Mail) gegen eine simulierte WordPress-Umgebung.
 * Aufruf: php tests/test-report-mail.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'ARRAY_A', 'ARRAY_A' );
$tmp = sys_get_temp_dir() . '/bwmail_' . getmypid();
@mkdir( $tmp, 0777, true );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = [ 'bw_bridge_log_db' => 1 ]; $GLOBALS['trans'] = []; $GLOBALS['hooks'] = []; $GLOBALS['mails'] = []; $GLOBALS['mail_ok'] = true;
$GLOBALS['user'] = (object) [ 'ID' => 0, 'user_login' => '' ];
function add_action( $t, $cb, ...$a ) { $GLOBALS['hooks'][ $t ][] = $cb; } function remove_action( $t, $cb, ...$a ) { $GLOBALS['hooks'][ $t ] = array_values( array_filter( $GLOBALS['hooks'][ $t ] ?? [], fn( $c ) => $c !== $cb ) ); }
function do_action( $t, ...$a ) { foreach ( $GLOBALS['hooks'][ $t ] ?? [] as $cb ) { $cb( ...$a ); } }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } function rest_get_url_prefix() { return 'wp-json'; } function add_options_page( ...$a ) {} function add_management_page( ...$a ) {} function register_rest_route( ...$a ) {}
function add_filter( ...$a ) {} function apply_filters( $t, $v = null, ...$a ) { return $v; } function did_action( $a ) { return 0; }
function register_setting( $g, $o, $a = [] ) { $GLOBALS['registered'][ $o ] = $a; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; } function set_transient( $k, $v, $e = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; } function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }
function has_action( $h ) { return ! empty( $GLOBALS['has_action'][ $h ] ); } function is_wp_error( $x ) { return $x instanceof WP_Error; } function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function current_user_can( $c ) { return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); } function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '-', $s ); } function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_get_current_user() { return $GLOBALS['user']; } function get_userdata( $id ) { return null; } function wp_doing_cron() { return true; }
function get_bloginfo( $k = '' ) { return 'Testseite'; } function get_temp_dir() { return $GLOBALS['tmp'] . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=abc'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; return $echo ? print( $r ) : $r; }
class WP_Error { public $code, $msg; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
	$files = [];
	foreach ( (array) $attachments as $f ) { $files[ basename( $f ) ] = is_file( $f ) ? file_get_contents( $f ) : null; }
	$GLOBALS['mails'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments', 'files' );
	if ( ! $GLOBALS['mail_ok'] ) { do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP kaputt' ) ); }
	return $GLOBALS['mail_ok'];
}

require __DIR__ . '/lib-mem-wpdb.php';
$GLOBALS['wpdb'] = new Mem_Wpdb();
require __DIR__ . '/../bw-wp-bridge.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }

/** Hand gebaute Berichtsdaten. */
function data( array $over = [] ) {
	return array_replace_recursive( [
		'period'   => [ 'kind' => 'previous', 'from' => '2026-07-01', 'to' => '2026-09-30', 'label' => 'Q3 2026' ],
		'site'     => [ 'name' => 'Testseite', 'url' => 'https://example.test/', 'id' => 'abc123' ],
		'kpis'     => [ 'updates' => 7, 'installs' => 2, 'security_events' => 3, 'failed_logins' => 1234, 'changes' => 11, 'notes' => 2, 'minutes' => 135, 'minutes_estimated' => 45, 'claude' => 5 ],
		'sections' => [ 'updates' => [ [ 'date' => '2026-07-02', 'message' => 'Plugin aktualisiert: Yoast 1 → 2' ] ] ],
		'notes'    => [ [ 'date' => '2026-08-01', 'text' => 'Formular angepasst', 'minutes' => 45, 'minutes_estimated' => true ], [ 'date' => '2026-08-02', 'text' => 'Backup geprüft', 'minutes' => 20 ] ],
		'audit'    => [ 'counts' => [ 'security' => [ 'fail' => 1, 'warn' => 1 ], 'seo' => [ 'fail' => 0, 'warn' => 0 ] ],
			'security' => [ [ 'status' => 'fail', 'title' => 'PHP veraltet', 'detail' => 'Version 7.4' ], [ 'status' => 'warn', 'title' => 'Debug an', 'detail' => '' ], [ 'status' => 'ok', 'title' => 'Alles gut', 'detail' => '' ] ], 'seo' => [] ],
		'seo_analysis' => [ 'score' => 82, 'categories' => [ 'meta' => [ 'label' => 'Meta', 'score' => 90, 'fail' => 0, 'warn' => 2 ] ], 'tasks' => [ [ 'title' => 'Titel kürzen', 'status' => 'warn', 'pages' => [ 'a', 'b' ], 'site' => false ] ] ],
		'scan'     => [ 'integrity' => [ 'issues' => [ [ 'unit' => 'x' ] ] ], 'vulns' => [ 'counts' => [ 'c' => 1, 'h' => 0, 'm' => 2, 'l' => 0, 'other' => 0 ] ] ],
		'versions' => [ 'wordpress' => '6.8', 'php' => '8.2', 'bridge' => '1.2.3' ],
	], $over );
}
BW_Bridge_Report_Mail::$report_builder = static fn( $range ) => data();
function opts_reset() { $GLOBALS['opts'] = [ 'bw_bridge_log_db' => 1 ]; $GLOBALS['trans'] = []; $GLOBALS['mails'] = []; $GLOBALS['mail_ok'] = true; $GLOBALS['wpdb']->rows = []; }
function log_actions() { return array_column( array_values( $GLOBALS['wpdb']->rows ), 'action' ); }

/* ---------- Empfänger ---------- */
check( 'sanitize: gemischte Trenner, Ungültiges und Doppeltes entfernt', 'a@b.at, c@d.at, e@f.at' === BW_Bridge_Report_Mail::sanitize_recipients( "a@b.at; c@d.at,nonsense  a@b.at\ne@f.at," ) );
check( 'sanitize: leer bleibt leer', '' === BW_Bridge_Report_Mail::sanitize_recipients( '' ) && '' === BW_Bridge_Report_Mail::sanitize_recipients( 'kaputt' ) );
check( 'Empfänger-Standard', [ 'maintenancereport@blickwert.at' ] === BW_Bridge_Report_Mail::recipients() );
$GLOBALS['opts']['bw_bridge_report_to'] = 'x@y.at; z@y.at';
check( 'Empfänger aus Option', [ 'x@y.at', 'z@y.at' ] === BW_Bridge_Report_Mail::recipients() );
$GLOBALS['opts']['bw_bridge_report_to'] = 'ungültig';
check( 'Ohne gültigen Empfänger nicht aktiv', ! BW_Bridge_Report_Mail::enabled() );
opts_reset();
check( 'Standardmäßig aktiv', BW_Bridge_Report_Mail::enabled() );
BW_Bridge_Report_Mail::register_settings( 'grp' );
check( 'Einstellungen registriert', isset( $GLOBALS['registered']['bw_bridge_report_mail'], $GLOBALS['registered']['bw_bridge_report_to'] ) && 0 === $GLOBALS['registered']['bw_bridge_report_mail']['sanitize_callback']( '' ) );
ob_start(); BW_Bridge_Report_Mail::settings_rows(); $rows = ob_get_clean();
check( 'Einstellungszeile mit Testlink und „noch keiner“', false !== strpos( $rows, 'action=bw_bridge_report_send&amp;period=current&amp;back=settings' ) && false !== strpos( $rows, 'noch keiner' ) && false === strpos( $rows, '<form' ) );

/* ---------- maybe_send ---------- */
$today = '2026-10-01';
$GLOBALS['opts']['bw_bridge_contract_start'] = '2026-01-01'; $GLOBALS['opts']['bw_bridge_site_id'] = 'abc123';
check( 'Erster Lauf: nichts gesendet', false === BW_Bridge_Report_Mail::maybe_send( '2026-07-01' ) && [] === $GLOBALS['mails'] );
check( 'Erster Lauf initialisiert OPT_LAST mit dem Vorquartal', '2026-04-01_2026-06-30' === get_option( 'bw_bridge_report_last' ) );
check( 'Gleicher Zeitraum: nichts gesendet', false === BW_Bridge_Report_Mail::maybe_send( '2026-07-15' ) && [] === $GLOBALS['mails'] );
check( 'Neuer Zeitraum: gesendet', true === BW_Bridge_Report_Mail::maybe_send( $today ) && 1 === count( $GLOBALS['mails'] ) );
$m = $GLOBALS['mails'][0];
check( 'Betreff', 'Wartungsbericht Q3 2026 – Testseite' === $m['subject'] );
check( 'Empfänger und HTML-Header', [ 'maintenancereport@blickwert.at' ] === $m['to'] && [ 'Content-Type: text/html; charset=UTF-8' ] === $m['headers'] );
check( 'JSON-Anhang war beim Versand vorhanden und lesbar', 1 === count( $m['files'] ) && 'wartung-abc123-2026-07-01_2026-09-30.json' === key( $m['files'] ) && 'Testseite' === json_decode( current( $m['files'] ), true )['site']['name'] );
check( 'Temp-Datei und Ordner danach entfernt', ! file_exists( $m['attachments'][0] ) && ! is_dir( dirname( $m['attachments'][0] ) ) );
check( 'OPT_LAST aktualisiert', '2026-07-01_2026-09-30' === get_option( 'bw_bridge_report_last' ) );
check( 'Protokolleintrag sent', [ 'sent' ] === log_actions() && 'cron' === array_values( $GLOBALS['wpdb']->rows )[0]['via'] );
BW_Bridge_Report_Mail::maybe_send( $today ); BW_Bridge_Report_Mail::maybe_send( '2026-10-02' );
check( 'Genau einmal je Zeitraum', 1 === count( $GLOBALS['mails'] ) );

/* ---------- Fehlschlag ---------- */
opts_reset();
$GLOBALS['opts']['bw_bridge_contract_start'] = '2026-01-01'; $GLOBALS['opts']['bw_bridge_report_last'] = '2026-04-01_2026-06-30'; $GLOBALS['mail_ok'] = false;
check( 'Fehlschlag: false', false === BW_Bridge_Report_Mail::maybe_send( $today ) );
check( 'Fehlschlag: OPT_LAST unverändert', '2026-04-01_2026-06-30' === get_option( 'bw_bridge_report_last' ) );
check( 'Fehlschlag: Protokolleintrag failed (Warnung)', [ 'failed' ] === log_actions() && 'warning' === array_values( $GLOBALS['wpdb']->rows )[0]['level'] );
$r = BW_Bridge_Report_Mail::send( BW_Bridge_Log::resolve_range( null, '2026-07-01', '2026-09-30' ), 'manual' );
check( 'send liefert WP_Error mit Fehlertext aus wp_mail_failed', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'SMTP kaputt' ) );
check( 'Ohne SMTP-Plugin: Hinweis in der Fehlermeldung', false !== strpos( $r->get_error_message(), 'SMTP-Plugin' ) );
$GLOBALS['has_action']['phpmailer_init'] = true; $r2 = BW_Bridge_Report_Mail::send( BW_Bridge_Log::resolve_range( null, '2026-07-01', '2026-09-30' ), 'manual' ); unset( $GLOBALS['has_action'] );
check( 'Mit SMTP-Einrichtung (phpmailer_init): kein Hinweis', is_wp_error( $r2 ) && false === strpos( $r2->get_error_message(), 'SMTP-Plugin' ) );
BW_Bridge_Report_Mail::maybe_send( '2026-10-02' ); BW_Bridge_Report_Mail::maybe_send( '2026-10-03' );
$n = count( $GLOBALS['mails'] );
BW_Bridge_Report_Mail::maybe_send( '2026-10-04' );
check( 'Höchstens 3 automatische Versuche je Zeitraum', $n === count( $GLOBALS['mails'] ) && 5 === $n ); // 3 automatische + 2 manuelle Versuche
$GLOBALS['mail_ok'] = true; $GLOBALS['trans'] = [];
check( 'Nächster Tag nach behobenem Fehler: gesendet', true === BW_Bridge_Report_Mail::maybe_send( '2026-10-05' ) && '2026-07-01_2026-09-30' === get_option( 'bw_bridge_report_last' ) );

/* ---------- abgeschaltet / ohne Empfänger ---------- */
opts_reset();
$GLOBALS['opts']['bw_bridge_report_mail'] = 0; $GLOBALS['opts']['bw_bridge_report_last'] = '2026-04-01_2026-06-30';
check( 'Abgeschaltet: kein Versand', false === BW_Bridge_Report_Mail::maybe_send( $today ) && [] === $GLOBALS['mails'] );
$GLOBALS['opts']['bw_bridge_report_mail'] = 1; $GLOBALS['opts']['bw_bridge_report_to'] = '';
check( 'Ohne Empfänger: send() → WP_Error', is_wp_error( BW_Bridge_Report_Mail::send( BW_Bridge_Log::resolve_range( null, '2026-07-01', '2026-09-30' ) ) ) && [] === $GLOBALS['mails'] );

/* ---------- HTML ---------- */
$h = BW_Bridge_Report_Mail::html( data(), 'https://example.test/wp-admin/tools.php?page=x&from=a' );
check( 'Mail: neutrale Bezeichnung statt Claude', false !== strpos( $h, 'Aktionen durch den Support' ) && false === strpos( $h, 'Claude' ) );
check( 'Mail: geschätzte Notiz „(45 min, geschätzt)“, bestätigte ohne Zusatz', false !== strpos( $h, '(45 min, geschätzt)' ) && false !== strpos( $h, '(20 min)' ) );
check( 'Mail: Kennzahl „davon geschätzt 0:45“', false !== strpos( $h, 'davon geschätzt 0:45' ) );
check( 'HTML: Kopf mit Zeitraum und Datum', false !== strpos( $h, 'Wartungsbericht · Testseite' ) && false !== strpos( $h, 'Q3 2026' ) && false !== strpos( $h, '01.07.2026 – 30.09.2026' ) );
check( 'HTML: Kennzahlen (9 Updates, 1.234 Anmeldungen, 2:15 h)', false !== strpos( $h, '>9<' ) && false !== strpos( $h, '1.234' ) && false !== strpos( $h, '2:15 h' ) );
check( 'HTML: Sicherheitsprüfung Dringend/Empfehlung ohne ok', false !== strpos( $h, 'PHP veraltet' ) && false !== strpos( $h, 'Dringend' ) && false !== strpos( $h, 'Empfehlung' ) && false === strpos( $h, 'Alles gut' ) );
check( 'HTML: SEO-Score, Kategorie und Aufgabe', false !== strpos( $h, '82 %' ) && false !== strpos( $h, 'Meta' ) && false !== strpos( $h, 'Titel kürzen' ) && false !== strpos( $h, '2 Seiten' ) );
check( 'HTML: Schwachstellen und Dateien', false !== strpos( $h, 'Bekannte Schwachstellen: 3 (1 kritisch, 2 mittel)' ) && false !== strpos( $h, '1 Bereich(e) weichen von den Originaldateien ab' ) );
check( 'HTML: Link, Notiz, Versionen, Anhang-Hinweis', false !== strpos( $h, 'href="https://example.test/wp-admin/tools.php?page=x&amp;from=a"' ) && false !== strpos( $h, 'Formular angepasst' ) && false !== strpos( $h, '45 min' ) && false !== strpos( $h, 'WordPress 6.8 · PHP 8.2 · BW WP Bridge 1.2.3' ) && false !== strpos( $h, 'wartung-abc123-2026-07-01_2026-09-30.json' ) );
$h = BW_Bridge_Report_Mail::html( data( [ 'seo_analysis' => [ 'score' => null ] ] ), '' );
check( 'HTML: SEO noch nicht gelaufen', false !== strpos( $h, 'SEO-Analyse noch nicht gelaufen' ) );
$h = BW_Bridge_Report_Mail::html( data( [ 'site' => [ 'name' => '<script>alert(1)</script>' ], 'audit' => [ 'security' => [ [ 'status' => 'fail', 'title' => '<script>x</script>', 'detail' => '<img src=x onerror=1>' ] ] ] ] ), 'https://x/"><script>' );
check( 'HTML: Schadcode escaped (Name, Prüfpunkt, Link)', false === strpos( $h, '<script>' ) && false === strpos( $h, '<img' ) && false !== strpos( $h, '&lt;script&gt;alert(1)' ) );
check( 'Betreff ohne Zeilenumbrüche', false === strpos( BW_Bridge_Report_Mail::subject( data( [ 'site' => [ 'name' => "A\r\nBcc: x" ] ] ) ), "\n" ) );
$ups = []; for ( $i = 1; $i <= 25; $i++ ) { $ups[] = [ 'date' => '2026-07-02', 'message' => "Update Nummer $i" ]; }
$d = data(); $d['sections']['updates'] = $ups;
$h = BW_Bridge_Report_Mail::html( $d, '' );
check( 'HTML: 20 Updates, „… und 5 weitere“', false !== strpos( $h, 'Update Nummer 20<' ) && false === strpos( $h, 'Update Nummer 21<' ) && false !== strpos( $h, '… und 5 weitere' ) );
$h = BW_Bridge_Report_Mail::html( data( [ 'sections' => [ 'updates' => [ [ 'date' => '2026-11-06', 'message' => 'Plugin installiert: X 1.0' ], [ 'date' => '2026-11-06', 'message' => 'Plugin installiert: X 1.0' ], [ 'date' => '2026-11-05', 'message' => 'Plugin installiert: X 1.0' ] ] ] ] ), 'https://x' );
check( 'HTML: gleiche Updates am selben Tag zusammengefasst', 1 === substr_count( $h, 'X 1.0 <span' ) && false !== strpos( $h, '(2×)' ) && 2 === substr_count( $h, 'Plugin installiert: X 1.0' ) );
check( 'HTML: Schrift am Inhalt (nicht nur am body)', 2 <= substr_count( $h, 'font-family:' ) );
$h = BW_Bridge_Report_Mail::html( [], '' );
check( 'HTML: leere Daten ohne Fehler', false !== strpos( $h, 'Keine offenen Punkte' ) || false !== strpos( $h, 'Keine Prüfung' ) );

array_map( 'unlink', glob( $tmp . '/*/*' ) ?: [] ); @array_map( 'rmdir', glob( $tmp . '/*' ) ?: [] ); @rmdir( $tmp );
echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
