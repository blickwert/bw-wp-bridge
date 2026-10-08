<?php
/**
 * Kern: Registrierung der Module, Rechteprüfung, Route status.
 */

defined( 'ABSPATH' ) || exit;

final class BW_WP_Bridge {

	const VERSION = BW_WP_BRIDGE_VERSION;
	const NS      = 'bw-bridge/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		BW_Bridge_Content_Types::init();
		BW_Bridge_Settings::init();
	}

	public static function register_routes() {
		$admin = [ __CLASS__, 'can_manage' ];

		register_rest_route( self::NS, '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'status' ],
			'permission_callback' => $admin,
		] );

		// Öffentlich, gibt nur Ja/Nein-Werte aus: hilft, wenn die Anmeldung nicht ankommt.
		register_rest_route( self::NS, '/auth-check', [
			'methods'             => 'GET',
			'callback'            => [ 'BW_Bridge_Auth', 'auth_check' ],
			'permission_callback' => '__return_true',
		] );

		BW_Bridge_Elementor::register_routes();
		BW_Bridge_Elementor_Texts::register_routes();
		BW_Bridge_Search::register_routes();
		BW_Bridge_Meta::register_routes();
		BW_Bridge_Batch::register_routes();
		BW_Bridge_Content_Types::register_routes();
		BW_Bridge_Theme_Files::register_routes();
		BW_Bridge_Plugins::register_routes();
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function status() {
		$el = BW_Bridge_Elementor::elementor();
		return rest_ensure_response( [
			'bridge'        => self::VERSION,
			'features'      => [ 'elementor-texts', 'elementor-backups', 'elementor-dry-run', 'search', 'translations', 'translation-link', 'theme-builder-refresh', 'render', 'meta', 'batch', 'plugins' ],
			'wordpress'     => get_bloginfo( 'version' ),
			'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'wpml'          => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : null,
			'active_kit'    => is_wp_error( $el ) ? null : (int) $el->kits_manager->get_active_id(),
			'theme'         => get_stylesheet(),
			'theme_files'   => BW_Bridge_Settings::summary(),
			'plugin_install' => BW_Bridge_Settings::plugins_summary(),
			'post_types'    => array_keys( (array) get_option( BW_Bridge_Content_Types::OPT_POST_TYPES, [] ) ),
			'taxonomies'    => array_keys( (array) get_option( BW_Bridge_Content_Types::OPT_TAXONOMIES, [] ) ),
		] );
	}
}
