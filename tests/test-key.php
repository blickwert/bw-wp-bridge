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

/* Benutzer-Stubs */
$GLOBALS['users'] = []; $GLOBALS['cur_user'] = 0;
class U { public $ID, $user_login, $roles = [ 'administrator' ], $meta = []; function __construct( $id, $login ) { $this->ID = $id; $this->user_login = $login; } function add_role( $r ) { $this->roles[] = $r; } }
function sanitize_user( $u, $strict = false ) { return preg_replace( '/[^a-z0-9_.\-@ ]/i', '', (string) $u ); }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['users'] as $u ) { if ( $u->user_login === $v ) { return $u; } } return false; }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function get_user_meta( $id, $k, $single = true ) { return $GLOBALS['users'][ $id ]->meta[ $k ] ?? ''; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['users'][ $id ]->meta[ $k ] = $v; return true; }
function get_users( $a ) { $o = []; foreach ( $GLOBALS['users'] as $u ) { if ( ( $u->meta[ $a['meta_key'] ] ?? null ) == $a['meta_value'] && in_array( $a['role'], $u->roles, true ) ) { $o[] = $u; } } return array_slice( $o, 0, $a['number'] ); }
function wp_insert_user( $d ) { $id = count( $GLOBALS['users'] ) + 10; $u = new U( $id, $d['user_login'] ); $u->roles = [ $d['role'] ]; $u->data = $d; $GLOBALS['users'][ $id ] = $u; return $id; }
function wp_get_current_user() { return $GLOBALS['users'][ $GLOBALS['cur_user'] ] ?? new U( 0, '' ); }
function remove_accents( $s ) { return $s; } function untrailingslashit( $s ) { return rtrim( $s, '/' ); }

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

/* 7. Dienstbenutzer */
check( 'Standard-Anmeldename ist „support“', 'support' === $K::service_login() && 'support' === $K::SERVICE_LOGIN );
$uid = $K::service_user_id();
$u   = $GLOBALS['users'][ $uid ];
check( 'neuer Dienstbenutzer: Login support, Administrator, Anzeigename Support', 'support' === $u->user_login && [ 'administrator' ] === $u->roles && 'Support' === $u->data['display_name'] && 'Support' === $u->data['nickname'] && 'Support' === $u->data['first_name'] );
check( 'neuer Dienstbenutzer ist per Meta markiert', 1 === $u->meta[ $K::SERVICE_META ] );
check( 'zweiter Aufruf legt keinen weiteren Benutzer an', $uid === $K::service_user_id() && 1 === count( $GLOBALS['users'] ) );
check( 'Umgebungsvariablen nennen den tatsächlichen Login', false !== strpos( $K::env_block( [ 'key' => 'k', 'app_password' => 'p' ], $K::service_user_login( $uid ) ), 'WP_TESTSEITE_USER=support' ) );
check( 'ohne Umbenennen kein Altname-Hinweis', null === $K::legacy_service_user() );
// is_claude
$GLOBALS['cur_user'] = $uid;
check( 'is_claude: Benutzer mit Dienstmarkierung', BW_Bridge_Log::is_claude() );
$GLOBALS['users'][ $uid ]->meta = [];
check( 'is_claude: ohne Meta, Login = service_login()', BW_Bridge_Log::is_claude() );
$GLOBALS['users'][ $uid ]->user_login = 'kunde';
check( 'is_claude: normaler Benutzer nein', ! BW_Bridge_Log::is_claude() );
$GLOBALS['users'][ $uid ]->meta[ $K::SERVICE_META ] = 1; $GLOBALS['users'][ $uid ]->user_login = 'irgendwer';
check( 'is_claude: Meta genügt auch bei beliebigem Login', BW_Bridge_Log::is_claude() );

// Altbestand: vorhandener Benutzer „claude“ wird weiterverwendet
$GLOBALS['users'] = [ 5 => new U( 5, 'claude' ) ];
$uid = $K::service_user_id();
check( 'Altbestand: Benutzer „claude“ weiterverwendet, kein zweiter Benutzer', 5 === $uid && 1 === count( $GLOBALS['users'] ) );
check( 'Altbestand: Meta wird gesetzt', 1 === $GLOBALS['users'][5]->meta[ $K::SERVICE_META ] );
check( 'Altbestand: Umgebungsvariablen zeigen „claude“', false !== strpos( $K::env_block( [ 'key' => 'k', 'app_password' => 'p' ], $K::service_user_login( $uid ) ), 'WP_TESTSEITE_USER=claude' ) );
check( 'Altbestand: Umbenennen wird angeboten', $K::legacy_service_user() && 5 === $K::legacy_service_user()->ID );
$GLOBALS['users'][5]->meta = []; $GLOBALS['cur_user'] = 5;
check( 'is_claude: früherer Login „claude“ ohne Meta', BW_Bridge_Log::is_claude() );

// Markierter Benutzer hat Vorrang vor Namen
$GLOBALS['users'][6] = new U( 6, 'support' ); $GLOBALS['users'][7] = new U( 7, 'agentur' ); $GLOBALS['users'][7]->meta[ $K::SERVICE_META ] = 1;
check( 'markierter Benutzer hat Vorrang', 7 === $K::service_user_id() );
$GLOBALS['users'] = [ 6 => new U( 6, 'support' ), 5 => new U( 5, 'claude' ) ];
check( 'ohne Markierung: service_login() vor Altname', 6 === $K::service_user_id() && 2 === count( $GLOBALS['users'] ) );

// Konstante (zuletzt, Konstanten lassen sich nicht zurücknehmen)
define( 'BW_WP_BRIDGE_SERVICE_LOGIN', 'Agentur Zugang!' );
check( 'Konstante überschreibt den Login (sanitize_user)', 'Agentur Zugang' === $K::service_login() );
$GLOBALS['users'] = [];
$uid = $K::service_user_id();
check( 'Konstante: neuer Benutzer mit diesem Login', 'Agentur Zugang' === $GLOBALS['users'][ $uid ]->user_login );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
