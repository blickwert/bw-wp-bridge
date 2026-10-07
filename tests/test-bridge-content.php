<?php
/**
 * Test für Sicherungen/Probelauf/Texte (Elementor), Suche, Meta, Stapel und WPML-Zuordnung gegen eine simulierte
 * WordPress-Umgebung (Speicher statt Datenbank). Aufruf: php tests/test-bridge-content.php
 */
define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['filters'] = []; $GLOBALS['caps'] = [ 'manage_options' => true ];
$GLOBALS['routes'] = []; $GLOBALS['now'] = 1700000000;
function add_action( ...$a ) {} function add_filter( ...$a ) {} function register_setting( ...$a ) {} function add_options_page( ...$a ) {}
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ?? [] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
function rest_ensure_response( $d ) { return $d; } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); } function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function maybe_unserialize( $v ) { return $v; } function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['meta'][ $id ] ?? [];
	if ( '' === $key ) { return array_map( static fn( $v ) => $v, $all ); }
	$vals = $all[ $key ] ?? [];
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function add_post_meta( $id, $key, $v ) { $GLOBALS['meta'][ $id ][ $key ][] = stripslashes( $v ); return true; }
function update_post_meta( $id, $key, $v ) { $GLOBALS['meta'][ $id ][ $key ] = [ is_string( $v ) ? stripslashes( $v ) : $v ]; return true; }
function delete_post_meta( $id, $key, $v = '' ) {
	if ( '' === $v ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
	$GLOBALS['meta'][ $id ][ $key ] = array_values( array_filter( $GLOBALS['meta'][ $id ][ $key ] ?? [], static fn( $x ) => $x !== stripslashes( $v ) && $x !== $v ) );
	return true;
}
function did_action( $a ) { return 1; }
function time_now() { return $GLOBALS['now']; }
function apply_filters( $tag, $v = null, ...$args ) { return isset( $GLOBALS['filters'][ $tag ] ) ? call_user_func( $GLOBALS['filters'][ $tag ], $v, ...$args ) : $v; }
function do_action( $tag, ...$args ) { if ( isset( $GLOBALS['actions'][ $tag ] ) ) { call_user_func( $GLOBALS['actions'][ $tag ], ...$args ); } }
function has_action( $tag ) { return isset( $GLOBALS['actions'][ $tag ] ); }
function has_filter( $tag ) { return isset( $GLOBALS['filters'][ $tag ] ); }
function gmdate_stub() {}
class WP_Error { public $code, $msg, $data; function __construct( $c, $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } }
class WP_REST_Request implements ArrayAccess {
	public $method, $route, $q = [], $p = [], $json = null, $headers = [];
	function __construct( $m = 'GET', $route = '', $p = [] ) { $this->method = $m; $this->route = $route; $this->p = $p; }
	function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; } function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
	function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; } function offsetUnset( $k ): void {}
	function get_param( $k ) { return $this->q[ $k ] ?? $this->p[ $k ] ?? null; }
	function get_json_params() { return $this->json; } function set_query_params( $a ) { $this->q = $a; } function get_query_params() { return $this->q; }
	function set_header( $k, $v ) { $this->headers[ strtolower( $k ) ] = $v; } function set_body( $b ) { $this->json = json_decode( $b, true ); }
	function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? null; }
}
class WP_Fake_Response { public $status, $data; function __construct( $s, $d ) { $this->status = $s; $this->data = $d; } function get_status() { return $this->status; } }
$GLOBALS['do_request_log'] = [];
function rest_do_request( $req ) { $GLOBALS['do_request_log'][] = [ $req->method, $req->route, $req->get_query_params(), $req->json ]; return new WP_Fake_Response( 'fail' === ( $req->json['mode'] ?? '' ) ? 404 : 200, [ 'echo' => $req->route ] ); }
function rest_get_server() { return new class { function response_to_data( $r, $embed ) { return $r->data; } }; }
// Elementor-Dokument: speichert in die simulierte Meta-Tabelle
eval( 'namespace Elementor; class Plugin { public static $instance; }' );
class Fake_Doc { public $id; function __construct( $id ) { $this->id = $id; } function set_is_built_with_elementor( $b ) {}
	function save( $d ) { $GLOBALS['meta'][ $this->id ]['_elementor_data'] = [ json_encode( $d['elements'] ) ]; $GLOBALS['meta'][ $this->id ]['_elementor_page_settings'] = [ $d['settings'] ]; return true; } }
