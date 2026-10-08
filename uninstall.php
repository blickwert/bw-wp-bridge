<?php
/**
 * Beim Löschen des Plugins: Freigaben für den Theme-Dateizugriff und temporäre Optionen entfernen.
 * Die CPT-/Taxonomie-Definitionen bleiben bewusst erhalten (sie gehören zur Website; löschen mit cpt-delete / tax-delete),
 * ebenso die Sicherungen unter wp-content/uploads/bw-bridge-backups-…/. Die Layout-Sicherungen (Post-Meta) werden mit entfernt.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'bw_bridge_files_read', 'bw_bridge_files_write', 'bw_bridge_files_parent', 'bw_bridge_plugins_install', 'bw_bridge_flush_rewrite' ] as $bw_bridge_option ) {
	delete_option( $bw_bridge_option );
}

// Sicherungen der Elementor-Layouts (Post-Meta) entfernen.
delete_post_meta_by_key( '_bw_bridge_el_backup' );
