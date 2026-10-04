<?php
/**
 * Zeichnet auf, was das Plugin bei WordPress registriert (Hooks, REST-Routen, Einstellungen, Menü),
 * und gibt es als sortiertes JSON aus. Dient als Regressionstest für Umbauten ohne Verhaltensänderung:
 *   php tests/registrations.php > nachher.json && diff vorher.json nachher.json
 * Aufruf mit --check: vergleicht mit tests/registrations.expected.json (Exit-Code 1 bei Abweichung).
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['rec'] = [ 'hooks' => [], 'routes' => [], 'settings' => [], 'menu' => [] ];
$GLOBALS['stored'] = [];

function bw_cb( $cb ) {
	if ( is_array( $cb ) ) { return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : 'Klasse' ) . '::' . $cb[1]; }
	if ( $cb instanceof Closure ) { return 'closure'; }
	return (string) $cb;
}
function bw_method( $cb ) { $s = bw_cb( $cb ); return false !== strpos( $s, '::' ) ? substr( $s, strpos( $s, '::' ) + 2 ) : $s; }
function add_action( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['rec']['hooks'][] = [ 'action', $tag, bw_method( $cb ), $prio ]; $GLOBALS['stored'][ $tag ][] = $cb; }
function add_filter( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['rec']['hooks'][] = [ 'filter', $tag, bw_method( $cb ), $prio ]; }
function register_setting( $group, $name, $args = [] ) { $GLOBALS['rec']['settings'][] = [ $group, $name, $args['type'] ?? null, $args['default'] ?? null, $args['show_in_rest'] ?? null ]; }
function add_options_page( $t, $m, $cap, $slug, $cb = null ) { $GLOBALS['rec']['menu'][] = [ $t, $m, $cap, $slug, $cb ? bw_method( $cb ) : null ]; }
function register_rest_route( $ns, $route, $args = [] ) {
	$handlers = isset( $args['methods'] ) ? [ $args ] : $args;
	foreach ( $handlers as $h ) {
		$GLOBALS['rec']['routes'][] = [
			$ns . $route, (string) ( $h['methods'] ?? '' ), bw_method( $h['callback'] ?? '' ),
			is_callable( $h['permission_callback'] ?? null ) || is_string( $h['permission_callback'] ?? null ) ? bw_method( $h['permission_callback'] ) : null,
			array_keys( $h['args'] ?? [] ),
		];
	}
}
function rest_get_url_prefix() { return 'wp-json'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return $d; }
function wp_is_application_passwords_available() { return true; } function is_user_logged_in() { return false; }
function current_user_can( $c ) { return false; } function is_wp_error( $x ) { return false; }
function __return_true() { return true; }

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'u:p' );
require __DIR__ . '/../bw-wp-bridge.php';

foreach ( [ 'rest_api_init', 'admin_menu', 'admin_init' ] as $tag ) {
	foreach ( $GLOBALS['stored'][ $tag ] ?? [] as $cb ) { if ( is_callable( $cb ) ) { call_user_func( $cb ); } }
}
$r = $GLOBALS['rec'];
foreach ( $r as $k => $v ) { sort( $v ); $r[ $k ] = $v; }
// Zusatzinfos: von außen aufrufbare Klassen/Funktionen, Konstanten
$r['symbols'] = [
	'BW_WP_Bridge' => class_exists( 'BW_WP_Bridge' ),
	'BW_Bridge_Theme_Files' => class_exists( 'BW_Bridge_Theme_Files' ),
	'NS' => defined( 'BW_WP_Bridge::NS' ) ? BW_WP_Bridge::NS : null,
	'auth_seen' => $GLOBALS['bw_bridge_auth_seen'] ?? null,
	'server_php_auth_user' => $_SERVER['PHP_AUTH_USER'] ?? null,
];
$json = json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
if ( in_array( '--check', $argv, true ) ) {
	$exp = @file_get_contents( __DIR__ . '/registrations.expected.json' );
	echo $json === $exp ? "OK: Registrierungen unverändert\n" : "ABWEICHUNG gegenüber tests/registrations.expected.json\n";
	exit( $json === $exp ? 0 : 1 );
}
echo $json;
