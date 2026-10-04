<?php
/**
 * Test für BW_Bridge_Theme_Files (Theme-Dateizugriff) gegen eine simulierte WordPress-Umgebung.
 * Aufruf: php tests/test-theme-files.php
 */
define( 'ABSPATH', __DIR__ . '/' );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = []; $GLOBALS['caps'] = [ 'manage_options' => true, 'edit_themes' => true ];
function add_action( ...$a ) {} function add_filter( ...$a ) {}
function register_setting( ...$a ) {} function register_rest_route( ...$a ) {}
function add_options_page( ...$a ) {} function rest_get_url_prefix() { return 'wp-json'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
function get_stylesheet() { return 'child'; } function get_template() { return $GLOBALS['has_parent'] ? 'parent' : 'child'; }
function get_stylesheet_directory() { return $GLOBALS['tmp'] . '/themes/child'; }
function get_template_directory() { return $GLOBALS['has_parent'] ? $GLOBALS['tmp'] . '/themes/parent' : $GLOBALS['tmp'] . '/themes/child'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_upload_dir( $t = null, $c = true ) { return [ 'basedir' => $GLOBALS['tmp'] . '/uploads', 'error' => false ]; }
function wp_generate_password( $n = 12, $s = true ) { return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, $n ); }
function wp_get_theme() { return new class { function cache_delete() {} }; }
function wp_hash( $s ) { return 'abcdef0123456789abcdef'; }
function rest_ensure_response( $d ) { return $d; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $code, $msg, $data; function __construct( $c, $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } }
class WP_REST_Request implements ArrayAccess {
	private $p; private $json;
	function __construct( $p = [], $json = null ) { $this->p = $p + [ 'theme' => 'child', 'path' => '' ]; $this->json = $json; }
	function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; } function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
	function offsetSet( $k, $v ): void {} function offsetUnset( $k ): void {}
	function get_param( $k ) { return $this->p[ $k ] ?? null; } function get_json_params() { return $this->json; }
}

require __DIR__ . '/../bw-wp-bridge.php';

/* ---------- Testumgebung ---------- */
function rrmdir( $d ) { if ( ! is_dir( $d ) ) return; foreach ( scandir( $d ) as $n ) { if ( $n === '.' || $n === '..' ) continue; $p = "$d/$n"; ( is_dir( $p ) && ! is_link( $p ) ) ? rrmdir( $p ) : unlink( $p ); } rmdir( $d ); }
$GLOBALS['tmp'] = sys_get_temp_dir() . '/bwtf_' . getmypid(); $GLOBALS['has_parent'] = true;
$t = $GLOBALS['tmp']; rrmdir( $t );
foreach ( [ 'themes/child/woocommerce/emails', 'themes/child/languages', 'themes/parent', 'uploads', 'outside' ] as $d ) mkdir( "$t/$d", 0777, true );
file_put_contents( "$t/themes/child/style.css", "/* Theme */\n" );
file_put_contents( "$t/themes/child/woocommerce/emails/customer-new-account.php", "<?php\necho 'alt';\n" );
file_put_contents( "$t/themes/parent/style.css", "/* Parent */\n" );
file_put_contents( "$t/outside/geheim.txt", 'GEHEIM' );
@symlink( "$t/outside", "$t/themes/child/link" );

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
$F = 'BW_Bridge_Theme_Files';

/* 1. Standard: alles aus */
check( 'Standard: Lesen ist aus', true !== $F::perm_read() && code( $F::perm_read() ) === 'bw_bridge_files_disabled' );
check( 'Standard: Schreiben ist aus', code( $F::perm_write() ) === 'bw_bridge_files_disabled' );
/* 2. Nur lesen */
update_option( $F::OPT_READ, 1 );
check( 'Lesen an: erlaubt', true === $F::perm_read() );
check( 'Lesen an, Schreiben aus: Schreiben gesperrt', code( $F::perm_write() ) === 'bw_bridge_files_readonly' );
update_option( $F::OPT_WRITE, 1 );
check( 'Schreiben an: erlaubt', true === $F::perm_write() );
$GLOBALS['caps']['edit_themes'] = false;
check( 'Ohne edit_themes: Schreiben gesperrt', code( $F::perm_write() ) === 'bw_bridge_files_forbidden' );
$GLOBALS['caps']['edit_themes'] = true;
$GLOBALS['caps']['manage_options'] = false;
check( 'Ohne manage_options: kein Zugriff', false === $F::perm_read() );
$GLOBALS['caps']['manage_options'] = true;
update_option( $F::OPT_READ, 0 );
check( 'Schreiben ohne Lesen wirkt nicht', ! $F::can_write_enabled() );
update_option( $F::OPT_READ, 1 );

