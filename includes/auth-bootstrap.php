<?php
/**
 * Läuft beim Laden des Plugins, vor allen Hooks: sorgt dafür, dass WordPress das Anwendungspasswort
 * auch hinter .htpasswd-Schutz, CGI/FastCGI und früher Benutzerermittlung erkennt.
 */

defined( 'ABSPATH' ) || exit;

// Für die Diagnose-Route auth-check: welche Anmelde-Header PHP überhaupt erreicht haben (nur ja/nein).
$GLOBALS['bw_bridge_auth_seen'] = [
	'authorization'          => ! empty( $_SERVER['HTTP_AUTHORIZATION'] ),
	'redirect_authorization' => ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
	'php_auth_user'          => ! empty( $_SERVER['PHP_AUTH_USER'] ),
	'x_wp_authorization'     => ! empty( $_SERVER['HTTP_X_WP_AUTHORIZATION'] ),
];

/*
 * Dev-Server mit .htpasswd-Schutz: Dort belegt die Server-Anmeldung den Authorization-Header.
 * Der Client schickt das WordPress-Anwendungspasswort dann zusätzlich als X-WP-Authorization;
 * WordPress prüft es wie gewohnt als Anwendungspasswort.
 */
if ( ! empty( $_SERVER['HTTP_X_WP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['HTTP_X_WP_AUTHORIZATION'], 'Basic ' ) ) {
	$bw_bridge_creds = base64_decode( substr( $_SERVER['HTTP_X_WP_AUTHORIZATION'], 6 ), true );
	if ( $bw_bridge_creds && false !== strpos( $bw_bridge_creds, ':' ) ) {
		list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $bw_bridge_creds, 2 );
	}
	unset( $bw_bridge_creds );
}

/*
 * Apache mit CGI/FastCGI: Der Authorization-Header landet oft nur in (REDIRECT_)HTTP_AUTHORIZATION,
 * nicht in PHP_AUTH_USER/PHP_AUTH_PW, die WordPress für Anwendungspasswörter auswertet.
 */
if ( empty( $_SERVER['PHP_AUTH_USER'] ) ) {
	foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $bw_bridge_key ) {
		if ( ! empty( $_SERVER[ $bw_bridge_key ] ) && 0 === stripos( $_SERVER[ $bw_bridge_key ], 'Basic ' ) ) {
			$bw_bridge_creds = base64_decode( substr( $_SERVER[ $bw_bridge_key ], 6 ), true );
			if ( $bw_bridge_creds && false !== strpos( $bw_bridge_creds, ':' ) ) {
				list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $bw_bridge_creds, 2 );
				break;
			}
		}
	}
	unset( $bw_bridge_key, $bw_bridge_creds );
}

/*
 * Ermittelt ein anderes Plugin den aktuellen Benutzer schon vor parse_request (also bevor
 * REST_REQUEST gesetzt ist), prüft WordPress das Anwendungspasswort nicht und die Anfrage
 * bleibt anonym. Darum REST-Aufrufe schon an der URL erkennen.
 */
add_filter(
	'application_password_is_api_request',
	static function ( $is_api ) {
		if ( $is_api ) {
			return $is_api;
		}
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
	}
);