\Elementor\Plugin::$instance = new class { public $documents; function __construct() { $this->documents = new class { function get( $id, $x ) { return new Fake_Doc( $id ); } }; } };
// wpdb: prüft, dass Platzhalter und Parameter zusammenpassen, und liefert vorgegebene IDs
class Fake_Wpdb { public $prefix = 'wp_', $posts = 'wp_posts', $postmeta = 'wp_postmeta', $ids = [], $last_sql = '', $last_args = [], $handler = null;
	function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	function prepare( $sql, ...$args ) { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } $n = preg_match_all( '/%[sd]/', $sql ); if ( $n !== count( $args ) ) { throw new Exception( "Platzhalter $n != Parameter " . count( $args ) ); } $this->last_sql = $sql; $this->last_args = $args; $i = 0; return preg_replace_callback( '/%([sd])/', static function ( $m ) use ( $args, &$i ) { $v = $args[ $i++ ]; return 'd' === $m[1] ? (int) $v : "'" . $v . "'"; }, $sql ); }
	function get_col( $sql ) { return $this->ids; }
	function get_results( $sql, $o = null ) { return $this->handler ? ( $this->handler )( $sql ) : []; }
	function get_var( $sql ) { return $this->handler ? ( $this->handler )( $sql ) : null; }
	function get_row( $sql, $o = null ) { return $this->handler ? ( $this->handler )( $sql ) : null; } }
$GLOBALS['wpdb'] = new Fake_Wpdb();

require __DIR__ . '/../bw-wp-bridge.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }
function post( $id, $type, $title, $content = '', $name = '' ) { $GLOBALS['posts'][ $id ] = (object) [ 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_name' => $name ?: "p$id", 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => '' ]; }
function layout_json( $title ) {
	return json_encode( [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
		[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'escaped-html', 'value' => $title ] ] ],
		[ 'id' => 'e1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Gültig 120 Tage</p>' ] ],
	] ] ] );
}

/* ---------- Elementor: Sicherung, Probelauf, Texte setzen, Wiederherstellen ---------- */
post( 42, 'page', 'Seite' ); $GLOBALS['meta'][42] = [ '_elementor_data' => [ layout_json( 'Alt' ) ], '_elementor_page_settings' => [ [ 'template' => 'x' ] ] ];

$r = new WP_REST_Request( 'POST', '', [ 'id' => 42 ] ); $r->json = [ 'changes' => [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'Neu' ] ], 'dry_run' => true ];
$res = BW_Bridge_Elementor_Texts::set_texts( $r );
check( 'set_texts Probelauf: nichts gespeichert, keine Sicherung', ! $res['saved'] && $res['results'][0]['status'] === 'ok' && empty( $GLOBALS['meta'][42][ BW_Bridge_Elementor::BACKUP_META ] ) && strpos( $GLOBALS['meta'][42]['_elementor_data'][0], 'Alt' ) !== false );

$r->json = [ 'changes' => [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'Neu' ], [ 'widget_id' => 'nix', 'path' => 'settings.title', 'value' => 'x' ] ] ];
$res = BW_Bridge_Elementor_Texts::set_texts( $r );
check( 'set_texts: ein Fehler => nichts wird gespeichert', ! $res['saved'] && strpos( $GLOBALS['meta'][42]['_elementor_data'][0], 'Alt' ) !== false );

$r->json = [ 'changes' => [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'Neu', 'expect' => 'Alt' ] ] ];
$GLOBALS['now'] = 1700000001; $res = BW_Bridge_Elementor_Texts::set_texts( $r );
check( 'set_texts: gespeichert', $res['saved'] === true && strpos( $GLOBALS['meta'][42]['_elementor_data'][0], '"Neu"' ) !== false );
check( 'set_texts: Sicherung des alten Stands angelegt', count( $GLOBALS['meta'][42][ BW_Bridge_Elementor::BACKUP_META ] ) === 1 );
$list = BW_Bridge_Elementor::list_backups( new WP_REST_Request( 'GET', '', [ 'id' => 42 ] ) );
$first_backup = $list['backups'][0]['time'];
check( 'Sicherungen: Liste mit Beschriftung', count( $list['backups'] ) === 1 && $list['backups'][0]['label'] === 'Texte ändern' );

// Layout speichern (save_elementor), Probelauf mit Textvergleich
$w = new WP_REST_Request( 'POST', '', [ 'id' => 42 ] ); $w->json = [ 'elements' => json_decode( layout_json( 'Ganz neu' ), true ), 'dry_run' => true ];
$res = BW_Bridge_Elementor::save_elementor( $w );
check( 'save_elementor Probelauf: Textvergleich, nichts gespeichert', $res['dry_run'] && $res['texts']['changed'][0]['old'] === 'Neu' && $res['texts']['changed'][0]['new'] === 'Ganz neu' && strpos( $GLOBALS['meta'][42]['_elementor_data'][0], '"Neu"' ) !== false );
check( 'save_elementor Probelauf: Elementzahlen', $res['elements'] === [ 'before' => 3, 'after' => 3 ] );

