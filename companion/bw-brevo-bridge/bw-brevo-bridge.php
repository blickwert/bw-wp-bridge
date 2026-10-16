<?php
/**
 * Plugin Name: BW Brevo Bridge
 * Description: Brücke zwischen WordPress, Claude (über BW WP Bridge) und Brevo: Newsletter-Kampagnen als eigener Beitragstyp mit Tab-Feldern anlegen und als Entwurf nach Brevo übertragen, Zustellbarkeit und Kampagnen-Statistiken auswerten (Webhook + Abgleich) und Brevo-Automationen über Ereignisse aus WordPress auslösen. Versand und Automationen sind standardmäßig gesperrt (Probelauf).
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: blickwert
 * License: GPL-2.0-or-later
 *
 * Läuft eigenständig, nutzt aber – wenn BW WP Bridge aktiv ist – deren Namespace (bw-bridge/v1/brevo/…),
 * damit Anmeldung, Verbindungsschlüssel und der Client tools/wp_bridge.py unverändert gelten.
 * API-Schlüssel am besten in der wp-config.php: define( 'BW_BREVO_API_KEY', 'xkeysib-…' );
 * Hart abschalten: define( 'BW_BREVO_BRIDGE_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BW_BREVO_BRIDGE_DISABLED' ) && BW_BREVO_BRIDGE_DISABLED ) {
	return;
}

define( 'BW_BREVO_VERSION', '0.1.0' ); // zusammen mit "Version:" oben ändern
define( 'BW_BREVO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BW_BREVO_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class ) {
		static $map = [
			'BW_Brevo'             => 'includes/class-bw-brevo.php',
			'BW_Brevo_API'         => 'includes/class-bw-brevo-api.php',
			'BW_Brevo_Settings'    => 'admin/class-bw-brevo-settings.php',
			'BW_Brevo_Campaigns'   => 'includes/class-bw-brevo-campaigns.php',
			'BW_Brevo_Templates'   => 'includes/class-bw-brevo-templates.php',
			'BW_Brevo_Stats'       => 'includes/class-bw-brevo-stats.php',
			'BW_Brevo_Webhook'     => 'includes/class-bw-brevo-webhook.php',
			'BW_Brevo_Automations' => 'includes/class-bw-brevo-automations.php',
			'BW_Brevo_Admin'       => 'admin/class-bw-brevo-admin.php',
		];
		if ( isset( $map[ $class ] ) ) {
			require_once BW_BREVO_DIR . $map[ $class ];
		}
	}
);

register_activation_hook( __FILE__, [ 'BW_Brevo', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BW_Brevo', 'deactivate' ] );

BW_Brevo::init();
