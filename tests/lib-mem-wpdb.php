<?php
/**
 * Gemeinsam genutzt von den Tests: wpdb im Speicher für das Wartungsprotokoll.
 */
/** wpdb im Speicher: versteht genau die Abfragen, die BW_Bridge_Log stellt. */
class Mem_Wpdb {
	public $prefix = 'wp_', $rows = [], $insert_id = 0, $next = 1;
	function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	function prepare( $sql, ...$args ) {
		$args = array_values( 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args );
		if ( preg_match_all( '/%[sd]/', $sql ) !== count( $args ) ) { throw new Exception( 'Platzhalter passen nicht: ' . $sql ); }
		return [ $sql, $args ];
	}
	function insert( $t, $row ) { $row['id'] = $this->next++; $this->rows[ $row['id'] ] = $row; $this->insert_id = $row['id']; return 1; }
	private function filter( $q ) {
		[ $sql, $args ] = is_array( $q ) ? $q : [ $q, [] ];
		$out = $this->rows; $i = 0; $limit = null;
		preg_match_all( '/(created >= %s|created <= %s|created < %s|(\w+) IN \(([%s,]+)\)|LIKE %s|LIMIT %d)/', $sql, $m, PREG_SET_ORDER );
		foreach ( $m as $x ) {
			if ( 'created >= %s' === $x[0] ) { $v = $args[ $i++ ]; $out = array_filter( $out, fn( $r ) => $r['created'] >= $v ); }
			elseif ( 'created <= %s' === $x[0] ) { $v = $args[ $i++ ]; $out = array_filter( $out, fn( $r ) => $r['created'] <= $v ); }
			elseif ( 'created < %s' === $x[0] ) { $v = $args[ $i++ ]; $out = array_filter( $out, fn( $r ) => $r['created'] < $v ); }
			elseif ( 'LIKE %s' === $x[0] ) { $i++; }
			elseif ( 'LIMIT %d' === $x[0] ) { $limit = $args[ $i++ ]; }
			else { $n = substr_count( $x[3], '%s' ); $vals = array_slice( $args, $i, $n ); $i += $n; $col = $x[2]; $out = array_filter( $out, fn( $r ) => in_array( $r[ $col ], $vals, true ) ); }
		}
		if ( false !== strpos( $sql, 'ORDER BY created DESC' ) ) { usort( $out, fn( $a, $b ) => strcmp( $b['created'], $a['created'] ) ?: $b['id'] <=> $a['id'] ); }
		return null === $limit ? $out : array_slice( array_values( $out ), 0, $limit );
	}
	function get_results( $q, $f = null ) { return array_values( $this->filter( $q ) ); }
	function get_var( $q ) {
		$sql = is_array( $q ) ? $q[0] : $q;
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return count( $this->filter( $q ) ); }
		if ( false !== strpos( $sql, 'MIN(created)' ) ) { return $this->rows ? min( array_column( $this->rows, 'created' ) ) : null; }
		return null;
	}
	function query( $q ) {
		[ $sql, $args ] = is_array( $q ) ? $q : [ $q, [] ];
		if ( false !== strpos( $sql, 'ON DUPLICATE KEY' ) ) {
			preg_match( '/\(([^)]+)\) VALUES/', $sql, $m ); $row = array_combine( array_map( 'trim', explode( ',', $m[1] ) ), $args );
			foreach ( $this->rows as $id => $r ) { if ( $r['agg_key'] === $row['agg_key'] ) { $this->rows[ $id ]['cnt']++; $this->rows[ $id ]['updated'] = $row['updated']; return 2; } }
			$this->insert( '', $row ); return 1;
		}
		if ( preg_match( '/WHERE id IN \(([\d,]+)\)/', $sql, $m ) ) { $n = 0; foreach ( explode( ',', $m[1] ) as $id ) { if ( isset( $this->rows[ (int) $id ] ) ) { unset( $this->rows[ (int) $id ] ); $n++; } } return $n; }
		if ( 0 === strpos( $sql, 'DELETE' ) ) { $gone = $this->filter( $q ); foreach ( $gone as $r ) { unset( $this->rows[ $r['id'] ] ); } return count( $gone ); }
		return 0;
	}
}