// Wiederherstellen
$rr = new WP_REST_Request( 'POST', '', [ 'id' => 42 ] ); $rr->json = [];
$res = BW_Bridge_Elementor::restore_backup( $rr );
check( 'restore: neueste Sicherung zurückgespielt', $res['restored'] === $first_backup && strpos( $GLOBALS['meta'][42]['_elementor_data'][0], '"Alt"' ) !== false );
check( 'restore: sichert vorher den aktuellen Stand', count( $GLOBALS['meta'][42][ BW_Bridge_Elementor::BACKUP_META ] ) === 2 );
$rr->json = [ 'time' => 5 ];
check( 'restore: unbekannter Zeitpunkt => Fehler', is_wp_error( BW_Bridge_Elementor::restore_backup( $rr ) ) );

// Begrenzung auf die letzten 10 Sicherungen
for ( $i = 0; $i < 15; $i++ ) { $GLOBALS['now'] = 1700001000 + $i; $r->json = [ 'changes' => [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => "V$i" ] ] ]; BW_Bridge_Elementor_Texts::set_texts( $r ); }
check( 'Sicherungen: höchstens ' . BW_Bridge_Elementor::BACKUPS_KEEP, count( $GLOBALS['meta'][42][ BW_Bridge_Elementor::BACKUP_META ] ) === BW_Bridge_Elementor::BACKUPS_KEEP );
$times = array_column( BW_Bridge_Elementor::list_backups( new WP_REST_Request( 'GET', '', [ 'id' => 42 ] ) )['backups'], 'time' );
check( 'Sicherungen: neueste zuerst, eindeutig, älteste verworfen', $times === array_values( array_unique( $times ) ) && $times[0] === max( $times ) && ! in_array( $first_backup, $times, true ) );
check( 'Sicherungen: Wiederherstellen per Zeitpunkt ist eindeutig', ( function () use ( $times ) { $x = new WP_REST_Request( 'POST', '', [ 'id' => 42 ] ); $x->json = [ 'time' => $times[3] ]; return BW_Bridge_Elementor::restore_backup( $x )['restored'] === $times[3]; } )() );

// get_texts mit Filter
$g = new WP_REST_Request( 'GET', '', [ 'id' => 42 ] ); $g->q = [ 'q' => '120' ];
$res = BW_Bridge_Elementor_Texts::get_texts( $g );
check( 'get_texts: Filter q', $res['count'] === 1 && $res['texts'][0]['widget_id'] === 'e1' );

/* ---------- Meta ---------- */
post( 7, 'product', 'Produkt A' ); $GLOBALS['meta'][7] = [ '_shop_hinweis' => [ 'Platzhaltertext' ], '_shop_tage' => [ '0' ], '_elementor_data' => [ '[]' ], 'plain' => [ 'a', 'b' ] ];
$res = BW_Bridge_Meta::get_meta( new WP_REST_Request( 'GET', '', [ 'id' => 7 ] ) );
check( 'meta-get: ohne _elementor_data, Mehrfachwerte als Liste', ! isset( $res['meta']->_elementor_data ) && $res['meta']->plain === [ 'a', 'b' ] && $res['meta']->_shop_tage === '0' );
$q = new WP_REST_Request( 'GET', '', [ 'id' => 7 ] ); $q->q = [ 'prefix' => '_shop_' ];
check( 'meta-get: prefix', array_keys( (array) BW_Bridge_Meta::get_meta( $q )['meta'] ) === [ '_shop_hinweis', '_shop_tage' ] );
$m = new WP_REST_Request( 'POST', '', [ 'id' => 7 ] ); $m->json = [ 'set' => [ '_shop_hinweis' => '' , '_shop_tage' => '180' ], 'dry_run' => true ];
$res = BW_Bridge_Meta::set_meta( $m );
check( 'meta-set Probelauf: nichts geändert, alt/neu gemeldet', $res['changes']->_shop_tage === [ 'old' => '0', 'new' => '180' ] && $GLOBALS['meta'][7]['_shop_tage'] === [ '0' ] );
$m->json = [ 'set' => [ '_shop_tage' => '180' ], 'delete' => [ 'plain' ] ]; $res = BW_Bridge_Meta::set_meta( $m );
check( 'meta-set: geschrieben und gelöscht', $GLOBALS['meta'][7]['_shop_tage'] === [ '180' ] && ! isset( $GLOBALS['meta'][7]['plain'] ) );
$m->json = [ 'set' => [ '_elementor_data' => 'x' ] ];
check( 'meta-set: _elementor_data gesperrt', is_wp_error( BW_Bridge_Meta::set_meta( $m ) ) && $GLOBALS['meta'][7]['_elementor_data'] === [ '[]' ] );
$m->json = [ 'delete' => [ BW_Bridge_Elementor::BACKUP_META ] ];
check( 'meta-set: Sicherungs-Feld gesperrt', is_wp_error( BW_Bridge_Meta::set_meta( $m ) ) );

