<?php
/**
 * Plugin Name: BW WP Bridge
 * Description: Erweitert die WordPress-REST-API um Elementor-Layouts (mit Textzugriff und Sicherungen), Suche, Post-Meta, Stapelaufrufe, Elementor-Kit (Global Colors/Fonts), Vorlagen-Import, CPT- und Taxonomie-Definitionen sowie (optional, im Backend freizuschalten) das Lesen und Schreiben von Theme-Dateien – für die Arbeit mit Claude Code auf Dev-/Staging-Servern.
 * Version: 1.2.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: blickwert
 * License: GPL-2.0-or-later
 *
 * Zugriff nur für Administratoren (Capability manage_options), Authentifizierung
 * über WordPress-Anwendungspasswörter. Abschalten: define( 'BW_WP_BRIDGE_DISABLED', true );
 * Theme-Dateizugriff ist standardmäßig aus (Einstellungen › BW WP Bridge); hart abschalten:
 * define( 'BW_WP_BRIDGE_FILES_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BW_WP_BRIDGE_DISABLED' ) && BW_WP_BRIDGE_DISABLED ) {
	return;
}

define( 'BW_WP_BRIDGE_VERSION', '1.2.0' ); // zusammen mit "Version:" oben ändern
define( 'BW_WP_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );

// Muss früh laufen, noch vor allen Hooks (Anmeldung per Anwendungspasswort).
require_once BW_WP_BRIDGE_DIR . 'includes/auth-bootstrap.php';

spl_autoload_register(
	static function ( $class ) {
		static $map = [
			'BW_WP_Bridge'            => 'includes/class-bw-bridge.php',
			'BW_Bridge_Auth'          => 'includes/class-bw-bridge-auth.php',
			'BW_Bridge_Elementor'     => 'includes/class-bw-bridge-elementor.php',
			'BW_Bridge_Elementor_Texts' => 'includes/class-bw-bridge-elementor-texts.php',
			'BW_Bridge_Search'        => 'includes/class-bw-bridge-search.php',
			'BW_Bridge_Meta'          => 'includes/class-bw-bridge-meta.php',
			'BW_Bridge_Batch'         => 'includes/class-bw-bridge-batch.php',
			'BW_Bridge_Content_Types' => 'includes/class-bw-bridge-content-types.php',
			'BW_Bridge_Theme_Files'   => 'includes/class-bw-bridge-theme-files.php',
			'BW_Bridge_Settings'      => 'admin/class-bw-bridge-settings.php',
		];
		if ( isset( $map[ $class ] ) ) {
			require_once BW_WP_BRIDGE_DIR . $map[ $class ];
		}
	}
);

BW_WP_Bridge::init();
