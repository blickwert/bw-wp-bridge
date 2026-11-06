<?php
/**
 * Test für den Datei- und Schwachstellen-Scan (BW_Bridge_Scan) gegen eine simulierte WordPress-Installation in einem
 * temporären Ordner (echte Dateien, Datenbank im Speicher, HTTP-Antworten vorgegeben). Aufruf: php tests/test-scan.php
 */
$root = sys_get_temp_dir() . '/bwscan_' . getmypid();
define( 'ABSPATH', $root . '/' ); define( 'WP_CONTENT_DIR', $root . '/wp-content' ); define( 'WP_PLUGIN_DIR', $root . '/wp-content/plugins' ); define( 'WPMU_PLUGIN_DIR', $root . '/wp-content/mu-plugins' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' );

function put( $rel, $content, $mtime = null ) {
	$f = ABSPATH . $rel;
	@mkdir( dirname( $f ), 0777, true );
	file_put_contents( $f, $content );
	touch( $f, $mtime ?? time() - 1000 );
}
put( 'index.php', "<?php require 'wp-blog-header.php';" );
put( 'wp-config.php', '<?php // config' );
put( 'wp-admin/index.php', '<?php // admin' );
put( 'wp-includes/version.php', '<?php $wp_version = "7.1";' );
put( 'wp-includes/js/jquery.js', '/* jquery */' );
put( 'wp-content/index.php', '<?php // Silence' );
put( 'wp-content/plugins/foo/foo.php', "<?php\n/* Plugin Name: Foo */\necho 1;" );
put( 'wp-content/plugins/foo/js/app.js', 'console.log(1)' );
put( 'wp-content/plugins/foo/readme.txt', 'nur Text' );
put( 'wp-content/plugins/hello.php', '<?php // Hello Dolly' );
put( 'wp-content/themes/mytheme/functions.php', '<?php // Theme' );
put( 'wp-content/themes/mytheme/style.css', '/* css */' );
put( 'wp-content/uploads/2026/10/bild.jpg', 'JPG' );
put( 'wp-content/uploads/bw-bridge-backups-abc/functions.php', '<?php // Sicherung' );
put( 'wp-content/cache/page.php', '<?php // Cache' );
put( 'wp-content/custom-tool/run.php', '<?php // eigenes Werkzeug' );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = [ 'bw_bridge_log_db' => 1 ]; $GLOBALS['tr'] = []; $GLOBALS['http'] = []; $GLOBALS['scheduled'] = []; $GLOBALS['filters'] = [];
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['plugins'] = [ 'foo/foo.php' => [ 'Name' => 'Foo', 'Version' => '1.0' ], 'hello.php' => [ 'Name' => 'Hello Dolly', 'Version' => '1.7' ] ];
foreach ( [ 'add_action', 'add_filter', 'register_setting', 'add_options_page', 'add_management_page', 'register_rest_route' ] as $f ) { eval( "function $f( ...\$a ) {}" ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $e = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; } function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function wp_next_scheduled( $h ) { return false; } function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][] = $h; } function wp_schedule_event( ...$a ) {}
function apply_filters( $t, $v = null, ...$a ) { return array_key_exists( $t, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $t ] : $v; }
function did_action( $a ) { return 0; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } function rest_get_url_prefix() { return 'wp-json'; }
function current_user_can( $c ) { return true; } function is_wp_error( $x ) { return $x instanceof WP_Error; } function rest_ensure_response( $d ) { return $d; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); } function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_get_current_user() { return (object) [ 'ID' => 0, 'user_login' => '' ]; } function get_userdata( $id ) { return null; }
function get_plugins() { return $GLOBALS['plugins']; } function get_theme_root() { return WP_CONTENT_DIR . '/themes'; }
function wp_upload_dir( $a = null, $b = true ) { return [ 'basedir' => WP_CONTENT_DIR . '/uploads', 'error' => false ]; }
class Fake_Theme { public $slug; function __construct( $s ) { $this->slug = $s; } function exists() { return is_dir( get_theme_root() . '/' . $this->slug ); } function get( $k ) { return 'Name' === $k ? 'Mein Theme' : '2.0'; } }
function wp_get_theme( $s = '' ) { return new Fake_Theme( $s ?: 'mytheme' ); } function wp_get_themes() { return [ 'mytheme' => new Fake_Theme( 'mytheme' ) ]; }
function get_locale() { return 'de_DE'; }
function get_core_checksums( $v, $l ) { return $GLOBALS['core_sums'][ $v ] ?? false; }
function wp_safe_remote_get( $url, $args = [] ) { $GLOBALS['http_log'][] = $url; return $GLOBALS['http'][ $url ] ?? [ 'code' => 404, 'body' => '' ]; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
class WP_Error { public $code; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; } }
require __DIR__ . '/lib-mem-wpdb.php';
$GLOBALS['wpdb'] = new Mem_Wpdb();
require __DIR__ . '/../bw-wp-bridge.php';