/* ---------- Stapel ---------- */
$b = new WP_REST_Request( 'POST' ); $b->json = [ 'operations' => [
	[ 'method' => 'POST', 'path' => 'wp/v2/pages/158?lang=en', 'body' => [ 'name' => 'X' ] ],
	[ 'method' => 'GET', 'path' => '/wp/v2/pages', 'query' => [ 'per_page' => 5 ] ],
] ];
$res = BW_Bridge_Batch::run( $b );
check( 'batch: führt alle aus', count( $res['results'] ) === 2 && $res['results'][0]['status'] === 200 && ! $res['stopped'] );
check( 'batch: Route, Query und Body werden übergeben', $GLOBALS['do_request_log'][0][1] === '/wp/v2/pages/158' && $GLOBALS['do_request_log'][0][2] === [ 'lang' => 'en' ] && $GLOBALS['do_request_log'][0][3] === [ 'name' => 'X' ] && $GLOBALS['do_request_log'][1][2] === [ 'per_page' => 5 ] );
$b->json = [ 'operations' => [ [ 'method' => 'POST', 'path' => 'a/b', 'body' => [ 'mode' => 'fail' ] ], [ 'method' => 'GET', 'path' => 'c' ] ] ];
$res = BW_Bridge_Batch::run( $b );
check( 'batch: stoppt standardmäßig beim ersten Fehler', count( $res['results'] ) === 1 && $res['stopped'] === true );
$b->json['stop_on_error'] = false; $res = BW_Bridge_Batch::run( $b );
check( 'batch: stop_on_error=false macht weiter', count( $res['results'] ) === 2 && $res['stopped'] === false );
$b->json = [ 'operations' => [ [ 'method' => 'POST', 'path' => 'bw-bridge/v1/batch' ] ] ];
check( 'batch: verschachtelter Stapel abgelehnt', BW_Bridge_Batch::run( $b )['results'][0]['status'] === 400 );
$b->json = [ 'operations' => [] ]; check( 'batch: leere Liste => Fehler', is_wp_error( BW_Bridge_Batch::run( $b ) ) );
$b->json = [ 'operations' => array_fill( 0, 51, [ 'path' => 'x' ] ) ]; check( 'batch: mehr als 50 => Fehler', is_wp_error( BW_Bridge_Batch::run( $b ) ) );

/* ---------- Suche + WPML ---------- */
post( 130, 'page', 'Seite Englisch' ); post( 1346, 'page', 'Seite Deutsch' ); post( 9, 'page', 'Kontakt', 'nichts' );
$GLOBALS['meta'][130] = [ '_elementor_data' => [ layout_json( 'Anleitung erklärt' ) ] ];
$GLOBALS['meta'][1346] = [ '_elementor_data' => [ layout_json( 'So funktioniert die Anleitung' ) ], '_shop_notiz' => [ 'Anleitung Hinweis' ] ];
$GLOBALS['wpdb']->ids = [ 1346, 130, 9 ];
$GLOBALS['filters']['wpml_element_language_details'] = static function ( $v, $a ) { return (object) [ 'language_code' => in_array( $a['element_id'], [ 1346 ], true ) ? 'de' : 'en' ]; };
$s = new WP_REST_Request( 'GET' ); $s->q = [ 'q' => 'Anleitung' ];
$res = BW_Bridge_Search::search( $s );
check( 'search: Treffer in Elementor-Text und Meta, Beitrag ohne Treffer fehlt', $res['count'] === 2 && $res['results'][0]['id'] === 1346 );
$wheres = array_column( $res['results'][0]['hits'], 'where' );
check( 'search: Fundstellen elementor + meta mit Widget-ID/Key', in_array( 'elementor', $wheres, true ) && in_array( 'meta', $wheres, true ) && $res['results'][0]['hits'][0]['widget_id'] === 'h1' );
check( 'search: Sprache wird mitgeliefert', $res['results'][0]['lang'] === 'de' && $res['results'][1]['lang'] === 'en' );
$s->q = [ 'q' => 'Anleitung', 'lang' => 'de' ]; $res = BW_Bridge_Search::search( $s );
check( 'search: lang-Filter', $res['count'] === 1 && $res['results'][0]['id'] === 1346 );
$s->q = [ 'q' => 'Anleitung', 'types' => 'page,product', 'meta' => '0' ]; $res = BW_Bridge_Search::search( $s );
check( 'search: SQL-Platzhalter passen (types, meta=0)', strpos( $GLOBALS['wpdb']->last_sql, 'p.post_type IN (%s,%s)' ) !== false && strpos( $GLOBALS['wpdb']->last_sql, 'm.meta_value' ) === false );
$s->q = [ 'q' => 'Gültig' ]; BW_Bridge_Search::search( $s );
check( 'search: Umlaute werden zusätzlich JSON-escaped gesucht (Elementor)', in_array( '%G\\\\u00fcltig%', $GLOBALS['wpdb']->last_args, true ) );
$s->q = [ 'q' => 'a' ]; check( 'search: zu kurzer Suchbegriff => Fehler', is_wp_error( BW_Bridge_Search::search( $s ) ) );

