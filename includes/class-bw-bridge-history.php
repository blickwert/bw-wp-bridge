<?php
/**
 * Wartungsprotokoll über die REST-API: Einträge lesen, Notizen schreiben, Berichtsdaten für einen Zeitraum.
 *
 *   GET  history          Einträge (period | from/to, area, category, level, source, via, q, limit, offset)
 *   POST history/notes    Notiz: text, area (updates|security|seo|changes), minutes, ticket, tags, date (Y-m-d, optional)
 *   GET  history/report   alles für den Wartungsbericht (period | from/to, audit=0 ohne Sicherheits-/SEO-Prüfung)
 *   GET  audit            Sicherheits- und SEO-Prüfung (siehe BW_Bridge_Audit)
 *
 * Die Berichtsdaten (report()) nutzt auch die Wartungsseite im Backend – eine Quelle für beides.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_History {

	const NOTE_AREAS = [ 'updates', 'security', 'seo', 'changes' ];

	public static function register_routes() {
		$admin  = [ 'BW_WP_Bridge', 'can_read' ]; // alle Routen hier auch für den Lesezugang
		$range  = [
			'period' => [ 'type' => 'string', 'enum' => [ 'current', 'previous', 'year', 'previous-year' ] ],
			'from'   => [ 'type' => 'string' ],
			'to'     => [ 'type' => 'string' ],
		];
		register_rest_route( BW_WP_Bridge::NS, '/history', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_route' ],
			'permission_callback' => $admin,
			'args'                => $range + [
				'area' => [ 'type' => 'string' ], 'category' => [ 'type' => 'string' ], 'level' => [ 'type' => 'string' ],
				'source' => [ 'type' => 'string' ], 'via' => [ 'type' => 'string' ], 'q' => [ 'type' => 'string' ],
				'limit' => [ 'type' => 'integer', 'default' => 100 ], 'offset' => [ 'type' => 'integer', 'default' => 0 ],
			],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/history/notes', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'note_route' ],
			'permission_callback' => $admin,
			'args'                => [
				'text' => [ 'type' => 'string', 'required' => true ], 'area' => [ 'type' => 'string', 'default' => 'changes' ],
				'minutes' => [ 'type' => 'integer' ], 'ticket' => [ 'type' => 'string' ], 'tags' => [ 'type' => [ 'array', 'string' ] ],
				'date' => [ 'type' => 'string' ],
			],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/history/report', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'report_route' ],
			'permission_callback' => $admin,
			'args'                => $range + [ 'audit' => [ 'type' => 'boolean', 'default' => true ] ],
		] );
		BW_Bridge_Audit::register_routes();
	}

	private static function not_ready() {
		return new WP_Error( 'bw_bridge_log_off', BW_Bridge_Log::disabled() ? 'Das Wartungsprotokoll ist abgeschaltet (BW_WP_BRIDGE_LOG_DISABLED).' : 'Die Protokoll-Tabelle ist noch nicht angelegt (einmal eine Seite im Backend aufrufen).', [ 'status' => 409 ] );
	}

	public static function list_route( $request ) {
		if ( ! BW_Bridge_Log::ready() ) {
			return self::not_ready();
		}
		$r    = BW_Bridge_Log::resolve_range( $request['period'], $request['from'], $request['to'] );
		$args = [ 'from' => $r['from_utc'], 'to' => $r['to_utc'], 'limit' => $request['limit'] ?? 100, 'offset' => $request['offset'] ?? 0 ];
		foreach ( [ 'area', 'category', 'level', 'source', 'via', 'q' ] as $k ) {
			if ( null !== $request[ $k ] && '' !== $request[ $k ] ) {
				$args[ $k ] = $request[ $k ];
			}
		}
		if ( ! $request['period'] && ! $request['from'] && ! $request['to'] ) {
			unset( $args['from'], $args['to'] ); // ohne Zeitraum: die neuesten Einträge
		}
		$res = BW_Bridge_Log::query( $args );
		return rest_ensure_response( [ 'period' => isset( $args['from'] ) ? $r : null, 'total' => $res['total'], 'items' => $res['items'] ] );
	}

	public static function note_route( $request ) {
		if ( ! BW_Bridge_Log::ready() ) {
			return self::not_ready();
		}
		$id = self::add_note( [
			'text' => $request['text'], 'area' => $request['area'], 'minutes' => $request['minutes'],
			'ticket' => $request['ticket'], 'tags' => $request['tags'], 'date' => $request['date'],
		] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( [ 'id' => $id, 'saved' => true ] );
	}

	/** Notiz speichern (REST und Backend-Formular). Rückgabe: ID oder WP_Error. */
	public static function add_note( array $n ) {
		$text = trim( wp_strip_all_tags( (string) ( $n['text'] ?? '' ) ) );
		if ( '' === $text ) {
			return new WP_Error( 'bw_bridge_note_empty', 'Die Notiz ist leer.', [ 'status' => 400 ] );
		}
		$area = in_array( $n['area'] ?? '', self::NOTE_AREAS, true ) ? $n['area'] : 'changes';
		$tags = $n['tags'] ?? [];
		$tags = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
		$tags = array_values( array_filter( array_map( static fn( $t ) => sanitize_text_field( trim( (string) $t ) ), $tags ) ) );
		$e    = [
			'area' => $area, 'category' => 'note', 'action' => 'note', 'source' => 'note',
			'message' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 250 ) : substr( $text, 0, 250 ),
			'details' => array_filter( [
				'text'    => $text,
				'minutes' => isset( $n['minutes'] ) && '' !== $n['minutes'] ? max( 0, (int) $n['minutes'] ) : null,
				'ticket'  => isset( $n['ticket'] ) ? sanitize_text_field( (string) $n['ticket'] ) : null,
				'tags'    => $tags ?: null,
			], static fn( $v ) => null !== $v && '' !== $v ),
		];
		// Nachtragen für einen vergangenen Tag (höchstens ein Jahr zurück, nicht in der Zukunft).
		if ( ! empty( $n['date'] ) ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $n['date'] ) ) {
				return new WP_Error( 'bw_bridge_note_date', 'Datum bitte als JJJJ-MM-TT.', [ 'status' => 400 ] );
			}
			$utc = BW_Bridge_Log::local_to_utc( $n['date'] );
			$utc = gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) + 12 * HOUR_IN_SECONDS );
			if ( strtotime( $utc . ' UTC' ) > time() + DAY_IN_SECONDS || strtotime( $utc . ' UTC' ) < time() - 366 * DAY_IN_SECONDS ) {
				return new WP_Error( 'bw_bridge_note_date', 'Datum muss im letzten Jahr liegen und darf nicht in der Zukunft sein.', [ 'status' => 400 ] );
			}
			$e['created']             = $utc;
			$e['details']['backdated'] = true;
		}
		$id = BW_Bridge_Log::add( $e );
		return false === $id ? new WP_Error( 'bw_bridge_note_failed', 'Notiz konnte nicht gespeichert werden.', [ 'status' => 500 ] ) : $id;
	}

	public static function report_route( $request ) {
		if ( ! BW_Bridge_Log::ready() ) {
			return self::not_ready();
		}
		$r = BW_Bridge_Log::resolve_range( $request['period'], $request['from'], $request['to'] );
		return rest_ensure_response( self::report( $r, false !== $request['audit'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Berichtsdaten                                                       */
	/* ------------------------------------------------------------------ */

	/** Alle Daten für den Wartungsbericht im Zeitraum $r (aus BW_Bridge_Log::resolve_range()). */
	public static function report( array $r, $with_audit = true ) {
		$res  = BW_Bridge_Log::query( [ 'from' => $r['from_utc'], 'to' => $r['to_utc'], 'limit' => 5000 ] );
		$data = self::build( $res['items'], $r );
		$data['truncated'] = $res['total'] > count( $res['items'] );
		$data['site']      = BW_Bridge_Key::summary()['site'];
		$data['contract']  = [ 'start' => BW_Bridge_Log::contract_start(), 'rhythm' => BW_Bridge_Log::rhythm() ];
		$data['generated'] = gmdate( 'c' );
		$data['versions']  = [ 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'theme' => get_stylesheet(), 'bridge' => BW_WP_Bridge::VERSION ];
		if ( $with_audit ) {
			$data['audit'] = BW_Bridge_Audit::run();
		}
		$data['scan'] = BW_Bridge_Scan::disabled() ? null : BW_Bridge_Scan::status();
		return $data;
	}

	/** Berichtsdaten aus Einträgen (reine Logik, ohne Datenbank testbar). */
	public static function build( array $items, array $period ) {
		$areas    = [ 'updates', 'security', 'seo', 'changes' ];
		$kpi      = [ 'updates' => 0, 'installs' => 0, 'security_events' => 0, 'warnings' => 0, 'logins' => 0, 'failed_logins' => 0, 'seo' => 0, 'changes' => 0, 'notes' => 0, 'minutes' => 0, 'claude' => 0 ];
		$by_via   = [];
		$sections = array_fill_keys( $areas, [] );
		$notes    = [];
		$failed   = [ 'total' => 0, 'days' => [], 'names' => [] ];
		$logins   = [];
		$objects  = [];
		$snaps    = [];
		$area_cnt = array_fill_keys( $areas, 0 );
		$weekly   = ( strtotime( $period['to'] ) - strtotime( $period['from'] ) ) <= 120 * DAY_IN_SECONDS;
		$buckets  = self::buckets( $period['from'], $period['to'], $weekly );

		foreach ( $items as $e ) {
			$cnt  = max( 1, (int) ( $e['cnt'] ?? 1 ) );
			$area = $e['area'] ?? 'changes';
			$date = self::local_date( $e['created'] );
			$via  = $e['via'] ?? '';
			// An- und Abmeldungen sind keine Arbeit an der Website: eigener Block, nicht in Grafik und „Wer und wie“.
			$auth = 'auth' === ( $e['category'] ?? '' );
			if ( ! $auth && 'system' !== $area ) {
				$by_via[ $via ] = ( $by_via[ $via ] ?? 0 ) + $cnt;
				if ( 'claude' === $via ) {
					$kpi['claude'] += $cnt;
				}
			}
			if ( 'warning' === ( $e['level'] ?? '' ) ) {
				$kpi['warnings'] += $cnt;
			}
			if ( 'audit' === ( $e['category'] ?? '' ) ) {
				$snaps[] = [ 'date' => $date, 'counts' => $e['details']['counts'] ?? null, 'message' => $e['message'] ];
				continue;
			}
			if ( 'system' === $area ) {
				continue;
			}
			$key = self::bucket_key( $date, $weekly );
			if ( ! $auth ) {
				$area     = isset( $area_cnt[ $area ] ) ? $area : 'changes';
				$area_cnt[ $area ] += $cnt;
				if ( isset( $buckets[ $key ] ) ) {
					$buckets[ $key ][ $area ] += $cnt;
				}
			}
			$d = (array) ( $e['details'] ?? [] );

			if ( 'note' === ( $e['source'] ?? '' ) ) {
				$kpi['notes']++;
				$kpi['minutes'] += (int) ( $d['minutes'] ?? 0 );
				$notes[] = [ 'date' => $date, 'area' => $area, 'text' => $d['text'] ?? $e['message'], 'minutes' => $d['minutes'] ?? null, 'ticket' => $d['ticket'] ?? null, 'tags' => $d['tags'] ?? [], 'user' => $e['user_login'] ];
				continue;
			}
			$cat = $e['category'] ?? '';
			$act = $e['action'] ?? '';

			if ( 'auth' === $cat && in_array( $act, [ 'login_failed', 'app_password_failed' ], true ) ) {
				$failed['total'] += $cnt;
				$failed['days'][ $date ] = ( $failed['days'][ $date ] ?? 0 ) + $cnt;
				$n = $e['object_name'] ?: '(Anwendungspasswort)';
				$failed['names'][ $n ] = ( $failed['names'][ $n ] ?? 0 ) + $cnt;
				$kpi['failed_logins'] += $cnt;
				continue;
			}
			if ( 'auth' === $cat && in_array( $act, [ 'login', 'logout' ], true ) ) {
				if ( 'login' === $act ) {
					$u = $e['user_login'] ?: $e['object_name'];
					$logins[ $u ] = [ 'count' => ( $logins[ $u ]['count'] ?? 0 ) + $cnt, 'last' => max( $logins[ $u ]['last'] ?? '', $date ), 'admin' => 'notice' === ( $e['level'] ?? '' ) ];
					$kpi['logins'] += $cnt;
				}
				continue;
			}
			if ( 'content' === $cat || 'bridge' === $cat ) {
				$okey = $cat . '|' . ( $e['object_type'] ?? '' ) . '|' . ( $e['object_id'] ?? '' ) . '|' . ( 'bridge' === $cat ? $e['object_name'] : '' );
				if ( ! isset( $objects[ $okey ] ) ) {
					$objects[ $okey ] = [ 'name' => $e['object_name'] ?: ( $e['object_id'] ?? '' ), 'type' => $e['object_type'] ?? '', 'id' => $e['object_id'] ?? '', 'kind' => $cat, 'actions' => [], 'count' => 0, 'first' => $date, 'last' => $date, 'users' => [], 'via' => [] ];
				}
				$o = &$objects[ $okey ];
				$o['count'] += $cnt;
				$o['actions'][ $act ] = ( $o['actions'][ $act ] ?? 0 ) + $cnt;
				$o['first'] = min( $o['first'], $date );
				$o['last']  = max( $o['last'], $date );
				if ( $e['user_login'] && ! in_array( $e['user_login'], $o['users'], true ) ) {
					$o['users'][] = $e['user_login'];
				}
				if ( $via && ! in_array( $via, $o['via'], true ) ) {
					$o['via'][] = $via;
				}
				unset( $o );
				if ( 'security' !== $area ) {
					$kpi['changes'] += $cnt;
					continue;
				}
			}
			$item = [ 'date' => $date, 'category' => $cat, 'action' => $act, 'level' => $e['level'] ?? 'info', 'name' => $e['object_name'], 'message' => $e['message'], 'count' => $cnt, 'via' => $via, 'user' => $e['user_login'] ];
			if ( isset( $d['from'] ) || isset( $d['to'] ) ) {
				$item['from'] = $d['from'] ?? null;
				$item['to']   = $d['to'] ?? null;
			}
			if ( 'updates' === $area ) {
				if ( 'updated' === $act ) {
					$kpi['updates'] += $cnt;
				} elseif ( 'installed' === $act ) {
					$kpi['installs'] += $cnt;
				}
			} elseif ( 'security' === $area ) {
				$kpi['security_events'] += $cnt;
			} elseif ( 'seo' === $area ) {
				$kpi['seo'] += $cnt;
			} else {
				$kpi['changes'] += $cnt;
			}
			$sections[ in_array( $area, $areas, true ) ? $area : 'changes' ][] = $item;
		}

		usort( $objects, static fn( $a, $b ) => $b['count'] <=> $a['count'] );
		arsort( $failed['names'] );
		arsort( $by_via );
		ksort( $failed['days'] );
		$failed['top_names'] = array_slice( $failed['names'], 0, 10, true );
		$failed['peak_day']  = $failed['days'] ? array_search( max( $failed['days'] ), $failed['days'], true ) : null;
		unset( $failed['names'] );
		uasort( $logins, static fn( $a, $b ) => $b['count'] <=> $a['count'] );

		return [
			'period'   => array_intersect_key( $period, array_flip( [ 'kind', 'from', 'to', 'label', 'rhythm' ] ) ),
			'kpis'     => $kpi,
			'by_area'  => $area_cnt,
			'by_via'   => $by_via,
			'timeline' => [ 'unit' => $weekly ? 'week' : 'month', 'buckets' => array_values( $buckets ) ],
			'sections' => [
				'updates'  => $sections['updates'],
				'security' => [ 'events' => $sections['security'], 'failed_logins' => $failed, 'logins' => $logins ],
				'seo'      => $sections['seo'],
				'changes'  => [ 'objects' => array_slice( $objects, 0, 200 ), 'objects_total' => count( $objects ), 'events' => $sections['changes'] ],
			],
			'notes'    => array_reverse( $notes ),
			'audit_snapshots' => $snaps ? [ 'first' => end( $snaps ), 'last' => reset( $snaps ), 'count' => count( $snaps ) ] : null,
		];
	}

	private static function local_date( $utc ) {
		if ( function_exists( 'wp_timezone' ) ) {
			return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		}
		return substr( (string) $utc, 0, 10 );
	}

	private static function bucket_key( $date, $weekly ) {
		return $weekly ? ( new DateTimeImmutable( $date ) )->modify( 'monday this week' )->format( 'Y-m-d' ) : substr( $date, 0, 7 );
	}

	/** Leere Zeitabschnitte (Wochen ab Montag bzw. Monate) für die Grafik. */
	private static function buckets( $from, $to, $weekly ) {
		$out = [];
		$cur = new DateTimeImmutable( $weekly ? self::bucket_key( $from, true ) : substr( $from, 0, 7 ) . '-01' );
		$end = new DateTimeImmutable( $to );
		for ( $i = 0; $cur <= $end && $i < 120; $i++ ) {
			$key         = $weekly ? $cur->format( 'Y-m-d' ) : $cur->format( 'Y-m' );
			$out[ $key ] = [ 'start' => $key, 'label' => $weekly ? 'KW ' . (int) $cur->format( 'W' ) : $cur->format( 'm/Y' ), 'updates' => 0, 'security' => 0, 'seo' => 0, 'changes' => 0 ];
			$cur         = $cur->modify( $weekly ? '+1 week' : '+1 month' );
		}
		return $out;
	}
}
