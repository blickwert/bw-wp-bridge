<?php
/**
 * Post-Meta eines Beitrags (Seite, Produkt, Kurs …) lesen und schreiben – unabhängig davon, ob der Typ
 * die Felder in seiner REST-Schnittstelle freigibt (z. B. Plugin-Felder wie _bw_feature_1_title).
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Meta {

	/** Dürfen weder gelesen (außer ausdrücklich per keys) noch geschrieben werden: eigene Wege (Elementor-Routen). */
	const NO_WRITE = [ '_elementor_data', BW_Bridge_Elementor::BACKUP_META, '_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time' ];

	public static function register_routes() {
		$can = static function ( WP_REST_Request $r ) {
			return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $r['id'] );
		};
		register_rest_route( BW_WP_Bridge::NS, '/meta/(?P<id>\d+)', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_meta' ], 'permission_callback' => $can ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'set_meta' ], 'permission_callback' => $can ],
		] );
	}

	/**
	 * GET meta/{id}[?prefix=_bw_][&keys=a,b] – Felder des Beitrags. Ohne keys werden _elementor_data und die
	 * Layout-Sicherungen ausgelassen (sehr groß). Einzelwerte kommen als Wert, Mehrfachwerte als Liste.
	 */
	public static function get_meta( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		$prefix = (string) $r->get_param( 'prefix' );
		$keys   = array_filter( array_map( 'trim', explode( ',', (string) $r->get_param( 'keys' ) ) ) );
		$out    = [];
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( $keys ? ! in_array( $key, $keys, true ) : ( in_array( $key, [ '_elementor_data', BW_Bridge_Elementor::BACKUP_META ], true ) || ( '' !== $prefix && 0 !== strpos( $key, $prefix ) ) ) ) {
				continue;
			}
			$vals        = array_map( 'maybe_unserialize', $values );
			$out[ $key ] = 1 === count( $vals ) ? $vals[0] : $vals;
		}
		ksort( $out );
		return rest_ensure_response( [ 'id' => $id, 'meta' => (object) $out ] );
	}

	/**
	 * POST meta/{id} – Body: { "set": { "key": "wert" }, "delete": ["key"], "dry_run": false }.
	 * Antwort: je Schlüssel alter und neuer Wert.
	 */
	public static function set_meta( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$body = (array) $r->get_json_params();
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		$set    = isset( $body['set'] ) && is_array( $body['set'] ) ? $body['set'] : [];
		$delete = isset( $body['delete'] ) && is_array( $body['delete'] ) ? $body['delete'] : [];
		if ( ! $set && ! $delete ) {
			return new WP_Error( 'bw_bridge_invalid', '"set" (Objekt) oder "delete" (Liste) fehlt.', [ 'status' => 400 ] );
		}
		foreach ( array_merge( array_keys( $set ), $delete ) as $k ) {
			if ( ! is_string( $k ) || '' === $k || in_array( $k, self::NO_WRITE, true ) ) {
				return new WP_Error( 'bw_bridge_forbidden_key', 'Schlüssel nicht erlaubt: ' . ( is_string( $k ) ? $k : '?' ), [ 'status' => 400 ] );
			}
		}
		$dry     = ! empty( $body['dry_run'] );
		$changes = [];
		foreach ( $set as $k => $v ) {
			$old = get_post_meta( $id, $k, true );
			if ( ! $dry ) {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
			$changes[ $k ] = [ 'old' => $old, 'new' => $v ];
		}
		foreach ( $delete as $k ) {
			$changes[ $k ] = [ 'old' => get_post_meta( $id, $k, true ), 'new' => null, 'deleted' => true ];
			if ( ! $dry ) {
				delete_post_meta( $id, $k );
			}
		}
		return rest_ensure_response( [ 'id' => $id, 'dry_run' => $dry, 'changes' => (object) $changes ] );
	}
}