$GLOBALS['filters']['wpml_element_type'] = static fn( $t ) => "post_$t";
$GLOBALS['filters']['wpml_element_trid'] = static fn( $v, $id, $type ) => 55;
$GLOBALS['filters']['wpml_get_element_translations'] = static fn( $v, $trid, $type ) => [ 'en' => (object) [ 'element_id' => 130 ], 'de' => (object) [ 'element_id' => 1346 ] ];
$res = BW_Bridge_Search::translations( new WP_REST_Request( 'GET', '', [ 'id' => 130 ] ) );
check( 'translations: WPML-Zuordnung', $res['wpml'] && (array) $res['translations'] === [ 'en' => 130, 'de' => 1346 ] && $res['language'] === 'en' );
$GLOBALS['filters'] = [];
$res = BW_Bridge_Search::translations( new WP_REST_Request( 'GET', '', [ 'id' => 130 ] ) );
check( 'translations: ohne WPML wpml=false', $res['wpml'] === false );


/* ---------- WPML: Übersetzungen verknüpfen ---------- */
// einfaches WPML-Modell: element_id => [ trid, lang ]
$GLOBALS['wpml'] = [ 130 => [ 'trid' => 55, 'lang' => 'en' ], 1346 => [ 'trid' => 55, 'lang' => 'de' ], 2121 => [ 'trid' => 70, 'lang' => 'en' ], 2124 => [ 'trid' => 71, 'lang' => 'de' ], 2125 => [ 'trid' => 72, 'lang' => 'de' ], 2126 => [ 'trid' => 73, 'lang' => 'en' ] ];
post( 2121, 'product', 'Produkt EN' ); post( 2124, 'product', 'Produkt DE' ); post( 2125, 'product', 'Produkt DE 2' ); post( 2126, 'product', 'Produkt EN 2' ); post( 2130, 'page', 'Seite' );
$GLOBALS['filters']['wpml_element_type'] = static fn( $t ) => "post_$t";
$GLOBALS['filters']['wpml_element_language_details'] = static function ( $v, $a ) { $x = $GLOBALS['wpml'][ $a['element_id'] ] ?? null; return $x ? (object) [ 'language_code' => $x['lang'] ] : null; };
$GLOBALS['filters']['wpml_element_trid'] = static function ( $v, $id, $type ) { return $GLOBALS['wpml'][ $id ]['trid'] ?? null; };
$GLOBALS['filters']['wpml_get_element_translations'] = static function ( $v, $trid, $type ) { $o = []; foreach ( $GLOBALS['wpml'] as $id => $x ) { if ( $x['trid'] === $trid ) { $o[ $x['lang'] ] = (object) [ 'element_id' => $id ]; } } return $o; };
$GLOBALS['actions']['wpml_set_element_language_details'] = static function ( $a ) { $GLOBALS['wpml'][ $a['element_id'] ] = [ 'trid' => $a['trid'], 'lang' => $a['language_code'], 'src' => $a['source_language_code'], 'type' => $a['element_type'] ]; };
function link_req( $id, $json ) { $q = new WP_REST_Request( 'POST', '', [ 'id' => $id ] ); $q->json = $json; return $q; }

