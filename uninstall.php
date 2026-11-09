<?php
/**
 * Beim Löschen des Plugins: Freigaben für den Theme-Dateizugriff und temporäre Optionen entfernen.
 * Die CPT-/Taxonomie-Definitionen bleiben bewusst erhalten (sie gehören zur Website; löschen mit cpt-delete / tax-delete),
 * ebenso die Sicherungen unter wp-content/uploads/bw-bridge-backups-…/ und die Archivdateien des Wartungsprotokolls.
 * Die Protokoll-Tabelle wird gelöscht. Die Layout-Sicherungen (Post-Meta) werden mit entfernt.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'bw_bridge_log_db', 'bw_bridge_log_db_days', 'bw_bridge_log_keep_days', 'bw_bridge_contract_start', 'bw_bridge_contract_rhythm', 'bw_bridge_audit_last', 'bw_bridge_scan_db', 'bw_bridge_scan_state', 'bw_bridge_scan_vulns', 'bw_bridge_scan_files', 'bw_bridge_scan_vulns_on', 'bw_bridge_files_read', 'bw_bridge_files_write', 'bw_bridge_files_parent', 'bw_bridge_plugins_install', 'bw_bridge_key_hash', 'bw_bridge_reader_key_hash', 'bw_bridge_site_id', 'bw_bridge_key_all', 'bw_bridge_flush_rewrite' ] as $bw_bridge_option ) {
	delete_option( $bw_bridge_option );
}

// Sicherungen der Elementor-Layouts (Post-Meta) entfernen.
delete_post_meta_by_key( '_bw_bridge_el_backup' );

// Rolle des Lesezugangs entfernen (der Benutzer „bw-office“ bleibt, er hat danach keine Rolle mehr).
remove_role( 'bw_bridge_reader' );

// Wartungsprotokoll: Tabelle und Cron entfernen. Die Archivdateien unter wp-content/uploads/bw-bridge-log-…/ bleiben (wie die Sicherungen).
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bw_bridge_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'bw_bridge_log_maintenance' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bw_bridge_files' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'bw_bridge_scan_daily' );
wp_clear_scheduled_hook( 'bw_bridge_scan_step' );
