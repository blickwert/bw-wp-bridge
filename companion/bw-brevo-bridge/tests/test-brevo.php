<?php
/**
 * Test gegen eine simulierte WordPress-Umgebung (kein echter Brevo-Zugriff).
 * Aufruf: php tests/test-brevo.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['opts'] = []; $GLOBALS['meta'] = []; $GLOBALS['calls'] = []; $GLOBALS['api'] = []; $GLOBALS['db'] = [];
$GLOBALS['posts'] = [ 7 => (object) [ 'ID' => 7, 'post_type' => 'bw_newsletter', 'post_status' => 'draft', 'post_title' => 'Herbst-News', 'post_content' => '<p>Hallo</p>' ] ];

function add_action( ...$a ) {} function add_filter( ...$a ) {} function register_activation_hook( ...$a ) {} function register_deactivation_hook( ...$a ) {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function apply_filters( $tag, $v, ...$a ) {
	if ( 'bwb_pre_request' === $tag ) { return ( $GLOBALS['api_handler'] )( ...$a ); }
	if ( 'the_content' === $tag ) { return $v; }
	if ( 'bwb_require_test_before_send' === $tag ) { return true; }
	return $v;
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_bloginfo( $s = '' ) { return 'de-DE'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); } function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function wpautop( $s ) { return $s ? '<p>' . $s . '</p>' : ''; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', trim( $s ) ) ? trim( $s ) : ''; }
function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $s ); }
function esc_url( $s ) { return preg_match( '#^https?://#', $s ) ? htmlspecialchars( $s ) : ''; } function wp_kses_post( $s ) { return preg_replace( '#<script.*?</script>#is', '', $s ); }
function date_i18n( $f, $t ) { return date( $f, $t ); } function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $e = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Vienna' ); }
function wp_salt( $s = '' ) { return 'salz'; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_request( ...$a ) { throw new Exception( 'echter HTTP-Aufruf!' ); }
function rest_ensure_response( $x ) { return $x; }
function current_user_can( $c ) { return true; }
class WP_Error { public $code, $msg, $data; function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->msg = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->msg; } function get_error_data() { return $this->data; } }
class Req { public $p, $body; function __construct( $p = [], $body = '' ) { $this->p = $p; $this->body = $body; } function get_param( $k ) { return $this->p[ $k ] ?? null; } function get_body() { return $this->body; } }
class WPDB_Stub { public $prefix = 'wp_'; function insert( $t, $row ) { $GLOBALS['db'][] = $row; return 1; } }
$wpdb = new WPDB_Stub;

require __DIR__ . '/../bw-brevo-bridge.php';

$pass = $fail = 0;
function check( $label, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function api( $handler ) { $GLOBALS['api_handler'] = static function ( $method, $url, $arg ) use ( $handler ) { $GLOBALS['calls'][] = [ $method, $url, json_decode( $arg['body'] ?? 'null', true ) ]; return $handler( $method, $url ); }; }
function ok( $body = [], $code = 200 ) { return [ 'code' => $code, 'body' => json_encode( $body ) ]; }

/* 1. Statistik */
$n = BW_Brevo_Stats::normalize( [ 'sent' => 1000, 'delivered' => 970, 'uniqueViews' => 400, 'uniqueClicks' => 80, 'softBounces' => 10, 'hardBounces' => 20, 'complaints' => 5, 'unsubscriptions' => 7 ] );
$r = BW_Brevo_Stats::rates( $n );
check( 'Zustellrate 97 %', $r['delivery_rate'] === 97.0 );
check( 'Bounce-Rate 3 %, Hard 2 %', $r['bounce_rate'] === 3.0 && $r['hard_bounce_rate'] === 2.0 );
check( 'Beschwerderate relativ zu zugestellt (0,52 %)', $r['complaint_rate'] === 0.52 );
$w = BW_Brevo_Stats::warnings( $n, $r );
check( 'Warnungen: Beschwerden über 0,3 % und Hard Bounces', count( $w ) === 2 && strpos( implode( '|', $w ), '0,3' ) !== false );
check( 'keine Division durch 0', BW_Brevo_Stats::rates( BW_Brevo_Stats::normalize( [] ) )['delivery_rate'] === null );
check( 'unbrauchbare Werte werden 0', BW_Brevo_Stats::normalize( [ 'sent' => -4, 'delivered' => 'x' ] )['sent'] === 0 );

