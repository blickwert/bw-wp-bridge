<?php
/**
 * Test für die SEO-Analyse (BW_Bridge_Seo): reine Logik (analyze, site_checks, robots_blocks_all, aggregate, Adress-Hilfen)
 * mit HTML-Beispielen sowie der Ablauf (start/step/finish) mit vorgegebenen HTTP-Antworten und WordPress-Stubs.
 * Aufruf: php tests/test-seo.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' );

/* ---------- WordPress-Stubs ---------- */
$GLOBALS['opts'] = [ 'bw_bridge_log_db' => 1, 'show_on_front' => 'page', 'page_on_front' => 10 ]; $GLOBALS['tr'] = []; $GLOBALS['did'] = []; $GLOBALS['scheduled'] = []; $GLOBALS['filters'] = [];
$GLOBALS['web'] = []; $GLOBALS['reqs'] = []; $GLOBALS['user'] = (object) [ 'ID' => 7, 'user_login' => 'claude', 'roles' => [ 'administrator' ] ];
foreach ( [ 'add_action', 'add_filter', 'register_setting', 'add_options_page', 'add_management_page', 'register_rest_route' ] as $f ) { eval( "function $f( ...\$a ) {}" ); }
function rest_get_url_prefix() { return 'wp-json'; } function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $e = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; } function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function apply_filters( $t, $v = null, ...$a ) { return array_key_exists( $t, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $t ] : $v; } function did_action( $a ) { return ! empty( $GLOBALS['did'][ $a ] ); }
function current_user_can( $c ) { return true; } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); } function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_get_current_user() { return $GLOBALS['user']; } function get_userdata( $id ) { return $id === $GLOBALS['user']->ID ? $GLOBALS['user'] : null; }
function rest_ensure_response( $d ) { return $d; } function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_next_scheduled( $h ) { return $GLOBALS['scheduled'][ $h ] ?? false; } function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][ $h ] = $t; return true; }
class WP_Error { public $code, $msg; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } }

// Inhalte
$GLOBALS['posts'] = [
	10 => [ 'url' => 'https://example.at/', 'title' => 'Start &amp; Ziel', 'type' => 'page' ],
	11 => [ 'url' => 'https://example.at/leistungen/', 'title' => 'Leistungen', 'type' => 'page' ],
	12 => [ 'url' => 'https://example.at/kontakt/', 'title' => 'Kontakt', 'type' => 'page' ],
	20 => [ 'url' => 'https://example.at/blog/neu/', 'title' => 'Neuer Beitrag', 'type' => 'post' ],
];
function home_url( $p = '' ) { return 'https://example.at' . $p; }
function get_permalink( $id ) { return $GLOBALS['posts'][ $id ]['url'] ?? false; } function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['title'] ?? ''; } function get_post_type( $id ) { return $GLOBALS['posts'][ $id ]['type'] ?? false; }
function get_post_types( $a = [] ) { return [ 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ]; }
function get_posts( $a ) {
	$t = (array) $a['post_type'];
	return array_keys( array_filter( $GLOBALS['posts'], fn( $p ) => in_array( $p['type'], $t, true ) ) );
}