$res = BW_Bridge_Search::link_translation( link_req( 2124, [ 'translation_of' => 2121 ] ) );
check( 'link: DE-Produkt wird Übersetzung des EN-Produkts', ! is_wp_error( $res ) && (array) $res['translations'] === [ 'en' => 2121, 'de' => 2124 ] );
check( 'link: WPML bekommt Gruppe, Sprache, Quellsprache und Elementtyp', $GLOBALS['wpml'][2124] === [ 'trid' => 70, 'lang' => 'de', 'src' => 'en', 'type' => 'post_product' ] );
$res = BW_Bridge_Search::link_translation( link_req( 2124, [ 'translation_of' => 2121 ] ) );
check( 'link: wiederholen ist unschädlich (idempotent)', ! is_wp_error( $res ) && (array) $res['translations'] === [ 'en' => 2121, 'de' => 2124 ] );
$res = BW_Bridge_Search::link_translation( link_req( 2125, [ 'translation_of' => 2121 ] ) );
check( 'link: Sprache schon durch andere Übersetzung belegt => 409', is_wp_error( $res ) && $res->data['status'] === 409 && $GLOBALS['wpml'][2125]['trid'] === 72 );
$res = BW_Bridge_Search::link_translation( link_req( 2126, [ 'translation_of' => 2121 ] ) );
check( 'link: gleiche Sprache ohne "language" => 400', is_wp_error( $res ) && $res->data['status'] === 400 );
post( 2131, 'product', 'Gruppe A en' ); post( 2132, 'product', 'Gruppe A de' ); post( 2133, 'product', 'Gruppe A fr' );
$GLOBALS['wpml'][2131] = [ 'trid' => 80, 'lang' => 'en' ]; $GLOBALS['wpml'][2132] = [ 'trid' => 80, 'lang' => 'de' ]; $GLOBALS['wpml'][2133] = [ 'trid' => 80, 'lang' => 'fr' ];
$res = BW_Bridge_Search::link_translation( link_req( 2132, [ 'translation_of' => 2126 ] ) );
check( 'link: Beitrag in anderer Gruppe mit weiteren Übersetzungen => 409', is_wp_error( $res ) && $res->data['status'] === 409 && $GLOBALS['wpml'][2132]['trid'] === 80 );
$res = BW_Bridge_Search::link_translation( link_req( 2130, [ 'translation_of' => 2121 ] ) );
check( 'link: verschiedene Beitragstypen => 400', is_wp_error( $res ) && $res->data['status'] === 400 );
$res = BW_Bridge_Search::link_translation( link_req( 2124, [ 'translation_of' => 2124 ] ) );
check( 'link: Beitrag mit sich selbst => 400', is_wp_error( $res ) && $res->data['status'] === 400 );
$res = BW_Bridge_Search::link_translation( link_req( 2124, [ 'translation_of' => 999999 ] ) );
check( 'link: unbekannte Quelle => 404', is_wp_error( $res ) && $res->data['status'] === 404 );
$res = BW_Bridge_Search::link_translation( link_req( 2124, [] ) );
check( 'link: ohne translation_of => 404', is_wp_error( $res ) );
$GLOBALS['wpml'][2127] = [ 'trid' => 74, 'lang' => 'en' ]; post( 2127, 'product', 'Eins' ); post( 2128, 'product', 'Zwei' ); $GLOBALS['wpml'][2128] = [ 'trid' => 75, 'lang' => 'en' ];
$res = BW_Bridge_Search::link_translation( link_req( 2128, [ 'translation_of' => 2127, 'language' => 'de' ] ) );
check( 'link: "language" setzt zugleich die Sprache des Beitrags', ! is_wp_error( $res ) && $GLOBALS['wpml'][2128]['lang'] === 'de' && (array) $res['translations'] === [ 'en' => 2127, 'de' => 2128 ] );
$GLOBALS['filters'] = [];
$res = BW_Bridge_Search::link_translation( link_req( 2124, [ 'translation_of' => 2121 ] ) );
check( 'link: ohne WPML => 409', is_wp_error( $res ) && $res->data['status'] === 409 );


/* ---------- WPML-Strings und -Optionen ---------- */
$wp = $GLOBALS['wpdb'];
$req = static function ( $q = [], $json = null ) { $x = new WP_REST_Request( 'GET' ); $x->q = $q; $x->json = $json; return $x; };

// ohne WPML String Translation (Funktion noch nicht definiert)
check( 'strings: ohne WPML ST nicht verfügbar', ! BW_Bridge_Wpml_Strings::available() );
check( 'strings: Liste ohne WPML ST => 409', is_wp_error( BW_Bridge_Wpml_Strings::list_strings( $req() ) ) && BW_Bridge_Wpml_Strings::list_strings( $req() )->data['status'] === 409 );
check( 'strings: Setzen ohne WPML ST => 409', is_wp_error( BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 1 ] ] ] ) ) ) );
check( 'optionen: Anmelden ohne WPML ST => 409', BW_Bridge_Wpml_Strings::add_options( $req( [], [ 'options' => [ 'x' ] ] ) )->data['status'] === 409 );
$GLOBALS['actions']['wpml_multilingual_options'] = null; unset( $GLOBALS['actions']['wpml_multilingual_options'] );
BW_Bridge_Wpml_Strings::register_options();
check( 'optionen: ohne WPML-Hook passiert beim Laden nichts', ! isset( $GLOBALS['multi'] ) );

// WPML ST "aktivieren"
$GLOBALS['icl_calls'] = [];
eval( 'function icl_add_string_translation( $id, $lang, $value, $status ) { $GLOBALS["icl_calls"][] = [ $id, $lang, $value, $status ]; return 1; }' );
check( 'strings: mit WPML ST verfügbar', BW_Bridge_Wpml_Strings::available() );