/** Datei-Liste im Speicher. */
class Mem_Store { public $rows = []; function load( $u ) { return $this->rows[ $u ] ?? []; } function save( $u, array $up, array $del ) { foreach ( $up as $p => $r ) { $this->rows[ $u ][ $p ] = $r + [ 'path' => $p ]; } foreach ( $del as $p ) { unset( $this->rows[ $u ][ $p ] ); } } }
BW_Bridge_Scan::$store = new Mem_Store();

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }
function entries( $cat = null ) { return array_values( array_filter( $GLOBALS['wpdb']->rows, fn( $r ) => null === $cat || $cat === $r['category'] ) ); }
function last_entry( $cat ) { $e = entries( $cat ); return end( $e ) ?: null; }
function core_sums() { $s = []; foreach ( [ 'index.php', 'wp-admin/index.php', 'wp-includes/version.php', 'wp-includes/js/jquery.js', 'wp-config-sample.php', 'wp-content/index.php' ] as $f ) { if ( is_file( ABSPATH . $f ) ) { $s[ $f ] = md5_file( ABSPATH . $f ); } } return $s; }
function plugin_sums_response( $ver ) { $files = []; foreach ( [ 'foo.php', 'js/app.js', 'readme.txt' ] as $f ) { $files[ $f ] = [ 'md5' => md5_file( WP_PLUGIN_DIR . '/foo/' . $f ), 'sha256' => 'x' ]; } $GLOBALS['http'][ "https://downloads.wordpress.org/plugin-checksums/foo/$ver.json" ] = [ 'code' => 200, 'body' => json_encode( [ 'files' => $files ] ) ]; }
function run_scan() { $GLOBALS['tr'] = array_filter( $GLOBALS['tr'], fn( $k ) => 0 !== strpos( $k, 'bw_bridge_vuln_' ), ARRAY_FILTER_USE_KEY ); $s = BW_Bridge_Scan::state(); $s['started'] = '2000-01-01 00:00:00'; update_option( BW_Bridge_Scan::OPT_STATE, $s ); return BW_Bridge_Scan::start( true ); }

/* ---------- Bereiche und Dateien ---------- */
$units = BW_Bridge_Scan::units();
check( 'Bereiche erkannt', [ 'core', 'content', 'plugins-root', 'plugin:foo', 'theme:mytheme', 'uploads', 'other' ] === $units );
$w = BW_Bridge_Scan::walk( BW_Bridge_Scan::unit_def( 'plugin:foo' ) );
check( 'Plugin: Code-Dateien, kein Text', [ 'foo.php', 'js/app.js' ] === array_keys( $w ) );
$w = BW_Bridge_Scan::walk( BW_Bridge_Scan::unit_def( 'core' ) );
check( 'Core: Wurzel nicht rekursiv, wp-admin/wp-includes rekursiv, ohne wp-content', [ 'index.php', 'wp-admin/index.php', 'wp-config.php', 'wp-includes/js/jquery.js', 'wp-includes/version.php' ] === array_keys( $w ) );
check( 'Uploads: Sicherungsordner der Bridge und Bilder ausgelassen', [] === array_keys( BW_Bridge_Scan::walk( BW_Bridge_Scan::unit_def( 'uploads' ) ) ) );
check( 'Sonstige Ordner: eigenes Werkzeug ja, Cache nein', [ 'custom-tool/run.php' ] === array_keys( BW_Bridge_Scan::walk( BW_Bridge_Scan::unit_def( 'other' ) ) ) );

