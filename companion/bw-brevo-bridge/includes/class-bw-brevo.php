<?php
/**
 * Kern: Start der Module, Aktivierung (Tabelle, Cron), REST-Routen.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo {

	const NS = 'bw-bridge/v1'; // gemeinsamer Namespace mit BW WP Bridge → Verbindungsschlüssel gilt automatisch

	public static function init() {
		BW_Brevo_Settings::init();
		BW_Brevo_Campaigns::init();
		BW_Brevo_Stats::init();
		BW_Brevo_Automations::init();
		BW_Brevo_Admin::init();
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function activate() {
		BW_Brevo_Stats::create_table();
		if ( ! wp_next_scheduled( 'bwb_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'bwb_daily' );
		}
		if ( '' === (string) get_option( 'bwb_webhook_token', '' ) ) {
			update_option( 'bwb_webhook_token', wp_generate_password( 32, false ), false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'bwb_daily' );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		$admin = [ __CLASS__, 'can_manage' ];
		$r     = static function ( $path, $methods, $cb, $perm = null, $args = [] ) use ( $admin ) {
			register_rest_route( self::NS, '/brevo' . $path, [ 'methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm ?: $admin, 'args' => $args ] );
		};
		$id = [ 'id' => [ 'validate_callback' => 'is_numeric' ] ];

		$r( '/status', 'GET', [ __CLASS__, 'status' ] );
		$r( '/lists', 'GET', [ 'BW_Brevo_Campaigns', 'rest_lists' ] );
		$r( '/bases', 'GET', [ 'BW_Brevo_Templates', 'rest_bases' ] );
		$r( '/campaigns', 'GET', [ 'BW_Brevo_Campaigns', 'rest_list' ] );
		$r( '/campaigns/(?P<id>\d+)/(?P<action>preview|push|test|send|schedule|sync)', 'POST', [ 'BW_Brevo_Campaigns', 'rest_action' ] );
		$r( '/stats', 'GET', [ 'BW_Brevo_Stats', 'rest_stats' ] );
		$r( '/stats/sync', 'POST', [ 'BW_Brevo_Stats', 'rest_sync' ] );
		$r( '/events', 'POST', [ 'BW_Brevo_Automations', 'rest_event' ] );
		$r( '/rules', 'GET', [ 'BW_Brevo_Automations', 'rest_rules' ] );
		$r( '/outbox', 'GET', [ 'BW_Brevo_Automations', 'rest_outbox' ] );
		$r( '/webhook/register', 'POST', [ 'BW_Brevo_Webhook', 'rest_register' ] );
		// Öffentlich, geschützt durch den geheimen Token in der Adresse (?token=…).
		$r( '/webhook', 'POST', [ 'BW_Brevo_Webhook', 'receive' ], '__return_true' );
	}

	public static function status() {
		$acc = BW_Brevo_API::key() ? BW_Brevo_API::request( 'GET', '/account' ) : null;
		return rest_ensure_response( [
			'brevo_bridge' => BW_BREVO_VERSION,
			'api_key'      => BW_Brevo_API::key() ? ( defined( 'BW_BREVO_API_KEY' ) ? 'wp-config' : 'option' ) : null,
			'api'          => null === $acc ? 'kein API-Schlüssel' : ( is_wp_error( $acc ) ? 'Fehler: ' . $acc->get_error_message() : 'ok' ),
			'account'      => is_array( $acc ) ? [ 'company' => $acc['companyName'] ?? null, 'email' => $acc['email'] ?? null, 'plan' => $acc['plan'][0]['type'] ?? null ] : null,
			'live'         => BW_Brevo_Settings::live(),
			'live_hint'    => BW_Brevo_Settings::live() ? null : 'Versand und Automationen sind gesperrt (Probelauf). Freigabe nur im Backend: Newsletter › Brevo.',
			'webhook_url'  => BW_Brevo_Webhook::url(),
			'webhook_events_stored' => BW_Brevo_Stats::event_count(),
			'sender'       => [ 'name' => get_option( 'bwb_sender_name' ), 'email' => get_option( 'bwb_sender_email' ) ],
			'default_lists' => BW_Brevo_Settings::default_lists(),
		] );
	}
}
