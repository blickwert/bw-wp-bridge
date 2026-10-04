<?php
/**
 * Diagnose der Anmeldung (Route auth-check). Die frühe Header-Aufbereitung steht in auth-bootstrap.php.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Auth {

	public static function auth_check() {
		global $wp_rest_application_password_status;
		$status = $wp_rest_application_password_status;
		return [
			'headers_seen'                => $GLOBALS['bw_bridge_auth_seen'],
			'application_passwords'       => wp_is_application_passwords_available(),
			'logged_in'                   => is_user_logged_in(),
			'is_admin'                    => current_user_can( 'manage_options' ),
			'application_password_result' => is_wp_error( $status ) ? $status->get_error_code() : ( true === $status ? 'ok' : null ),
		];
	}
}
