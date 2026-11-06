<?php
/**
 * Webhook-Empfänger für Brevo (Zustellbarkeits-Ereignisse) und Anlegen des Webhooks über die API.
 * Schutz: geheimer Token in der Adresse (hash_equals), Größenlimit, nur bekannte Ereignisse werden benannt gespeichert.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Webhook {

	public static function init() {}

	public static function url() {
		$t = (string) get_option( 'bwb_webhook_token', '' );
		return add_query_arg( 'token', $t, rest_url( BW_Brevo::NS . '/brevo/webhook' ) );
	}

	/** Ereignisnamen von Brevo (verschiedene Schreibweisen) auf feste Namen abbilden. */
	public static function normalize_event( $name ) {
		$k = strtolower( preg_replace( '/[^a-z]/i', '', (string) $name ) );
		$map = [
			'request' => 'sent', 'sent' => 'sent', 'delivered' => 'delivered',
			'opened' => 'opened', 'uniqueopened' => 'opened', 'proxyopen' => 'opened', 'uniqueproxyopen' => 'opened',
			'click' => 'click', 'clicked' => 'click',
			'hardbounce' => 'hard_bounce', 'softbounce' => 'soft_bounce',
			'spam' => 'spam', 'complaint' => 'spam',
			'unsubscribe' => 'unsubscribe', 'unsubscribed' => 'unsubscribe',
			'blocked' => 'blocked', 'invalid' => 'invalid', 'invalidemail' => 'invalid', 'deferred' => 'deferred',
		];
		return $map[ $k ] ?? ( '' === $k ? 'other' : 'other:' . substr( $k, 0, 24 ) );
	}

	public static function receive( $request ) {
		$token = (string) get_option( 'bwb_webhook_token', '' );
		if ( '' === $token || ! hash_equals( $token, (string) $request->get_param( 'token' ) ) ) {
			return new WP_Error( 'bwb_forbidden', 'Token ungültig.', [ 'status' => 403 ] );
		}
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > 262144 ) {
			return new WP_Error( 'bwb_too_large', 'Zu groß.', [ 'status' => 413 ] );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bwb_bad_json', 'Kein JSON.', [ 'status' => 400 ] );
		}
		$items = isset( $data['event'] ) ? [ $data ] : $data;   // einzelnes Ereignis oder Stapel
		$n = 0;
		foreach ( array_slice( $items, 0, 500 ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['event'] ) ) {
				continue;
			}
			$ts = $e['ts_event'] ?? $e['ts'] ?? null;
			$ts = is_numeric( $ts ) ? (int) $ts : ( ! empty( $e['date'] ) ? (int) strtotime( (string) $e['date'] ) : time() );
			if ( BW_Brevo_Stats::record( self::normalize_event( $e['event'] ), $e['camp_id'] ?? $e['campaign_id'] ?? 0, $e['email'] ?? '', $e['reason'] ?? '', $ts ) ) {
				$n++;
			}
		}
		return rest_ensure_response( [ 'received' => $n ] );
	}

	public static function register() {
		$url = self::url();
		$ex  = BW_Brevo_API::request( 'GET', '/webhooks', null, [ 'type' => 'marketing' ] );
		if ( is_wp_error( $ex ) ) {
			return $ex;
		}
		foreach ( $ex['webhooks'] ?? [] as $w ) {
			if ( ( $w['url'] ?? '' ) === $url ) {
				return [ 'ok' => true, 'existing' => true, 'id' => $w['id'] ?? null ];
			}
		}
		$r = BW_Brevo_API::request( 'POST', '/webhooks', [
			'url' => $url, 'description' => 'BW Brevo Bridge – ' . get_bloginfo( 'name' ), 'type' => 'marketing',
			'events' => [ 'delivered', 'opened', 'click', 'hardBounce', 'softBounce', 'spam', 'unsubscribed' ],
		] );
		return is_wp_error( $r ) ? $r : [ 'ok' => true, 'existing' => false, 'id' => $r['id'] ?? null ];
	}

	public static function rest_register() {
		$r = self::register();
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}
}
