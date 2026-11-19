<?php
/**
 * Test für BW_Bridge_Site (Themes, Permalinks, Testmail, Fehlerprotokoll) gegen eine simulierte WordPress-Umgebung.
 * Aufruf: php tests/test-site.php
 */
$abs = sys_get_temp_dir() . '/bwsite_' . getmypid() . '/';
@mkdir( $abs . 'wp-admin/includes', 0777, true );
foreach ( [ 'file', 'plugin', 'misc', 'class-wp-upgrader', 'plugin-install', 'class-wp-ajax-upgrader-skin', 'theme' ] as $f ) { file_put_contents( $abs . 'wp-admin/includes/' . $f . '.php', "<?php\n" ); }
define( 'ABSPATH', $abs );
define( 'HOUR_IN_SECONDS', 3600 );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = [ 'admin_email' => 'admin@example.test', 'permalink_structure' => '' ]; $GLOBALS['transients'] = [];
$GLOBALS['caps'] = [ 'manage_options' => true, 'install_themes' => true, 'delete_themes' => true, 'switch_themes' => true, 'update_themes' => true ];
$GLOBALS['active_theme'] = 'twentytwentyfive'; $GLOBALS['actions'] = []; $GLOBALS['log'] = [];
function add_action( $t, $cb ) { $GLOBALS['actions'][ $t ][] = $cb; } function remove_action( $t, $cb ) { $GLOBALS['actions'][ $t ] = []; }
function add_filter( ...$a ) {} function apply_filters( $t, $v, ...$a ) { return $v; }
function register_setting( ...$a ) {} function register_rest_route( ...$a ) {} function add_options_page( ...$a ) {}
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
function rest_ensure_response( $d ) { return $d; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_bloginfo( $s = '' ) { return 'Testseite'; } function wp_specialchars_decode( $s ) { return $s; } function wp_date( $f ) { return '2026-01-01'; }
function get_posts( $a ) { return [ 2 ]; } function get_permalink( $id ) { return '' === $GLOBALS['opts']['permalink_structure'] ? 'https://example.test/?page_id=2' : 'https://example.test/beispielseite/'; }
function flush_rewrite_rules( $h = true ) { $GLOBALS['log'][] = 'flush'; }
function wp_mail( $to, $s, $b ) { $GLOBALS['log'][] = 'mail ' . $to; if ( ! empty( $GLOBALS['mail_fail'] ) ) { foreach ( $GLOBALS['actions']['wp_mail_failed'] ?? [] as $cb ) { $cb( new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' ) ); } return false; } return true; }
function get_stylesheet() { return $GLOBALS['active_theme']; } function get_template() { return $GLOBALS['active_theme']; }
function switch_theme( $s ) { $GLOBALS['active_theme'] = $s; }
function delete_theme( $s ) { $GLOBALS['log'][] = 'delete ' . $s; unset( $GLOBALS['themes'][ $s ] ); return true; }
function wp_get_themes( $a = [] ) { $o = []; foreach ( $GLOBALS['themes'] as $s => $t ) { $o[ $s ] = new WP_Theme( $s, $t ); } return $o; }
function wp_get_theme( $s ) { return new WP_Theme( $s, $GLOBALS['themes'][ $s ] ?? null ); }
class WP_Theme {
	private $s, $d; function __construct( $s, $d ) { $this->s = $s; $this->d = $d; }
	function exists() { return null !== $this->d; } function get_stylesheet() { return $this->s; }
	function get( $k ) { return 'Name' === $k ? ucfirst( $this->s ) : ( $this->d['version'] ?? '1.0' ); }
	function errors() { return empty( $this->d['broken'] ) ? false : new WP_Error( 'theme_no_stylesheet', 'Stylesheet fehlt.' ); }
	function parent() { return false; } function is_block_theme() { return ! empty( $this->d['block'] ); }
}
class WP_Error {
	public $code, $msg, $data; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
	function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; }
	function has_errors() { return '' !== $this->code; } function get_error_messages() { return [ $this->msg ]; }
}
class WP_Rewrite { function set_permalink_structure( $s ) { $GLOBALS['opts']['permalink_structure'] = $s; } }
$GLOBALS['wp_rewrite'] = new WP_Rewrite();
class WP_REST_Request {
	private $p; private $json;
	function __construct( $json = [], $p = [] ) { $this->json = $json; $this->p = $p; }
	function get_json_params() { return $this->json; } function get_param( $k ) { return $this->p[ $k ] ?? null; }
}

require __DIR__ . '/../bw-wp-bridge.php';

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
$S = 'BW_Bridge_Site';
$GLOBALS['themes'] = [ 'hello-biz' => [ 'version' => '1.2.2' ], 'perma-mind' => [ 'version' => '0.1.0', 'block' => true ], 'kaputt' => [ 'broken' => true ] ];

/* 1. Rechte */
check( 'Installieren ohne Freigabe: gesperrt', code( $S::perm_install() ) === 'bw_bridge_themes_disabled' && code( $S::perm_delete() ) === 'bw_bridge_themes_disabled' );
check( 'Aktivieren braucht keine Freigabe', true === $S::perm_switch() );
update_option( BW_Bridge_Settings::OPT_PLUGINS, 1 );
check( 'Freigabe an: erlaubt', true === $S::perm_install() && true === $S::perm_delete() );
$GLOBALS['caps']['delete_themes'] = false;
check( 'Löschen ohne delete_themes: gesperrt', code( $S::perm_delete() ) === 'bw_bridge_themes_forbidden' );
$GLOBALS['caps']['delete_themes'] = true;

/* 2. Themes */
$list = $S::list_themes();
$miss = array_values( array_filter( $list, fn( $t ) => $t['missing'] ) );
check( 'Liste meldet fehlendes aktives Theme', 1 === count( $miss ) && 'twentytwentyfive' === $miss[0]['stylesheet'] && $miss[0]['active'] );
check( 'Liste: Block-Theme erkannt', (bool) array_filter( $list, fn( $t ) => 'perma-mind' === $t['stylesheet'] && $t['block_theme'] ) );
check( 'Aktivieren: unbekanntes Theme → 404', code( $S::activate_theme( new WP_REST_Request( [ 'stylesheet' => 'gibtsnicht' ] ) ) ) === 'bw_bridge_themes_unknown' );
check( 'Aktivieren: fehlerhaftes Theme abgelehnt', code( $S::activate_theme( new WP_REST_Request( [ 'stylesheet' => 'kaputt' ] ) ) ) === 'bw_bridge_themes_broken' && 'twentytwentyfive' === get_stylesheet() );
$r = $S::activate_theme( new WP_REST_Request( [ 'stylesheet' => 'perma-mind' ] ) );
check( 'Aktivieren: perma-mind aktiv', ! is_wp_error( $r ) && $r['active'] && 'perma-mind' === get_stylesheet() );
check( 'Löschen: aktives Theme abgelehnt', code( $S::delete_theme( new WP_REST_Request( [ 'stylesheet' => 'perma-mind' ] ) ) ) === 'bw_bridge_themes_active' );
$r = $S::delete_theme( new WP_REST_Request( [ 'stylesheet' => 'hello-biz' ] ) );
check( 'Löschen: inaktives Theme gelöscht', ! is_wp_error( $r ) && $r['deleted'] && ! isset( $GLOBALS['themes']['hello-biz'] ) );
check( 'Installieren: genau eine Quelle', code( $S::install_theme( new WP_REST_Request( [] ) ) ) === 'bw_bridge_themes_source' && code( $S::install_theme( new WP_REST_Request( [ 'slug' => 'a', 'url' => 'https://x/y.zip' ] ) ) ) === 'bw_bridge_themes_source' );

/* 3. Permalinks */
check( 'Struktur gültig: /%postname%/ und leer', $S::valid_structure( '/%postname%/' ) && $S::valid_structure( '' ) );
check( 'Struktur ungültig: ohne Tag, ohne /, mit Anführungszeichen', ! $S::valid_structure( '/seite/' ) && ! $S::valid_structure( '%postname%' ) && ! $S::valid_structure( '/%postname%/"x' ) );
check( 'Setzen ungültig → 400', code( $S::set_permalinks( new WP_REST_Request( [ 'structure' => 'kaputt' ] ) ) ) === 'bw_bridge_permalinks' );
$r = $S::set_permalinks( new WP_REST_Request( [ 'structure' => '/%postname%/' ] ) );
check( 'Setzen: gespeichert, Regeln neu aufgebaut', '/%postname%/' === $r['structure'] && in_array( 'flush', $GLOBALS['log'], true ) && 'https://example.test/beispielseite/' === $r['example'] );

/* 4. Testmail */
$r = $S::mail_test( new WP_REST_Request( [] ) );
check( 'Testmail: Standard an Administrator-Adresse', $r['sent'] && 'admin@example.test' === $r['to'] && 'PHP mail()' === $r['mailer'] );
check( 'Testmail: ungültige Adresse → 400', code( $S::mail_test( new WP_REST_Request( [ 'to' => 'keine-adresse' ] ) ) ) === 'bw_bridge_mail' );
$GLOBALS['mail_fail'] = true;
$r = $S::mail_test( new WP_REST_Request( [ 'to' => 'a@example.test' ] ) );
check( 'Testmail: Fehler von wp_mail wird gemeldet', ! $r['sent'] && 'Could not instantiate mail function.' === $r['error'] );
$GLOBALS['transients']['bw_bridge_mail_test'] = 10;
check( 'Testmail: Grenze pro Stunde', code( $S::mail_test( new WP_REST_Request( [] ) ) ) === 'bw_bridge_mail_limit' );

/* 5. Fehlerprotokoll */
$log = tempnam( sys_get_temp_dir(), 'bwlog' );
file_put_contents( $log, implode( "\n", array_map( fn( $i ) => "[01-Jan-2026] PHP Warning: Zeile $i", range( 1, 30 ) ) ) . "\n" );
$t = $S::tail( $log, 5 );
check( 'tail: letzte 5 Zeilen', 5 === count( $t ) && str_ends_with( $t[4], 'Zeile 30' ) && str_ends_with( $t[0], 'Zeile 26' ) );
check( 'tail: mehr Zeilen als vorhanden', 30 === count( $S::tail( $log, 100 ) ) );
$r = $S::debug_log( new WP_REST_Request( [], [ 'lines' => 10 ] ) );
check( 'ohne WP_DEBUG_LOG: Hinweis statt Inhalt', ! $r['wp_debug_log'] && [] === $r['lines'] && str_contains( $r['note'], 'WP_DEBUG_LOG' ) );
define( 'WP_DEBUG_LOG', $log );
$r = $S::debug_log( new WP_REST_Request( [], [ 'lines' => 3 ] ) );
check( 'mit WP_DEBUG_LOG (Pfad): letzte Zeilen', $r['exists'] && 3 === count( $r['lines'] ) && str_ends_with( $r['lines'][2], 'Zeile 30' ) );
@unlink( $log );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