// reine SQL-Bausteine
$base = [ 'q' => '', 'like' => '', 'context' => '', 'lang' => '', 'status' => '', 'limit' => 10, 'offset' => 0 ];
foreach ( [ [], [ 'context' => 'admin_texts_*' ], [ 'context' => 'ctx' ], [ 'q' => 'x', 'like' => '%x%' ], [ 'lang' => 'de', 'status' => 'missing' ], [ 'lang' => 'de', 'status' => 'incomplete' ], [ 'lang' => 'de', 'status' => 'open', 'q' => 'x', 'like' => '%x%', 'context' => 'a*' ], [ 'lang' => 'de', 'status' => 'complete' ] ] as $i => $over ) {
	[ $sql, $args ] = BW_Bridge_Wpml_Strings::build_list_query( 'wp_', $over + $base );
	check( "build_list_query #$i: Platzhalter = Parameter", preg_match_all( '/%[sd]/', $sql ) === count( $args ) );
}
[ $sql, $args ] = BW_Bridge_Wpml_Strings::build_list_query( 'wp_', [ 'context' => 'admin_texts_*', 'lang' => 'de', 'status' => 'missing' ] + $base );
check( 'build_list_query: Präfix-Kontext und Status "missing"', strpos( $sql, 's.context LIKE %s' ) !== false && strpos( $sql, 't.id IS NULL' ) !== false && $args[0] === 'de' && $args[1] === 'admin_texts_%' );
check( 'build_list_query: limit+1 für "weitere vorhanden"', array_slice( $args, -2 ) === [ 11, 0 ] );
[ $sql ] = BW_Bridge_Wpml_Strings::build_list_query( 'wp_', $base );
check( 'build_list_query: ohne Sprache kein Join', strpos( $sql, 'JOIN' ) === false );

// Liste
$wp->handler = static function ( $sql ) {
	if ( strpos( $sql, 'FROM wp_icl_string_translations' ) !== false ) { return [ [ 'string_id' => '5', 'language' => 'de', 'status' => '10', 'value' => 'Hallo' ], [ 'string_id' => '6', 'language' => 'de', 'status' => '3', 'value' => 'Alt' ] ]; }
	return [ [ 'id' => '5', 'context' => 'admin_texts_opt', 'name' => '[opt]a', 'value' => 'Hello', 'language' => 'en' ], [ 'id' => '6', 'context' => 'admin_texts_opt', 'name' => '[opt]b', 'value' => 'Bye', 'language' => 'en' ], [ 'id' => '7', 'context' => 'admin_texts_opt', 'name' => '[opt]c', 'value' => 'Third', 'language' => 'en' ] ];
};
$res = BW_Bridge_Wpml_Strings::list_strings( $req( [ 'limit' => 2 ] ) );
check( 'strings: Liste mit Übersetzungen je Sprache und Status', $res['count'] === 2 && $res['strings'][0]['translations']->de === [ 'value' => 'Hallo', 'status' => 10 ] && $res['strings'][1]['translations']->de['status'] === 3 );
check( 'strings: has_more bei mehr Treffern als limit', $res['has_more'] === true );
check( 'strings: Treffer ohne Übersetzung haben leere translations', (array) BW_Bridge_Wpml_Strings::list_strings( $req( [ 'limit' => 3 ] ) )['strings'][2]['translations'] === [] );
check( 'strings: status ohne lang => 400', BW_Bridge_Wpml_Strings::list_strings( $req( [ 'status' => 'missing' ] ) )->data['status'] === 400 );

// Setzen
$GLOBALS['filters']['wpml_active_languages'] = static fn() => [ 'en' => [], 'de' => [] ];
$wp->handler = static function ( $sql ) {
	if ( preg_match( '/FROM wp_icl_strings WHERE id = (\d+)/', $sql, $m ) ) { return (int) $m[1] === 5 ? [ 'id' => '5', 'language' => 'en', 'value' => 'Hello' ] : null; }
	if ( strpos( $sql, 'context = ' ) !== false && strpos( $sql, 'SELECT id FROM' ) !== false ) { return strpos( $sql, "'[opt]a'" ) !== false ? '5' : null; }
	if ( strpos( $sql, 'FROM wp_icl_string_translations' ) !== false ) { return 'Altwert'; }
	return null;
};
$res = BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 5, 'language' => 'de', 'value' => 'Hallo' ] ] ] ) );
check( 'translate: Übersetzung gesetzt (Status übersetzt)', $res['failed'] === 0 && $GLOBALS['icl_calls'] === [ [ 5, 'de', 'Hallo', 10 ] ] && $res['results'][0]['old'] === 'Altwert' && $res['results'][0]['source'] === 'Hello' );
$GLOBALS['icl_calls'] = [];
BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 5, 'language' => 'de', 'value' => 'Hallo', 'complete' => false ] ] ] ) );
check( 'translate: complete=false => Status "muss aktualisiert werden"', $GLOBALS['icl_calls'] === [ [ 5, 'de', 'Hallo', 3 ] ] );
$GLOBALS['icl_calls'] = [];
$res = BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'context' => 'admin_texts_opt', 'name' => '[opt]a', 'language' => 'de', 'value' => 'Hallo' ] ] ] ) );
check( 'translate: String über context + name gefunden', $res['failed'] === 0 && $GLOBALS['icl_calls'][0][0] === 5 );
$GLOBALS['icl_calls'] = [];
$res = BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 5, 'language' => 'de', 'value' => 'Probe' ] ], 'dry_run' => true ] ) );
check( 'translate: Probelauf schreibt nichts', $res['dry_run'] && $res['results'][0]['status'] === 'ok' && $GLOBALS['icl_calls'] === [] );
$res = BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 99, 'language' => 'de', 'value' => 'x' ], [ 'id' => 5, 'language' => 'en', 'value' => 'x' ], [ 'id' => 5, 'language' => 'fr', 'value' => 'x' ], [ 'id' => 5, 'language' => 'de' ], [ 'language' => 'de', 'value' => 'x' ] ] ] ) );
check( 'translate: Fehlerfälle (unbekannt, Ausgangssprache, inaktive Sprache, ohne Wert, ohne id) schreiben nichts', $res['failed'] === 5 && $GLOBALS['icl_calls'] === [] );
check( 'translate: leere Liste => 400', BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [] ] ) )->data['status'] === 400 );
$res = BW_Bridge_Wpml_Strings::set_translations( $req( [], [ 'translations' => [ [ 'id' => 5, 'language' => 'de', 'value' => 'Gut' ], [ 'id' => 99, 'language' => 'de', 'value' => 'x' ] ] ] ) );
check( 'translate: gemischte Liste: gültige werden gesetzt, Fehler gemeldet', $res['failed'] === 1 && count( $GLOBALS['icl_calls'] ) === 1 );

