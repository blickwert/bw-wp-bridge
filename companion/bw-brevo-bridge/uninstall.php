<?php
/**
 * Beim Löschen: Einstellungen, Ereignis-Tabelle und Cron entfernen. Newsletter-Beiträge bleiben bestehen.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
foreach ( [ 'bwb_api_key', 'bwb_sender_name', 'bwb_sender_email', 'bwb_default_lists', 'bwb_default_base', 'bwb_test_emails', 'bwb_footer', 'bwb_live', 'bwb_consent_required', 'bwb_rules', 'bwb_webhook_token', 'bwb_outbox', 'bwb_db_version' ] as $bwb_opt ) {
	delete_option( $bwb_opt );
}
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'bwb_events' ); // phpcs:ignore WordPress.DB.PreparedSQL
wp_clear_scheduled_hook( 'bwb_daily' );
