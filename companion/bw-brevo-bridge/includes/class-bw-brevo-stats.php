<?php
/**
 * Auswertung: Kampagnen-Statistik aus Brevo (globalStats), Kennzahlen zur Zustellbarkeit, Ereignis-Tabelle (Webhook).
 * E-Mail-Adressen werden nicht gespeichert, nur ein Hash und die Domain (Auswertung nach Postfachanbieter).
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Stats {

	const KEYS = [ 'sent', 'delivered', 'uniqueViews', 'viewed', 'appleMppOpens', 'uniqueClicks', 'clickers', 'softBounces', 'hardBounces', 'complaints', 'unsubscriptions' ];

	public static function init() {
		add_action( 'bwb_daily', [ __CLASS__, 'daily' ] );
		BW_Brevo_Webhook::init();
	}

	/* ------------------------------------------------------------------ */
	/* Reine Berechnung                                                    */
	/* ------------------------------------------------------------------ */

	public static function normalize( $g ) {
		$n = [];
		foreach ( self::KEYS as $k ) {
			$n[ $k ] = isset( $g[ $k ] ) ? max( 0, (int) $g[ $k ] ) : 0;
		}
		return $n;
	}

	private static function pct( $a, $b ) {
		return $b > 0 ? round( 100 * $a / $b, 2 ) : null;
	}

	/** Raten in Prozent. Öffnungen sind durch Apple Mail Privacy Protection verzerrt – Klicks sind die verlässlichere Größe. */
	public static function rates( $n ) {
		$bounces = $n['softBounces'] + $n['hardBounces'];
		return [
			'delivery_rate'     => self::pct( $n['delivered'], $n['sent'] ),
			'bounce_rate'       => self::pct( $bounces, $n['sent'] ),
			'hard_bounce_rate'  => self::pct( $n['hardBounces'], $n['sent'] ),
			'open_rate'         => self::pct( $n['uniqueViews'], $n['delivered'] ),
			'click_rate'        => self::pct( $n['uniqueClicks'], $n['delivered'] ),
			'complaint_rate'    => self::pct( $n['complaints'], $n['delivered'] ),
			'unsubscribe_rate'  => self::pct( $n['unsubscriptions'], $n['delivered'] ),
		];
	}

	/** Hinweise anhand üblicher Schwellen (Gmail/Yahoo: Beschwerden dauerhaft unter 0,3 %, Ziel unter 0,1 %). */
	public static function warnings( $n, $r = null ) {
		$r = $r ?: self::rates( $n );
		$w = [];
		if ( $n['sent'] < 100 ) {
			$w[] = 'Weniger als 100 Empfänger: Raten sind kaum aussagekräftig.';
		}
		if ( null !== $r['complaint_rate'] && $r['complaint_rate'] >= 0.3 ) {
			$w[] = 'Beschwerderate ' . $r['complaint_rate'] . ' % – über dem Grenzwert von Gmail/Yahoo (0,3 %). Liste und Einwilligungen prüfen.';
		} elseif ( null !== $r['complaint_rate'] && $r['complaint_rate'] >= 0.1 ) {
			$w[] = 'Beschwerderate ' . $r['complaint_rate'] . ' % – über dem Zielwert von 0,1 %.';
		}
		if ( null !== $r['hard_bounce_rate'] && $r['hard_bounce_rate'] >= 2 ) {
			$w[] = 'Hard-Bounce-Rate ' . $r['hard_bounce_rate'] . ' % – Liste bereinigen (ungültige Adressen).';
		}
		if ( null !== $r['delivery_rate'] && $r['delivery_rate'] < 95 && $n['sent'] >= 100 ) {
			$w[] = 'Zustellrate ' . $r['delivery_rate'] . ' % – unter 95 %. SPF/DKIM/DMARC und Absenderreputation prüfen.';
		}
		return $w;
	}

	/** Antwort von GET /emailCampaigns/{id}?statistics=globalStats speichern. */
	public static function store( $post_id, $resp ) {
		$g     = $resp['statistics']['globalStats'] ?? null;
		$n     = self::normalize( is_array( $g ) ? $g : [] );
		$rates = self::rates( $n );
		$stats = [ 'counts' => $n, 'rates' => $rates, 'warnings' => self::warnings( $n, $rates ), 'status' => $resp['status'] ?? null, 'synced_at' => gmdate( 'c' ), 'has_stats' => is_array( $g ) ];
		update_post_meta( $post_id, 'bwb_stats', $stats );
		$hist   = get_post_meta( $post_id, 'bwb_stats_history', true );
		$hist   = is_array( $hist ) ? $hist : [];
		$hist[] = [ 't' => $stats['synced_at'], 'counts' => $n ];
		update_post_meta( $post_id, 'bwb_stats_history', array_slice( $hist, -30 ) );
		return $stats;
	}

	/* ------------------------------------------------------------------ */
	/* Ereignis-Tabelle                                                    */
	/* ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bwb_events';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			event varchar(32) NOT NULL,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email_hash char(64) NOT NULL,
			domain varchar(100) NOT NULL DEFAULT '',
			reason varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY ts_event (ts,event),
			KEY campaign (campaign_id,event)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'bwb_db_version', 1, false );
	}

	public static function email_hash( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) );
	}

	public static function record( $event, $campaign_id, $email, $reason = '', $ts = null ) {
		global $wpdb;
		$email  = strtolower( trim( (string) $email ) );
		$domain = false !== strpos( $email, '@' ) ? substr( strrchr( $email, '@' ), 1 ) : '';
		return (bool) $wpdb->insert( self::table(), [
			'ts'          => gmdate( 'Y-m-d H:i:s', $ts ?: time() ),
			'event'       => substr( $event, 0, 32 ),
			'campaign_id' => (int) $campaign_id,
			'email_hash'  => self::email_hash( $email ),
			'domain'      => substr( preg_replace( '/[^a-z0-9.\-]/', '', $domain ), 0, 100 ),
			'reason'      => mb_substr( sanitize_text_field( (string) $reason ), 0, 190 ),
		] );
	}

	public static function event_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Zusammenfassung der letzten $days Tage; $campaign_id = Brevo-ID (0 = alle). */
	public static function summary( $days = 30, $campaign_id = 0 ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, min( 365, (int) $days ) ) * DAY_IN_SECONDS );
		$where = $wpdb->prepare( 'ts >= %s', $since ) . ( $campaign_id ? $wpdb->prepare( ' AND campaign_id = %d', $campaign_id ) : '' );
		$by_event = [];
		foreach ( (array) $wpdb->get_results( "SELECT event, COUNT(*) c FROM $t WHERE $where GROUP BY event", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$by_event[ $row['event'] ] = (int) $row['c'];
		}
		$domains = [];
		foreach ( (array) $wpdb->get_results( "SELECT domain, event, COUNT(*) c FROM $t WHERE $where AND domain <> '' AND event IN ('hard_bounce','soft_bounce','spam','blocked','deferred') GROUP BY domain, event ORDER BY c DESC LIMIT 60", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$domains[ $row['domain'] ][ $row['event'] ] = (int) $row['c'];
		}
		$reasons = [];
		foreach ( (array) $wpdb->get_results( "SELECT reason, COUNT(*) c FROM $t WHERE $where AND reason <> '' GROUP BY reason ORDER BY c DESC LIMIT 10", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$reasons[] = [ 'reason' => $row['reason'], 'count' => (int) $row['c'] ];
		}
		return [ 'days' => (int) $days, 'events' => $by_event, 'problem_domains' => $domains, 'top_reasons' => $reasons ];
	}

	/* ------------------------------------------------------------------ */
	/* Abgleich                                                            */
	/* ------------------------------------------------------------------ */

	public static function synced_posts() {
		return get_posts( [ 'post_type' => BW_Brevo_Campaigns::CPT, 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => 'bwb_campaign_id', 'meta_compare' => 'EXISTS' ] );
	}

	public static function daily() {
		$done = 0;
		foreach ( self::synced_posts() as $id ) {
			if ( $done >= 30 ) {
				break;
			}
			$st = (string) get_post_meta( $id, 'bwb_status', true );
			if ( in_array( $st, [ 'sent', 'in_process', 'queued' ], true ) ) {
				$sy = get_post_meta( $id, 'bwb_stats', true );
				// Versandte Kampagnen nur 60 Tage lang nachladen.
				if ( 'sent' === $st && is_array( $sy ) && ! empty( $sy['synced_at'] ) && strtotime( $sy['synced_at'] ) < time() - 60 * DAY_IN_SECONDS ) {
					continue;
				}
				BW_Brevo_Campaigns::sync( $id );
				$done++;
			}
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE ts < %s', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function overview() {
		$tot = array_fill_keys( self::KEYS, 0 );
		$per = [];
		foreach ( self::synced_posts() as $id ) {
			$s = get_post_meta( $id, 'bwb_stats', true );
			if ( ! is_array( $s ) || empty( $s['counts'] ) ) {
				continue;
			}
			foreach ( self::KEYS as $k ) {
				$tot[ $k ] += $s['counts'][ $k ];
			}
			$per[] = [ 'id' => $id, 'title' => get_the_title( $id ), 'status' => $s['status'], 'counts' => $s['counts'], 'rates' => $s['rates'], 'warnings' => $s['warnings'], 'synced_at' => $s['synced_at'] ];
		}
		$rates = self::rates( $tot );
		return [ 'total' => [ 'counts' => $tot, 'rates' => $rates, 'warnings' => self::warnings( $tot, $rates ) ], 'campaigns' => $per ];
	}

	public static function rest_stats( $request ) {
		$post = (int) $request->get_param( 'campaign' );
		$cid  = $post ? (int) get_post_meta( $post, 'bwb_campaign_id', true ) : 0;
		return rest_ensure_response( [
			'note'     => 'Öffnungen sind durch Apple Mail Privacy Protection zu hoch; Klicks und Zustellrate sind verlässlicher.',
			'synced'   => self::overview(),
			'webhook'  => self::summary( (int) ( $request->get_param( 'days' ) ?: 30 ), $cid ),
		] );
	}

	public static function rest_sync( $request ) {
		$out = [];
		foreach ( self::synced_posts() as $id ) {
			$r = BW_Brevo_Campaigns::sync( $id );
			$out[ $id ] = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'status' => $r['status'], 'sent' => $r['counts']['sent'] ];
		}
		return rest_ensure_response( $out );
	}
}