/* 2. Webhook */
$N = [ 'BW_Brevo_Webhook', 'normalize_event' ];
check( 'Ereignisnamen vereinheitlicht', $N( 'hardBounce' ) === 'hard_bounce' && $N( 'hard_bounce' ) === 'hard_bounce' && $N( 'unsubscribed' ) === 'unsubscribe' && $N( 'uniqueOpened' ) === 'opened' && $N( 'spam' ) === 'spam' );
check( 'unbekanntes Ereignis bleibt erkennbar', $N( 'listAddition' ) === 'other:listaddition' );
update_option( 'bwb_webhook_token', 'geheim123' );
check( 'Webhook: falscher Token → 403', code( BW_Brevo_Webhook::receive( new Req( [ 'token' => 'falsch' ], '{"event":"delivered"}' ) ) ) === 'bwb_forbidden' );
check( 'Webhook: kein Token → 403', code( BW_Brevo_Webhook::receive( new Req( [], '{}' ) ) ) === 'bwb_forbidden' );
check( 'Webhook: kein JSON → 400', code( BW_Brevo_Webhook::receive( new Req( [ 'token' => 'geheim123' ], 'kaputt' ) ) ) === 'bwb_bad_json' );
$res = BW_Brevo_Webhook::receive( new Req( [ 'token' => 'geheim123' ], json_encode( [ [ 'event' => 'hard_bounce', 'email' => 'Max@Gmail.com', 'camp_id' => 12, 'reason' => 'mailbox does not exist', 'ts_event' => 1760000000 ], [ 'event' => 'delivered', 'email' => 'a@web.de', 'camp_id' => 12 ], [ 'kein' => 'event' ] ] ) ) );
check( 'Webhook: Stapel – 2 gespeichert, 1 übersprungen', $res['received'] === 2 && count( $GLOBALS['db'] ) === 2 );
$row = $GLOBALS['db'][0];
check( 'Webhook: nur Hash + Domain, keine Adresse', $row['domain'] === 'gmail.com' && strlen( $row['email_hash'] ) === 64 && strpos( json_encode( $row ), 'Max' ) === false && strpos( json_encode( $row ), 'max@' ) === false );
check( 'Webhook: Zeit und Kampagne übernommen', $row['ts'] === gmdate( 'Y-m-d H:i:s', 1760000000 ) && $row['campaign_id'] === 12 && $row['event'] === 'hard_bounce' );
$res = BW_Brevo_Webhook::receive( new Req( [ 'token' => 'geheim123' ], json_encode( [ 'event' => 'spam', 'email' => 'x@y.de' ] ) ) );
check( 'Webhook: Einzelereignis', $res['received'] === 1 );
update_option( 'bwb_webhook_token', '' );
check( 'Webhook: ohne gesetzten Token nie offen', code( BW_Brevo_Webhook::receive( new Req( [ 'token' => '' ], '{"event":"spam"}' ) ) ) === 'bwb_forbidden' );

/* 3. Kampagne: Nutzlast und Prüfung */
update_option( 'bwb_sender_name', 'Blickwert' ); update_option( 'bwb_sender_email', 'news@example.test' ); update_option( 'bwb_default_lists', '3,7,x,3' );
$f = BW_Brevo_Campaigns::fields( 7 );
check( 'Standardwerte: Betreff=Titel, Absender, Listen ohne Müll/Dubletten', $f['subject'] === 'Herbst-News' && $f['sender_email'] === 'news@example.test' && $f['list_ids'] === [ 3, 7 ] );
$html = BW_Brevo_Campaigns::build_html( 7, $f );
check( 'HTML enthält Inhalt und Abmeldelink', strpos( $html, '<p>Hallo</p>' ) !== false && strpos( $html, '{{ unsubscribe }}' ) !== false );
$p = BW_Brevo_Campaigns::build_payload( $f, $html );
check( 'Nutzlast ohne scheduledAt (Entwurf wird nie geplant)', ! isset( $p['scheduledAt'] ) && $p['recipients']['listIds'] === [ 3, 7 ] && $p['sender']['name'] === 'Blickwert' );
check( 'Validierung ok', null === BW_Brevo_Campaigns::validate( $f, $html ) );
check( 'Validierung: fehlender Abmeldelink', strpos( (string) BW_Brevo_Campaigns::validate( $f, '<p>x</p>' ), 'Abmeldelink' ) !== false );
$g = $f; $g['list_ids'] = []; $g['sender_email'] = '';
check( 'Validierung: fehlende Listen/Absender', strpos( (string) BW_Brevo_Campaigns::validate( $g, $html ), 'Listen-IDs' ) !== false );
check( 'Hash ignoriert scheduledAt', BW_Brevo_Campaigns::payload_hash( $p ) === BW_Brevo_Campaigns::payload_hash( $p + [ 'scheduledAt' => 'x' ] ) );
check( 'Zeit: Vergangenheit abgelehnt', code( BW_Brevo_Campaigns::parse_time( '2020-01-01 10:00' ) ) === 'bwb_time' );
check( 'Zeit: Unsinn abgelehnt', code( BW_Brevo_Campaigns::parse_time( 'bald' ) ) === 'bwb_time' );
$t = BW_Brevo_Campaigns::parse_time( date( 'Y-m-d H:i', time() + 86400 ) );
check( 'Zeit: Zukunft → ISO 8601 mit Zone', (bool) preg_match( '/\+0[12]:00$/', $t ) );

