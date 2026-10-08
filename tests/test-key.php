<?php
/**
 * Test für BW_Bridge_Key (Verbindungsschlüssel) gegen eine simulierte WordPress-Umgebung.
 * Aufruf: php tests/test-key.php
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts'] = []; $GLOBALS['logged_in'] = true; $GLOBALS['app_pw'] = false; $GLOBALS['transients'] = [];
function add_action( ...$a ) {} function add_filter( ...$a ) {}
function register_setting( ...$a ) {} function register_rest_route( ...$a ) {} function add_options_page( ...$a ) {}
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function wp_salt( $s = 'auth' ) { return 'salz-' . $s; }
function wp_generate_password( $n = 12, $s = true, $e = false ) { return substr( str_repeat( 'abcdef0123456789XYZ', 5 ), 0, $n ); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function did_action( $t ) { return 'application_password_did_authenticate' === $t && $GLOBALS['app_pw'] ? 1 : 0; }
function get_bloginfo( $s = '' ) { return 'Testseite'; } function home_url( $p = '' ) { return 'https://example.test' . $p; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_current_user_id() { return 1; }
class WP_Error { public $code, $msg, $data; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }
class Req { public $route, $headers; function __construct( $route, $key = null ) { $this->route = $route; $this->headers = $key === null ? [] : [ 'x_bw_bridge_key' => $key ]; } function get_route() { return $this->route; } function get_header( $n ) { return $this->headers[ $n ] ?? null; } }

require __DIR__ . '/../bw-wp-bridge.php';

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
$K = 'BW_Bridge_Key';

/* 1. Ohne Schlüssel ändert sich nichts */
check( 'ohne Schlüssel: Bridge-Route läuft durch', null === $K::enforce( null, null, new Req( '/bw-bridge/v1/theme/files' ) ) );
check( 'ohne Schlüssel: nicht aktiv', ! $K::is_active() && $K::summary()['key']['valid'] === true );

/* 2. Schlüssel erzeugen */
$key = $K::generate();
check( 'Schlüssel hat das Präfix bwk_', 0 === strpos( $key, 'bwk_' ) && strlen( $key ) === 44 );
check( 'nur der Hash wird gespeichert, nicht der Schlüssel', strpos( serialize( $GLOBALS['opts'] ), $key ) === false );
check( 'Kennung: 8 Hex-Zeichen', (bool) preg_match( '/^[0-9a-f]{8}$/', $K::site_id() ) );
$id = $K::site_id(); $K::generate();
check( 'neuer Schlüssel behält die Kennung', $K::site_id() === $id );
$key = $K::generate();

/* 3. Prüfung */
check( 'richtiger Schlüssel: erlaubt', $K::is_valid( $key ) && null === $K::enforce( null, null, new Req( '/bw-bridge/v1/theme/files', $key ) ) );
check( 'falscher Schlüssel: 403 bw_bridge_key', code( $K::enforce( null, null, new Req( '/bw-bridge/v1/theme/files', 'bwk_falsch' ) ) ) === 'bw_bridge_key' );
check( 'kein Schlüssel gesendet: 403', code( $K::enforce( null, null, new Req( '/bw-bridge/v1/elementor/12' ) ) ) === 'bw_bridge_key' );
$e = $K::enforce( null, null, new Req( '/bw-bridge/v1/plugins' ) );
check( 'Fehlermeldung nennt Website und Kennung', strpos( $e->get_error_message(), 'Testseite' ) !== false && strpos( $e->get_error_message(), $id ) !== false && $e->data['status'] === 403 );
check( 'status ist ohne Schlüssel erreichbar', null === $K::enforce( null, null, new Req( '/bw-bridge/v1/status' ) ) );
check( 'auth-check ist ohne Schlüssel erreichbar', null === $K::enforce( null, null, new Req( '/bw-bridge/v1/auth-check' ) ) );
check( 'schon beantwortete Anfragen bleiben unberührt', 'x' === $K::enforce( 'x', null, new Req( '/bw-bridge/v1/plugins' ) ) );
$GLOBALS['logged_in'] = false;
check( 'nicht angemeldet: nicht zuständig (die Route lehnt selbst ab)', null === $K::enforce( null, null, new Req( '/bw-bridge/v1/plugins' ) ) );
$GLOBALS['logged_in'] = true;

/* 4. Andere REST-Routen */
check( 'Standard: Kern-Routen ohne Schlüssel erlaubt', null === $K::enforce( null, null, new Req( '/wp/v2/pages' ) ) );
update_option( $K::OPT_ALL, 1 );
check( 'alle Anfragen: Cookie-Anmeldung bleibt erlaubt', null === $K::enforce( null, null, new Req( '/wp/v2/pages' ) ) );
$GLOBALS['app_pw'] = true;
check( 'alle Anfragen: Anwendungspasswort ohne Schlüssel gesperrt', code( $K::enforce( null, null, new Req( '/wp/v2/pages' ) ) ) === 'bw_bridge_key' );
check( 'alle Anfragen: Anwendungspasswort mit Schlüssel erlaubt', null === $K::enforce( null, null, new Req( '/wp/v2/pages', $key ) ) );

/* 5. status-Angaben */
$s = $K::summary( new Req( '/bw-bridge/v1/status', 'bwk_falsch' ) );
check( 'status: falscher Schlüssel → valid=false, Website erkennbar', $s['key']['required'] && ! $s['key']['valid'] && $s['site']['id'] === $id && $s['site']['name'] === 'Testseite' );
check( 'status: richtiger Schlüssel → valid=true', $K::summary( new Req( '/x', $key ) )['key']['valid'] === true );

/* 6. Entfernen */
$K::remove();
check( 'entfernt: wieder ohne Prüfung', ! $K::is_active() && null === $K::enforce( null, null, new Req( '/bw-bridge/v1/plugins' ) ) && $K::site_id() === '' );
check( 'alter Schlüssel nach dem Entfernen ungültig', ! $K::is_valid( $key ) );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
