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
	 * Body: { "elements": [...], "settings": {...}, "template_type": "wp-page" }
	 * "settings" wird gemerged (z. B. { "template": "elementor_canvas", "hide_title": "yes" }).
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
		if ( ! empty( $body['template_type'] ) ) {
			update_post_meta( $id, '_elementor_template_type', sanitize_key( $body['template_type'] ) );
		}

		$document = $el->documents->get( $id, false );
		if ( ! $document ) {
			return new WP_Error( 'bw_bridge_document', 'Elementor-Dokument konnte nicht geladen werden.', [ 'status' => 500 ] );
		}
		$document->set_is_built_with_elementor( true );

		$settings = (array) ( get_post_meta( $id, '_elementor_page_settings', true ) ?: [] );
		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$settings = array_merge( $settings, $body['settings'] );
		}

		$saved = $document->save( [
			'elements' => $body['elements'],
			'settings' => $settings,
		] );
		if ( ! $saved ) {
			return new WP_Error( 'bw_bridge_save', 'Speichern fehlgeschlagen.', [ 'status' => 500 ] );
		}
		return self::get_elementor( $r );
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