/* ---------- Malware-Muster ---------- */
check( 'eval(base64_decode()) erkannt', [ 'eval-encoded' ] === BW_Bridge_Scan::patterns( '<?php eval(base64_decode("ZWNobyAx"));' ) );
check( 'eval($_POST) erkannt', in_array( 'eval-input', BW_Bridge_Scan::patterns( '<?php @eval($_POST["x"]);' ), true ) );
check( '$_GET[a]($_GET[b]) erkannt', in_array( 'input-call', BW_Bridge_Scan::patterns( '<?php $_GET["f"]($_GET["c"]);' ), true ) );
check( 'Webshell-Namen erkannt', in_array( 'shell-names', BW_Bridge_Scan::patterns( '<?php // FilesMan' ), true ) );
$own = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$rel = substr( $f->getPathname(), strlen( dirname( __DIR__ ) ) + 1 );
	if ( 'php' === $f->getExtension() && ! preg_match( '#^(tests|\.git)/#', $rel ) && ( $hits = BW_Bridge_Scan::patterns( file_get_contents( $f ) ) ) ) {
		$own[ $rel ] = $hits;
	}
}
check( 'Der eigene Quelltext löst keine Muster aus (sonst halten Hoster-Scanner die Bridge für Schadcode)', [] === $own ) || print_r( $own );
check( 'Normales base64_decode ist kein Treffer', [] === BW_Bridge_Scan::patterns( '<?php $img = base64_decode( $data ); echo esc_html( $_POST["name"] ?? "" );' ) );

/* ---------- Erster Lauf: Ersterfassung ---------- */
$GLOBALS['core_sums']['7.1'] = core_sums();
plugin_sums_response( '1.0' );
$GLOBALS['http']['https://www.wpvulnerability.net/plugin/foo/'] = [ 'code' => 200, 'body' => json_encode( [ 'error' => 0, 'data' => [ 'name' => 'Foo', 'closed' => 0, 'vulnerability' => [
	[ 'name' => 'Foo < 1.2 – XSS', 'operator' => [ 'max_version' => '1.2', 'max_operator' => 'lt', 'unfixed' => '0' ], 'source' => [ [ 'id' => 'CVE-2026-1', 'link' => 'https://example.org/cve1' ] ], 'impact' => [ 'cvss' => [ 'score' => '7.5', 'severity' => 'h' ] ] ],
	[ 'name' => 'Foo <= 0.9 – alt', 'operator' => [ 'max_version' => '0.9', 'max_operator' => 'le' ], 'source' => [ [ 'id' => 'CVE-2025-9' ] ], 'impact' => [ 'cvss' => [ 'severity' => 'm' ] ] ],
] ] ] ) ];
$GLOBALS['http']['https://www.wpvulnerability.net/core/7.1/'] = [ 'code' => 200, 'body' => json_encode( [ 'error' => 0, 'data' => [ 'vulnerability' => [] ] ] ) ];
$st = run_scan();
check( 'Lauf fertig', ! $st['running'] && $st['finished'] && 7 === $st['units'] );
$base = entries( 'files' );
check( 'Ersterfassung: nur PHP in sonstigen Ordnern einmal als Hinweis', 1 === count( $base ) && 'other' === $base[0]['object_id'] && 'notice' === $base[0]['level'] && 'baseline' === $base[0]['action'] );
check( 'Integrität: Core und Foo unverändert', 2 === $st['integrity']['verified'] && [] === $st['integrity']['issues'] );
check( 'Schwachstelle in Foo 1.0 gefunden, alte (≤ 0.9) nicht', 1 === count( $st['vulns']['items'] ) && '1.2' === $st['vulns']['items'][0]['fixed_in'] && 'h' === $st['vulns']['items'][0]['severity'] );
$v = last_entry( 'vuln' );
check( 'Erster Fund als „Bekannte Schwachstelle“ protokolliert', $v && 'warning' === $v['level'] && false !== strpos( $v['message'], 'Bekannte Schwachstelle in Foo 1.0' ) );
check( 'Theme ohne Antwort zählt als fehlgeschlagen', in_array( 'theme:mytheme', $st['vulns']['errors'], true ) );

