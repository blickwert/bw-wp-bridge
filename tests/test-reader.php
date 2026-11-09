<?php
/**
 * Test für den Lesezugang (BW_Bridge_Reader): Rolle, Rechte und welche Routen er erreicht.
 * Aufruf: php tests/test-reader.php
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['caps']   = [];
$GLOBALS['roles']  = [];
$GLOBALS['routes'] = [];
function add_action( ...$a ) {} function add_filter( ...$a ) {}
function register_setting( ...$a ) {} function add_options_page( ...$a ) {} function add_management_page( ...$a ) {}
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return $d; }
function is_user_logged_in() { return false; }
function current_user_can( $c ) { return in_array( $c, $GLOBALS['caps'], true ); }
function get_role( $r ) { return $GLOBALS['roles'][ $r ] ?? null; }
function add_role( $r, $name, $caps ) { $GLOBALS['roles'][ $r ] = $caps; return true; }
function register_rest_route( $ns, $route, $args = [] ) {
	foreach ( isset( $args['methods'] ) ? [ $args ] : $args as $h ) {
		$GLOBALS['routes'][ $h['methods'] . ' ' . $route ] = $h['permission_callback'];
	}
}
function __return_true() { return true; }
class WP_REST_Request implements ArrayAccess { function offsetExists( $o ): bool { return true; } #[\ReturnTypeWillChange] function offsetGet( $o ) { return 1; } function offsetSet( $o, $v ): void {} function offsetUnset( $o ): void {} function get_param( $k ) { return null; } }

require __DIR__ . '/../bw-wp-bridge.php';

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function allowed( $key ) { $cb = $GLOBALS['routes'][ $key ] ?? null; return null !== $cb && (bool) call_user_func( $cb, new WP_REST_Request() ); }

BW_Bridge_Reader::ensure_role();
check( 'Rolle wird angelegt, nur mit read und bw_bridge_read', [ 'read' => true, 'bw_bridge_read' => true ] === $GLOBALS['roles']['bw_bridge_reader'] );

BW_WP_Bridge::register_routes();

$reader_ok = [ 'GET /status', 'GET /history', 'POST /history/notes', 'GET /history/report', 'GET /audit', 'GET /scan', 'POST /scan', 'GET /search', 'GET /render/(?P<id>\d+)', 'GET /plugins', 'POST /elementor/clear-cache' ];

// Lesezugang
$GLOBALS['caps'] = [ 'read', 'bw_bridge_read' ];
check( 'Lesezugang: can_read', BW_WP_Bridge::can_read() );
check( 'Lesezugang: kein can_manage', ! BW_WP_Bridge::can_manage() );
check( 'Lesezugang: is_reader_only', BW_Bridge_Reader::is_reader_only() );
foreach ( $reader_ok as $key ) {
	check( "Lesezugang darf $key", allowed( $key ) );
}
$denied = array_diff( array_keys( $GLOBALS['routes'] ), $reader_ok, [ 'GET /auth-check' ] );
$leak   = array_filter( $denied, 'allowed' );
check( 'Lesezugang erreicht keine andere Route (' . count( $denied ) . ' geprüft)' . ( $leak ? ': ' . implode( ', ', $leak ) : '' ), [] === $leak && count( $denied ) > 20 );

// Administrator
$GLOBALS['caps'] = [ 'read', 'manage_options', 'activate_plugins', 'edit_post' ];
check( 'Administrator: can_read', BW_WP_Bridge::can_read() );
check( 'Administrator: nicht is_reader_only (sucht weiter in Post-Meta)', ! BW_Bridge_Reader::is_reader_only() );
check( 'Administrator darf Plugin-Liste', allowed( 'GET /plugins' ) );

// Anonym
$GLOBALS['caps'] = [];
check( 'Anonym: kein can_read', ! BW_WP_Bridge::can_read() );
check( 'Anonym: keine Plugin-Liste', ! allowed( 'GET /plugins' ) );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