/* 4. push / test / send gegen simulierte API */
update_option( 'bwb_test_emails', 'test@example.test' );
$GLOBALS['calls'] = [];
api( static function ( $m, $u ) { return 'POST' === $m && substr( $u, -15 ) === "/emailCampaigns" ? ok( [ 'id' => 55 ], 201 ) : ok( [], 204 ); } );
update_option( 'bwb_api_key', 'xkeysib-test' );
$r = BW_Brevo_Campaigns::push( 7 );
check( 'push: legt Entwurf an, merkt Kampagnen-ID', $r['campaign_id'] === 55 && get_post_meta( 7, 'bwb_campaign_id', true ) === 55 && $GLOBALS['calls'][0][0] === 'POST' );
check( 'push: kein Versand-Aufruf', count( array_filter( $GLOBALS['calls'], static function ( $c ) { return strpos( $c[1], 'sendNow' ) !== false; } ) ) === 0 );
$GLOBALS['calls'] = [];
api( static function ( $m, $u ) { return 'GET' === $m ? ok( [ 'id' => 55, 'status' => 'sent' ] ) : ok( [], 204 ); } );
check( 'push: versendete Kampagne wird nicht überschrieben (409)', code( BW_Brevo_Campaigns::push( 7 ) ) === 'bwb_locked' && $GLOBALS['calls'][0][0] === 'GET' && count( $GLOBALS['calls'] ) === 1 );

api( static function ( $m, $u ) { return 'GET' === $m ? ok( [ 'status' => 'draft' ] ) : ok( [], 204 ); } );
$GLOBALS['calls'] = [];
check( 'test ohne Freigabe: nur Fremde → abgelehnt, nichts gesendet', code( BW_Brevo_Campaigns::test( 7, [ 'fremd@example.test' ] ) ) === 'bwb_no_test_recipient' && count( $GLOBALS['calls'] ) === 0 );
$r = BW_Brevo_Campaigns::test( 7, [ 'fremd@example.test', 'test@example.test' ] );
check( 'test ohne Freigabe: nur Testempfänger, Fremde werden ignoriert', $r['sent_to'] === [ 'test@example.test' ] );
check( 'test: ruft sendTest, merkt Inhalt', (bool) array_filter( $GLOBALS['calls'], static function ( $c ) { return substr( $c[1], -9 ) === '/sendTest'; } ) && get_post_meta( 7, 'bwb_tested_hash', true ) !== '' );

$GLOBALS['calls'] = [];
$r = BW_Brevo_Campaigns::send( 7 );
check( 'send ohne Freigabe: Probelauf, keine API-Aufrufe', ! empty( $r['dry_run'] ) && count( $GLOBALS['calls'] ) === 0 );
check( 'Probelauf steht im Protokoll', get_option( 'bwb_outbox' )[0]['result'] === 'dry_run' );

update_option( 'bwb_live', 1 );
update_post_meta( 7, 'bwb_tested_hash', 'veraltet' );
$GLOBALS['calls'] = [];
check( 'send live: ohne passenden Test gesperrt (409)', code( BW_Brevo_Campaigns::send( 7 ) ) === 'bwb_untested' && count( $GLOBALS['calls'] ) === 0 );
BW_Brevo_Campaigns::test( 7 ); $GLOBALS['calls'] = [];
$r = BW_Brevo_Campaigns::send( 7 );
check( 'send live: nach Test → sendNow', $r['status'] === 'in_process' && (bool) array_filter( $GLOBALS['calls'], static function ( $c ) { return substr( $c[1], -8 ) === '/sendNow'; } ) );

