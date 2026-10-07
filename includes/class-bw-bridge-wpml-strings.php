<?php
/**
 * WPML String Translation: Texte aus dem Backend (Optionen, Plugin-Einstellungen, Mailtexte …) für WPML anmelden,
 * Strings suchen und Übersetzungen setzen.
 *
 * - Optionen: Eine Liste von Optionsnamen wird gespeichert und bei jedem Laden mit der WPML-Funktion
 *   "wpml_multilingual_options" mehrsprachig gemacht (WPML legt daraus Strings im Kontext "admin_texts_<option>" an
 *   und übernimmt Änderungen im Backend).
 * - Strings: lesen (auch nach Kontext, Text und Übersetzungsstand) und Übersetzungen über die WPML-Funktion
 *   icl_add_string_translation() setzen – die offiziellen REST-Routen von WPML sind für Anwendungspasswörter gesperrt.
 *
 * Alles arbeitet nur, wenn WPML String Translation aktiv ist.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Wpml_Strings {

	const OPT          = 'bw_bridge_wpml_options';
	const COMPLETE     = 10; // ICL_TM_COMPLETE
	const NEEDS_UPDATE = 3;  // ICL_TM_NEEDS_UPDATE

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_options' ], 20 );
	}

	public static function register_routes() {
		$admin = [ 'BW_WP_Bridge', 'can_manage' ];
		register_rest_route( BW_WP_Bridge::NS, '/wpml/strings', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_strings' ], 'permission_callback' => $admin ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'set_translations' ], 'permission_callback' => $admin ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/wpml/options', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'list_options' ], 'permission_callback' => $admin ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'add_options' ], 'permission_callback' => $admin ],
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'remove_options' ], 'permission_callback' => $admin ],
		] );
	}

	public static function available() {
		return function_exists( 'icl_add_string_translation' );
	}

	private static function unavailable() {
		return new WP_Error( 'bw_bridge_no_wpml_st', 'WPML String Translation ist nicht aktiv.', [ 'status' => 409 ] );
	}

	/* ---------------------------------------------------------------- Optionen */

	/** Gespeicherte Optionsnamen (Liste). */
	public static function options() {
		$o = get_option( self::OPT, [] );
		return is_array( $o ) ? array_values( array_filter( $o, 'is_string' ) ) : [];
	}

	/** Bei jedem Laden: gespeicherte Optionen für WPML mehrsprachig machen. */
	public static function register_options() {
		if ( ! has_action( 'wpml_multilingual_options' ) ) {
			return;
		}
		foreach ( self::options() as $name ) {
			do_action( 'wpml_multilingual_options', $name );
		}
	}

	private static function clean_names( $names ) {
		$out = [];
		foreach ( (array) $names as $n ) {
			if ( is_string( $n ) && preg_match( '/^[A-Za-z0-9_\-]{1,191}$/', $n ) ) {
				$out[] = $n;
			} else {
				return new WP_Error( 'bw_bridge_invalid', 'Ungültiger Optionsname: ' . ( is_string( $n ) ? $n : '?' ), [ 'status' => 400 ] );
			}
		}
		return $out;
	}

	private static function string_count( $context ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_strings WHERE context = %s", $context ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function options_status() {
		$out = [];
		foreach ( self::options() as $name ) {
			$ctx   = 'admin_texts_' . $name;
			$out[] = [ 'option' => $name, 'context' => $ctx, 'strings' => self::available() ? self::string_count( $ctx ) : null ];
		}
		return $out;
	}

	/** GET wpml/options – angemeldete Optionen mit der Zahl ihrer WPML-Strings (0 = WPML kennt noch keine). */
	public static function list_options() {
		return rest_ensure_response( [ 'string_translation' => self::available(), 'options' => self::options_status() ] );
	}

	/** POST wpml/options – Body: { "options": [ "option_name", … ] }. */
	public static function add_options( WP_REST_Request $r ) {
		$names = self::clean_names( ( (array) $r->get_json_params() )['options'] ?? [] );
		if ( is_wp_error( $names ) ) {
			return $names;
		}
		if ( ! $names ) {
			return new WP_Error( 'bw_bridge_invalid', '"options" (Liste von Optionsnamen) fehlt.', [ 'status' => 400 ] );
		}
		if ( ! self::available() ) {
			return self::unavailable();
		}
		update_option( self::OPT, array_values( array_unique( array_merge( self::options(), $names ) ) ), false );
		self::register_options();
		return self::list_options();
	}

	/** DELETE wpml/options – Body: { "options": [ … ] }. Bereits angelegte WPML-Strings bleiben bestehen. */
	public static function remove_options( WP_REST_Request $r ) {
		$names = self::clean_names( ( (array) $r->get_json_params() )['options'] ?? (array) $r->get_param( 'option' ) );
		if ( is_wp_error( $names ) ) {
			return $names;
		}
		update_option( self::OPT, array_values( array_diff( self::options(), $names ) ), false );
		return self::list_options();
	}

	/* ---------------------------------------------------------------- Strings lesen */

	/**
	 * SQL und Parameter für die Stringliste. Rein, damit testbar.
	 *
	 * @return array{0:string,1:array}
	 */
	public static function build_list_query( $prefix, array $a ) {
		$s    = $prefix . 'icl_strings';
		$t    = $prefix . 'icl_string_translations';
		$join = '';
		$args = [];
		if ( '' !== $a['lang'] ) {
			$join   = "LEFT JOIN $t t ON t.string_id = s.id AND t.language = %s";
			$args[] = $a['lang'];
		}
		$where = [ '1=1' ];
		if ( '' !== $a['context'] ) {
			if ( '*' === substr( $a['context'], -1 ) ) {
				$where[] = 's.context LIKE %s';
				$args[]  = rtrim( $a['context'], '*' ) . '%';
			} else {
				$where[] = 's.context = %s';
				$args[]  = $a['context'];
			}
		}
		if ( '' !== $a['q'] ) {
			$where[] = '(s.value LIKE %s OR s.name LIKE %s)';
			$args[]  = $a['like'];
			$args[]  = $a['like'];
		}
		if ( '' !== $a['lang'] && 'missing' === $a['status'] ) {
			$where[] = 't.id IS NULL';
		} elseif ( '' !== $a['lang'] && 'incomplete' === $a['status'] ) {
			$where[] = 't.id IS NOT NULL AND t.status <> ' . self::COMPLETE;
		} elseif ( '' !== $a['lang'] && 'open' === $a['status'] ) {
			$where[] = '(t.id IS NULL OR t.status <> ' . self::COMPLETE . ')';
		} elseif ( '' !== $a['lang'] && 'complete' === $a['status'] ) {
			$where[] = 't.status = ' . self::COMPLETE;
		}
		$args[] = $a['limit'] + 1;
		$args[] = $a['offset'];
		$sql    = "SELECT s.id, s.context, s.name, s.value, s.language FROM $s s $join WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.context, s.name LIMIT %d OFFSET %d';
		return [ $sql, $args ];
	}

	/**
	 * GET wpml/strings[?q=Text][&context=Kontext oder Präfix*][&lang=de][&status=missing|incomplete|open|complete][&limit=100][&offset=0]
	 * Antwort: je String id, context, name, value (Ausgangstext), language und translations { de: { value, status } }.
	 */
	public static function list_strings( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! self::available() ) {
			return self::unavailable();
		}
		$q      = trim( (string) $r->get_param( 'q' ) );
		$status = (string) $r->get_param( 'status' );
		$lang   = sanitize_key( (string) $r->get_param( 'lang' ) );
		if ( '' !== $status && '' === $lang ) {
			return new WP_Error( 'bw_bridge_invalid', '"status" braucht "lang".', [ 'status' => 400 ] );
		}
		[ $sql, $args ] = self::build_list_query( $wpdb->prefix, [
			'q'       => $q,
			'like'    => '%' . $wpdb->esc_like( $q ) . '%',
			'context' => trim( (string) $r->get_param( 'context' ) ),
			'lang'    => $lang,
			'status'  => $status,
			'limit'   => max( 1, min( 500, (int) ( $r->get_param( 'limit' ) ?: 100 ) ) ),
			'offset'  => max( 0, (int) $r->get_param( 'offset' ) ),
		] );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$limit = max( 1, min( 500, (int) ( $r->get_param( 'limit' ) ?: 100 ) ) );
		$more  = count( $rows ) > $limit;
		$rows  = array_slice( $rows, 0, $limit );
		$by_id = [];
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = [
				'id' => (int) $row['id'], 'context' => $row['context'], 'name' => $row['name'], 'value' => $row['value'],
				'language' => $row['language'], 'translations' => [],
			];
		}
		if ( $by_id ) {
			$ids = array_keys( $by_id );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$tr  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT string_id, language, status, value FROM {$wpdb->prefix}icl_string_translations WHERE string_id IN ($in)", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $tr as $x ) {
				$by_id[ (int) $x['string_id'] ]['translations'][ $x['language'] ] = [ 'value' => $x['value'], 'status' => (int) $x['status'] ];
			}
		}
		foreach ( $by_id as &$item ) {
			$item['translations'] = (object) $item['translations'];
		}
		unset( $item );
		return rest_ensure_response( [ 'count' => count( $by_id ), 'has_more' => $more, 'strings' => array_values( $by_id ) ] );
	}

	/* ---------------------------------------------------------------- Übersetzungen setzen */

	/**
	 * POST wpml/strings – Body: { "translations": [ { "id": 12, "language": "de", "value": "Text", "complete": true } ], "dry_run": false }.
	 * Statt "id" geht auch { "context": "…", "name": "…" }. "complete" (Standard true) setzt den Status "übersetzt",
	 * sonst "muss aktualisiert werden". Ergebnis je Eintrag mit altem und neuem Wert.
	 */
	public static function set_translations( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! self::available() ) {
			return self::unavailable();
		}
		$body = (array) $r->get_json_params();
		$list = isset( $body['translations'] ) && is_array( $body['translations'] ) ? $body['translations'] : [];
		if ( ! $list ) {
			return new WP_Error( 'bw_bridge_invalid', '"translations" (Liste) fehlt.', [ 'status' => 400 ] );
		}
		$dry    = ! empty( $body['dry_run'] );
		$active = array_keys( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) );
		$out    = [];
		foreach ( $list as $c ) {
			$res = [ 'status' => 'error' ];
			$c   = is_array( $c ) ? $c : [];
			$lang = isset( $c['language'] ) ? sanitize_key( (string) $c['language'] ) : '';
			$id   = isset( $c['id'] ) ? (int) $c['id'] : 0;
			if ( ! $id && ! empty( $c['context'] ) && ! empty( $c['name'] ) ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}icl_strings WHERE context = %s AND name = %s", (string) $c['context'], (string) $c['name'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$res['id'] = $id ?: null;
			$res['language'] = $lang;
			if ( ! $id || '' === $lang || ! isset( $c['value'] ) || ! is_string( $c['value'] ) ) {
				$out[] = $res + [ 'message' => 'id (oder context + name), language und value (Text) erwartet.' ];
				continue;
			}
			$str = $wpdb->get_row( $wpdb->prepare( "SELECT id, language, value FROM {$wpdb->prefix}icl_strings WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $str ) {
				$out[] = $res + [ 'message' => 'String nicht gefunden.' ];
				continue;
			}
			if ( $active && ! in_array( $lang, $active, true ) ) {
				$out[] = $res + [ 'message' => 'Sprache ist in WPML nicht aktiv.' ];
				continue;
			}
			if ( $lang === $str['language'] ) {
				$out[] = $res + [ 'message' => 'Das ist die Ausgangssprache des Strings.' ];
				continue;
			}
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}icl_string_translations WHERE string_id = %d AND language = %s", $id, $lang ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $dry ) {
				$complete = ! array_key_exists( 'complete', $c ) || ! empty( $c['complete'] );
				icl_add_string_translation( $id, $lang, $c['value'], $complete ? self::COMPLETE : self::NEEDS_UPDATE );
			}
			$out[] = [ 'status' => 'ok', 'id' => $id, 'language' => $lang, 'source' => $str['value'], 'old' => $old, 'new' => $c['value'] ];
		}
		$failed = count( array_filter( $out, static function ( $x ) {
			return 'ok' !== $x['status'];
		} ) );
		return rest_ensure_response( [ 'dry_run' => $dry, 'failed' => $failed, 'results' => $out ] );
	}
}
