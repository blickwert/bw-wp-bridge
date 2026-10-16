<?php
/**
 * Backend: Meldungen, Auswertungsseite „Zustellbarkeit“, Webhook-Anlegen.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_post_bwb_webhook_register', [ __CLASS__, 'handle_webhook' ] );
	}

	public static function flash( $msg ) {
		set_transient( 'bwb_flash_' . get_current_user_id(), $msg, 120 );
	}

	public static function notices() {
		$m = get_transient( 'bwb_flash_' . get_current_user_id() );
		if ( $m ) {
			delete_transient( 'bwb_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $m[0] ? 'error' : 'success', esc_html( $m[1] ) );
		}
	}

	public static function handle_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bwb_webhook' );
		$r = BW_Brevo_Webhook::register();
		self::flash( is_wp_error( $r ) ? [ 'error', $r->get_error_message() ] : [ 'success', ! empty( $r['existing'] ) ? 'Webhook war schon angelegt.' : 'Webhook bei Brevo angelegt.' ] );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . BW_Brevo_Campaigns::CPT . '&page=' . BW_Brevo_Settings::PAGE ) );
		exit;
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . BW_Brevo_Campaigns::CPT, 'Zustellbarkeit', 'Zustellbarkeit', 'edit_posts', 'bwb-stats', [ __CLASS__, 'render_stats' ] );
	}

	private static function fmt( $v ) {
		return null === $v ? '–' : esc_html( number_format_i18n( $v, 2 ) ) . ' %';
	}

	public static function stats_table( $s ) {
		$n = $s['counts'];
		$r = $s['rates'];
		echo '<table class="widefat striped" style="max-width:40em"><tbody>';
		printf( '<tr><td>Versendet</td><td>%d</td><td>Zustellrate</td><td>%s</td></tr>', (int) $n['sent'], self::fmt( $r['delivery_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- fmt() escaped
		printf( '<tr><td>Zugestellt</td><td>%d</td><td>Bounce-Rate</td><td>%s</td></tr>', (int) $n['delivered'], self::fmt( $r['bounce_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><td>Hard / Soft Bounces</td><td>%d / %d</td><td>Beschwerderate</td><td>%s</td></tr>', (int) $n['hardBounces'], (int) $n['softBounces'], self::fmt( $r['complaint_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><td>Öffnungen (verzerrt*)</td><td>%d</td><td>Öffnungsrate*</td><td>%s</td></tr>', (int) $n['uniqueViews'], self::fmt( $r['open_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><td>Klicks</td><td>%d</td><td>Klickrate</td><td>%s</td></tr>', (int) $n['uniqueClicks'], self::fmt( $r['click_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><td>Abmeldungen</td><td>%d</td><td>Abmelderate</td><td>%s</td></tr>', (int) $n['unsubscriptions'], self::fmt( $r['unsubscribe_rate'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table><p class="description">* Apple Mail Privacy Protection löst Öffnungen automatisch aus. ' . ( empty( $s['synced_at'] ) || '–' === $s['synced_at'] ? '' : 'Stand: ' . esc_html( $s['synced_at'] ) ) . '</p>';
		foreach ( $s['warnings'] as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}
	}

	public static function render_stats() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ov = BW_Brevo_Stats::overview();
		echo '<div class="wrap"><h1>Zustellbarkeit &amp; Auswertung</h1>';
		echo '<h2>Gesamt (abgeglichene Kampagnen)</h2>';
		self::stats_table( $ov['total'] + [ 'synced_at' => '–' ] );
		echo '<h2>Kampagnen</h2><table class="widefat striped"><thead><tr><th>Newsletter</th><th>Status</th><th>Versendet</th><th>Zustellrate</th><th>Bounce</th><th>Beschwerden</th><th>Klickrate</th><th>Stand</th></tr></thead><tbody>';
		foreach ( $ov['campaigns'] as $c ) {
			printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( get_edit_post_link( $c['id'] ) ), esc_html( $c['title'] ), esc_html( (string) $c['status'] ), (int) $c['counts']['sent'], self::fmt( $c['rates']['delivery_rate'] ), self::fmt( $c['rates']['bounce_rate'] ), self::fmt( $c['rates']['complaint_rate'] ), self::fmt( $c['rates']['click_rate'] ), esc_html( $c['synced_at'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- fmt() escaped
		}
		echo '</tbody></table>';
		$w = BW_Brevo_Stats::summary( 30 );
		echo '<h2>Webhook-Ereignisse (30 Tage)</h2>';
		if ( ! $w['events'] ) {
			echo '<p>Noch keine Ereignisse. Webhook unter Newsletter › Brevo anlegen.</p>';
		} else {
			echo '<p>';
			foreach ( $w['events'] as $e => $c ) {
				echo '<code>' . esc_html( $e ) . '</code>: ' . (int) $c . ' &nbsp; ';
			}
			echo '</p>';
		}
		if ( $w['problem_domains'] ) {
			echo '<h3>Probleme nach Postfachanbieter</h3><table class="widefat striped" style="max-width:40em"><thead><tr><th>Domain</th><th>Ereignisse</th></tr></thead><tbody>';
			foreach ( $w['problem_domains'] as $d => $ev ) {
				echo '<tr><td>' . esc_html( $d ) . '</td><td>' . esc_html( wp_json_encode( $ev ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