/* 3. Listing und Lesen */
$l = $F::get( new WP_REST_Request( [ 'path' => '' ] ) );
$names = array_column( $l['entries'], 'path' );
check( 'Listing: Wurzel zeigt style.css und woocommerce', in_array( 'style.css', $names ) && in_array( 'woocommerce', $names ) );
$r = $F::get( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ] ) );
check( 'Lesen: Inhalt und sha1', $r['content'] === "<?php\necho 'alt';\n" && $r['sha1'] === sha1( $r['content'] ) && $r['encoding'] === 'utf8' );

/* 4. Pfadschutz */
foreach ( [ '../outside/geheim.txt', 'woocommerce/../../outside/geheim.txt', '/etc/passwd', '..', 'a/./b', '.env', 'woocommerce/.git/config', "x\0y", 'node_modules/x.js' ] as $bad ) {
	$res = $F::get( new WP_REST_Request( [ 'path' => $bad ] ) );
	check( 'Pfad abgewiesen: ' . str_replace( "\0", '\\0', $bad ), is_wp_error( $res ) );
}
$res = $F::get( new WP_REST_Request( [ 'path' => 'link/geheim.txt' ] ) );
check( 'Symlink aus dem Theme heraus wird abgewiesen', is_wp_error( $res ) && $res->get_error_code() === 'bw_bridge_files_path' );
$res = $F::put( new WP_REST_Request( [ 'path' => 'link/neu.txt' ], [ 'content' => 'x' ] ) );
check( 'Schreiben über Symlink nach außen wird abgewiesen', is_wp_error( $res ) && ! file_exists( "$t/outside/neu.txt" ) );

/* 5. Schreiben */
$res = $F::put( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-on-hold-order.php' ], [ 'content' => "<?php\n// neu\n" ] ) );
check( 'Neue Datei in neuem Ordner anlegen', code( $res ) === 'ok' && $res['created'] === true && file_get_contents( "$t/themes/child/woocommerce/emails/customer-on-hold-order.php" ) === "<?php\n// neu\n" );
$res = $F::put( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ], [ 'content' => "<?php\necho 'neu';\n" ] ) );
$bk = $res['backup'] ?? null;
check( 'Überschreiben: Sicherung wird angelegt', $bk && file_exists( "$t/uploads/bw-bridge-backups-abcdef012345/child/woocommerce/emails/$bk" ) && file_get_contents( "$t/uploads/bw-bridge-backups-abcdef012345/child/woocommerce/emails/$bk" ) === "<?php\necho 'alt';\n" );
check( 'Sicherungsordner ist geschützt (.htaccess)', file_exists( "$t/uploads/bw-bridge-backups-abcdef012345/.htaccess" ) );
$res = $F::put( new WP_REST_Request( [ 'path' => 'fehler.php' ], [ 'content' => "<?php\nfunction (\n" ] ) );
check( 'PHP-Syntaxfehler wird abgelehnt', code( $res ) === 'bw_bridge_files_php_syntax' && ! file_exists( "$t/themes/child/fehler.php" ) );
$res = $F::put( new WP_REST_Request( [ 'path' => 'shell.exe' ], [ 'content' => 'x' ] ) );
check( 'Dateityp nicht erlaubt (.exe)', code( $res ) === 'bw_bridge_files_ext' );
$res = $F::put( new WP_REST_Request( [ 'path' => 'groß.txt' ], [ 'content' => str_repeat( 'a', 1048577 ) ] ) );
check( 'Zu große Datei abgelehnt', code( $res ) === 'bw_bridge_files_too_large' );
$res = $F::put( new WP_REST_Request( [ 'path' => 'x.txt' ], [ 'content' => "\xff\xfe" ] ) );
check( 'Ungültiges UTF-8 abgelehnt', code( $res ) === 'bw_bridge_invalid' );
$res = $F::put( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ], [ 'content' => "<?php\n", 'expected_sha1' => 'falsch' ] ) );
check( 'Konflikt bei falschem sha1 (409)', code( $res ) === 'bw_bridge_files_conflict' );
$cur = $F::get( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ] ) );
$res = $F::put( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ], [ 'content' => "<?php\necho 'v3';\n", 'expected_sha1' => $cur['sha1'] ] ) );
check( 'Schreiben mit passendem sha1', code( $res ) === 'ok' );
$bin = "\x00\x01\xff\xfe";
$res = $F::put( new WP_REST_Request( [ 'path' => 'languages/bw-emails-de_DE.mo' ], [ 'content' => base64_encode( $bin ), 'encoding' => 'base64' ] ) );
$rd = $F::get( new WP_REST_Request( [ 'path' => 'languages/bw-emails-de_DE.mo' ] ) );
check( 'Binärdatei (.mo) per Base64 hin und zurück', code( $res ) === 'ok' && $rd['encoding'] === 'base64' && base64_decode( $rd['content'] ) === $bin );
$res = $F::put( new WP_REST_Request( [ 'path' => 'languages' ], [ 'content' => 'x' ] ) );
check( 'Ordner kann nicht als Datei geschrieben werden', is_wp_error( $res ) );
check( 'Keine temporären Dateien übrig', ! array_filter( glob( "$t/themes/child/woocommerce/emails/.bw-bridge-*" ) ) );