// Optionen
$GLOBALS['multi'] = []; $GLOBALS['actions']['wpml_multilingual_options'] = static function ( $n ) { $GLOBALS['multi'][] = $n; };
$wp->handler = static fn( $sql ) => strpos( $sql, "'admin_texts_my_settings'" ) !== false ? '4' : '0';
$res = BW_Bridge_Wpml_Strings::add_options( $req( [], [ 'options' => [ 'my_settings', 'other_opt' ] ] ) );
check( 'optionen: angemeldet, gespeichert und sofort bei WPML registriert', $GLOBALS['opts']['bw_bridge_wpml_options'] === [ 'my_settings', 'other_opt' ] && $GLOBALS['multi'] === [ 'my_settings', 'other_opt' ] );
check( 'optionen: Liste mit Stringzahl je Option (0 = WPML kennt noch keine)', $res['options'][0] === [ 'option' => 'my_settings', 'context' => 'admin_texts_my_settings', 'strings' => 4 ] && $res['options'][1]['strings'] === 0 );
$GLOBALS['multi'] = []; BW_Bridge_Wpml_Strings::register_options();
check( 'optionen: bei jedem Laden erneut registriert', $GLOBALS['multi'] === [ 'my_settings', 'other_opt' ] );
BW_Bridge_Wpml_Strings::add_options( $req( [], [ 'options' => [ 'my_settings', 'third' ] ] ) );
check( 'optionen: doppelte werden nicht doppelt gespeichert', $GLOBALS['opts']['bw_bridge_wpml_options'] === [ 'my_settings', 'other_opt', 'third' ] );
BW_Bridge_Wpml_Strings::remove_options( $req( [], [ 'options' => [ 'other_opt' ] ] ) );
check( 'optionen: entfernen', $GLOBALS['opts']['bw_bridge_wpml_options'] === [ 'my_settings', 'third' ] );
foreach ( [ 'a b', '../x', '', 'x;y' ] as $bad ) { $e = BW_Bridge_Wpml_Strings::add_options( $req( [], [ 'options' => [ $bad ] ] ) ); if ( ! is_wp_error( $e ) || $e->data['status'] !== 400 ) { check( "optionen: ungültiger Name '$bad' abgelehnt", false ); } }
check( 'optionen: ungültige Namen werden abgelehnt (400)', true );
check( 'optionen: leere Liste => 400', BW_Bridge_Wpml_Strings::add_options( $req( [], [ 'options' => [] ] ) )->data['status'] === 400 );

/* ---------- Routen ---------- */
BW_WP_Bridge::register_routes();
foreach ( [ 'wpml/strings', 'wpml/options', 'search', 'batch', 'translations/(?P<id>\d+)', 'render/(?P<id>\d+)', 'meta/(?P<id>\d+)', 'elementor/(?P<id>\d+)/texts', 'elementor/(?P<id>\d+)/backups', 'elementor/(?P<id>\d+)/restore' ] as $route ) {
	check( "Route registriert: $route", isset( $GLOBALS['routes'][ 'bw-bridge/v1/' . $route ] ) );
}

echo "\n" . ( $fail ? "$fail FEHLGESCHLAGEN, $pass bestanden" : "$pass/$pass Prüfungen bestanden" ) . "\n";
exit( $fail ? 1 : 0 );