/* 5. API-Fehler */
api( static function () { return ok( [ 'message' => 'Sender is not valid', 'code' => 'invalid_parameter' ], 400 ); } );
$e = BW_Brevo_API::request( 'GET', '/x' );
check( 'API-Fehler → WP_Error mit Brevo-Meldung', code( $e ) === 'bwb_api' && strpos( $e->get_error_message(), 'Sender is not valid' ) !== false );
unset( $GLOBALS['opts']['bwb_api_key'] );
check( 'ohne API-Schlüssel kein Aufruf', code( BW_Brevo_API::request( 'GET', '/x' ) ) === 'bwb_no_key' );

/* 6. Automationen */
update_option( 'bwb_live', 0 );
update_option( 'bwb_api_key', 'xkeysib-test' );
$A = 'BW_Brevo_Automations';
$s = $A::sanitize_rules( [ 'user_register' => [ 'enabled' => '1', 'event' => 'neu angemeldet!<b>' ], 'boese' => [ 'enabled' => 1 ] ] );
check( 'Regeln: Name bereinigt, unbekannte Auslöser verworfen', $s['user_register']['event'] === 'neuangemeldetb' && ! isset( $s['boese'] ) && $s['wp_login']['enabled'] === 0 );
check( 'Standard-Ereignisnamen ohne doppeltes wp_', $A::default_event( 'wp_login' ) === 'wp_login' && $A::default_event( 'user_register' ) === 'wp_user_register' );
$GLOBALS['calls'] = [];
$r = $A::fire( 'anna@example.test', 'wp_user_register', [], false, 'test' );
check( 'ohne Einwilligung: übersprungen, nichts gesendet', $r['result'] === 'skipped_no_consent' && count( $GLOBALS['calls'] ) === 0 );
$r = $A::fire( 'anna@example.test', 'wp_user_register', [], true, 'test' );
check( 'mit Einwilligung, aber ohne Freigabe: Probelauf', ! empty( $r['dry_run'] ) && count( $GLOBALS['calls'] ) === 0 );
check( 'Protokoll ohne E-Mail-Klartext', strpos( json_encode( get_option( 'bwb_outbox' ) ), 'anna@' ) === false && strpos( json_encode( get_option( 'bwb_outbox' ) ), 'a***@example.test' ) !== false );

update_option( 'bwb_live', 1 );
$GLOBALS['calls'] = [];
api( static function ( $m, $u ) { return ok( [], 204 ); } );
$r = $A::fire( 'anna@example.test', 'wp_user_register', [ 'role' => 'subscriber', 'verschachtelt' => [ 1 ], 'ba d' => 'x' ], true, 'test' );
$urls = array_map( static function ( $c ) { return $c[0] . ' ' . substr( $c[1], 24 ); }, $GLOBALS['calls'] );
check( 'live: Kontakt anlegen, dann Ereignis senden', $r['result'] === 'sent' && $urls === [ 'POST /contacts', 'POST /events' ] );
$ev = $GLOBALS['calls'][1][2];
check( 'Ereignis: Name, Kennung, nur skalare Eigenschaften', $ev['event_name'] === 'wp_user_register' && $ev['identifiers']['email_id'] === 'anna@example.test' && $ev['event_properties'] === [ 'role' => 'subscriber', 'bad' => 'x' ] );
check( 'ungültige Adresse wird abgelehnt', $A::fire( 'keine-adresse', 'x', [], true, 't' )['result'] === 'invalid' );
update_option( 'bwb_consent_required', 0 );
check( 'Einwilligungsprüfung abschaltbar', $A::fire( 'b@example.test', 'e', [], false, 't' )['result'] === 'sent' );
define( 'BW_BREVO_LIVE_DISABLED', true );
check( 'wp-config-Sperre schlägt die Freigabe', ! BW_Brevo_Settings::live() && ! empty( $A::fire( 'b@example.test', 'e', [], true, 't' )['dry_run'] ) );