/* 6. Sicherungen und Wiederherstellen */
$list = $F::backups( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ] ) );
check( 'Sicherungsliste: mindestens 2 Einträge', count( $list['backups'] ) >= 2 );
$res = $F::restore( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ], [ 'backup' => $bk ] ) );
check( 'Wiederherstellen der ersten Sicherung', code( $res ) === 'ok' && file_get_contents( "$t/themes/child/woocommerce/emails/customer-new-account.php" ) === "<?php\necho 'alt';\n" );
$res = $F::restore( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-new-account.php' ], [ 'backup' => '../../x.bak' ] ) );
check( 'Wiederherstellen mit Pfadtrick abgewiesen', code( $res ) === 'bw_bridge_files_not_found' );

/* 7. Löschen */
$res = $F::delete( new WP_REST_Request( [ 'path' => 'woocommerce/emails/customer-on-hold-order.php' ] ) );
check( 'Datei löschen (mit Sicherung)', code( $res ) === 'ok' && ! file_exists( "$t/themes/child/woocommerce/emails/customer-on-hold-order.php" ) && $res['backup'] );
$res = $F::delete( new WP_REST_Request( [ 'path' => 'languages' ] ) );
check( 'Ordner löschen wird abgewiesen', is_wp_error( $res ) && is_dir( "$t/themes/child/languages" ) );

/* 8. Parent-Theme */
$res = $F::get( new WP_REST_Request( [ 'theme' => 'parent', 'path' => 'style.css' ] ) );
check( 'Parent ohne Freigabe gesperrt', code( $res ) === 'bw_bridge_files_parent_off' );
update_option( $F::OPT_PARENT, 1 );
$res = $F::get( new WP_REST_Request( [ 'theme' => 'parent', 'path' => 'style.css' ] ) );
check( 'Parent mit Freigabe lesbar', code( $res ) === 'ok' && $res['theme'] === 'parent' );
$res = $F::put( new WP_REST_Request( [ 'theme' => 'parent', 'path' => 'neu.css' ], [ 'content' => 'a{}' ] ) );
check( 'Parent beschreibbar mit Freigabe', code( $res ) === 'ok' && file_exists( "$t/themes/parent/neu.css" ) );
$GLOBALS['has_parent'] = false;
$res = $F::get( new WP_REST_Request( [ 'theme' => 'parent', 'path' => 'style.css' ] ) );
check( 'Ohne Parent-Theme: 404', code( $res ) === 'bw_bridge_files_no_parent' );
$GLOBALS['has_parent'] = true;

/* 9. Hart abschalten, wp-config-Sperre, Status */
check( 'Status meldet Freigaben', BW_Bridge_Theme_Files::summary()['write'] === true && BW_Bridge_Theme_Files::summary()['theme'] === 'child' );
define( 'DISALLOW_FILE_EDIT', true );
check( 'DISALLOW_FILE_EDIT sperrt Schreiben', code( $F::perm_write() ) === 'bw_bridge_files_forbidden' && true === $F::perm_read() );
define( 'BW_WP_BRIDGE_FILES_DISABLED', true );
check( 'BW_WP_BRIDGE_FILES_DISABLED sperrt alles', code( $F::perm_read() ) === 'bw_bridge_files_disabled' );

rrmdir( $t );
printf( "\n%d/%d Prüfungen bestanden\n", $pass, $pass + $fail );
exit( $fail ? 1 : 0 );
