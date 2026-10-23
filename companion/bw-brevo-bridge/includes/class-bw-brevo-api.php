<?php
/**
 * Schlanker Client für die Brevo-API v3 (https://api.brevo.com/v3).
 * Für Tests lässt sich die Antwort über den Filter bwb_pre_request ersetzen.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_API {

	const BASE = 'https://api.brevo.com/v3';

	public static function key() {
		if ( defined( 'BW_BREVO_API_KEY' ) && BW_BREVO_API_KEY ) {
			return (string) BW_BREVO_API_KEY;
		}
		return (string) get_option( 'bwb_api_key', '' );
	}

	/** @return array|WP_Error dekodierte Antwort (leeres Array bei 204) */
	public static function request( $method, $path, $body = null, $query = [] ) {
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'bwb_no_key', 'Kein Brevo-API-Schlüssel eingetragen (wp-config.php: BW_BREVO_API_KEY oder Newsletter › Brevo).', [ 'status' => 400 ] );
		}
		$url = self::BASE . '/' . ltrim( $path, '/' ) . ( $query ? '?' . http_build_query( $query ) : '' );
		$arg = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [ 'api-key' => $key, 'accept' => 'application/json', 'content-type' => 'application/json' ],
		];
		if ( null !== $body ) {
			$arg['body'] = wp_json_encode( $body );
		}
		$res = apply_filters( 'bwb_pre_request', null, $method, $url, $arg );
		if ( null === $res ) {
			$res = wp_remote_request( $url, $arg );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'bwb_http', 'Brevo nicht erreichbar: ' . $res->get_error_message(), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = '' === $raw ? [] : json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			return new WP_Error( 'bwb_api', 'Brevo: ' . $msg, [ 'status' => $code >= 400 && $code < 500 ? 422 : 502, 'brevo_status' => $code, 'brevo_code' => $data['code'] ?? null ] );
		}
		return is_array( $data ) ? $data : [];
	}
}
