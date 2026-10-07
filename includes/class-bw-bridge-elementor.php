<?php
/**
 * Elementor: Layout einer Seite/eines Beitrags/einer Vorlage, Kit (Global Colors/Fonts), Vorlagen-Import, CSS-Cache.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Elementor {

	public static function register_routes() {
		$admin = [ 'BW_WP_Bridge', 'can_manage' ];

		register_rest_route( BW_WP_Bridge::NS, '/elementor/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_elementor' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'save_elementor' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
			],
		] );

		register_rest_route( BW_WP_Bridge::NS, '/elementor/(?P<id>\d+)/backups', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_backups' ],
			'permission_callback' => [ __CLASS__, 'can_edit_post' ],
		] );

		register_rest_route( BW_WP_Bridge::NS, '/elementor/(?P<id>\d+)/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'restore_backup' ],
			'permission_callback' => [ __CLASS__, 'can_edit_post' ],
		] );

		register_rest_route( BW_WP_Bridge::NS, '/elementor/kit', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_kit' ], 'permission_callback' => $admin ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_kit' ], 'permission_callback' => $admin ],
		] );

		register_rest_route( BW_WP_Bridge::NS, '/elementor/templates', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_template' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( BW_WP_Bridge::NS, '/elementor/clear-cache', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'clear_cache' ],
			'permission_callback' => $admin,
		] );
	}

	public static function can_edit_post( WP_REST_Request $r ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $r['id'] );
	}

	public static function elementor() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'bw_bridge_no_elementor', 'Elementor ist nicht aktiv.', [ 'status' => 409 ] );
		}
		return \Elementor\Plugin::$instance;
	}

	/* ---- Elementor-Layout einer Seite / eines Beitrags / einer Vorlage ---- */

	public static function get_elementor( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		$data = get_post_meta( $id, '_elementor_data', true );
		return rest_ensure_response( [
			'id'            => $id,
			'post_type'     => $post->post_type,
			'title'         => $post->post_title,
			'edit_mode'     => get_post_meta( $id, '_elementor_edit_mode', true ),
			'template_type' => get_post_meta( $id, '_elementor_template_type', true ),
			'page_template' => get_page_template_slug( $id ),
			'settings'      => (object) ( get_post_meta( $id, '_elementor_page_settings', true ) ?: [] ),
			'elements'      => $data ? json_decode( is_string( $data ) ? $data : wp_json_encode( $data ), true ) : [],
		] );
	}

	/**
	 * Body: { "elements": [...], "settings": {...}, "template_type": "wp-page", "dry_run": false }
	 * "settings" wird gemerged (z. B. { "template": "elementor_canvas", "hide_title": "yes" }).
	 * Vor dem Speichern wird der bisherige Stand gesichert (siehe backups/restore); mit "dry_run" wird nichts gespeichert,
	 * stattdessen kommt ein Textvergleich alt/neu zurück.
	 */
	public static function save_elementor( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$id   = (int) $r['id'];
		$body = $r->get_json_params();
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		if ( ! isset( $body['elements'] ) || ! is_array( $body['elements'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"elements" (Array) fehlt.', [ 'status' => 400 ] );
		}
		if ( ! empty( $body['dry_run'] ) ) {
			$current = self::layout( $id );
			return rest_ensure_response( [
				'id'       => $id,
				'dry_run'  => true,
				'saved'    => false,
				'elements' => [ 'before' => self::count_elements( $current ), 'after' => self::count_elements( $body['elements'] ) ],
				'texts'    => BW_Bridge_Elementor_Texts::diff( $current, $body['elements'] ),
			] );
		}
		if ( ! empty( $body['template_type'] ) ) {
			update_post_meta( $id, '_elementor_template_type', sanitize_key( $body['template_type'] ) );
		}
		$saved = self::persist( $id, $body['elements'], isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : [], 'Layout speichern' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::get_elementor( $r );
	}

	/** Elemente (Array) einer Seite, leer wenn noch kein Layout existiert. */
	public static function layout( $id ) {
		if ( ! get_post( (int) $id ) ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		$data = get_post_meta( (int) $id, '_elementor_data', true );
		$arr  = $data ? json_decode( is_string( $data ) ? $data : wp_json_encode( $data ), true ) : [];
		return is_array( $arr ) ? $arr : [];
	}

	private static function count_elements( array $elements ) {
		$n = 0;
		foreach ( $elements as $e ) {
			$n += 1 + ( ! empty( $e['elements'] ) && is_array( $e['elements'] ) ? self::count_elements( $e['elements'] ) : 0 );
		}
		return $n;
	}

	/**
	 * Speichert ein Layout über das Elementor-Dokument (nach einer Sicherung des bisherigen Stands).
	 * $settings werden mit den vorhandenen Seiteneinstellungen gemerged.
	 */
	public static function persist( $id, array $elements, array $settings = [], $label = '' ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$document = $el->documents->get( (int) $id, false );
		if ( ! $document ) {
			return new WP_Error( 'bw_bridge_document', 'Elementor-Dokument konnte nicht geladen werden.', [ 'status' => 500 ] );
		}
		self::backup( (int) $id, $label );
		$document->set_is_built_with_elementor( true );
		$merged = array_merge( (array) ( get_post_meta( (int) $id, '_elementor_page_settings', true ) ?: [] ), $settings );
		if ( ! $document->save( [ 'elements' => $elements, 'settings' => $merged ] ) ) {
			return new WP_Error( 'bw_bridge_save', 'Speichern fehlgeschlagen.', [ 'status' => 500 ] );
		}
		return true;
	}

	/* ---- Sicherungen der Layouts (Post-Meta, die letzten BACKUPS_KEEP Stände) ---- */

	const BACKUP_META  = '_bw_bridge_el_backup';
	const BACKUPS_KEEP = 10;

	private static function backup( $id, $label ) {
		$elements = get_post_meta( $id, '_elementor_data', true );
		if ( ! $elements ) {
			return;
		}
		// Zeitstempel eindeutig und aufsteigend halten (mehrere Speicherungen in derselben Sekunde).
		$last  = self::backups( $id );
		$time  = max( time(), $last ? (int) $last[0]['time'] + 1 : 0 );
		$entry = wp_json_encode( [
			'time'     => $time,
			'label'    => (string) $label,
			'elements' => json_decode( is_string( $elements ) ? $elements : wp_json_encode( $elements ), true ),
			'settings' => (object) ( get_post_meta( $id, '_elementor_page_settings', true ) ?: [] ),
		] );
		add_post_meta( $id, self::BACKUP_META, wp_slash( $entry ) );
		$all = get_post_meta( $id, self::BACKUP_META, false );
		while ( count( $all ) > self::BACKUPS_KEEP ) {
			delete_post_meta( $id, self::BACKUP_META, array_shift( $all ) );
		}
	}

	private static function backups( $id ) {
		$list = [];
		foreach ( get_post_meta( $id, self::BACKUP_META, false ) as $raw ) {
			$b = json_decode( $raw, true );
			if ( is_array( $b ) && isset( $b['time'] ) ) {
				$list[] = $b + [ '_bytes' => strlen( $raw ) ];
			}
		}
		usort( $list, static function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		return $list;
	}

	/** GET elementor/{id}/backups – neueste zuerst. */
	public static function list_backups( WP_REST_Request $r ) {
		$out = [];
		foreach ( self::backups( (int) $r['id'] ) as $b ) {
			$out[] = [ 'time' => $b['time'], 'date' => gmdate( 'c', $b['time'] ), 'label' => $b['label'] ?? '', 'bytes' => $b['_bytes'] ];
		}
		return rest_ensure_response( [ 'id' => (int) $r['id'], 'backups' => $out ] );
	}

	/** POST elementor/{id}/restore – Body: { "time": 1700000000 } (ohne Angabe: neueste Sicherung). */
	public static function restore_backup( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$body = (array) $r->get_json_params();
		$all  = self::backups( $id );
		$pick = null;
		foreach ( $all as $b ) {
			if ( empty( $body['time'] ) || (int) $body['time'] === (int) $b['time'] ) {
				$pick = $b;
				break;
			}
		}
		if ( ! $pick ) {
			return new WP_Error( 'bw_bridge_not_found', 'Keine passende Sicherung.', [ 'status' => 404 ] );
		}
		$saved = self::persist( $id, (array) $pick['elements'], (array) ( $pick['settings'] ?? [] ), 'vor Wiederherstellung' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return rest_ensure_response( [ 'id' => $id, 'restored' => $pick['time'] ] );
	}

	/* ---- Kit: Global Colors, Global Fonts, Theme Style, Layout ---- */

	public static function get_kit() {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$kit_id = (int) $el->kits_manager->get_active_id();
		return rest_ensure_response( [
			'id'       => $kit_id,
			'settings' => (object) ( get_post_meta( $kit_id, '_elementor_page_settings', true ) ?: [] ),
		] );
	}

	/** Body: { "settings": {...}, "replace": false } – standardmäßig wird gemerged. */
	public static function save_kit( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$body = $r->get_json_params();
		if ( ! isset( $body['settings'] ) || ! is_array( $body['settings'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"settings" (Objekt) fehlt.', [ 'status' => 400 ] );
		}
		$kit_id   = (int) $el->kits_manager->get_active_id();
		$current  = (array) ( get_post_meta( $kit_id, '_elementor_page_settings', true ) ?: [] );
		$settings = empty( $body['replace'] ) ? array_merge( $current, $body['settings'] ) : $body['settings'];
		$kit      = $el->documents->get( $kit_id, false );
		$kit->save( [ 'settings' => $settings ] );
		$el->files_manager->clear_cache();
		return self::get_kit();
	}

	/* ---- Vorlage (Elementor-Export-JSON) in die Vorlagen-Bibliothek importieren ---- */

	/** Body: das JSON einer Elementor-Vorlage ({ "title", "type", "content", "page_settings" }). */
	public static function import_template( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$body = $r->get_json_params();
		if ( empty( $body['content'] ) || empty( $body['type'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', 'Vorlagen-JSON mit "type" und "content" erwartet.', [ 'status' => 400 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'bw-bridge-template' );
		file_put_contents( $tmp, wp_json_encode( $body ) );
		// Der Name (nicht der Pfad) entscheidet bei Elementor über JSON vs. ZIP.
		$result = $el->templates_manager->get_source( 'local' )->import_template( 'template.json', $tmp );
		@unlink( $tmp );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function clear_cache() {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$el->files_manager->clear_cache();
		return rest_ensure_response( [ 'cleared' => true ] );
	}
}
