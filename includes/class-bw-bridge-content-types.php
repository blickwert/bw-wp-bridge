<?php
/**
 * Eigene Post Types und Taxonomien ohne Code: Definitionen liegen in Optionen und werden bei init registriert.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Content_Types {

	const OPT_POST_TYPES = 'bw_bridge_post_types';
	const OPT_TAXONOMIES = 'bw_bridge_taxonomies';
	const OPT_FLUSH      = 'bw_bridge_flush_rewrite';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_content_types' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite' ], 999 );
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

	public static function register_routes() {
		$admin = [ 'BW_WP_Bridge', 'can_manage' ];

		foreach ( [ 'post-types' => self::OPT_POST_TYPES, 'taxonomies' => self::OPT_TAXONOMIES ] as $route => $option ) {
			register_rest_route( BW_WP_Bridge::NS, '/' . $route, [
				'methods'             => 'GET',
				'callback'            => function () use ( $option ) {
					return rest_ensure_response( (object) get_option( $option, [] ) );
				},
				'permission_callback' => $admin,
			] );
			register_rest_route( BW_WP_Bridge::NS, '/' . $route . '/(?P<slug>[a-z0-9_-]{1,32})', [
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