/* ---------- Unerwartete Änderung ---------- */
put( 'wp-content/plugins/foo/foo.php', "<?php\n/* Plugin Name: Foo */\necho 2;", time() - 500 );
run_scan();
$e = last_entry( 'files' );
check( 'Unerwartete Änderung → Warnung', $e && 'unexpected' === $e['action'] && 'warning' === $e['level'] && [ 'foo.php' ] === json_decode( $e['details'], true )['modified'] );
$i = last_entry( 'integrity' );
check( 'Integrität: Abweichung vom Original protokolliert', $i && 'modified' === $i['action'] && [ 'foo.php' ] === json_decode( $i['details'], true )['modified'] );
check( 'Prüfung zeigt Abweichung', 'warn' === BW_Bridge_Audit::scan_checks( BW_Bridge_Scan::status(), [ 'unexpected' => 1, 'suspicious' => 0 ] )[2]['status'] );

/* ---------- Nur Zeitstempel geändert ---------- */
$n = count( entries( 'files' ) );
touch( WP_PLUGIN_DIR . '/foo/js/app.js', time() - 100 );
run_scan();
check( 'Nur Zeitstempel geändert → kein Eintrag', $n === count( entries( 'files' ) ) );

/* ---------- Update auf neue Version ---------- */
$GLOBALS['plugins']['foo/foo.php']['Version'] = '1.2';
put( 'wp-content/plugins/foo/foo.php', "<?php\n/* Plugin Name: Foo 1.2 */\necho 3;", time() - 50 );
put( 'wp-content/plugins/foo/js/new.js', 'neu', time() - 50 );
plugin_sums_response( '1.2' );
$GLOBALS['http']['https://downloads.wordpress.org/plugin-checksums/foo/1.2.json'] = [ 'code' => 200, 'body' => json_encode( [ 'files' => [ 'foo.php' => [ 'md5' => [ 'alt', md5_file( WP_PLUGIN_DIR . '/foo/foo.php' ) ] ], 'js/app.js' => [ 'md5' => md5_file( WP_PLUGIN_DIR . '/foo/js/app.js' ) ], 'js/new.js' => [ 'md5' => md5_file( WP_PLUGIN_DIR . '/foo/js/new.js' ) ] ] ] ) ];
$st = run_scan();
$e  = last_entry( 'files' );
check( 'Neue Version → erwartet', $e && 'expected' === $e['action'] && 'info' === $e['level'] && false !== strpos( $e['message'], 'Version 1.0 → 1.2, Update nicht im Protokoll' ) );
check( 'Integrität wieder in Ordnung (md5 als Liste)', 'restored' === last_entry( 'integrity' )['action'] && [] === $st['integrity']['issues'] );
check( 'Schwachstelle nach Update behoben', [] === $st['vulns']['items'] && 'resolved' === last_entry( 'vuln' )['action'] );

/* ---------- Protokolliertes Update erklärt Änderungen ---------- */
BW_Bridge_Log::add( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'updated', 'object_id' => 'foo/foo.php', 'message' => 'Plugin aktualisiert: Foo 1.2 → 1.2.1', 'via' => 'claude' ] );
$k = array_key_last( $GLOBALS['wpdb']->rows ); $GLOBALS['wpdb']->rows[ $k ]['created'] = gmdate( 'Y-m-d H:i:s', time() + 5 );
put( 'wp-content/plugins/foo/js/app.js', 'console.log(2)', time() - 10 );
run_scan();
check( 'Änderung durch protokolliertes Update erklärt', 'expected' === last_entry( 'files' )['action'] && false !== strpos( last_entry( 'files' )['message'], 'Plugin aktualisiert: Foo 1.2 → 1.2.1' ) );

