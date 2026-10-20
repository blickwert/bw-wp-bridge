<?php
/**
 * ACF-Aufbau lesen (nur lesend): Feldgruppen mit allen Feldern, Platzierungsregeln sowie die Post Types und
 * Taxonomien, die in Regeln oder Feldern vorkommen. Gedacht für Analyse und Dokumentation.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Acf {

	public static function register_routes() {
		register_rest_route( BW_WP_Bridge::NS, '/acf/schema', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'schema' ],
			'permission_callback' => [ 'BW_WP_Bridge', 'can_manage' ],
		] );
	}

	/** GET acf/schema[?group=Teil des Titels oder Schlüssel] */
	public static function schema( WP_REST_Request $r ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return new WP_Error( 'bw_bridge_no_acf', 'ACF ist nicht aktiv.', [ 'status' => 404 ] );
		}
		$filter = (string) $r->get_param( 'group' );
		$groups = [];
		foreach ( acf_get_field_groups() as $g ) {
			if ( '' !== $filter && false === stripos( $g['title'] . ' ' . $g['key'], $filter ) ) {
				continue;
			}
			$groups[] = [
				'key'      => $g['key'],
				'title'    => $g['title'],
				'active'   => ! empty( $g['active'] ),
				'location' => $g['location'],
				'fields'   => self::fields( acf_get_fields( $g['key'] ) ),
			];
		}
		return [ 'groups' => $groups, 'post_types' => self::post_types(), 'taxonomies' => self::taxonomies() ];
	}

	private static function fields( $fields ) {
		$out = [];
		foreach ( (array) $fields as $f ) {
			$item = [ 'key' => $f['key'], 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'] ];
			foreach ( [ 'required', 'post_type', 'taxonomy', 'filter', 'return_format', 'multiple', 'field_type', 'choices', 'default_value', 'conditional_logic', 'save_terms', 'load_terms', 'instructions', 'min', 'max', 'layout', 'button_label' ] as $k ) {
				if ( isset( $f[ $k ] ) && '' !== $f[ $k ] && array() !== $f[ $k ] && 0 !== $f[ $k ] && false !== $f[ $k ] ) {
					$item[ $k ] = $f[ $k ];
				}
			}
			if ( ! empty( $f['sub_fields'] ) ) {
				$item['sub_fields'] = self::fields( $f['sub_fields'] );
			}
			if ( ! empty( $f['layouts'] ) ) {
				$item['layouts'] = [];
				foreach ( $f['layouts'] as $l ) {
					$item['layouts'][] = [ 'name' => $l['name'], 'label' => $l['label'], 'sub_fields' => self::fields( isset( $l['sub_fields'] ) ? $l['sub_fields'] : [] ) ];
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	private static function post_types() {
		$out = [];
		foreach ( get_post_types( [ '_builtin' => false ], 'objects' ) as $slug => $pt ) {
			$out[ $slug ] = [ 'label' => $pt->label, 'taxonomies' => get_object_taxonomies( $slug ), 'hierarchical' => (bool) $pt->hierarchical, 'supports' => array_keys( get_all_post_type_supports( $slug ) ) ];
		}
		return $out;
	}

	private static function taxonomies() {
		$out = [];
		foreach ( get_taxonomies( [ '_builtin' => false ], 'objects' ) as $slug => $tx ) {
			$out[ $slug ] = [ 'label' => $tx->label, 'object_type' => $tx->object_type, 'hierarchical' => (bool) $tx->hierarchical ];
		}
		return $out;
	}
}
