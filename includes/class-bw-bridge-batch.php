<?php
/**
 * Mehrere REST-Aufrufe in einer Anfrage ausführen (Stapel). Jede Operation läuft intern über die normale REST-Schicht,
 * also mit allen Rechteprüfungen der jeweiligen Route.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Batch {

	const MAX_OPERATIONS = 50;

	public static function register_routes() {
		register_rest_route( BW_WP_Bridge::NS, '/batch', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'run' ],
			'permission_callback' => [ 'BW_WP_Bridge', 'can_manage' ],
		] );
	}

	/**
	 * Body: { "operations": [ { "method": "POST", "path": "wc/v3/products/158", "query": {…}, "body": {…} }, … ],
	 *         "stop_on_error": true }
	 * Antwort: { "results": [ { "status": 200, "data": … }, … ], "stopped": false }
	 */
	public static function run( WP_REST_Request $r ) {
		$body = (array) $r->get_json_params();
		$ops  = isset( $body['operations'] ) && is_array( $body['operations'] ) ? $body['operations'] : [];
		if ( ! $ops || count( $ops ) > self::MAX_OPERATIONS ) {
			return new WP_Error( 'bw_bridge_invalid', '"operations" muss 1 bis ' . self::MAX_OPERATIONS . ' Einträge enthalten.', [ 'status' => 400 ] );
		}
		$stop    = ! array_key_exists( 'stop_on_error', $body ) || ! empty( $body['stop_on_error'] );
		$results = [];
		$stopped = false;
		foreach ( $ops as $i => $op ) {
			$res = self::one( is_array( $op ) ? $op : [] );
			$results[] = $res;
			if ( $stop && $res['status'] >= 400 ) {
				$stopped = $i < count( $ops ) - 1;
				break;
			}
		}
		return rest_ensure_response( [ 'results' => $results, 'stopped' => $stopped ] );
	}

	private static function one( array $op ) {
		$method = strtoupper( (string) ( $op['method'] ?? 'GET' ) );
		$path   = '/' . ltrim( (string) ( $op['path'] ?? '' ), '/' );
		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) || '/' === $path ) {
			return [ 'status' => 400, 'data' => [ 'code' => 'bw_bridge_invalid', 'message' => 'method/path ungültig.' ] ];
		}
		$parts = wp_parse_url( $path );
		$route = rtrim( $parts['path'] ?? '', '/' );
		if ( 0 === strpos( $route, '/' . BW_WP_Bridge::NS . '/batch' ) ) {
			return [ 'status' => 400, 'data' => [ 'code' => 'bw_bridge_invalid', 'message' => 'Verschachtelte Stapel sind nicht erlaubt.' ] ];
		}
		$req = new WP_REST_Request( $method, $route );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			$req->set_query_params( $q );
		}
		if ( ! empty( $op['query'] ) && is_array( $op['query'] ) ) {
			$req->set_query_params( array_merge( $req->get_query_params(), $op['query'] ) );
		}
		if ( array_key_exists( 'body', $op ) && null !== $op['body'] ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $op['body'] ) );
		}
		$response = rest_do_request( $req );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );
		return [ 'status' => $response->get_status(), 'data' => $data ];
	}
}
