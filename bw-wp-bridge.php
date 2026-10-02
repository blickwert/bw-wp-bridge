<?php
/**
 * Plugin Name: BW WP Bridge
 * Description: Erweitert die WordPress-REST-API um Elementor-Layouts, Elementor-Kit (Global Colors/Fonts), Vorlagen-Import sowie CPT- und Taxonomie-Definitionen – für die Arbeit mit Claude Code auf Dev-/Staging-Servern.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: blickwert
 * License: GPL-2.0-or-later
 *
 * Zugriff nur für Administratoren (Capability manage_options), Authentifizierung
 * über WordPress-Anwendungspasswörter. Abschalten: define( 'BW_WP_BRIDGE_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BW_WP_BRIDGE_DISABLED' ) && BW_WP_BRIDGE_DISABLED ) {
	return;
}

/*
 * Dev-Server mit .htpasswd-Schutz: Dort belegt die Server-Anmeldung den Authorization-Header.
 * Der Client schickt das WordPress-Anwendungspasswort dann zusätzlich als X-WP-Authorization;
 * WordPress prüft es wie gewohnt als Anwendungspasswort.
 */
if ( ! empty( $_SERVER['HTTP_X_WP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['HTTP_X_WP_AUTHORIZATION'], 'Basic ' ) ) {
	$bw_bridge_creds = base64_decode( substr( $_SERVER['HTTP_X_WP_AUTHORIZATION'], 6 ), true );
	if ( $bw_bridge_creds && false !== strpos( $bw_bridge_creds, ':' ) ) {
		list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $bw_bridge_creds, 2 );
	}
	unset( $bw_bridge_creds );
}

final class BW_WP_Bridge {

	const NS             = 'bw-bridge/v1';
	const OPT_POST_TYPES = 'bw_bridge_post_types';
	const OPT_TAXONOMIES = 'bw_bridge_taxonomies';
	const OPT_FLUSH      = 'bw_bridge_flush_rewrite';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_content_types' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite' ], 999 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/* ------------------------------------------------------------------ */
	/* CPTs & Taxonomien aus Optionen registrieren                         */
	/* ------------------------------------------------------------------ */

	public static function register_content_types() {
		foreach ( (array) get_option( self::OPT_POST_TYPES, [] ) as $slug => $args ) {
			if ( ! post_type_exists( $slug ) ) {
				register_post_type( $slug, self::post_type_args( $args ) );
			}
		}
		foreach ( (array) get_option( self::OPT_TAXONOMIES, [] ) as $slug => $def ) {
			if ( ! taxonomy_exists( $slug ) ) {
				$object_types = isset( $def['object_types'] ) ? (array) $def['object_types'] : [];
				register_taxonomy( $slug, $object_types, self::taxonomy_args( $def['args'] ?? [] ) );
			}
		}
	}

	private static function post_type_args( array $args ) {
		return array_merge( [
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => true,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
		], $args );
	}

	private static function taxonomy_args( array $args ) {
		return array_merge( [
			'public'            => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
		], $args );
	}

	public static function maybe_flush_rewrite() {
		if ( get_option( self::OPT_FLUSH ) ) {
			delete_option( self::OPT_FLUSH );
			flush_rewrite_rules( false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* REST-Routen                                                         */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		$admin = [ __CLASS__, 'can_manage' ];

		register_rest_route( self::NS, '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'status' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::NS, '/elementor/(?P<id>\d+)', [
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

		register_rest_route( self::NS, '/elementor/kit', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_kit' ], 'permission_callback' => $admin ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_kit' ], 'permission_callback' => $admin ],
		] );

		register_rest_route( self::NS, '/elementor/templates', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_template' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::NS, '/elementor/clear-cache', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'clear_cache' ],
			'permission_callback' => $admin,
		] );

		foreach ( [ 'post-types' => self::OPT_POST_TYPES, 'taxonomies' => self::OPT_TAXONOMIES ] as $route => $option ) {
			register_rest_route( self::NS, '/' . $route, [
				'methods'             => 'GET',
				'callback'            => function () use ( $option ) {
					return rest_ensure_response( (object) get_option( $option, [] ) );
				},
				'permission_callback' => $admin,
			] );
			register_rest_route( self::NS, '/' . $route . '/(?P<slug>[a-z0-9_-]{1,32})', [
				[
					'methods'             => 'POST',
					'callback'            => function ( WP_REST_Request $r ) use ( $option ) {
						return self::save_definition( $option, $r );
					},
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => function ( WP_REST_Request $r ) use ( $option ) {
						return self::delete_definition( $option, $r );
					},
					'permission_callback' => $admin,
				],
			] );
		}
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function can_edit_post( WP_REST_Request $r ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $r['id'] );
	}

	private static function elementor() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'bw_bridge_no_elementor', 'Elementor ist nicht aktiv.', [ 'status' => 409 ] );
		}
		return \Elementor\Plugin::$instance;
	}

	public static function status() {
		$el = self::elementor();
		return rest_ensure_response( [
			'bridge'        => '1.0.0',
			'wordpress'     => get_bloginfo( 'version' ),
			'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'active_kit'    => is_wp_error( $el ) ? null : (int) $el->kits_manager->get_active_id(),
			'theme'         => get_stylesheet(),
			'post_types'    => array_keys( (array) get_option( self::OPT_POST_TYPES, [] ) ),
			'taxonomies'    => array_keys( (array) get_option( self::OPT_TAXONOMIES, [] ) ),
		] );
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

	/* ---- CPT-/Taxonomie-Definitionen ---- */

	/**
	 * post-types/{slug}: Body = register_post_type()-Argumente.
	 * taxonomies/{slug}: Body = { "object_types": ["post", "event"], "args": {...} }.
	 */
	private static function save_definition( $option, WP_REST_Request $r ) {
		$slug = sanitize_key( $r['slug'] );
		$body = $r->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'bw_bridge_invalid', 'JSON-Objekt erwartet.', [ 'status' => 400 ] );
		}
		if ( self::OPT_POST_TYPES === $option && strlen( $slug ) > 20 ) {
			return new WP_Error( 'bw_bridge_invalid', 'Post-Type-Slug darf höchstens 20 Zeichen haben.', [ 'status' => 400 ] );
		}
		$defs          = (array) get_option( $option, [] );
		$defs[ $slug ] = $body;
		update_option( $option, $defs, true );
		update_option( self::OPT_FLUSH, 1, true );
		return rest_ensure_response( [ 'slug' => $slug, 'definition' => $body ] );
	}

	private static function delete_definition( $option, WP_REST_Request $r ) {
		$slug = sanitize_key( $r['slug'] );
		$defs = (array) get_option( $option, [] );
		$had  = isset( $defs[ $slug ] );
		unset( $defs[ $slug ] );
		update_option( $option, $defs, true );
		update_option( self::OPT_FLUSH, 1, true );
		return rest_ensure_response( [ 'slug' => $slug, 'deleted' => $had ] );
	}
}

BW_WP_Bridge::init();
