<?php
/**
 * Suche über Seiten, Beiträge, Produkte, Post-Meta und Elementor-Texte, WPML-Übersetzungen eines Beitrags,
 * gerenderter Text einer Seite im Frontend.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Search {

	/** Post Types, die nie durchsucht werden. */
	const SKIP_TYPES = [ 'revision', 'nav_menu_item', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'shop_order', 'shop_order_placehold', 'shop_coupon' ];

	public static function register_routes() {
		$admin = [ 'BW_WP_Bridge', 'can_manage' ];
		register_rest_route( BW_WP_Bridge::NS, '/search', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'search' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( BW_WP_Bridge::NS, '/translations/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'translations' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( BW_WP_Bridge::NS, '/render/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'render' ],
			'permission_callback' => $admin,
		] );
	}

	/* ---------------------------------------------------------------- Suche */

	/**
	 * GET search?q=Text[&types=page,product][&lang=de][&meta=1][&limit=50][&context=60]
	 * Durchsucht Titel, Inhalt, Auszug, Post-Meta und Elementor-Texte (Beiträge aller Status außer Papierkorb).
	 */
	public static function search( WP_REST_Request $r ) {
		global $wpdb;
		$q = trim( (string) $r->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 2 ) {
			return new WP_Error( 'bw_bridge_invalid', '"q" (mindestens 2 Zeichen) fehlt.', [ 'status' => 400 ] );
		}
		$limit   = max( 1, min( 200, (int) ( $r->get_param( 'limit' ) ?: 50 ) ) );
		$context = max( 20, min( 300, (int) ( $r->get_param( 'context' ) ?: 70 ) ) );
		$meta    = '0' !== (string) $r->get_param( 'meta' );
		$lang    = (string) $r->get_param( 'lang' );
		$types   = array_filter( array_map( 'sanitize_key', explode( ',', (string) $r->get_param( 'types' ) ) ) );

		// Elementor speichert Text JSON-escaped (ü => ü, / => \/): beide Schreibweisen suchen.
		$needles = [ $q ];
		$escaped = trim( wp_json_encode( $q ), '"' );
		if ( $escaped !== $q ) {
			$needles[] = $escaped;
		}
		$like   = [];
		$params = [];
		foreach ( $needles as $n ) {
			$l = '%' . $wpdb->esc_like( $n ) . '%';
			foreach ( [ 'p.post_title', 'p.post_content', 'p.post_excerpt' ] as $col ) {
				$like[]   = "$col LIKE %s";
				$params[] = $l;
			}
			if ( $meta ) {
				$like[]   = 'm.meta_value LIKE %s';
				$params[] = $l;
			}
		}
		$skip = self::SKIP_TYPES;
		$sql  = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key NOT IN (%s, '_edit_lock', '_edit_last')"
			. " WHERE p.post_status NOT IN ('auto-draft','trash','inherit')"
			. ' AND p.post_type NOT IN (' . implode( ',', array_fill( 0, count( $skip ), '%s' ) ) . ')';
		$args = array_merge( [ BW_Bridge_Elementor::BACKUP_META ], $skip );
		if ( $types ) {
			$sql .= ' AND p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$args = array_merge( $args, $types );
		}
		$sql .= ' AND (' . implode( ' OR ', $like ) . ') ORDER BY p.ID DESC LIMIT 500';
		$ids  = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $args, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$results = [];
		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$post      = get_post( $id );
			$post_lang = self::language_of( $id, $post->post_type );
			if ( '' !== $lang && $post_lang !== $lang ) {
				continue;
			}
			$hits = self::hits( $post, $q, $context, $meta );
			if ( ! $hits ) {
				continue;
			}
			$results[] = [
				'id'     => $id,
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'slug'   => $post->post_name,
				'title'  => $post->post_title,
				'lang'   => $post_lang,
				'hits'   => $hits,
			];
			if ( count( $results ) >= $limit ) {
				break;
			}
		}
		return rest_ensure_response( [ 'q' => $q, 'count' => count( $results ), 'results' => $results ] );
	}

	private static function snippet( $text, $q, $context ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		$pos  = BW_Bridge_Elementor_Texts::ipos( $text, $q );
		if ( false === $pos ) {
			return null;
		}
		$start = max( 0, $pos - (int) ( $context / 2 ) );
		return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, $context + mb_strlen( $q ) ) . '…';
	}

	private static function hits( $post, $q, $context, $meta ) {
		$hits = [];
		foreach ( [ 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt ] as $where => $text ) {
			if ( $s = self::snippet( $text, $q, $context ) ) {
				$hits[] = [ 'where' => $where, 'snippet' => $s ];
			}
		}
		$elements = BW_Bridge_Elementor::layout( $post->ID );
		if ( is_array( $elements ) && $elements ) {
			foreach ( BW_Bridge_Elementor_Texts::extract( $elements ) as $t ) {
				if ( $s = self::snippet( $t['value'], $q, $context ) ) {
					$hits[] = [ 'where' => 'elementor', 'widget_id' => $t['widget_id'], 'widget_type' => $t['widget_type'], 'path' => $t['path'], 'snippet' => $s ];
				}
			}
		}
		if ( $meta ) {
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( in_array( $key, [ '_elementor_data', BW_Bridge_Elementor::BACKUP_META, '_edit_lock', '_edit_last' ], true ) ) {
					continue;
				}
				foreach ( $values as $v ) {
					if ( is_string( $v ) && ( $s = self::snippet( $v, $q, $context ) ) ) {
						$hits[] = [ 'where' => 'meta', 'key' => $key, 'snippet' => $s ];
					}
				}
			}
		}
		return $hits;
	}

	/* ---------------------------------------------------------------- WPML */

	private static function wpml() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_element_trid' ) || has_filter( 'wpml_element_language_details' );
	}

	private static function language_of( $id, $type ) {
		if ( ! self::wpml() ) {
			return null;
		}
		$d = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $id, 'element_type' => $type ] );
		return $d && isset( $d->language_code ) ? $d->language_code : null;
	}

	/** GET translations/{id} – { language, translations: { de: 12, en: 9 } }. */
	public static function translations( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		if ( ! self::wpml() ) {
			return rest_ensure_response( [ 'id' => $id, 'wpml' => false, 'language' => null, 'translations' => (object) [] ] );
		}
		$type = apply_filters( 'wpml_element_type', $post->post_type );
		$trid = apply_filters( 'wpml_element_trid', null, $id, $type );
		$list = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : [];
		$map  = [];
		foreach ( (array) $list as $code => $t ) {
			if ( isset( $t->element_id ) ) {
				$map[ $code ] = (int) $t->element_id;
			}
		}
		return rest_ensure_response( [ 'id' => $id, 'wpml' => true, 'language' => self::language_of( $id, $post->post_type ), 'translations' => (object) $map ] );
	}

	/* ---------------------------------------------------------------- Render */

	/**
	 * GET render/{id}[?q=Text][&limit=400] – sichtbarer Text der Seite im Frontend (ohne Skripte/Stile), Zeile für Zeile.
	 * Ruft die eigene Permalink-URL ab (nur dieselbe Domain); bei Serverschutz per .htpasswd wird dessen Anmeldung weitergereicht.
	 */
	public static function render( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$url = get_permalink( $id );
		if ( ! $url ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag oder Permalink nicht gefunden.', [ 'status' => 404 ] );
		}
		$args = [ 'timeout' => 25, 'redirection' => 3, 'headers' => [ 'Cache-Control' => 'no-cache' ] ];
		$auth = $r->get_header( 'authorization' );
		if ( $auth && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && 0 === stripos( $auth, 'basic ' ) && $r->get_header( 'x_wp_authorization' ) ) {
			$args['headers']['Authorization'] = $auth; // nur wenn die Anmeldung des Servers (.htpasswd) im Authorization-Header liegt
		}
		$res = wp_remote_get( add_query_arg( 'bw_render', time(), $url ), $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'bw_bridge_render', 'Abruf fehlgeschlagen: ' . $res->get_error_message(), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'bw_bridge_render', 'Frontend antwortet mit HTTP ' . $code . '.', [ 'status' => 502 ] );
		}
		$lines = self::visible_lines( wp_remote_retrieve_body( $res ) );
		$q     = (string) $r->get_param( 'q' );
		if ( '' !== $q ) {
			$lines = array_values( array_filter( $lines, static function ( $l ) use ( $q ) {
				return false !== BW_Bridge_Elementor_Texts::ipos( $l, $q );
			} ) );
		}
		$limit = max( 1, min( 2000, (int) ( $r->get_param( 'limit' ) ?: 400 ) ) );
		return rest_ensure_response( [ 'id' => $id, 'url' => $url, 'count' => count( $lines ), 'lines' => array_slice( $lines, 0, $limit ) ] );
	}

	/** Sichtbarer Text eines HTML-Dokuments als Zeilenliste. */
	public static function visible_lines( $html ) {
		$html = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>|</(p|div|li|h[1-6]|tr|section|article|header|footer|ul|ol|table|form|button|a)>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out  = [];
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			$line = trim( preg_replace( '/\s+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
