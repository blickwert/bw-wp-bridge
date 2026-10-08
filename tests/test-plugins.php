<?php
/**
 * Test für BW_Bridge_Plugins (Plugins installieren/aktualisieren) gegen eine simulierte WordPress-Umgebung.
 * Aufruf: php tests/test-plugins.php
 */
$abs = sys_get_temp_dir() . '/bwplug_' . getmypid() . '/';
@mkdir( $abs . 'wp-admin/includes', 0777, true );
foreach ( [ 'file', 'plugin', 'misc', 'class-wp-upgrader', 'plugin-install', 'class-wp-ajax-upgrader-skin' ] as $f ) { file_put_contents( $abs . 'wp-admin/includes/' . $f . '.php', "<?php\n" ); }
define( 'ABSPATH', $abs );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = []; $GLOBALS['caps'] = [ 'manage_options' => true, 'install_plugins' => true, 'update_plugins' => true, 'activate_plugins' => true ];
$GLOBALS['hosts'] = []; $GLOBALS['log'] = [];
function add_action( ...$a ) {} function add_filter( ...$a ) {}
function register_setting( ...$a ) {} function register_rest_route( ...$a ) {} function add_options_page( ...$a ) {}
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
function apply_filters( $tag, $v, ...$a ) { return 'bw_bridge_plugin_allowed_hosts' === $tag ? $GLOBALS['hosts'] : $v; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function rest_ensure_response( $d ) { return $d; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_delete_file( $f ) { $GLOBALS['log'][] = 'delete'; @unlink( $f ); }
function wp_tempnam( $n = '' ) { return tempnam( sys_get_temp_dir(), 'bwp' ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_clean_plugins_cache( $b = true ) {} function wp_update_plugins() {}
function get_site_transient( $k ) { return $GLOBALS['updates'] ?? (object) [ 'response' => [] ]; }
function get_plugins() { return $GLOBALS['plugins']; }
function is_plugin_active( $f ) { return ! empty( $GLOBALS['active'][ $f ] ); }
function activate_plugin( $f ) { if ( ! empty( $GLOBALS['activate_fail'] ) ) return new WP_Error( 'x', 'Fatal' ); $GLOBALS['active'][ $f ] = true; return null; }
function download_url( $u, $t = 300 ) { $GLOBALS['log'][] = 'download ' . $u; $f = tempnam( sys_get_temp_dir(), 'bwd' ); file_put_contents( $f, $GLOBALS['download_body'] ); return $f; }
function plugins_api( $a, $args ) { return 'unknown-slug' === $args['slug'] ? new WP_Error( 'api', 'Plugin nicht gefunden' ) : (object) [ 'download_link' => 'https://downloads.wordpress.org/plugin/' . $args['slug'] . '.zip' ]; }
class WP_Error {
	public $code, $msg, $data; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
	function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; }
	function has_errors() { return '' !== $this->code; } function get_error_messages() { return [ $this->msg ]; }
}
class WP_Ajax_Upgrader_Skin { public $err; function get_errors() { return $GLOBALS['skin_error'] ?? new WP_Error(); } function get_upgrade_messages() { return [ '<b>Plugin installiert</b>' ]; } }
class Plugin_Upgrader {
	public $skin; function __construct( $s ) { $this->skin = $s; }
	function install( $package, $args = [] ) {
		$GLOBALS['log'][] = 'install ' . $package . ' overwrite=' . ( $args['overwrite_package'] ? '1' : '0' );
		if ( ! empty( $GLOBALS['install_result'] ) ) return $GLOBALS['install_result'];
		$GLOBALS['plugins']['neu/neu.php'] = [ 'Name' => 'Neu', 'Version' => '1.0' ]; return true;
	}
	function plugin_info() { return $GLOBALS['plugin_info'] ?? 'neu/neu.php'; }
	function upgrade( $f ) { $GLOBALS['log'][] = 'upgrade ' . $f; $GLOBALS['plugins'][ $f ]['Version'] = '2.0'; return true; }
}
class WP_REST_Request {
	private $p; private $json;
	function __construct( $json = [], $p = [] ) { $this->json = $json; $this->p = $p; }
	function get_json_params() { return $this->json; } function get_param( $k ) { return $this->p[ $k ] ?? null; }
}

require __DIR__ . '/../bw-wp-bridge.php';

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function zip_file( array $entries ) { $f = tempnam( sys_get_temp_dir(), 'bwz' ); $z = new ZipArchive(); $z->open( $f, ZipArchive::OVERWRITE ); foreach ( $entries as $n => $c ) $z->addFromString( $n, $c ); $z->close(); return $f; }
function reset_env() { $GLOBALS['log'] = []; $GLOBALS['plugins'] = [ 'alt/alt.php' => [ 'Name' => 'Alt', 'Version' => '1.0' ] ]; $GLOBALS['active'] = []; unset( $GLOBALS['install_result'], $GLOBALS['skin_error'], $GLOBALS['plugin_info'], $GLOBALS['activate_fail'], $GLOBALS['updates'] ); }
$P = 'BW_Bridge_Plugins';
reset_env();

/* 1. Freigaben */
check( 'Standard: Installation ist aus', code( $P::perm_install() ) === 'bw_bridge_plugins_disabled' );
check( 'Standard: Auflisten ist erlaubt', true === $P::perm_list() );
update_option( BW_Bridge_Settings::OPT_PLUGINS, 1 );
check( 'Freigabe an: erlaubt', true === $P::perm_install() && true === $P::perm_update() );
$GLOBALS['caps']['install_plugins'] = false;
check( 'Ohne install_plugins: gesperrt', code( $P::perm_install() ) === 'bw_bridge_plugins_forbidden' );
$GLOBALS['caps']['install_plugins'] = true; $GLOBALS['caps']['update_plugins'] = false;
check( 'Aktualisieren ohne update_plugins: gesperrt', code( $P::perm_update() ) === 'bw_bridge_plugins_forbidden' && true === $P::perm_install() );
$GLOBALS['caps']['update_plugins'] = true; $GLOBALS['caps']['manage_options'] = false;
check( 'Ohne manage_options: kein Zugriff', false === $P::perm_install() && false === $P::perm_list() );
$GLOBALS['caps']['manage_options'] = true;
check( 'Zusammenfassung: install an', BW_Bridge_Settings::plugins_summary()['install'] === true );

/* 2. URL-Prüfung */
check( 'URL: https erlaubt', true === $P::check_url( 'https://github.com/x/y/releases/download/1/y.zip' ) );
check( 'URL: http abgelehnt', code( $P::check_url( 'http://example.com/a.zip' ) ) === 'bw_bridge_plugins_url' );
check( 'URL: file:// abgelehnt', code( $P::check_url( 'file:///etc/passwd' ) ) === 'bw_bridge_plugins_url' );
check( 'URL: Zugangsdaten abgelehnt', code( $P::check_url( 'https://u:p@example.com/a.zip' ) ) === 'bw_bridge_plugins_url' );
$GLOBALS['hosts'] = [ 'github.com' ];
check( 'URL: Host-Liste lässt andere Hosts nicht zu', code( $P::check_url( 'https://example.com/a.zip' ) ) === 'bw_bridge_plugins_url' && true === $P::check_url( 'https://GitHub.com/a.zip' ) );
$GLOBALS['hosts'] = [];

/* 3. ZIP-Prüfung */
$good = zip_file( [ 'neu/neu.php' => '<?php /* Plugin Name: Neu */' ] );
check( 'ZIP: gültig', true === $P::check_zip_file( $good ) );
$txt = tempnam( sys_get_temp_dir(), 'bwt' ); file_put_contents( $txt, 'kein zip' );
check( 'ZIP: Textdatei abgelehnt', code( $P::check_zip_file( $txt ) ) === 'bw_bridge_plugins_zip' );
check( 'ZIP: ".." im Pfad abgelehnt', code( $P::check_zip_file( zip_file( [ '../evil.php' => 'x' ] ) ) ) === 'bw_bridge_plugins_zip' );
check( 'ZIP: verschachteltes ".." abgelehnt', code( $P::check_zip_file( zip_file( [ 'a/../../evil.php' => 'x' ] ) ) ) === 'bw_bridge_plugins_zip' );
check( 'ZIP: absoluter Pfad abgelehnt', code( $P::check_zip_file( zip_file( [ '/etc/evil.php' => 'x' ] ) ) ) === 'bw_bridge_plugins_zip' );
check( 'ZIP: Dateiname mit Punkten ist ok', true === $P::check_zip_file( zip_file( [ 'a/b..c.php' => 'x' ] ) ) );

/* 4. Installation */
reset_env();
$r = $P::install( new WP_REST_Request( [ 'slug' => 'wordpress-seo', 'activate' => true ] ) );
check( 'slug: installiert und aktiviert', ! is_wp_error( $r ) && $r['plugin'] === 'neu/neu.php' && $r['installed'] && $r['activated'] && $r['active'] );
check( 'slug: Upgrader bekommt die wordpress.org-Adresse', in_array( 'install https://downloads.wordpress.org/plugin/wordpress-seo.zip overwrite=0', $GLOBALS['log'], true ) );
check( 'Meldungen des Upgraders ohne HTML', $r['messages'] === [ 'Plugin installiert' ] );
check( 'slug: ungültiger slug abgelehnt', code( $P::install( new WP_REST_Request( [ 'slug' => '../x' ] ) ) ) === 'bw_bridge_plugins_slug' );
check( 'slug: unbekanntes Plugin → Fehler', code( $P::install( new WP_REST_Request( [ 'slug' => 'unknown-slug' ] ) ) ) === 'bw_bridge_plugins_slug' );
check( 'ohne Quelle: Fehler', code( $P::install( new WP_REST_Request( [] ) ) ) === 'bw_bridge_plugins_source' );
check( 'zwei Quellen: Fehler', code( $P::install( new WP_REST_Request( [ 'slug' => 'a', 'url' => 'https://x.test/a.zip' ] ) ) ) === 'bw_bridge_plugins_source' );

reset_env(); $GLOBALS['download_body'] = file_get_contents( $good );
$r = $P::install( new WP_REST_Request( [ 'url' => 'https://example.com/neu.zip', 'overwrite' => true ] ) );
check( 'url: installiert, nicht aktiviert', ! is_wp_error( $r ) && $r['installed'] && ! $r['activated'] && ! $r['active'] );
check( 'url: overwrite wird weitergereicht, Temp-Datei gelöscht', (bool) preg_grep( '/^install .* overwrite=1$/', $GLOBALS['log'] ) && in_array( 'delete', $GLOBALS['log'], true ) );
reset_env(); $GLOBALS['download_body'] = 'html statt zip';
check( 'url: Nicht-ZIP wird nicht installiert', code( $P::install( new WP_REST_Request( [ 'url' => 'https://example.com/x.zip' ] ) ) ) === 'bw_bridge_plugins_zip' && ! preg_grep( '/^install/', $GLOBALS['log'] ) );
reset_env();
check( 'url: http abgelehnt, kein Download', code( $P::install( new WP_REST_Request( [ 'url' => 'http://example.com/x.zip' ] ) ) ) === 'bw_bridge_plugins_url' && ! preg_grep( '/^download/', $GLOBALS['log'] ) );

reset_env();
$r = $P::install( new WP_REST_Request( [ 'zip_base64' => base64_encode( file_get_contents( $good ) ), 'activate' => true ] ) );
check( 'zip_base64: installiert und aktiviert', ! is_wp_error( $r ) && $r['activated'] );
check( 'zip_base64: kaputtes base64 abgelehnt', code( $P::install( new WP_REST_Request( [ 'zip_base64' => '***' ] ) ) ) === 'bw_bridge_plugins_zip' );

reset_env(); $GLOBALS['caps']['update_plugins'] = false;
check( 'overwrite ohne update_plugins: gesperrt', code( $P::install( new WP_REST_Request( [ 'slug' => 'a', 'overwrite' => true ] ) ) ) === 'bw_bridge_plugins_forbidden' );
$GLOBALS['caps']['update_plugins'] = true; $GLOBALS['caps']['activate_plugins'] = false;
check( 'activate ohne activate_plugins: gesperrt', code( $P::install( new WP_REST_Request( [ 'slug' => 'a', 'activate' => true ] ) ) ) === 'bw_bridge_plugins_forbidden' );
$GLOBALS['caps']['activate_plugins'] = true;

reset_env(); $GLOBALS['install_result'] = new WP_Error( 'folder_exists', 'Zielordner existiert bereits.' );
check( 'Upgrader-Fehler wird als Fehler gemeldet', code( $P::install( new WP_REST_Request( [ 'slug' => 'a' ] ) ) ) === 'bw_bridge_plugins_install' );
reset_env(); $GLOBALS['skin_error'] = new WP_Error( 'skin', 'Kein Plugin gefunden' );
check( 'Fehler der Skin wird gemeldet', code( $P::install( new WP_REST_Request( [ 'slug' => 'a' ] ) ) ) === 'bw_bridge_plugins_install' );
reset_env(); $GLOBALS['activate_fail'] = true;
check( 'Aktivierung schlägt fehl: Fehler mit Hinweis', code( $P::install( new WP_REST_Request( [ 'slug' => 'a', 'activate' => true ] ) ) ) === 'bw_bridge_plugins_activate' );

/* 5. Aktualisieren */
reset_env();
check( 'update: unbekanntes Plugin', code( $P::update( new WP_REST_Request( [ 'plugin' => 'x/x.php' ] ) ) ) === 'bw_bridge_plugins_unknown' );
$r = $P::update( new WP_REST_Request( [ 'plugin' => 'alt/alt.php' ] ) );
check( 'update: ohne verfügbares Update nichts zu tun', ! is_wp_error( $r ) && $r['updated'] === false && ! preg_grep( '/^upgrade/', $GLOBALS['log'] ) );
$GLOBALS['updates'] = (object) [ 'response' => [ 'alt/alt.php' => (object) [ 'new_version' => '2.0' ] ] ];
$r = $P::update( new WP_REST_Request( [ 'plugin' => 'alt/alt.php' ] ) );
check( 'update: aktualisiert', ! is_wp_error( $r ) && $r['updated'] && $r['old_version'] === '1.0' && $r['version'] === '2.0' );

/* 6. Liste */
$GLOBALS['plugins']['alt/alt.php']['Version'] = '1.0'; $GLOBALS['active']['alt/alt.php'] = true;
$l = $P::list_plugins();
check( 'Liste: Status und Update-Hinweis', $l[0]['plugin'] === 'alt/alt.php' && $l[0]['active'] && $l[0]['update'] === '2.0' );

/* 7. Hart abgeschaltet */
define( 'BW_WP_BRIDGE_PLUGINS_DISABLED', true );
check( 'wp-config-Konstante schaltet fest ab', code( $P::perm_install() ) === 'bw_bridge_plugins_disabled' && BW_Bridge_Settings::plugins_summary()['hard_disabled'] );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