/* 7. Layout aus Brevo (Vorlage/Kampagne) */
$T = 'BW_Brevo_Templates';
check( 'Referenzen: t12 / template:12 / 12 / c55 / campaign:55', $T::normalize_ref( 't12' ) === 't12' && $T::normalize_ref( 'template:12' ) === 't12' && $T::normalize_ref( '12' ) === 't12' && $T::normalize_ref( 'C55' ) === 'c55' && $T::normalize_ref( 'campaign:55' ) === 'c55' && $T::normalize_ref( 'x; DROP' ) === '' );
$lay = '<html><h1>[[bw_title]]</h1><a href="[[bw_var:cta_url]]">Los</a>[[ bw_content ]]<p>[[bw_date]] · [[bw_var:ort]]</p><a href="{{ unsubscribe }}">ab</a></html>';
$vars = [ 'content' => '<p>Inhalt</p>', 'title' => 'T &amp; Co', 'date' => '03.11.2026', 'var:cta_url' => 'https://x.test/a?b=1&amp;c=2', 'var:ort' => 'Graz' ];
$r = $T::fill( $lay, $vars );
check( 'fill: alle Platzhalter ersetzt, Brevos {{ }} bleibt', $r['error'] === null && strpos( $r['html'], '[[' ) === false && strpos( $r['html'], '<p>Inhalt</p>' ) !== false && strpos( $r['html'], '{{ unsubscribe }}' ) !== false && strpos( $r['html'], 'Graz' ) !== false );
check( 'fill: ohne [[bw_content]] → Fehler', strpos( (string) $T::fill( '<p>nix</p>', $vars )['error'], 'bw_content' ) !== false );
$e = $T::fill( $lay, array_diff_key( $vars, [ 'var:ort' => 1 ] ) )['error'];
check( 'fill: Platzhalter ohne Wert → Fehler nennt ihn', strpos( (string) $e, '[[bw_var:ort]]' ) !== false );
check( 'fill: unbekannter Platzhalter → Fehler', strpos( (string) $T::fill( '[[bw_content]][[bw_unsinn]]', $vars )['error'], '[[bw_unsinn]]' ) !== false );
check( 'Zeilen name=wert werden gelesen', $T::parse_vars( "ort = Graz\nbutton_url=https://x.test/?a=1\nkaputt\n" ) === [ 'ort' => 'Graz', 'button_url' => 'https://x.test/?a=1' ] );
$F = [ 'title' => 'A<b>', 'subject' => 'S', 'preheader' => '', 'scheduled_at' => '2026-11-03 09:00', 'vars' => "cta_url=javascript:alert(1)\nnotiz_html=<b>fett</b><script>x</script>\nort=<i>Graz</i>" ];
$v = $T::vars( 7, $F, '<p>B</p>' );
check( 'vars: Text maskiert, Datum, javascript:-Adresse leer, HTML ohne script', $v['title'] === 'A&lt;b&gt;' && $v['date'] === '03.11.2026' && $v['var:cta_url'] === '' && $v['var:notiz_html'] === '<b>fett</b>' && $v['var:ort'] === '&lt;i&gt;Graz&lt;/i&gt;' );

update_option( 'bwb_live', 0 ); $GLOBALS['tr'] = [];
$GLOBALS['calls'] = [];
api( static function ( $m, $u ) { return strpos( $u, '/smtp/templates/12' ) ? ok( [ 'htmlContent' => '<html>[[bw_content]]<a href="{{ unsubscribe }}">x</a></html>' ] ) : ( strpos( $u, '/emailCampaigns/55' ) ? ok( [ 'htmlContent' => '' ] ) : ok( [], 204 ) ); } );
update_post_meta( 7, 'bwb_base', 't12' );
$h = BW_Brevo_Campaigns::build_html( 7 );
check( 'build_html mit Basis t12: Layout aus Brevo + Inhalt aus WordPress', is_string( $h ) && $h === '<html><p>Hallo</p><a href="{{ unsubscribe }}">x</a></html>' );
$n = count( $GLOBALS['calls'] ); BW_Brevo_Campaigns::build_html( 7 );
check( 'Layout wird kurz zwischengespeichert (1 Abruf)', count( $GLOBALS['calls'] ) === $n );
BW_Brevo_Campaigns::build_html( 7, null, true );
check( 'fresh holt neu', count( $GLOBALS['calls'] ) === $n + 1 );
update_post_meta( 7, 'bwb_base', 'c55' );
check( 'Basis ohne HTML-Inhalt → verständlicher Fehler', code( BW_Brevo_Campaigns::build_html( 7 ) ) === 'bwb_base' );
update_post_meta( 7, 'bwb_base', 't12' ); update_option( 'bwb_default_lists', '3' );
$pv = BW_Brevo_Campaigns::preview( 7 );
check( 'preview liefert HTML und Layout, ohne Übertragung', $pv['layout'] === 't12' && strpos( $pv['html'], 'Hallo' ) !== false );
check( 'in_sync mit Layout berechenbar', BW_Brevo_Campaigns::in_sync( 7 ) === false );
update_post_meta( 7, 'bwb_base', '' );

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit( $fail ? 1 : 0 );