/* ---------- PHP in uploads, verdächtiger Code ---------- */
put( 'wp-content/uploads/2026/11/cache.php', '<?php eval(base64_decode("ZWNobyAx"));', time() );
run_scan();
$e = last_entry( 'files' );
$d = json_decode( $e['details'], true );
check( 'PHP-Datei in uploads mit Schadcode-Muster → Warnung „suspicious“', 'suspicious' === $e['action'] && 'warning' === $e['level'] && [ '2026/11/cache.php' ] === $d['php_unusual'] && [ 'eval-encoded' ] === $d['suspicious']['2026/11/cache.php'] );
check( 'Datei wird nur gemeldet, nicht verschoben', is_file( WP_CONTENT_DIR . '/uploads/2026/11/cache.php' ) );
$checks = BW_Bridge_Audit::scan_checks( BW_Bridge_Scan::status(), [ 'unexpected' => 2, 'suspicious' => 1 ] );
check( 'Prüfung: verdächtig → dringend', 'fail' === $checks[1]['status'] );

/* ---------- Zusätzliche PHP-Datei im Core ---------- */
put( 'wp-includes/wp-l0gin.php', '<?php // eingeschleust', time() );
$st = run_scan();
$core = array_values( array_filter( $st['integrity']['issues'], fn( $x ) => 'core' === $x['unit'] ) );
check( 'Zusätzliche PHP-Datei im Core erkannt', $core && [ 'wp-includes/wp-l0gin.php' ] === $core[0]['extra'] );
check( 'Core-Abweichung → Prüfung dringend', 'fail' === BW_Bridge_Audit::scan_checks( $st, [ 'unexpected' => 0, 'suspicious' => 0 ] )[2]['status'] );
check( 'wp-config.php gilt nicht als zusätzlich', ! in_array( 'wp-config.php', $core[0]['extra'], true ) );

/* ---------- Plugin gelöscht ---------- */
foreach ( [ 'foo.php', 'js/app.js', 'js/new.js', 'readme.txt' ] as $f ) { unlink( WP_PLUGIN_DIR . '/foo/' . $f ); }
rmdir( WP_PLUGIN_DIR . '/foo/js' ); rmdir( WP_PLUGIN_DIR . '/foo' ); unset( $GLOBALS['plugins']['foo/foo.php'] );
$st = run_scan();
$e  = last_entry( 'files' );
check( 'Gelöschtes Plugin: Dateien als gelöscht gemeldet und Bereich vergessen', 'plugin:foo' === $e['object_id'] && 3 === json_decode( $e['details'], true )['counts']['deleted'] && ! isset( BW_Bridge_Scan::state()['meta']['plugin:foo'] ) && [] === BW_Bridge_Scan::$store->load( 'plugin:foo' ) );

/* ---------- Zeitbudget ---------- */
$GLOBALS['filters']['bw_bridge_scan_budget'] = 0; $GLOBALS['scheduled'] = [];
$st = run_scan();
check( 'Budget aufgebraucht → Fortsetzung per Cron eingeplant', $st['running'] && in_array( BW_Bridge_Scan::CRON_STEP, $GLOBALS['scheduled'], true ) );
unset( $GLOBALS['filters']['bw_bridge_scan_budget'] );
BW_Bridge_Scan::step();
check( 'Nächster Schritt schließt den Lauf ab', ! BW_Bridge_Scan::status()['running'] );

/* ---------- Versionsvergleich ---------- */
check( 'applies: < max', BW_Bridge_Scan::applies( [ 'max_version' => '2.0', 'max_operator' => 'lt' ], '1.9.9' ) && ! BW_Bridge_Scan::applies( [ 'max_version' => '2.0', 'max_operator' => 'lt' ], '2.0' ) );
check( 'applies: Bereich min/max', BW_Bridge_Scan::applies( [ 'min_version' => '1.0', 'min_operator' => 'ge', 'max_version' => '1.5', 'max_operator' => 'le' ], '1.5' ) && ! BW_Bridge_Scan::applies( [ 'min_version' => '1.0', 'min_operator' => 'ge', 'max_version' => '1.5', 'max_operator' => 'le' ], '0.9' ) );
check( 'applies: ohne Operator nie', ! BW_Bridge_Scan::applies( [], '1.0' ) );
$closed = BW_Bridge_Scan::vuln_parse( 'plugin', 'bar', 'Bar', '1.0', [ 'error' => 0, 'data' => [ 'closed' => 1, 'vulnerability' => [] ] ] );
check( 'Geschlossenes Plugin wird gemeldet', 1 === count( $closed ) && $closed[0]['unfixed'] );

// Aufräumen
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); } rmdir( $root );
echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