// HTTP: $GLOBALS['web'][ Methode . ' ' . URL ] oder [ URL ] => [ 'code' =>, 'headers' =>, 'body' => ] bzw. WP_Error
function fake_lookup( $m, $url ) {
	$GLOBALS['reqs'][] = "$m $url";
	return $GLOBALS['web'][ "$m $url" ] ?? $GLOBALS['web'][ $url ] ?? [ 'code' => 404, 'headers' => [], 'body' => 'nicht gefunden' ];
}
function wp_remote_get( $u, $a = [] ) { return fake_lookup( 'GET', $u ); } function wp_remote_head( $u, $a = [] ) { $r = fake_lookup( 'HEAD', $u ); if ( is_array( $r ) ) { $r['body'] = ''; } return $r; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { foreach ( $r['headers'] as $k => $v ) { if ( strtolower( $k ) === strtolower( $h ) ) { return $v; } } return ''; }

define( 'REST_REQUEST', true );
require __DIR__ . '/lib-mem-wpdb.php';
$GLOBALS['wpdb'] = new Mem_Wpdb();
require __DIR__ . '/../bw-wp-bridge.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }
function st( $res, $id ) { foreach ( $res['checks'] as $c ) { if ( $c['id'] === $id ) { return $c['status']; } } return null; }
function sc( $checks, $id ) { foreach ( $checks as $c ) { if ( $c['id'] === $id ) { return $c['status']; } } return null; }
function lorem( $n ) { return implode( ' ', array_fill( 0, $n, 'Wort' ) ); }

/* ---------- Beispielseiten ---------- */
$title = 'Webdesign aus Wien – Homepages mit Konzept und Technik'; // 30–60 Zeichen
$desc  = 'Wir gestalten Websites für kleine Unternehmen in Wien: klares Design, schnelle Technik und Texte, die gefunden werden. Jetzt unverbindlich anfragen.'; // 120–160
$good  = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . $title . '</title>'
	. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="' . $desc . '">'
	. '<link rel="canonical" href="https://example.at/leistungen/">'
	. '<meta property="og:title" content="x"><meta property="og:description" content="y"><meta property="og:image" content="https://example.at/a.jpg">'
	. '<script type="application/ld+json">{"@type":"Organization"}</script><style>.x{color:red}</style></head><body>'
	. '<h1>Leistungen</h1><h2>Webdesign</h2><p>' . lorem( 320 ) . '</p><script>var wortwortwort=1;</script>'
	. '<img src="/a.jpg" alt="Büro"><img src="/b.jpg" alt=""><h2>Technik</h2>'
	. '<a href="/kontakt/">Kontakt</a> <a href="https://www.example.at/blog/neu/#top">Blog</a> <a href="leistungen/preise/">Preise</a> <a href="https://extern.com/">Extern</a>'
	. '<a href="/wp-admin/">Admin</a> <a href="/feed/">Feed</a> <a href="/files/info.pdf">PDF</a> <a href="/bilder/x.JPG">Bild</a> <a href="mailto:a@b.at">Mail</a> <a href="tel:+43">Tel</a> <a href="#oben">Oben</a>'
	. '</body></html>';
$resp_ok = [ 'code' => 200, 'ms' => 250, 'redirects' => [], 'headers' => [ 'content-type' => 'text/html; charset=UTF-8' ] ];
$home    = 'https://example.at/';
$url     = 'https://example.at/leistungen/';

$r = BW_Bridge_Seo::analyze( '', $url, [ 'code' => 404, 'ms' => 50 ], $home );
check( 'Nicht-200: nur http_status fail, Score 0', 1 === count( $r['checks'] ) && 'fail' === st( $r, 'http_status' ) && 0 === $r['score'] && 404 === $r['code'] );
$r = BW_Bridge_Seo::analyze( '', $url, [ 'code' => 0, 'error' => 'timeout' ], $home );
check( 'Nicht erreichbar: Fehlertext im Detail', 'fail' === st( $r, 'http_status' ) && 'timeout' === $r['checks'][0]['detail'] && 'Seite nicht abrufbar' === $r['checks'][0]['title'] );
$r = BW_Bridge_Seo::analyze( '', $url, [ 'code' => 401 ], $home );
check( '401: Hinweis auf Serverschutz', false !== strpos( $r['checks'][0]['detail'], 'htpasswd' ) );

$g = BW_Bridge_Seo::analyze( $good, $url, $resp_ok, $home );
$bad_status = array_filter( $g['checks'], fn( $c ) => ! in_array( $c['status'], [ 'ok', 'info' ], true ) );
check( 'Gute Seite: alle Prüfungen ok/info', ! $bad_status );
check( 'Gute Seite: hoher Score', $g['score'] >= 95 );
foreach ( [ 'http_status', 'redirects', 'response_time', 'page_size', 'title', 'description', 'robots', 'canonical', 'lang', 'viewport', 'charset', 'open_graph', 'h1', 'word_count', 'image_alt', 'structured_data', 'mixed_content', 'internal_links', 'link_text' ] as $id ) {
	check( "Gute Seite: $id ok", 'ok' === st( $g, $id ) );
}
check( 'Gute Seite: Canonical = Seite selbst', 'ok' === st( $g, 'canonical' ) && $url === $g['metrics']['canonical'] );
check( 'Gute Seite: Titel und Beschreibung erfasst', $title === $g['metrics']['title'] && $desc === $g['metrics']['description'] );
check( 'Gute Seite: alt="" zählt nicht als fehlend', 2 === $g['metrics']['images'] && 0 === $g['metrics']['images_no_alt'] );
check( 'Gute Seite: Wörter ohne Script/Style gezählt', $g['metrics']['words'] >= 320 && $g['metrics']['words'] < 360 );
check( 'Gute Seite: eine H1', [ 'Leistungen' ] === $g['metrics']['h1'] );
check( 'Gute Seite: interne Links (Kontakt, Blog mit www, relativ aufgelöst)', [ 'https://example.at/kontakt/', 'https://www.example.at/blog/neu/', 'https://example.at/leistungen/leistungen/preise/' ] === $g['internal_urls'] && 3 === $g['metrics']['links_internal'] );
check( 'Interne Links: kein wp-admin, feed, pdf, jpg, mailto, tel, Anker', 5 > count( $g['internal_urls'] ) && ! array_filter( $g['internal_urls'], fn( $u ) => preg_match( '/wp-admin|feed|\.pdf|\.jpg|mailto|tel:|#/i', $u ) ) );
check( 'Externe Links gezählt', 1 === $g['metrics']['links_external'] );
check( 'Bytes erfasst', strlen( $good ) === $g['bytes'] && 250 === $g['ms'] );

// alt-Attribut fehlt vs. leer
$h = '<html lang="de"><head><title>' . $title . '</title></head><body><h1>A</h1><img src="/a.jpg"><img src="/b.jpg" alt=""><img src="/c.jpg" alt="x"><img src="/d.jpg" alt="y"><noscript><img src="/n.jpg"></noscript></body></html>';
$r = BW_Bridge_Seo::analyze( $h, $url, $resp_ok, $home );
check( 'Bild ohne alt: 1 von 4 → warn (noscript ignoriert)', 1 === $r['metrics']['images_no_alt'] && 4 === $r['metrics']['images'] && 'warn' === st( $r, 'image_alt' ) );
$r = BW_Bridge_Seo::analyze( '<html><body><h1>A</h1><img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg" alt=""></body></html>', $url, $resp_ok, $home );
check( 'Mehr als die Hälfte ohne alt → fail', 'fail' === st( $r, 'image_alt' ) );
$r = BW_Bridge_Seo::analyze( '<html><body><h1>A</h1></body></html>', $url, $resp_ok, $home );
check( 'Keine Bilder → ok', 'ok' === st( $r, 'image_alt' ) );

// schlechte Seite
$badhtml = '<html><head></head><body><h1>Eins</h1><h1>Zwei</h1><p>Kurzer Text.</p><img src="/x.png"><img src="/y.png">'
	. '<script src="http://cdn.example.com/a.js"></script><a href="/leer/"></a><a href="https://extern.com/" rel="nofollow">E</a></body></html>';
$resp_bad = [ 'code' => 200, 'ms' => 3500, 'redirects' => [ [ 'url' => 'http://example.at/seite/', 'code' => 301 ], [ 'url' => 'https://example.at/seite', 'code' => 302 ] ], 'headers' => [ 'x-robots-tag' => 'noindex, nofollow' ] ];
$b = BW_Bridge_Seo::analyze( $badhtml, 'https://example.at/seite/', $resp_bad, $home );
check( 'Schlecht: Titel fehlt (fail)', 'fail' === st( $b, 'title' ) );
check( 'Schlecht: Beschreibung fehlt (fail)', 'fail' === st( $b, 'description' ) );
check( 'Schlecht: 2 H1 (warn)', 'warn' === st( $b, 'h1' ) && 2 === count( $b['metrics']['h1'] ) );
check( 'Schlecht: viewport fehlt (fail)', 'fail' === st( $b, 'viewport' ) );
check( 'Schlecht: Sprache und Zeichensatz fehlen (warn)', 'warn' === st( $b, 'lang' ) && 'warn' === st( $b, 'charset' ) );
check( 'Schlecht: Canonical fehlt (warn)', 'warn' === st( $b, 'canonical' ) );
check( 'Schlecht: Open Graph unvollständig (info)', 'info' === st( $b, 'open_graph' ) );
check( 'Schlecht: Bilder ohne alt (fail)', 'fail' === st( $b, 'image_alt' ) && 2 === $b['metrics']['images_no_alt'] );
check( 'Schlecht: Mixed Content (fail)', 'fail' === st( $b, 'mixed_content' ) );
check( 'Schlecht: Link ohne Text (warn)', 'warn' === st( $b, 'link_text' ) );
check( 'Schlecht: noindex per X-Robots-Tag (warn)', 'warn' === st( $b, 'robots' ) );
check( 'Schlecht: Weiterleitungen (warn)', 'warn' === st( $b, 'redirects' ) );
check( 'Schlecht: Antwortzeit über 3 s (fail)', 'fail' === st( $b, 'response_time' ) );
check( 'Schlecht: unter 150 Wörter (fail)', 'fail' === st( $b, 'word_count' ) );
check( 'Schlecht: Score niedrig', $b['score'] < 60 && $b['score'] < $g['score'] );
check( 'Schlecht: externer Link nofollow vermerkt', 1 === $b['metrics']['links_external'] && false !== strpos( $b['checks'][ array_search( 'external_links', array_column( $b['checks'], 'id' ) ) ]['detail'], 'nofollow' ) );
$r = BW_Bridge_Seo::analyze( '<html><head><meta name="robots" content="NOINDEX,follow"></head><body>x</body></html>', $url, $resp_ok, $home );
check( 'noindex per Meta robots (warn)', 'warn' === st( $r, 'robots' ) );
$r = BW_Bridge_Seo::analyze( '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>x</body></html>', $url, [ 'code' => 200 ], $home );
check( 'Zeichensatz über http-equiv erkannt', 'ok' === st( $r, 'charset' ) );
$r = BW_Bridge_Seo::analyze( '<html><head><link rel="canonical" href="/andere/"></head><body>x</body></html>', $url, $resp_ok, $home );
check( 'Canonical auf andere Adresse → info', 'info' === st( $r, 'canonical' ) );
$r = BW_Bridge_Seo::analyze( '<html><head><title>Ä</title></head><body>x</body></html>', 'http://example.at/', $resp_ok, 'http://example.at/' );
check( 'http-Seite: kein Mixed-Content-Fehler', 'ok' === st( $r, 'mixed_content' ) && 'warn' === st( $r, 'title' ) );
$r = BW_Bridge_Seo::analyze( '<html lang="de"><head><title>' . $title . '</title></head><body><h1>H</h1><h3>x</h3></body></html>', $url, $resp_ok, $home );
check( 'Übersprungene Überschriftenebene → info', 'info' === st( $r, 'headings' ) );
$r = BW_Bridge_Seo::analyze( '<html><body><h1>H</h1><h2></h2><h2>b</h2></body></html>', $url, $resp_ok, $home );
check( 'Leere Überschrift → warn', 'warn' === st( $r, 'headings' ) );
$r = BW_Bridge_Seo::analyze( $good, $url, [ 'code' => 200, 'ms' => 1500, 'redirects' => [] ], $home );
check( 'Antwortzeit 1,5 s → warn', 'warn' === st( $r, 'response_time' ) );
$r = BW_Bridge_Seo::analyze( '<html><body><a href="https://x.at/"><img src="a.png" alt="Logo"></a><a href="/p/" aria-label="Mehr"></a></body></html>', $url, $resp_ok, $home );
check( 'Link mit Bild-alt oder aria-label hat Text', 'ok' === st( $r, 'link_text' ) );
$r = BW_Bridge_Seo::analyze( '<html><body><h1>H</h1></body></html>', $url, $resp_ok, $home );
check( 'Ohne Links: internal_links warn, keine internal_urls', 'warn' === st( $r, 'internal_links' ) && [] === $r['internal_urls'] );
$r = BW_Bridge_Seo::analyze( '<html><body><a href="https://dev.example.at/x/">Sub</a><a href="https://example.at.evil.com/">E</a></body></html>', $url, $resp_ok, $home );
check( 'Subdomain und fremde Domain sind extern', [] === $r['internal_urls'] && 2 === $r['metrics']['links_external'] );

/* ---------- Adress-Hilfen ---------- */
check( 'absolute: relativ zum Verzeichnis', 'https://example.at/a/b/c.html' === BW_Bridge_Seo::absolute( 'c.html', 'https://example.at/a/b/x' ) );
check( 'absolute: ../ und ./', 'https://example.at/a/c/' === BW_Bridge_Seo::absolute( '../c/', 'https://example.at/a/b/' ) && 'https://example.at/a/b/c' === BW_Bridge_Seo::absolute( './c', 'https://example.at/a/b/' ) );
check( 'absolute: Wurzelpfad', 'https://example.at/x/' === BW_Bridge_Seo::absolute( '/x/', 'https://example.at/a/b/' ) );
check( 'absolute: protokollrelativ', 'https://cdn.com/a.js' === BW_Bridge_Seo::absolute( '//cdn.com/a.js', 'https://example.at/' ) );
check( 'absolute: vollständige Adresse bleibt', 'http://x.at/a' === BW_Bridge_Seo::absolute( 'http://x.at/a', 'https://example.at/' ) );
check( 'absolute: Query und Anker', 'https://example.at/a/b/?s=1' === BW_Bridge_Seo::absolute( '?s=1', 'https://example.at/a/b/' ) && 'https://example.at/a/#t' === BW_Bridge_Seo::absolute( '#t', 'https://example.at/a/' ) );
check( 'absolute: Port bleibt', 'http://localhost:8080/x' === BW_Bridge_Seo::absolute( '/x', 'http://localhost:8080/a' ) );
check( 'absolute: leer = Basis', 'https://example.at/a' === BW_Bridge_Seo::absolute( '', 'https://example.at/a' ) );
check( 'norm_url: Schema, www, Anker, Schrägstrich, Groß-/Kleinschreibung', 'example.at/a' === BW_Bridge_Seo::norm_url( 'HTTPS://www.Example.at/a/#x' ) && BW_Bridge_Seo::norm_url( 'http://example.at/a' ) === BW_Bridge_Seo::norm_url( 'https://www.example.at/a/' ) );
check( 'norm_url: Query bleibt, Anker-only wird leer', 'example.at/?p=1' === BW_Bridge_Seo::norm_url( 'https://example.at/?p=1' ) && '' === BW_Bridge_Seo::norm_url( '#x' ) );

/* ---------- robots.txt ---------- */
check( 'robots: Disallow / für * sperrt', BW_Bridge_Seo::robots_blocks_all( "User-agent: *\nDisallow: /" ) );
check( 'robots: Groß-/Kleinschreibung, CRLF', BW_Bridge_Seo::robots_blocks_all( "user-agent: *\r\ndisallow: /\r\n" ) );
check( 'robots: normales WordPress-robots sperrt nicht', ! BW_Bridge_Seo::robots_blocks_all( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nSitemap: https://example.at/wp-sitemap.xml" ) );
check( 'robots: gruppierte User-Agents (Googlebot + *)', BW_Bridge_Seo::robots_blocks_all( "User-agent: Googlebot\nUser-agent: *\nDisallow: /" ) );
check( 'robots: nur Googlebot gesperrt zählt nicht', ! BW_Bridge_Seo::robots_blocks_all( "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /intern/" ) );
check( 'robots: Allow / hebt Disallow / auf', ! BW_Bridge_Seo::robots_blocks_all( "User-agent: *\nDisallow: /\nAllow: /" ) && ! BW_Bridge_Seo::robots_blocks_all( "User-agent: *\nAllow: /\nDisallow: /" ) );
check( 'robots: Kommentare ignoriert', ! BW_Bridge_Seo::robots_blocks_all( "User-agent: *\n# Disallow: /\nDisallow: /tmp/ # nicht /" ) && BW_Bridge_Seo::robots_blocks_all( "User-agent: * # alle\nDisallow: / # alles" ) );
check( 'robots: leeres Disallow und leere Datei sperren nicht', ! BW_Bridge_Seo::robots_blocks_all( "User-agent: *\nDisallow:" ) && ! BW_Bridge_Seo::robots_blocks_all( '' ) );
check( 'robots: Sperre nach anderer Gruppe erkannt', BW_Bridge_Seo::robots_blocks_all( "User-agent: Bingbot\nDisallow: /x/\n\nUser-agent: *\nDisallow: /\n" ) );

/* ---------- Website-weite Prüfungen ---------- */
$site_ok = [
	'robots'   => [ 'code' => 200, 'body' => "User-agent: *\nDisallow: /wp-admin/\nSitemap: https://example.at/wp-sitemap.xml" ],
	'sitemap'  => [ 'url' => 'https://example.at/wp-sitemap.xml', 'code' => 200, 'body' => '<?xml version="1.0"?><urlset xmlns="x"></urlset>' ],
	'http'     => [ 'code' => 301, 'url' => 'https://example.at/', 'redirects' => [ [ 'url' => 'http://example.at/', 'code' => 301 ] ] ],
	'alt_host' => [ 'code' => 301, 'url' => 'https://example.at/', 'redirects' => [ [ 'url' => 'https://www.example.at/', 'code' => 301 ] ] ],
	'notfound' => [ 'code' => 404 ], 'site_icon' => true, 'home' => 'https://example.at/',
];
$s = BW_Bridge_Seo::site_checks( $site_ok );
check( 'Website gut: alles ok', ! array_filter( $s, fn( $c ) => 'ok' !== $c['status'] ) && 6 === count( $s ) );
check( 'Website gut: robots, Sitemap, https, www, 404, Favicon ok', 'ok' === sc( $s, 'robots_txt' ) && 'ok' === sc( $s, 'sitemap_xml' ) && 'ok' === sc( $s, 'https_redirect' ) && 'ok' === sc( $s, 'www_redirect' ) && 'ok' === sc( $s, 'not_found' ) && 'ok' === sc( $s, 'favicon' ) );
$t = fn( array $o ) => BW_Bridge_Seo::site_checks( $o + $site_ok );
check( 'robots.txt sperrt alles → fail', 'fail' === sc( $t( [ 'robots' => [ 'code' => 200, 'body' => "User-agent: *\nDisallow: /" ] ] ), 'robots_txt' ) );
check( 'robots.txt 404 → warn', 'warn' === sc( $t( [ 'robots' => [ 'code' => 404, 'body' => '' ] ] ), 'robots_txt' ) );
check( 'Sitemap fehlt → warn', 'warn' === sc( $t( [ 'sitemap' => [ 'url' => 'https://example.at/wp-sitemap.xml', 'code' => 404, 'body' => '' ] ] ), 'sitemap_xml' ) && 'warn' === sc( BW_Bridge_Seo::site_checks( array_diff_key( $site_ok, [ 'sitemap' => 1 ] ) ), 'sitemap_xml' ) );
check( 'Sitemap 200 aber kein XML → warn', 'warn' === sc( $t( [ 'sitemap' => [ 'url' => 'u', 'code' => 200, 'body' => '<html>Startseite</html>' ] ] ), 'sitemap_xml' ) );
check( 'Sitemapindex akzeptiert', 'ok' === sc( $t( [ 'sitemap' => [ 'url' => 'u', 'code' => 200, 'body' => '<sitemapindex></sitemapindex>' ] ] ), 'sitemap_xml' ) );
check( 'Sitemap nicht in robots.txt → info sitemap_robots', 'info' === sc( $t( [ 'robots' => [ 'code' => 200, 'body' => "User-agent: *\nDisallow: /wp-admin/" ] ] ), 'sitemap_robots' ) && null === sc( $s, 'sitemap_robots' ) );
check( 'http→https 302 → warn', 'warn' === sc( $t( [ 'http' => [ 'code' => 302, 'url' => 'https://example.at/', 'redirects' => [ [ 'url' => 'http://example.at/', 'code' => 302 ] ] ] ] ), 'https_redirect' ) );
check( 'http ohne Weiterleitung → fail', 'fail' === sc( $t( [ 'http' => [ 'code' => 200, 'url' => 'http://example.at/', 'redirects' => [] ] ] ), 'https_redirect' ) );
check( 'http-Weiterleitung auf http → fail', 'fail' === sc( $t( [ 'http' => [ 'code' => 301, 'url' => 'http://example.at/start/', 'redirects' => [ [ 'url' => 'http://example.at/', 'code' => 301 ] ] ] ] ), 'https_redirect' ) );
check( 'Ohne http-Prüfung (http-Website) keine https-Aufgabe', null === sc( BW_Bridge_Seo::site_checks( array_diff_key( $site_ok, [ 'http' => 1 ] ) ), 'https_redirect' ) );
check( 'alt_host 200 ohne Weiterleitung → warn', 'warn' === sc( $t( [ 'alt_host' => [ 'code' => 200, 'url' => 'https://www.example.at/', 'redirects' => [] ] ] ), 'www_redirect' ) );
check( 'alt_host 302 → warn', 'warn' === sc( $t( [ 'alt_host' => [ 'code' => 302, 'url' => 'https://example.at/', 'redirects' => [ [ 'url' => 'x', 'code' => 302 ] ] ] ] ), 'www_redirect' ) );
check( 'alt_host nicht erreichbar → keine Aufgabe', null === sc( $t( [ 'alt_host' => [ 'code' => 0, 'url' => 'x', 'redirects' => [] ] ] ), 'www_redirect' ) );
check( 'Ohne alt_host (Subdomain) keine www-Aufgabe', null === sc( BW_Bridge_Seo::site_checks( array_diff_key( $site_ok, [ 'alt_host' => 1 ] ) ), 'www_redirect' ) );
check( '404-Seite antwortet 200 → warn (Soft-404)', 'warn' === sc( $t( [ 'notfound' => [ 'code' => 200 ] ] ), 'not_found' ) && 'ok' === sc( $t( [ 'notfound' => [ 'code' => 410 ] ] ), 'not_found' ) );
check( 'Favicon fehlt → warn', 'warn' === sc( $t( [ 'site_icon' => false ] ), 'favicon' ) );

/* ---------- Zusammenfassung ---------- */
$mk = function ( $id, $name, $t, $d, $over = [] ) use ( $resp_ok ) {
	$html = '<html lang="de"><head><meta charset="utf-8"><title>' . $t . '</title>' . ( '' !== $d ? '<meta name="description" content="' . $d . '">' : '' ) . '<meta name="viewport" content="w"></head><body><h1>' . $name . '</h1><p>' . lorem( 320 ) . '</p>'
		. '<a href="/kontakt/">K</a></body></html>';
	return [ 'id' => $id, 'url' => 'https://example.at/' . $id . '/', 'name' => $name, 'type' => 'page' ] + BW_Bridge_Seo::analyze( $over['html'] ?? $html, 'https://example.at/' . $id . '/', $over['resp'] ?? $resp_ok, 'https://example.at/' );
};
$p1 = $mk( 1, 'Eins', $title, $desc );
$p2 = $mk( 2, 'Zwei', strtoupper( $title ) === $title ? $title : mb_strtoupper( $title, 'UTF-8' ), $desc ); // gleicher Titel (ohne Groß-/Kleinschreibung) und gleiche Beschreibung
$p3 = $mk( 3, 'Drei', 'Ein ganz anderer Seitentitel für Seite drei', 'Eine eigene, ausreichend lange Meta-Beschreibung für die dritte Seite, die weder zu kurz noch zu lang ist und sich klar von allen anderen unterscheidet.' );
$p4 = [ 'id' => 4, 'url' => 'https://example.at/4/', 'name' => 'Vier', 'type' => 'page' ] + BW_Bridge_Seo::analyze( '', 'https://example.at/4/', [ 'code' => 404 ], $home );
$broken = [ [ 'url' => 'https://example.at/weg/', 'code' => 404, 'from' => [ 1 ] ] ];
$site   = $s;
$a = BW_Bridge_Seo::aggregate( [ $p1, $p2, $p3, $p4 ], $site, $broken );
$byid = [];
foreach ( $a['pages'] as $p ) { $byid[ $p['id'] ] = $p; }
check( 'Ergebnis-Schlüssel', [ 'score', 'categories', 'counts', 'tasks', 'site', 'pages', 'broken_links' ] === array_keys( $a ) );
check( 'Doppelter Titel auf Seite 1 und 2, nicht 3', 'warn' === sc( $byid[1]['checks'], 'duplicate_title' ) && 'warn' === sc( $byid[2]['checks'], 'duplicate_title' ) && null === sc( $byid[3]['checks'], 'duplicate_title' ) );
check( 'Doppelte Beschreibung auf Seite 1 und 2', 'warn' === sc( $byid[1]['checks'], 'duplicate_description' ) && 'warn' === sc( $byid[2]['checks'], 'duplicate_description' ) && null === sc( $byid[3]['checks'], 'duplicate_description' ) );
check( 'Defekter Link nur an Seite 1 (fail)', 'fail' === sc( $byid[1]['checks'], 'broken_links' ) && null === sc( $byid[2]['checks'], 'broken_links' ) );
check( 'Seiten enthalten nur Befunde ungleich ok, ohne internal_urls', ! array_filter( $a['pages'], fn( $p ) => isset( $p['internal_urls'] ) || array_filter( $p['checks'], fn( $c ) => 'ok' === $c['status'] ) ) );
check( 'Seiten: fail/warn-Zähler und Score', 1 === $byid[1]['fail'] && 3 === $byid[1]['warn'] && $byid[1]['score'] < $byid[3]['score'] && 0 === $byid[4]['score'] && 1 === $byid[4]['fail'] );
check( 'Seiten nach Score aufsteigend', 4 === $a['pages'][0]['id'] && $a['pages'][1]['score'] <= $a['pages'][2]['score'] );
check( 'Seitenfelder erhalten', ! array_diff( [ 'id', 'url', 'name', 'type', 'code', 'ms', 'bytes', 'metrics', 'checks', 'score', 'fail', 'warn' ], array_keys( $byid[1] ) ) );
check( 'Seite 4 (404) ohne Duplikat-/Linkprüfung', 1 === count( $byid[4]['checks'] ) );
check( 'Bereiche mit Label, Score, fail, warn', ! array_diff( [ 'meta', 'quality', 'structure', 'links', 'server' ], array_keys( $a['categories'] ) ) && 'Links' === $a['categories']['links']['label'] && 1 === $a['categories']['links']['fail'] && 0 === $a['categories']['links']['warn'] && $a['categories']['quality']['warn'] >= 4 );
$sc = array_column( $a['categories'], 'score' );
check( 'Gesamtscore = Mittel der Bereiche', (int) round( array_sum( $sc ) / count( $sc ) ) === $a['score'] && $a['score'] > 0 && $a['score'] < 100 );
$only = BW_Bridge_Seo::aggregate( [], [ BW_Bridge_Seo::site_checks( $site_ok )[0] ], [] );
check( 'Bereich ohne Prüfungen hat Score null und zählt nicht ins Mittel', null === $only['categories']['quality']['score'] && 'ok' === $only['site'][0]['status'] && 100 === $only['score'] );
check( 'Leer aggregiert: Score 0, keine Fehler', 0 === BW_Bridge_Seo::aggregate( [], [] )['score'] );
$tk = []; foreach ( $a['tasks'] as $x ) { $tk[ $x['id'] . ':' . $x['status'] ] = $x; }
check( 'Aufgabe duplicate_title mit Seiten 1 und 2', [ 1, 2 ] === array_column( $tk['duplicate_title:warn']['pages'], 'id' ) && 'Eins' === $tk['duplicate_title:warn']['pages'][0]['name'] && ! $tk['duplicate_title:warn']['site'] );
check( 'Aufgabe broken_links nur Seite 1', [ 1 ] === array_column( $tk['broken_links:fail']['pages'], 'id' ) );
check( 'Aufgabe http_status (Seite 4)', [ 4 ] === array_column( $tk['http_status:fail']['pages'], 'id' ) );
check( 'Aufgaben ohne ok/info', ! array_filter( $a['tasks'], fn( $x ) => ! in_array( $x['status'], [ 'fail', 'warn' ], true ) ) );
$first_warn = array_search( 'warn', array_column( $a['tasks'], 'status' ), true );
check( 'Aufgaben: fail vor warn', false !== $first_warn && ! in_array( 'fail', array_slice( array_column( $a['tasks'], 'status' ), $first_warn ), true ) );
$site_warn = BW_Bridge_Seo::aggregate( [], $t( [ 'site_icon' => false ] ) );
check( 'Website-Aufgabe mit site=true und ohne Seiten', 1 === count( $site_warn['tasks'] ) && 'favicon' === $site_warn['tasks'][0]['id'] && $site_warn['tasks'][0]['site'] && [] === $site_warn['tasks'][0]['pages'] );
check( 'Zähler passen zu den Befunden', $a['counts']['fail'] + $a['counts']['warn'] + $a['counts']['info'] + $a['counts']['ok'] === count( $site ) + array_sum( array_map( fn( $p ) => count( $p['checks'] ), [ $p1, $p2, $p3, $p4 ] ) ) + 7 && 2 === $a['counts']['fail'] );
check( 'broken_links durchgereicht', $broken === $a['broken_links'] );
check( 'Aufgabentitel ohne Seitenzahlen', 'Gleicher Seitentitel auf mehreren Seiten' === $tk['duplicate_title:warn']['title'] );

/* ---------- Ablauf mit Stubs ---------- */
$ct = 'text/html; charset=UTF-8';
$page = function ( $t, $links ) use ( $desc ) {
	return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . $t . ' – Beispiel GmbH Wien</title><meta name="viewport" content="w"><meta name="description" content="' . $desc . ' ' . $t . '">'
		. '<link rel="canonical" href="https://example.at/"></head><body><h1>' . $t . '</h1><h2>Unter</h2><p>' . lorem( 320 ) . '</p><img src="/a.jpg" alt="x">' . $links . '</body></html>';
};
$GLOBALS['web'] = [
	'https://example.at/'                => [ 'code' => 200, 'headers' => [ 'content-type' => $ct ], 'body' => $page( 'Start', '<a href="/leistungen/">L</a> <a href="/kontakt/">K</a> <a href="/gibt-es-nicht/">Weg</a> <a href="/ok-seite/">Ok</a> <a href="/nur-get/">Get</a> <a href="/leistungen/">Nochmal</a>' ) ],
	'https://example.at/leistungen/'     => [ 'code' => 200, 'headers' => [ 'content-type' => $ct ], 'body' => $page( 'Leistungen', '<a href="/">Start</a> <a href="/gibt-es-nicht/">Weg</a> <a href="/kontakt/">K</a>' ) ],
	'https://example.at/kontakt/'        => [ 'code' => 500, 'headers' => [], 'body' => 'Fehler' ],
	'https://example.at/blog/neu/'       => [ 'code' => 200, 'headers' => [ 'content-type' => $ct ], 'body' => $page( 'Beitrag', '<a href="/ok-seite/">Ok</a>' ) ],
	'https://example.at/ok-seite/'       => [ 'code' => 200, 'headers' => [], 'body' => 'ok' ],
	'HEAD https://example.at/nur-get/'   => [ 'code' => 405, 'headers' => [], 'body' => '' ],
	'GET https://example.at/nur-get/'    => [ 'code' => 200, 'headers' => [], 'body' => 'ok' ],
	'https://example.at/robots.txt'      => [ 'code' => 200, 'headers' => [], 'body' => "User-agent: *\nDisallow: /wp-admin/\nSitemap: https://example.at/sm.xml" ],
	'https://example.at/sm.xml'          => [ 'code' => 200, 'headers' => [], 'body' => '<?xml version="1.0"?><urlset></urlset>' ],
	'http://example.at/'                 => [ 'code' => 301, 'headers' => [ 'Location' => 'https://example.at/' ], 'body' => '' ],
	'https://www.example.at/'            => [ 'code' => 301, 'headers' => [ 'Location' => 'https://example.at/' ], 'body' => '' ],
];
$GLOBALS['web_full'] = $GLOBALS['web'];
$GLOBALS['opts']['site_icon'] = 1;
check( 'Ziele: Startseite zuerst, dann Seiten, dann Beiträge', [ 10, 11, 12, 20 ] === BW_Bridge_Seo::targets() );
$st = BW_Bridge_Seo::start( true );
$state = BW_Bridge_Seo::state();
check( 'Lauf abgeschlossen: Warteschlange leer, kein Lauf aktiv', [] === $state['queue'] && false === $st['running'] && 0 === $st['pending'] );
check( 'Zwischenstände nach finish geleert', [] === $state['pages'] && [] === $state['links'] );
$res = get_option( 'bw_bridge_seo_result' );
check( 'Ergebnis gespeichert (score, finished, started, links_checked)', is_int( $res['score'] ) && $res['score'] > 0 && ! empty( $res['finished'] ) && ! empty( $res['started'] ) && isset( $res['links_checked'] ) );
check( 'status() liefert Ergebnis plus running/pending', $res['score'] === $st['score'] && isset( $st['running'], $st['pending'] ) );
check( '4 Seiten im Ergebnis, Startseite benannt (Entities aufgelöst)', 4 === count( $res['pages'] ) );
$pg = []; foreach ( $res['pages'] as $p ) { $pg[ $p['id'] ] = $p; }
check( 'Startseite: Name aus Titel, Typ page', 'Start & Ziel' === $pg[10]['name'] && 'page' === $pg[10]['type'] && 200 === $pg[10]['code'] );
check( 'Seite mit HTTP 500: fail', 500 === $pg[12]['code'] && 'fail' === sc( $pg[12]['checks'], 'http_status' ) );
check( 'Doppelte Canonical/Titel-Logik greift nicht fälschlich', null === sc( $pg[10]['checks'], 'duplicate_title' ) );
$bl = []; foreach ( $res['broken_links'] as $b ) { $bl[ $b['url'] ] = $b; }
check( 'Defekte interne Links: /gibt-es-nicht/ (404 per HEAD) von Seite 10 und 11', isset( $bl['https://example.at/gibt-es-nicht/'] ) && 404 === $bl['https://example.at/gibt-es-nicht/']['code'] && [ 10, 11 ] === $bl['https://example.at/gibt-es-nicht/']['from'] );
check( 'Bereits geprüfte Seite mit Fehlercode (500) gilt als defekt, von 10 und 11', isset( $bl['https://example.at/kontakt/'] ) && 500 === $bl['https://example.at/kontakt/']['code'] && [ 10, 11 ] === $bl['https://example.at/kontakt/']['from'] );
check( 'Funktionierende Links nicht defekt; HEAD 405 → GET-Fallback', 2 === count( $bl ) && ! isset( $bl['https://example.at/nur-get/'] ) );
check( 'Bereits geprüfte Seiten nicht erneut per HEAD abgerufen', ! in_array( 'HEAD https://example.at/leistungen/', $GLOBALS['reqs'], true ) && in_array( 'HEAD https://example.at/gibt-es-nicht/', $GLOBALS['reqs'], true ) && 1 === count( array_keys( $GLOBALS['reqs'], 'HEAD https://example.at/gibt-es-nicht/', true ) ) );
check( 'links_checked = geprüfte Links (ok-seite, nur-get, gibt-es-nicht, kontakt)', 4 === $res['links_checked'] );
check( 'Defekter Link als fail auf der verlinkenden Seite', 'fail' === sc( $pg[10]['checks'], 'broken_links' ) && 'fail' === sc( $pg[11]['checks'], 'broken_links' ) && null === sc( $pg[20]['checks'], 'broken_links' ) );
$ids = array_column( $res['tasks'], 'id' );
check( 'Aufgabe broken_links mit Seiten 10 und 11', in_array( 'broken_links', $ids, true ) && [ 10, 11 ] === array_column( $res['tasks'][ array_search( 'broken_links', $ids, true ) ]['pages'], 'id' ) );
check( 'Website-Prüfungen im Ergebnis (robots, Sitemap, https, www, 404, Favicon)', 6 === count( $res['site'] ) && ! array_filter( $res['site'], fn( $c ) => 'ok' !== $c['status'] ) );
check( 'Apex-Domain: http- und www-Variante wurden geprüft', in_array( 'GET http://example.at/', $GLOBALS['reqs'], true ) && in_array( 'GET https://www.example.at/', $GLOBALS['reqs'], true ) );
$logs = array_values( array_filter( $GLOBALS['wpdb']->rows, fn( $r ) => 'analysis' === $r['action'] ) );
check( 'Protokolleintrag seo/analysis geschrieben', 1 === count( $logs ) && 'seo' === $logs[0]['category'] && 'seo' === $logs[0]['area'] && 'claude' === $logs[0]['via'] && 0 === strpos( $logs[0]['message'], 'SEO-Analyse: ' . $res['score'] . ' %' ) );
check( 'Kein Folgeschritt eingeplant', empty( $GLOBALS['scheduled'] ) );

// Zweiter Lauf: vorheriger Score im Protokoll, kein Neustart innerhalb der Woche
$n = count( $GLOBALS['reqs'] );
BW_Bridge_Seo::maybe_start();
check( 'maybe_start: frisches Ergebnis → kein neuer Lauf', $n === count( $GLOBALS['reqs'] ) );
$res['finished'] = gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ); $GLOBALS['opts']['bw_bridge_seo_result'] = $res;
BW_Bridge_Seo::maybe_start();
$logs = array_values( array_filter( $GLOBALS['wpdb']->rows, fn( $r ) => 'analysis' === $r['action'] ) );
check( 'maybe_start: nach 8 Tagen neuer Lauf, Eintrag mit „vorher“ und Cron-Quelle', 2 === count( $logs ) && false !== strpos( $logs[1]['message'], 'vorher' ) && 'cron' === $logs[1]['via'] );

// Zeitbudget: Lauf in Teilen, Folgeschritt per Cron
$GLOBALS['web'] = $GLOBALS['web_full'];
$GLOBALS['opts']['bw_bridge_seo_state'] = []; $GLOBALS['opts']['bw_bridge_seo_result'] = []; $GLOBALS['scheduled'] = [];
$GLOBALS['filters']['bw_bridge_seo_budget'] = 0;
$st = BW_Bridge_Seo::start();
check( 'Budget 0: Lauf angelegt, noch nichts abgearbeitet, Folgeschritt eingeplant', $st['running'] && 7 === $st['pending'] && ! empty( $GLOBALS['scheduled']['bw_bridge_seo_step'] ) && empty( get_option( 'bw_bridge_seo_result' ) ) );
check( 'Sperre nach dem Schritt wieder frei', false === get_transient( 'bw_bridge_seo_lock' ) );
$GLOBALS['tr']['bw_bridge_seo_lock'] = 1;
$before = count( $GLOBALS['reqs'] );
$GLOBALS['filters']['bw_bridge_seo_budget'] = 20;
BW_Bridge_Seo::step();
check( 'Sperre verhindert parallelen Schritt', $before === count( $GLOBALS['reqs'] ) && 7 === BW_Bridge_Seo::status()['pending'] );
unset( $GLOBALS['tr']['bw_bridge_seo_lock'] );
$st = BW_Bridge_Seo::start();
check( 'Laufender Lauf wird fortgesetzt und beendet', ! $st['running'] && 0 === $st['pending'] && ! empty( get_option( 'bw_bridge_seo_result' )['finished'] ) );

// Nicht erreichbare Website
$GLOBALS['web'] = [ 'https://example.at/' => new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) ];
$GLOBALS['opts']['bw_bridge_seo_state'] = []; $GLOBALS['opts']['bw_bridge_seo_result'] = [];
BW_Bridge_Seo::start();
$res = get_option( 'bw_bridge_seo_result' );
$pg = []; foreach ( $res['pages'] as $p ) { $pg[ $p['id'] ] = $p; }
check( 'Fehlerhafte Website: Seite nicht abrufbar, keine Linkprüfung, Lauf trotzdem fertig', 0 === $pg[10]['code'] && 'cURL error 28: timeout' === $pg[10]['checks'][0]['detail'] && [] === $res['broken_links'] && 0 === $res['links_checked'] && [] === BW_Bridge_Seo::state()['queue'] );

// Weiterleitungsketten in fetch()
$GLOBALS['web'] = [
	'http://a.at/1' => [ 'code' => 301, 'headers' => [ 'Location' => '/2' ], 'body' => '' ],
	'http://a.at/2' => [ 'code' => 302, 'headers' => [ 'location' => 'http://a.at/3' ], 'body' => '' ],
	'http://a.at/3' => [ 'code' => 200, 'headers' => [ 'X-Robots-Tag' => [ 'noindex', 'nofollow' ] ], 'body' => 'ende' ],
	'http://a.at/loop' => [ 'code' => 301, 'headers' => [ 'Location' => '/loop' ], 'body' => '' ],
];
$f = BW_Bridge_Seo::fetch( 'http://a.at/1' );
check( 'fetch folgt Weiterleitungen und zählt sie', 200 === $f['code'] && 'http://a.at/3' === $f['url'] && [ 301, 302 ] === array_column( $f['redirects'], 'code' ) && 'ende' === $f['body'] );
check( 'fetch: mehrfache Header werden zu Text', 'noindex, nofollow' === $f['headers']['x-robots-tag'] );
$f = BW_Bridge_Seo::fetch( 'http://a.at/loop' );
check( 'fetch: Weiterleitungsschleife → Code 0 mit Fehler', 0 === $f['code'] && ! empty( $f['error'] ) );
$f = BW_Bridge_Seo::fetch( 'http://a.at/1', 'GET', 0 );
check( 'fetch ohne Folgen: Code 301 und Ziel', 301 === $f['code'] && 'http://a.at/2' === $f['url'] && 1 === count( $f['redirects'] ) );
$f = BW_Bridge_Seo::fetch( 'http://a.at/nix', 'HEAD' );
check( 'fetch HEAD 404', 404 === $f['code'] && '' === $f['body'] );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
