<?php
/**
 * SEO-Analyse der veröffentlichten Seiten (ähnlich einem SEO-Check wie Seobility): ruft die Seiten im Frontend ab und
 * prüft je Seite Meta-Angaben, Seitenqualität, Seitenstruktur, Links und Server, dazu website-weite Punkte (robots.txt,
 * Sitemap, Weiterleitung auf HTTPS, 404-Seite, Favicon, doppelte Titel/Beschreibungen, defekte interne Links).
 * Ergebnis: Score je Bereich und gesamt (0–100 %), Aufgabenliste (gleiche Befunde über alle Seiten zusammengefasst)
 * und Befunde je Seite.
 *
 * Läuft in Schritten mit Zeitbudget (wie der Datei-Scan) per Cron weiter: wöchentlich automatisch, sofort über
 * POST seo, wp_bridge.py seo --start oder „Jetzt prüfen“ im Wartungsbericht. Höchstens bw_bridge_seo_max_pages
 * Seiten (Standard 50; Startseite zuerst, dann Seiten, dann neueste Beiträge), höchstens 150 interne Links je Lauf.
 * Nur lesend. Hart abschalten: define( 'BW_WP_BRIDGE_SEO_DISABLED', true );
 *
 * Die Prüfung selbst (analyze(), site_checks(), aggregate()) ist reine Logik ohne WordPress und in tests/test-seo.php getestet.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Seo {

	const OPT_STATE  = 'bw_bridge_seo_state';
	const OPT_RESULT = 'bw_bridge_seo_result';
	const CRON_STEP  = 'bw_bridge_seo_step';
	const LOCK       = 'bw_bridge_seo_lock';
	const MAX_LINKS  = 150;

	const CATEGORIES = [ 'meta' => 'Meta-Angaben', 'quality' => 'Seitenqualität', 'structure' => 'Seitenstruktur', 'links' => 'Links', 'server' => 'Server' ];

	/** Punkte je Status für den Score: Hinweise zählen nicht als Abzug. */
	const POINTS = [ 'ok' => 1.0, 'info' => 1.0, 'warn' => 0.5, 'fail' => 0.0 ];

	public static function disabled() {
		return defined( 'BW_WP_BRIDGE_SEO_DISABLED' ) && BW_WP_BRIDGE_SEO_DISABLED;
	}

	public static function init() {
		if ( self::disabled() ) {
			return;
		}
		add_action( self::CRON_STEP, [ __CLASS__, 'step' ] );
	}

	public static function register_routes() {
		if ( self::disabled() ) {
			return;
		}
		register_rest_route( BW_WP_Bridge::NS, '/seo', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'status_route' ], 'permission_callback' => [ 'BW_WP_Bridge', 'can_manage' ] ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'start_route' ], 'permission_callback' => [ 'BW_WP_Bridge', 'can_manage' ] ],
		] );
	}

	public static function status_route() {
		return rest_ensure_response( self::status() );
	}

	public static function start_route() {
		return rest_ensure_response( self::start( true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Ablauf                                                              */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$s = get_option( self::OPT_STATE, [] );
		$d = [ 'queue' => [], 'pages' => [], 'links' => [], 'site' => [], 'started' => null, 'manual' => false ];
		return is_array( $s ) ? $s + $d : $d;
	}

	private static function save_state( array $s ) {
		update_option( self::OPT_STATE, $s, false );
	}

	/** Vom täglichen Cron des Protokolls: einmal pro Woche neu prüfen. */
	public static function maybe_start() {
		if ( self::disabled() ) {
			return;
		}
		$res = get_option( self::OPT_RESULT, [] );
		$s   = self::state();
		if ( $s['queue'] ) {
			self::step();
			return;
		}
		if ( empty( $res['finished'] ) || strtotime( $res['finished'] . ' UTC' ) < time() - 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) {
			self::start();
		}
	}

	/** Neuen Lauf beginnen (läuft schon einer seit weniger als 2 Stunden, wird er fortgesetzt). */
	public static function start( $manual = false ) {
		$s = self::state();
		if ( ! $s['queue'] || ! $s['started'] || strtotime( $s['started'] . ' UTC' ) < time() - 2 * HOUR_IN_SECONDS ) {
			$s = [
				'queue'   => array_merge( [ 'site' ], array_map( static fn( $p ) => 'page:' . $p, self::targets() ), [ 'links', 'finish' ] ),
				'pages'   => [],
				'links'   => [],
				'site'    => [],
				'started' => gmdate( 'Y-m-d H:i:s' ),
				'manual'  => (bool) $manual,
			];
			self::save_state( $s );
		}
		self::step();
		return self::status();
	}

	/** IDs der zu prüfenden Inhalte: Startseite (0 = Beitragsübersicht), Seiten, dann neueste sonstige Inhalte. */
	public static function targets() {
		$max   = max( 1, (int) apply_filters( 'bw_bridge_seo_max_pages', 50 ) );
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$ids   = [ $front ];
		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		$pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => $max, 'orderby' => 'menu_order title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => false ] );
		$rest  = array_values( array_diff( $types, [ 'page' ] ) );
		$other = ! $rest ? [] : get_posts( [ 'post_type' => $rest, 'post_status' => 'publish', 'numberposts' => $max, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'suppress_filters' => false ] );
		foreach ( array_merge( $pages, $other ) as $id ) {
			if ( ! in_array( (int) $id, $ids, true ) ) {
				$ids[] = (int) $id;
			}
		}
		return array_slice( $ids, 0, $max );
	}

	/** Arbeitet die Warteschlange ab, bis das Zeitbudget verbraucht ist; plant sonst den nächsten Schritt ein. */
	public static function step() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		$budget = (float) apply_filters( 'bw_bridge_seo_budget', 20 );
		$t0     = microtime( true );
		$s      = self::state();
		while ( $s['queue'] && microtime( true ) - $t0 < $budget ) {
			$item = $s['queue'][0];
			$done = true;
			if ( 'site' === $item ) {
				$s['site'] = self::fetch_site();
			} elseif ( 0 === strpos( $item, 'page:' ) ) {
				$r = self::fetch_page( (int) substr( $item, 5 ) );
				if ( $r ) {
					$s['pages'][] = $r;
				}
			} elseif ( 'links' === $item ) {
				$done = self::check_links( $s, $t0, $budget );
			} elseif ( 'finish' === $item ) {
				self::finish( $s );
			}
			if ( $done ) {
				array_shift( $s['queue'] );
			}
			self::save_state( $s );
		}
		if ( $s['queue'] && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_STEP ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_STEP );
		}
		delete_transient( self::LOCK );
	}

	private static function request_args() {
		return (array) apply_filters( 'bw_bridge_seo_request_args', [ 'timeout' => 20, 'redirection' => 0, 'user-agent' => 'BW-WP-Bridge-SEO/' . BW_WP_Bridge::VERSION, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
	}

	/** Abruf ohne automatische Weiterleitung (Weiterleitungen werden selbst verfolgt und gezählt). */
	public static function fetch( $url, $method = 'GET', $max_redirects = 3 ) {
		$hops = [];
		for ( $i = 0; $i <= $max_redirects; $i++ ) {
			$t0  = microtime( true );
			$res = 'HEAD' === $method ? wp_remote_head( $url, self::request_args() ) : wp_remote_get( $url, self::request_args() );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( is_wp_error( $res ) ) {
				return [ 'url' => $url, 'code' => 0, 'error' => $res->get_error_message(), 'ms' => $ms, 'redirects' => $hops, 'body' => '', 'headers' => [] ];
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$loc  = self::header( $res, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $loc ) {
				$hops[] = [ 'url' => $url, 'code' => $code ];
				$url    = self::absolute( $loc, $url );
				if ( 0 === $max_redirects ) {
					// Nur die erste Weiterleitung feststellen (nicht folgen): Code und Ziel zurückgeben.
					return [ 'url' => $url, 'code' => $code, 'ms' => $ms, 'redirects' => $hops, 'body' => '', 'headers' => [] ];
				}
				continue;
			}
			return [
				'url'       => $url,
				'code'      => $code,
				'ms'        => $ms,
				'redirects' => $hops,
				'body'      => (string) wp_remote_retrieve_body( $res ),
				'headers'   => [
					'content-type'  => self::header( $res, 'content-type' ),
					'x-robots-tag'  => self::header( $res, 'x-robots-tag' ),
					'cache-control' => self::header( $res, 'cache-control' ),
				],
			];
		}
		return [ 'url' => $url, 'code' => 0, 'error' => 'Zu viele Weiterleitungen', 'ms' => 0, 'redirects' => $hops, 'body' => '', 'headers' => [] ];
	}

	/** Antwort-Header als Text (mehrfach vorhandene Header werden zusammengefasst). */
	private static function header( $res, $name ) {
		$v = wp_remote_retrieve_header( $res, $name );
		return is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
	}

	private static function fetch_page( $id ) {
		$url   = $id ? get_permalink( $id ) : home_url( '/' );
		$title = $id ? get_the_title( $id ) : 'Startseite';
		if ( ! $url ) {
			return null;
		}
		$r = self::fetch( $url );
		$a = self::analyze( $r['body'], $url, [ 'code' => $r['code'], 'ms' => $r['ms'], 'redirects' => $r['redirects'], 'error' => $r['error'] ?? null, 'headers' => $r['headers'] ], home_url( '/' ) );
		return [ 'id' => $id, 'url' => $url, 'name' => html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ), 'type' => $id ? get_post_type( $id ) : 'home' ] + $a;
	}

	private static function fetch_site() {
		$home = home_url( '/' );
		$in   = [
			'robots'    => self::fetch( home_url( '/robots.txt' ) ),
			'notfound'  => self::fetch( home_url( '/bw-bridge-seo-' . substr( md5( (string) time() ), 0, 8 ) . '-nicht-vorhanden/' ), 'GET', 0 ),
			'site_icon' => (bool) get_option( 'site_icon' ),
			'home'      => $home,
		];
		$sm = '';
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', $in['robots']['body'], $m ) ) {
			$sm = $m[1][0];
		}
		foreach ( array_unique( array_filter( [ $sm, home_url( '/sitemap_index.xml' ), home_url( '/wp-sitemap.xml' ) ] ) ) as $u ) {
			$r = self::fetch( $u );
			if ( 200 === $r['code'] ) {
				$in['sitemap'] = [ 'url' => $u, 'code' => 200, 'body' => substr( $r['body'], 0, 2000 ) ];
				break;
			}
			$in['sitemap'] = [ 'url' => $u, 'code' => $r['code'], 'body' => '' ];
		}
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );
		if ( 0 === strpos( $home, 'https://' ) ) {
			$in['http'] = self::fetch( 'http://' . $host . '/', 'GET', 0 );
		}
		// Gegenstück mit/ohne www nur bei Hauptdomains (example.at) oder www-Adressen, nicht bei Subdomains (dev.example.at).
		$www = 0 === strpos( $host, 'www.' );
		if ( '' !== $host && ( $www || 1 === substr_count( $host, '.' ) ) ) {
			$alt            = $www ? substr( $host, 4 ) : 'www.' . $host;
			$in['alt_host'] = self::fetch( ( 0 === strpos( $home, 'https://' ) ? 'https://' : 'http://' ) . $alt . '/', 'GET', 0 );
		}
		$in['robots']['body'] = substr( $in['robots']['body'], 0, 20000 );
		return self::site_checks( $in );
	}

	/** Prüft interne Links aller Seiten (die nicht selbst geprüft wurden) per HEAD; zählt defekte. */
	private static function check_links( array &$s, $t0, $budget ) {
		if ( ! $s['links'] ) {
			$known = [];
			foreach ( $s['pages'] as $p ) {
				$known[ self::norm_url( $p['url'] ) ] = $p['code'];
			}
			$todo = [];
			foreach ( $s['pages'] as $p ) {
				foreach ( $p['internal_urls'] ?? [] as $u ) {
					$k = self::norm_url( $u );
					if ( isset( $known[ $k ] ) ) {
						if ( $known[ $k ] >= 400 || 0 === $known[ $k ] ) {
							$todo[ $k ] = [ 'url' => $u, 'code' => $known[ $k ], 'done' => true, 'from' => array_values( array_unique( array_merge( $todo[ $k ]['from'] ?? [], [ $p['id'] ] ) ) ) ];
						}
						continue;
					}
					$todo[ $k ] = [ 'url' => $u, 'code' => null, 'done' => false, 'from' => array_values( array_unique( array_merge( $todo[ $k ]['from'] ?? [], [ $p['id'] ] ) ) ) ];
				}
			}
			$s['links'] = array_slice( $todo, 0, self::MAX_LINKS, true );
		}
		foreach ( $s['links'] as $k => $l ) {
			if ( ! empty( $l['done'] ) ) {
				continue;
			}
			if ( microtime( true ) - $t0 >= $budget ) {
				return false;
			}
			$r = self::fetch( $l['url'], 'HEAD' );
			if ( 405 === $r['code'] || 501 === $r['code'] ) {
				$r = self::fetch( $l['url'] );
			}
			$s['links'][ $k ]['code'] = $r['code'];
			$s['links'][ $k ]['done'] = true;
		}
		return true;
	}

	private static function finish( array &$s ) {
		$broken = [];
		foreach ( $s['links'] as $l ) {
			if ( 0 === (int) $l['code'] || (int) $l['code'] >= 400 ) {
				$broken[] = [ 'url' => $l['url'], 'code' => (int) $l['code'], 'from' => $l['from'] ];
			}
		}
		$res = self::aggregate( $s['pages'], $s['site'], $broken );
		$res['started']  = $s['started'];
		$res['finished'] = gmdate( 'Y-m-d H:i:s' );
		$res['links_checked'] = count( $s['links'] );
		$prev = get_option( self::OPT_RESULT, [] );
		update_option( self::OPT_RESULT, $res, false );
		$s['pages'] = [];
		$s['links'] = [];
		if ( class_exists( 'BW_Bridge_Log' ) && BW_Bridge_Log::ready() ) {
			$c = $res['counts'];
			BW_Bridge_Log::add( [
				'area' => 'seo', 'category' => 'seo', 'action' => 'analysis', 'level' => 'info',
				'via' => ! empty( $s['manual'] ) ? BW_Bridge_Log::via() : 'cron',
				'message' => sprintf( 'SEO-Analyse: %d %% (%d Seiten, %d dringend, %d Empfehlungen)%s', $res['score'], count( $res['pages'] ), $c['fail'], $c['warn'], isset( $prev['score'] ) ? sprintf( ', vorher %d %%', $prev['score'] ) : '' ),
				'details' => [ 'score' => $res['score'], 'categories' => array_map( static fn( $x ) => $x['score'], $res['categories'] ), 'counts' => $c ],
			] );
		}
	}

	/** Stand für REST, Bericht und Backend. */
	public static function status() {
		$s   = self::state();
		$res = get_option( self::OPT_RESULT, [] );
		return ( is_array( $res ) && $res ? $res : [ 'score' => null, 'finished' => null ] ) + [
			'running' => (bool) $s['queue'],
			'pending' => count( $s['queue'] ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Prüfung einer Seite (reine Logik)                                   */
	/* ------------------------------------------------------------------ */

	private static function c( $id, $cat, $status, $title, $detail = '' ) {
		return [ 'id' => $id, 'cat' => $cat, 'status' => $status, 'title' => $title, 'detail' => $detail ];
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function clean( $s ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Prüft ein HTML-Dokument. $resp: code, ms, redirects, error, headers. Rückgabe: code, ms, bytes, metrics, checks,
	 * internal_urls (für die Linkprüfung), score.
	 */
	public static function analyze( $html, $url, array $resp, $home ) {
		$code   = (int) ( $resp['code'] ?? 0 );
		$checks = [];
		$m      = [ 'title' => '', 'description' => '', 'h1' => [], 'words' => 0, 'images' => 0, 'images_no_alt' => 0, 'links_internal' => 0, 'links_external' => 0, 'canonical' => '' ];
		$out    = [ 'code' => $code, 'ms' => (int) ( $resp['ms'] ?? 0 ), 'bytes' => strlen( (string) $html ), 'internal_urls' => [] ];

		// Server
		if ( 200 !== $code ) {
			$checks[] = self::c( 'http_status', 'server', 'fail', $code ? 'Seite antwortet mit HTTP ' . $code : 'Seite nicht abrufbar', $resp['error'] ?? ( 401 === $code ? 'Serverschutz (.htpasswd)? Dann kann die Analyse die Seite nicht lesen.' : '' ) );
			$out['metrics'] = $m;
			$out['checks']  = $checks;
			$out['score']   = 0;
			return $out;
		}
		$checks[] = self::c( 'http_status', 'server', 'ok', 'HTTP 200' );
		$redir    = $resp['redirects'] ?? [];
		$checks[] = $redir ? self::c( 'redirects', 'server', 'warn', count( $redir ) . ' Weiterleitung(en) bis zur Seite', implode( ' → ', array_column( $redir, 'code' ) ) . ' – Links direkt auf die Zieladresse setzen.' ) : self::c( 'redirects', 'server', 'ok', 'Keine Weiterleitung' );
		$ms       = (int) ( $resp['ms'] ?? 0 );
		$checks[] = self::c( 'response_time', 'server', $ms > 3000 ? 'fail' : ( $ms > 1000 ? 'warn' : 'ok' ), 'Antwortzeit ' . number_format( $ms / 1000, 2, ',', '' ) . ' s', $ms > 1000 ? 'Seiten-Cache, Bildgrößen und Plugins prüfen (Ziel: unter 1 s).' : '' );
		$kb       = (int) round( strlen( (string) $html ) / 1024 );
		$checks[] = self::c( 'page_size', 'server', $kb > 1024 ? 'fail' : ( $kb > 400 ? 'warn' : 'ok' ), 'HTML-Größe ' . $kb . ' kB', $kb > 400 ? 'Sehr großes HTML (eingebettetes CSS/JS, viele Elemente) verlangsamt den Aufbau.' : '' );

		if ( ! class_exists( 'DOMDocument' ) ) {
			$checks[] = self::c( 'dom', 'server', 'info', 'PHP-Erweiterung dom fehlt – Inhalt nicht ausgewertet' );
			$out['metrics'] = $m;
			$out['checks']  = $checks;
			$out['score']   = self::score( $checks );
			return $out;
		}
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new DOMXPath( $doc );

		$is_https = 0 === strpos( $url, 'https://' );
		$host     = strtolower( (string) parse_url( $home, PHP_URL_HOST ) );
		$metas    = [];
		foreach ( $xp->query( '//meta' ) as $el ) {
			$k = strtolower( $el->getAttribute( 'name' ) ?: ( $el->getAttribute( 'property' ) ?: $el->getAttribute( 'http-equiv' ) ) );
			if ( '' !== $k && ! isset( $metas[ $k ] ) ) {
				$metas[ $k ] = self::clean( $el->getAttribute( 'content' ) );
			}
		}

		// Meta-Angaben
		$titles     = $xp->query( '//head/title|//title' );
		$m['title'] = $titles->length ? self::clean( $titles->item( 0 )->textContent ) : '';
		$tl         = self::len( $m['title'] );
		if ( '' === $m['title'] ) {
			$checks[] = self::c( 'title', 'meta', 'fail', 'Kein Seitentitel (<title>)' );
		} else {
			$checks[] = self::c( 'title', 'meta', $tl > 65 || $tl < 20 ? 'warn' : 'ok', sprintf( 'Seitentitel: %d Zeichen', $tl ), $tl > 65 ? 'Wird in Suchergebnissen abgeschnitten (Ziel: 30–60 Zeichen).' : ( $tl < 20 ? 'Sehr kurz – Hauptbegriff und Marke aufnehmen (Ziel: 30–60 Zeichen).' : '' ) );
		}
		$m['description'] = $metas['description'] ?? '';
		$dl               = self::len( $m['description'] );
		if ( '' === $m['description'] ) {
			$checks[] = self::c( 'description', 'meta', 'fail', 'Keine Meta-Beschreibung', 'Suchmaschinen bauen dann selbst einen Ausschnitt. Eigenen Text mit 120–160 Zeichen schreiben.' );
		} else {
			$checks[] = self::c( 'description', 'meta', $dl > 165 || $dl < 70 ? 'warn' : 'ok', sprintf( 'Meta-Beschreibung: %d Zeichen', $dl ), $dl > 165 ? 'Wird abgeschnitten (Ziel: 120–160 Zeichen).' : ( $dl < 70 ? 'Kurz – Platz für Nutzen und Handlungsaufforderung (Ziel: 120–160 Zeichen).' : '' ) );
		}
		$robots  = strtolower( ( $metas['robots'] ?? '' ) . ' ' . ( $resp['headers']['x-robots-tag'] ?? '' ) );
		$noindex = false !== strpos( $robots, 'noindex' );
		$checks[] = self::c( 'robots', 'meta', $noindex ? 'warn' : 'ok', $noindex ? 'Seite auf noindex' : 'Seite darf indexiert werden', $noindex ? 'Erscheint nicht in Suchergebnissen – prüfen, ob gewollt.' : '' );
		$can     = $xp->query( '//link[@rel="canonical"]' );
		$m['canonical'] = $can->length ? self::absolute( $can->item( 0 )->getAttribute( 'href' ), $url ) : '';
		if ( '' === $m['canonical'] ) {
			$checks[] = self::c( 'canonical', 'meta', 'warn', 'Keine Canonical-Angabe', 'Ohne Canonical können Varianten der Adresse als doppelter Inhalt gelten.' );
		} else {
			$same     = self::norm_url( $m['canonical'] ) === self::norm_url( $url );
			$checks[] = self::c( 'canonical', 'meta', $same ? 'ok' : 'info', $same ? 'Canonical verweist auf die Seite selbst' : 'Canonical verweist auf eine andere Adresse', $same ? '' : $m['canonical'] );
		}
		$lang     = $xp->query( '//html/@lang' );
		$checks[] = self::c( 'lang', 'meta', $lang->length && '' !== trim( $lang->item( 0 )->nodeValue ) ? 'ok' : 'warn', $lang->length && '' !== trim( $lang->item( 0 )->nodeValue ) ? 'Sprache angegeben (' . $lang->item( 0 )->nodeValue . ')' : 'Keine Sprachangabe (<html lang>)' );
		$checks[] = self::c( 'viewport', 'meta', isset( $metas['viewport'] ) ? 'ok' : 'fail', isset( $metas['viewport'] ) ? 'Für Mobilgeräte eingerichtet (viewport)' : 'Kein viewport – Seite ist nicht für Mobilgeräte eingerichtet' );
		$charset  = $xp->query( '//meta[@charset]' )->length || false !== stripos( $resp['headers']['content-type'] ?? '', 'charset' ) || false !== stripos( $metas['content-type'] ?? '', 'charset' );
		$checks[] = self::c( 'charset', 'meta', $charset ? 'ok' : 'warn', $charset ? 'Zeichensatz angegeben' : 'Kein Zeichensatz angegeben' );
		$og       = array_filter( [ 'og:title', 'og:description', 'og:image' ], static fn( $k ) => empty( $metas[ $k ] ) );
		$checks[] = self::c( 'open_graph', 'meta', $og ? 'info' : 'ok', $og ? 'Social-Vorschau unvollständig' : 'Social-Vorschau (Open Graph) vollständig', $og ? 'Fehlt: ' . implode( ', ', $og ) : '' );

		// Seitenstruktur
		$h = [];
		foreach ( $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $el ) {
			$h[] = [ (int) substr( strtolower( $el->nodeName ), 1 ), self::clean( $el->textContent ) ];
		}
		$m['h1'] = array_values( array_map( static fn( $x ) => $x[1], array_filter( $h, static fn( $x ) => 1 === $x[0] ) ) );
		$n1      = count( $m['h1'] );
		$checks[] = self::c( 'h1', 'structure', 0 === $n1 ? 'fail' : ( $n1 > 1 ? 'warn' : 'ok' ), 0 === $n1 ? 'Keine H1-Überschrift' : ( $n1 > 1 ? $n1 . ' H1-Überschriften' : 'Eine H1-Überschrift' ), 0 === $n1 ? 'Jede Seite braucht genau eine H1 mit dem Hauptthema.' : ( $n1 > 1 ? 'Nur eine H1 je Seite verwenden: ' . implode( ' · ', array_slice( $m['h1'], 0, 3 ) ) : $m['h1'][0] ) );
		$empty    = count( array_filter( $h, static fn( $x ) => '' === $x[1] ) );
		$jump     = 0;
		$last     = 0;
		foreach ( $h as $x ) {
			if ( $last && $x[0] > $last + 1 ) {
				$jump++;
			}
			$last = $x[0];
		}
		$checks[] = self::c( 'headings', 'structure', $empty ? 'warn' : ( $jump ? 'info' : ( count( $h ) < 2 ? 'info' : 'ok' ) ), sprintf( '%d Überschriften', count( $h ) ), trim( ( $empty ? $empty . ' leer. ' : '' ) . ( $jump ? $jump . '× übersprungene Ebene (z. B. H2 → H4). ' : '' ) . ( count( $h ) < 2 ? 'Längere Texte mit Zwischenüberschriften gliedern.' : '' ) ) );
		$path     = (string) parse_url( $url, PHP_URL_PATH );
		$checks[] = self::c( 'url', 'structure', self::len( $url ) > 115 ? 'warn' : ( preg_match( '/[A-Z_]|%[0-9A-F]{2}/', $path ) ? 'info' : 'ok' ), self::len( $url ) > 115 ? 'Lange Adresse (' . self::len( $url ) . ' Zeichen)' : 'Adresse', preg_match( '/[A-Z_]|%[0-9A-F]{2}/', $path ) ? 'Großbuchstaben, Unterstriche oder Sonderzeichen in der Adresse.' : $path );

		// Seitenqualität
		$body = $xp->query( '//body' )->item( 0 );
		$text = '';
		if ( $body ) {
			$clone = $body->cloneNode( true );
			foreach ( [ 'script', 'style', 'noscript', 'template', 'svg' ] as $tag ) {
				$rm = [];
				foreach ( $clone->getElementsByTagName( $tag ) as $el ) {
					$rm[] = $el;
				}
				foreach ( $rm as $el ) {
					$el->parentNode->removeChild( $el );
				}
			}
			$text = self::clean( $clone->textContent );
		}
		$m['words'] = '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
		$checks[]   = self::c( 'word_count', 'quality', $m['words'] < 150 ? 'fail' : ( $m['words'] < 300 ? 'warn' : 'ok' ), sprintf( '%d Wörter', $m['words'] ), $m['words'] < 300 ? 'Wenig Text: Suchmaschinen können das Thema schlecht einordnen (Ziel: ab 300 Wörtern auf Inhaltsseiten).' : '' );
		$imgs   = $xp->query( '//body//img[not(ancestor::noscript)]' );
		$no_alt = [];
		foreach ( $imgs as $el ) {
			// alt="" ist gültig (dekoratives Bild); nur ein fehlendes Attribut zählt.
			if ( ! $el->hasAttribute( 'alt' ) ) {
				$no_alt[] = basename( (string) parse_url( $el->getAttribute( 'src' ) ?: $el->getAttribute( 'data-src' ), PHP_URL_PATH ) );
			}
		}
		$m['images']        = $imgs->length;
		$m['images_no_alt'] = count( $no_alt );
		$checks[] = self::c( 'image_alt', 'quality', $no_alt ? ( count( $no_alt ) > $imgs->length / 2 ? 'fail' : 'warn' ) : 'ok', $no_alt ? sprintf( '%d von %d Bildern ohne alt-Attribut', count( $no_alt ), $imgs->length ) : ( $imgs->length ? 'Alle Bilder mit alt-Attribut' : 'Keine Bilder' ), $no_alt ? implode( ', ', array_slice( array_filter( $no_alt ), 0, 5 ) ) : '' );
		$ld       = $xp->query( '//script[@type="application/ld+json"]' )->length;
		$checks[] = self::c( 'structured_data', 'quality', $ld ? 'ok' : 'info', $ld ? 'Strukturierte Daten (JSON-LD) vorhanden' : 'Keine strukturierten Daten', $ld ? '' : 'Schema.org-Angaben (Organisation, Artikel, Produkt …) helfen bei Rich Results.' );
		$mixed = 0;
		if ( $is_https ) {
			foreach ( $xp->query( '//img/@src|//script/@src|//link[@rel="stylesheet"]/@href|//iframe/@src|//source/@src|//video/@src|//audio/@src' ) as $at ) {
				if ( 0 === stripos( trim( $at->nodeValue ), 'http://' ) ) {
					$mixed++;
				}
			}
		}
		$checks[] = self::c( 'mixed_content', 'server', $mixed ? 'fail' : 'ok', $mixed ? $mixed . ' Ressource(n) über http:// auf einer https-Seite' : 'Keine unsicheren Ressourcen (http://)', $mixed ? 'Browser blockieren solche Inhalte bzw. zeigen „nicht sicher“.' : '' );

		// Links
		$int  = [];
		$ext  = 0;
		$bad  = 0;
		$nofo = 0;
		foreach ( $xp->query( '//body//a[@href]' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href ) ) {
				continue;
			}
			$txt = self::clean( $a->textContent );
			if ( '' === $txt && '' === trim( $a->getAttribute( 'aria-label' ) ) && '' === trim( $a->getAttribute( 'title' ) ) ) {
				$alt = '';
				foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
					$alt .= trim( $img->getAttribute( 'alt' ) );
				}
				if ( '' === $alt ) {
					$bad++;
				}
			}
			$abs = self::absolute( $href, $url );
			$lh  = strtolower( (string) parse_url( $abs, PHP_URL_HOST ) );
			if ( $lh === $host || preg_replace( '/^www\./', '', $lh ) === preg_replace( '/^www\./', '', $host ) ) {
				$p = (string) parse_url( $abs, PHP_URL_PATH );
				if ( ! preg_match( '#/(wp-admin|wp-login\.php|wp-json|wp-content|feed)(/|$)|\.(jpe?g|png|gif|webp|svg|pdf|zip|docx?|xlsx?|mp4|mp3)$#i', $p ) ) {
					$int[ explode( '#', $abs, 2 )[0] ] = true;
				}
			} elseif ( $lh ) {
				$ext++;
				if ( false !== stripos( $a->getAttribute( 'rel' ), 'nofollow' ) ) {
					$nofo++;
				}
			}
		}
		$m['links_internal'] = count( $int );
		$m['links_external'] = $ext;
		$out['internal_urls'] = array_slice( array_keys( $int ), 0, 200 );
		$checks[] = self::c( 'internal_links', 'links', $int ? 'ok' : 'warn', sprintf( '%d interne Links', count( $int ) ), $int ? '' : 'Keine Links zu anderen Seiten der Website – Besucher und Suchmaschinen kommen hier nicht weiter.' );
		$checks[] = self::c( 'external_links', 'links', 'info', sprintf( '%d externe Links', $ext ), $nofo ? $nofo . ' davon nofollow' : '' );
		$checks[] = self::c( 'link_text', 'links', $bad ? 'warn' : 'ok', $bad ? $bad . ' Link(s) ohne erkennbaren Text' : 'Alle Links mit Linktext', $bad ? 'Linktext, aria-label oder alt-Text am verlinkten Bild ergänzen (Barrierefreiheit, Suchmaschinen).' : '' );

		$out['metrics'] = $m;
		$out['checks']  = $checks;
		$out['score']   = self::score( $checks );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Website-weite Prüfungen (reine Logik)                               */
	/* ------------------------------------------------------------------ */

	/**
	 * $in: robots (code, body), sitemap (url, code, body), notfound (code), http (code, redirects/url), alt_host (code,
	 * redirects), site_icon (bool), home.
	 */
	public static function site_checks( array $in ) {
		$out  = [];
		$rb   = $in['robots'] ?? [ 'code' => 0, 'body' => '' ];
		if ( 200 !== (int) $rb['code'] ) {
			$out[] = self::c( 'robots_txt', 'server', 'warn', 'Keine robots.txt (HTTP ' . (int) $rb['code'] . ')', 'WordPress erzeugt sonst eine virtuelle robots.txt – prüfen, ob eine Server-Regel sie blockiert.' );
		} else {
			$all = self::robots_blocks_all( (string) $rb['body'] );
			$out[] = self::c( 'robots_txt', 'server', $all ? 'fail' : 'ok', $all ? 'robots.txt sperrt die ganze Website' : 'robots.txt vorhanden', $all ? '„Disallow: /“ für alle Suchmaschinen – auf einer Live-Website entfernen.' : '' );
		}
		$sm = $in['sitemap'] ?? null;
		$ok = $sm && 200 === (int) $sm['code'] && preg_match( '/<(urlset|sitemapindex)\b/i', (string) $sm['body'] );
		$out[] = self::c( 'sitemap_xml', 'server', $ok ? 'ok' : 'warn', $ok ? 'XML-Sitemap erreichbar' : 'Keine XML-Sitemap erreichbar', $ok ? $sm['url'] : ( $sm ? $sm['url'] . ' → HTTP ' . (int) $sm['code'] : '' ) );
		if ( $ok && ! preg_match( '/^\s*sitemap:/im', (string) ( $rb['body'] ?? '' ) ) ) {
			$out[] = self::c( 'sitemap_robots', 'server', 'info', 'Sitemap nicht in der robots.txt eingetragen', 'Zeile „Sitemap: ' . $sm['url'] . '“ ergänzen.' );
		}
		if ( isset( $in['http'] ) ) {
			$h   = $in['http'];
			$loc = (string) ( $h['redirects'][0]['url'] ?? '' );
			$to  = $h['redirects'] ? (string) $h['url'] : '';
			$ok  = $h['redirects'] && 0 === strpos( $to, 'https://' ) && in_array( (int) $h['redirects'][0]['code'], [ 301, 308 ], true );
			$out[] = self::c( 'https_redirect', 'server', $ok ? 'ok' : ( $h['redirects'] && 0 === strpos( $to, 'https://' ) ? 'warn' : 'fail' ), $ok ? 'http:// leitet dauerhaft auf https:// weiter' : ( $h['redirects'] && 0 === strpos( $to, 'https://' ) ? 'http:// leitet nur vorübergehend (HTTP ' . (int) $h['redirects'][0]['code'] . ') auf https:// weiter' : 'http:// leitet nicht auf https:// weiter' ), $ok ? '' : ( $loc ? '' : 'Auf dem Server eine 301-Weiterleitung auf https:// einrichten.' ) );
		}
		if ( isset( $in['alt_host'] ) ) {
			$a = $in['alt_host'];
			if ( $a['redirects'] ) {
				$out[] = self::c( 'www_redirect', 'server', in_array( (int) $a['redirects'][0]['code'], [ 301, 308 ], true ) ? 'ok' : 'warn', 'Adresse mit/ohne www leitet weiter', 'HTTP ' . (int) $a['redirects'][0]['code'] . ' → ' . $a['url'] );
			} elseif ( 200 === (int) $a['code'] ) {
				$out[] = self::c( 'www_redirect', 'server', 'warn', 'Website unter zwei Adressen erreichbar (mit und ohne www)', 'Eine Variante per 301 auf die andere weiterleiten, sonst doppelter Inhalt.' );
			}
		}
		$nf    = (int) ( $in['notfound']['code'] ?? 0 );
		$out[] = self::c( 'not_found', 'server', 404 === $nf || 410 === $nf ? 'ok' : 'warn', 404 === $nf || 410 === $nf ? 'Nicht vorhandene Seiten antworten mit 404' : 'Nicht vorhandene Seiten antworten mit HTTP ' . $nf, 404 === $nf || 410 === $nf ? '' : 'Soft-404: Suchmaschinen halten Fehlerseiten sonst für Inhalte.' );
		$out[] = self::c( 'favicon', 'meta', ! empty( $in['site_icon'] ) ? 'ok' : 'warn', ! empty( $in['site_icon'] ) ? 'Website-Icon (Favicon) gesetzt' : 'Kein Website-Icon (Favicon)', ! empty( $in['site_icon'] ) ? '' : 'Design › Customizer › Website-Informationen.' );
		return $out;
	}

	/** Sperrt die robots.txt die ganze Website für alle Crawler (User-agent: * mit Disallow: /)? */
	public static function robots_blocks_all( $txt ) {
		$agents  = [];
		$prev_ua = false;
		$dis     = false; // Gruppe mit Disallow: /
		$allow   = false; // … und Allow: / (bei gleicher Länge gewinnt Allow)
		$blocked = false;
		$flush   = static function () use ( &$agents, &$dis, &$allow, &$blocked ) {
			if ( in_array( '*', $agents, true ) && $dis && ! $allow ) {
				$blocked = true;
			}
			$dis   = false;
			$allow = false;
		};
		foreach ( preg_split( '/\R/', (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( ! preg_match( '/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m ) ) {
				continue;
			}
			$k = strtolower( $m[1] );
			$v = trim( $m[2] );
			if ( 'user-agent' === $k ) {
				if ( ! $prev_ua ) {
					$flush();
					$agents = [];
				}
				$agents[] = strtolower( $v );
				$prev_ua  = true;
				continue;
			}
			$prev_ua = false;
			if ( 'disallow' === $k && in_array( $v, [ '/', '/*' ], true ) ) {
				$dis = true;
			} elseif ( 'allow' === $k && in_array( $v, [ '/', '/*' ], true ) ) {
				$allow = true;
			}
		}
		$flush();
		return $blocked;
	}

	/* ------------------------------------------------------------------ */
	/* Zusammenfassung (reine Logik)                                       */
	/* ------------------------------------------------------------------ */

	public static function score( array $checks ) {
		if ( ! $checks ) {
			return 0;
		}
		$p = 0;
		foreach ( $checks as $c ) {
			$p += self::POINTS[ $c['status'] ] ?? 0;
		}
		return (int) round( 100 * $p / count( $checks ) );
	}

	/**
	 * Gesamtergebnis: score (Mittel der Bereiche), categories (score, fail, warn je Bereich), counts, tasks (gleiche
	 * Befunde über alle Seiten zusammengefasst, dringend zuerst), site (website-weite Prüfungen), pages (je Seite: Score,
	 * Kennzahlen, nur Befunde ungleich ok), broken_links.
	 */
	public static function aggregate( array $pages, array $site, array $broken = [] ) {
		// Doppelte Titel und Beschreibungen über alle Seiten mit HTTP 200
		$seen = [ 'title' => [], 'description' => [] ];
		foreach ( $pages as $p ) {
			if ( 200 !== (int) $p['code'] ) {
				continue;
			}
			foreach ( [ 'title', 'description' ] as $k ) {
				$v = self::lower( (string) ( $p['metrics'][ $k ] ?? '' ) );
				if ( '' !== $v ) {
					$seen[ $k ][ $v ][] = $p['id'];
				}
			}
		}
		foreach ( $pages as &$p ) {
			if ( 200 !== (int) $p['code'] ) {
				continue;
			}
			foreach ( [ 'title' => 'Seitentitel', 'description' => 'Meta-Beschreibung' ] as $k => $label ) {
				$v = self::lower( (string) ( $p['metrics'][ $k ] ?? '' ) );
				if ( '' !== $v && count( $seen[ $k ][ $v ] ) > 1 ) {
					$p['checks'][] = self::c( 'duplicate_' . $k, 'quality', 'warn', $label . ' auf ' . count( $seen[ $k ][ $v ] ) . ' Seiten gleich', 'Doppelte Angaben machen Seiten austauschbar – je Seite einen eigenen Text schreiben.' );
				}
			}
			$own = array_values( array_filter( $broken, static fn( $b ) => in_array( $p['id'], $b['from'], true ) ) );
			if ( $own ) {
				$p['checks'][] = self::c( 'broken_links', 'links', 'fail', count( $own ) . ' defekte(r) interne(r) Link(s)', implode( ', ', array_map( static fn( $b ) => $b['url'] . ' (' . ( $b['code'] ?: 'nicht erreichbar' ) . ')', array_slice( $own, 0, 5 ) ) ) );
			} elseif ( ! empty( $p['metrics']['links_internal'] ) ) {
				$p['checks'][] = self::c( 'broken_links', 'links', 'ok', 'Keine defekten internen Links' );
			}
			$p['score'] = self::score( $p['checks'] );
		}
		unset( $p );

		$all  = $site;
		foreach ( $pages as $p ) {
			foreach ( $p['checks'] as $c ) {
				$all[] = $c;
			}
		}
		$cats = [];
		foreach ( self::CATEGORIES as $k => $label ) {
			$cc         = array_values( array_filter( $all, static fn( $c ) => $k === $c['cat'] ) );
			$cats[ $k ] = [ 'label' => $label, 'score' => $cc ? self::score( $cc ) : null, 'fail' => count( array_filter( $cc, static fn( $c ) => 'fail' === $c['status'] ) ), 'warn' => count( array_filter( $cc, static fn( $c ) => 'warn' === $c['status'] ) ) ];
		}
		$scored = array_filter( array_column( $cats, 'score' ), static fn( $v ) => null !== $v );
		$score  = $scored ? (int) round( array_sum( $scored ) / count( $scored ) ) : 0;

		// Aufgaben: gleiche Befunde (id + Status) zusammenfassen
		$tasks = [];
		foreach ( $site as $c ) {
			if ( in_array( $c['status'], [ 'fail', 'warn' ], true ) ) {
				$tasks[ 'site:' . $c['id'] ] = [ 'id' => $c['id'], 'cat' => $c['cat'], 'status' => $c['status'], 'title' => $c['title'], 'detail' => $c['detail'], 'pages' => [], 'site' => true ];
			}
		}
		foreach ( $pages as $p ) {
			foreach ( $p['checks'] as $c ) {
				if ( ! in_array( $c['status'], [ 'fail', 'warn' ], true ) ) {
					continue;
				}
				$key = $c['id'] . ':' . $c['status'];
				if ( ! isset( $tasks[ $key ] ) ) {
					$tasks[ $key ] = [ 'id' => $c['id'], 'cat' => $c['cat'], 'status' => $c['status'], 'title' => self::task_title( $c ), 'detail' => $c['detail'], 'pages' => [], 'site' => false ];
				}
				$tasks[ $key ]['pages'][] = [ 'id' => $p['id'], 'url' => $p['url'], 'name' => $p['name'] ?? '' ];
			}
		}
		$tasks = array_values( $tasks );
		usort( $tasks, static fn( $a, $b ) => [ 'fail' === $a['status'] ? 0 : 1, -count( $a['pages'] ) ] <=> [ 'fail' === $b['status'] ? 0 : 1, -count( $b['pages'] ) ] );

		$counts = [ 'fail' => 0, 'warn' => 0, 'info' => 0, 'ok' => 0 ];
		foreach ( $all as $c ) {
			$counts[ $c['status'] ]++;
		}
		$slim = [];
		foreach ( $pages as $p ) {
			unset( $p['internal_urls'] );
			$p['fail']   = count( array_filter( $p['checks'], static fn( $c ) => 'fail' === $c['status'] ) );
			$p['warn']   = count( array_filter( $p['checks'], static fn( $c ) => 'warn' === $c['status'] ) );
			$p['checks'] = array_values( array_filter( $p['checks'], static fn( $c ) => 'ok' !== $c['status'] ) );
			$slim[]      = $p;
		}
		usort( $slim, static fn( $a, $b ) => $a['score'] <=> $b['score'] );
		return [
			'score'        => $score,
			'categories'   => $cats,
			'counts'       => $counts,
			'tasks'        => $tasks,
			'site'         => $site,
			'pages'        => $slim,
			'broken_links' => $broken,
		];
	}

	/** Titel einer Aufgabe ohne seitenbezogene Zahl (z. B. „Meta-Beschreibung zu lang“ statt „… 190 Zeichen“). */
	private static function task_title( array $c ) {
		$map = [
			'title'          => [ 'fail' => 'Seitentitel fehlt', 'warn' => 'Seitentitel zu lang oder zu kurz' ],
			'description'    => [ 'fail' => 'Meta-Beschreibung fehlt', 'warn' => 'Meta-Beschreibung zu lang oder zu kurz' ],
			'response_time'  => [ 'fail' => 'Sehr langsame Antwortzeit (über 3 s)', 'warn' => 'Langsame Antwortzeit (über 1 s)' ],
			'page_size'      => [ 'fail' => 'Sehr großes HTML (über 1 MB)', 'warn' => 'Großes HTML (über 400 kB)' ],
			'word_count'     => [ 'fail' => 'Sehr wenig Text (unter 150 Wörtern)', 'warn' => 'Wenig Text (unter 300 Wörtern)' ],
			'image_alt'      => [ 'fail' => 'Viele Bilder ohne alt-Attribut', 'warn' => 'Bilder ohne alt-Attribut' ],
			'h1'             => [ 'fail' => 'H1-Überschrift fehlt', 'warn' => 'Mehrere H1-Überschriften' ],
			'redirects'      => [ 'warn' => 'Seite nur über Weiterleitung erreichbar' ],
			'internal_links' => [ 'warn' => 'Keine internen Links' ],
			'link_text'      => [ 'warn' => 'Links ohne erkennbaren Text' ],
			'mixed_content'  => [ 'fail' => 'Unsichere Ressourcen (http://) auf https-Seiten' ],
			'http_status'    => [ 'fail' => 'Seite nicht erreichbar oder Fehlerstatus' ],
			'broken_links'   => [ 'fail' => 'Defekte interne Links' ],
			'duplicate_title' => [ 'warn' => 'Gleicher Seitentitel auf mehreren Seiten' ],
			'duplicate_description' => [ 'warn' => 'Gleiche Meta-Beschreibung auf mehreren Seiten' ],
			'url'            => [ 'warn' => 'Lange Adressen' ],
			'headings'       => [ 'warn' => 'Leere Überschriften' ],
		];
		return $map[ $c['id'] ][ $c['status'] ] ?? $c['title'];
	}

	/* ------------------------------------------------------------------ */
	/* Hilfen                                                              */
	/* ------------------------------------------------------------------ */

	/** Relative Adresse gegen eine Basis auflösen. */
	public static function absolute( $href, $base ) {
		$href = trim( (string) $href );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return $href;
		}
		$b = parse_url( $base );
		$scheme = $b['scheme'] ?? 'https';
		if ( 0 === strpos( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}
		$root = $scheme . '://' . ( $b['host'] ?? '' ) . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( '' === $href ) {
			return $base;
		}
		if ( '/' === $href[0] ) {
			return $root . $href;
		}
		if ( '?' === $href[0] || '#' === $href[0] ) {
			return $root . ( $b['path'] ?? '/' ) . $href;
		}
		$dir   = preg_replace( '#/[^/]*$#', '/', $b['path'] ?? '/' );
		$parts = [];
		foreach ( explode( '/', $dir . $href ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $parts );
			} elseif ( '.' !== $seg ) {
				$parts[] = $seg;
			}
		}
		return $root . implode( '/', $parts );
	}

	/** Vergleichsform einer Adresse: ohne Schema, www, Anker, abschließenden Schrägstrich. */
	public static function norm_url( $u ) {
		$u = strtolower( explode( '#', (string) $u, 2 )[0] );
		$u = preg_replace( '#^https?://(www\.)?#', '', $u );
		return rtrim( $u, '/' );
	}
}
