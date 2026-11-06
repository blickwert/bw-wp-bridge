<?php
/**
 * Wartungsprotokoll: Speicher (eigene Tabelle), Archiv auf dem Dateisystem, Abrechnungszeiträume.
 *
 * - Nur Anhängen: Einträge werden nicht geändert (Ausnahme: Zähler zusammengefasster Einträge) und nur durch die
 *   Aufbewahrung entfernt. Über die REST-API lassen sich nur Notizen hinzufügen.
 * - Datenbankfreundlich: schlanke Spalten, Indizes auf Zeit und Bereich, häufige Ereignisse (fehlgeschlagene Anmeldungen,
 *   Bearbeitungen derselben Seite, REST-Schreibzugriffe) werden je Tag zu einem Eintrag mit Zähler zusammengefasst.
 * - Einträge, die älter als „Tage in der Datenbank“ (Standard 365) sind, wandern monatsweise in gzip-Dateien
 *   (JSON Lines) unter wp-content/uploads/bw-bridge-log-<zufällig>/ und werden für Berichte von dort mitgelesen.
 *   Nach „Aufbewahrung gesamt“ (Standard 1095 Tage, 0 = unbegrenzt) werden auch die Archivdateien gelöscht.
 * - IP-Adressen nur gekürzt (IPv4 /24, IPv6 /48).
 *
 * Hart abschalten: define( 'BW_WP_BRIDGE_LOG_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Log {

	const DB_VERSION      = 1;
	const OPT_DB          = 'bw_bridge_log_db';
	const OPT_DB_DAYS     = 'bw_bridge_log_db_days';
	const OPT_KEEP_DAYS   = 'bw_bridge_log_keep_days';
	const OPT_START       = 'bw_bridge_contract_start';
	const OPT_RHYTHM      = 'bw_bridge_contract_rhythm';
	const OPT_AUDIT_LAST  = 'bw_bridge_audit_last';
	const CRON            = 'bw_bridge_log_maintenance';
	const DEFAULT_DB_DAYS = 365;
	const DEFAULT_KEEP    = 1095;

	/** Bereiche des Berichts. */
	const AREAS   = [ 'updates', 'security', 'seo', 'changes', 'system' ];
	const LEVELS  = [ 'info', 'notice', 'warning' ];
	const RHYTHMS = [ 'month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12 ];

	/** Während Archivierung/Bereinigung nichts protokollieren. */
	private static $paused = false;

	public static function disabled() {
		return defined( 'BW_WP_BRIDGE_LOG_DISABLED' ) && BW_WP_BRIDGE_LOG_DISABLED;
	}

	public static function init() {
		if ( self::disabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 1 );
		add_action( self::CRON, [ __CLASS__, 'maintenance' ] );
		BW_Bridge_Log_WP::init();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bw_bridge_log';
	}

	public static function ready() {
		return ! self::disabled() && self::DB_VERSION === (int) get_option( self::OPT_DB, 0 );
	}

	/** Tabelle anlegen bzw. aktualisieren (läuft auch ohne Aktivierung, z. B. als Must-Use-Plugin), Cron einplanen. */
	public static function maybe_install() {
		if ( ! self::ready() ) {
			self::install();
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t  = self::table();
		$cc = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created datetime NOT NULL,
  updated datetime NOT NULL,
  area varchar(12) NOT NULL DEFAULT 'changes',
  category varchar(20) NOT NULL DEFAULT '',
  action varchar(40) NOT NULL DEFAULT '',
  level varchar(10) NOT NULL DEFAULT 'info',
  source varchar(10) NOT NULL DEFAULT 'wp',
  via varchar(10) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  object_name varchar(191) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  details longtext NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  cnt int(10) unsigned NOT NULL DEFAULT 1,
  agg_key char(32) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created (created),
  KEY area_created (area,created),
  UNIQUE KEY agg_key (agg_key)
) $cc;" );
		update_option( self::OPT_DB, self::DB_VERSION, true );
	}

	/* ------------------------------------------------------------------ */
	/* Schreiben                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Eintrag hinzufügen. Felder: area, category, action, level, message, object_type, object_id, object_name,
	 * details (Array), source (wp|bridge|note), via, user_id, user_login, created (UTC, nur Notizen),
	 * aggregate (Zeichenkette: gleiche Einträge desselben Tages werden mit Zähler zusammengefasst).
	 * Rückgabe: ID (bei Zusammenfassung 0) oder false.
	 */
	public static function add( array $e ) {
		if ( self::$paused || ! self::ready() ) {
			return false;
		}
		global $wpdb;
		$row = self::build_row( $e );
		if ( ! empty( $e['aggregate'] ) ) {
			$row['agg_key'] = self::agg_key( $row, (string) $e['aggregate'] );
			$cols = array_keys( $row );
			$sql  = 'INSERT INTO ' . self::table() . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_map( [ __CLASS__, 'placeholder' ], $cols ) ) . ')'
				. ' ON DUPLICATE KEY UPDATE cnt = cnt + 1, updated = VALUES(updated)';
			$wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );
			return 0;
		}
		return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : false;
	}

	public static function placeholder( $col ) {
		return in_array( $col, [ 'user_id', 'cnt' ], true ) ? '%d' : '%s';
	}

	/** Zeile aus den Feldern bauen (ohne Datenbank, testbar). */
	public static function build_row( array $e ) {
		$now  = gmdate( 'Y-m-d H:i:s', time() );
		$user = null;
		$via  = (string) ( $e['via'] ?? self::via() );
		if ( isset( $e['user_id'] ) ) {
			$user = $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null;
		} elseif ( 'cron' !== $via && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
		}
		$details = isset( $e['details'] ) && [] !== $e['details'] ? wp_json_encode( $e['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		if ( null !== $details && strlen( $details ) > 16000 ) {
			$details = wp_json_encode( [ 'truncated' => true ] );
		}
		$area = (string) ( $e['area'] ?? 'changes' );
		return [
			'created'     => isset( $e['created'] ) ? (string) $e['created'] : $now,
			'updated'     => $now,
			'area'        => in_array( $area, self::AREAS, true ) ? $area : 'changes',
			'category'    => self::cut( sanitize_key( (string) ( $e['category'] ?? '' ) ), 20 ),
			'action'      => self::cut( sanitize_key( (string) ( $e['action'] ?? '' ) ), 40 ),
			'level'       => in_array( $e['level'] ?? '', self::LEVELS, true ) ? $e['level'] : 'info',
			'source'      => in_array( $e['source'] ?? '', [ 'wp', 'bridge', 'note' ], true ) ? $e['source'] : 'wp',
			'via'         => self::cut( $via, 10 ),
			'user_id'     => $user && ! empty( $user->ID ) ? (int) $user->ID : 0,
			'user_login'  => self::cut( $user && ! empty( $user->ID ) ? (string) $user->user_login : (string) ( $e['user_login'] ?? '' ), 60 ),
			'object_type' => self::cut( (string) ( $e['object_type'] ?? '' ), 30 ),
			'object_id'   => self::cut( (string) ( $e['object_id'] ?? '' ), 64 ),
			'object_name' => self::cut( wp_strip_all_tags( (string) ( $e['object_name'] ?? '' ) ), 191 ),
			'message'     => self::cut( wp_strip_all_tags( (string) ( $e['message'] ?? '' ) ), 255 ),
			'details'     => $details,
			'ip'          => in_array( $via, [ 'cron', 'cli' ], true ) ? '' : self::truncate_ip( self::remote_ip() ),
			'cnt'         => 1,
			'agg_key'     => null,
		];
	}

	public static function agg_key( array $row, $extra ) {
		return md5( substr( $row['created'], 0, 10 ) . '|' . $row['area'] . '|' . $row['category'] . '|' . $row['action'] . '|' . $row['user_id'] . '|' . $row['via'] . '|' . $extra );
	}

	private static function cut( $s, $n ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
	}

	private static function remote_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return (string) apply_filters( 'bw_bridge_log_ip', $ip );
	}

	/** IP kürzen: IPv4 letztes Oktett 0 (/24), IPv6 auf die ersten 48 Bit. Ungültiges wird leer. */
	public static function truncate_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			return inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) );
		}
		return '';
	}

	/**
	 * Wie kam die Änderung zustande: claude (Dienstbenutzer oder Client der Bridge), app (Anwendungspasswort),
	 * rest, backend, web (Anmeldeseite/Frontend), cron, cli.
	 */
	public static function via() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( self::is_claude() ) {
				return 'claude';
			}
			return did_action( 'application_password_did_authenticate' ) ? 'app' : 'rest';
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'is_admin' ) && is_admin() ) ) {
			return 'backend';
		}
		return 'web';
	}

	public static function is_claude() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( preg_match( '/bw-wp-bridge-client|claude-code\//i', $ua ) ) {
			return true;
		}
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}
		$u = wp_get_current_user();
		return $u && ! empty( $u->ID ) && BW_Bridge_Key::SERVICE_LOGIN === $u->user_login;
	}

	/* ------------------------------------------------------------------ */
	/* Lesen                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Einträge lesen. $a: from, to (UTC 'Y-m-d H:i:s'), area, category, level, source, q, limit (max 5000), offset,
	 * archive (bool, Standard true: ältere Monate aus den Archivdateien mitlesen).
	 * Rückgabe: [ 'items' => [...], 'total' => n ], neueste zuerst.
	 */
	public static function query( array $a = [] ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return [ 'items' => [], 'total' => 0 ];
		}
		$limit  = max( 1, min( 5000, (int) ( $a['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
		[ $where, $args ] = self::where( $a );
		$sql_where = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$t         = self::table();
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t$sql_where", $args ) : "SELECT COUNT(*) FROM $t$sql_where" );

		$arch = ( $a['archive'] ?? true ) ? self::read_archive( $a ) : [];
		$need = $limit + $offset;
		$sql  = "SELECT * FROM $t$sql_where ORDER BY created DESC, id DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $need ] ) ), ARRAY_A );
		$rows = array_map( [ __CLASS__, 'normalize' ], (array) $rows );
		if ( $arch ) {
			$rows = array_merge( $rows, $arch );
			usort( $rows, static function ( $x, $y ) {
				return strcmp( $y['created'], $x['created'] ) ?: ( $y['id'] <=> $x['id'] );
			} );
			$total += count( $arch );
		}
		return [ 'items' => array_slice( $rows, $offset, $limit ), 'total' => $total ];
	}

	private static function where( array $a ) {
		global $wpdb;
		$w = [];
		$p = [];
		if ( ! empty( $a['from'] ) ) {
			$w[] = 'created >= %s';
			$p[] = (string) $a['from'];
		}
		if ( ! empty( $a['to'] ) ) {
			$w[] = 'created <= %s';
			$p[] = (string) $a['to'];
		}
		foreach ( [ 'area', 'category', 'level', 'source', 'via' ] as $f ) {
			if ( ! empty( $a[ $f ] ) ) {
				$vals = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $a[ $f ] ) ) ) );
				if ( $vals ) {
					$w[] = "$f IN (" . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . ')';
					$p   = array_merge( $p, $vals );
				}
			}
		}
		if ( isset( $a['q'] ) && '' !== (string) $a['q'] ) {
			$like = '%' . $wpdb->esc_like( (string) $a['q'] ) . '%';
			$w[]  = '(message LIKE %s OR object_name LIKE %s OR details LIKE %s)';
			array_push( $p, $like, $like, $like );
		}
		return [ $w, $p ];
	}

	public static function normalize( $r ) {
		$r            = (array) $r;
		$r['id']      = (int) ( $r['id'] ?? 0 );
		$r['user_id'] = (int) ( $r['user_id'] ?? 0 );
		$r['cnt']     = max( 1, (int) ( $r['cnt'] ?? 1 ) );
		if ( isset( $r['details'] ) && is_string( $r['details'] ) ) {
			$d            = json_decode( $r['details'], true );
			$r['details'] = is_array( $d ) ? $d : null;
		}
		unset( $r['agg_key'] );
		return $r;
	}

	/** Kennzahlen zum Speicher für status und die Einstellungsseite. */
	public static function stats() {
		global $wpdb;
		if ( ! self::ready() ) {
			return [ 'enabled' => false ];
		}
		$t    = self::table();
		$dir  = self::archive_dir( false );
		$file = $dir && is_dir( $dir ) ? glob( $dir . '/*.jsonl.gz' ) : [];
		return [
			'enabled'       => true,
			'rows'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
			'oldest'        => $wpdb->get_var( "SELECT MIN(created) FROM $t" ),
			'db_days'       => self::db_days(),
			'keep_days'     => self::keep_days(),
			'archive_files' => count( (array) $file ),
			'archive_bytes' => array_sum( array_map( 'filesize', (array) $file ) ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Aufbewahrung und Archiv                                             */
	/* ------------------------------------------------------------------ */

	public static function db_days() {
		$d = (int) get_option( self::OPT_DB_DAYS, self::DEFAULT_DB_DAYS );
		return $d > 0 ? max( 30, $d ) : self::DEFAULT_DB_DAYS;
	}

	/** 0 = unbegrenzt; sonst mindestens so lang wie die Tage in der Datenbank. */
	public static function keep_days() {
		$k = (int) get_option( self::OPT_KEEP_DAYS, self::DEFAULT_KEEP );
		return $k <= 0 ? 0 : max( $k, self::db_days() );
	}

	/** Archivordner (nicht erratbarer Name, per .htaccess gesperrt). Mit $create = false nur der Pfad. */
	public static function archive_dir( $create = true ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$u = wp_upload_dir( null, false );
		if ( ! empty( $u['error'] ) || empty( $u['basedir'] ) ) {
			return '';
		}
		$dir = rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' ) . '/bw-bridge-log-' . substr( wp_hash( 'bw_bridge_log' ), 0, 12 );
		if ( $create && ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			@file_put_contents( $dir . '/index.html', '' );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/** Täglich: alte Einträge archivieren, abgelaufene Archive löschen, Sicherheits-Schnappschuss. */
	public static function maintenance() {
		if ( ! self::ready() ) {
			return;
		}
		$moved = self::archive_old();
		$gone  = self::purge_expired();
		if ( $moved || $gone ) {
			self::add( [
				'area' => 'system', 'category' => 'log', 'action' => 'archived', 'via' => 'cron', 'user_id' => 0,
				'message' => sprintf( 'Protokoll aufgeräumt: %d Einträge ins Archiv verschoben, %d abgelaufene Einträge/Dateien entfernt', $moved, $gone ),
			] );
		}
		BW_Bridge_Audit::maybe_snapshot();
	}

	/** Verschiebt Einträge älter als db_days() in Monatsdateien. Rückgabe: Anzahl verschobener Einträge. */
	public static function archive_old( $now = null ) {
		global $wpdb;
		$dir = self::archive_dir();
		if ( '' === $dir ) {
			return 0;
		}
		$cut   = gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - self::db_days() * DAY_IN_SECONDS );
		$t     = self::table();
		$moved = 0;
		self::$paused = true;
		for ( $i = 0; $i < 50; $i++ ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE created < %s ORDER BY id ASC LIMIT %d", $cut, 1000 ), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			$by_month = [];
			foreach ( $rows as $r ) {
				$by_month[ substr( $r['created'], 0, 7 ) ][] = self::normalize( $r );
			}
			foreach ( $by_month as $month => $list ) {
				if ( ! self::append_archive( $dir, $month, $list ) ) {
					self::$paused = false;
					return $moved; // nichts löschen, was nicht sicher im Archiv steht
				}
			}
			$ids = array_map( 'intval', array_column( $rows, 'id' ) );
			$wpdb->query( "DELETE FROM $t WHERE id IN (" . implode( ',', $ids ) . ')' );
			$moved += count( $ids );
		}
		self::$paused = false;
		return $moved;
	}

	public static function append_archive( $dir, $month, array $rows ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || ! function_exists( 'gzopen' ) ) {
			return false;
		}
		$lines = '';
		foreach ( $rows as $r ) {
			$lines .= wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		}
		// Jeder Aufruf hängt ein eigenes gzip-Mitglied an; beim Lesen werden alle nacheinander entpackt.
		$gz = @gzopen( $dir . '/' . $month . '.jsonl.gz', 'ab9' );
		if ( ! $gz ) {
			return false;
		}
		$ok = false !== gzwrite( $gz, $lines );
		return gzclose( $gz ) && $ok;
	}

	/** Archivierte Einträge im Zeitraum (from/to UTC) mit denselben Filtern wie query(). */
	public static function read_archive( array $a ) {
		$dir = self::archive_dir( false );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return [];
		}
		$from = ! empty( $a['from'] ) ? (string) $a['from'] : '0000-00-00 00:00:00';
		$to   = ! empty( $a['to'] ) ? (string) $a['to'] : '9999-12-31 23:59:59';
		$out  = [];
		foreach ( (array) glob( $dir . '/*.jsonl.gz' ) as $file ) {
			$month = basename( $file, '.jsonl.gz' );
			if ( $month < substr( $from, 0, 7 ) || $month > substr( $to, 0, 7 ) ) {
				continue;
			}
			$gz = @gzopen( $file, 'rb' );
			if ( ! $gz ) {
				continue;
			}
			while ( ! gzeof( $gz ) ) {
				$line = gzgets( $gz );
				$r    = $line ? json_decode( $line, true ) : null;
				if ( ! is_array( $r ) || $r['created'] < $from || $r['created'] > $to || ! self::matches( $r, $a ) ) {
					continue;
				}
				$r['archived'] = true;
				$out[]         = $r;
			}
			gzclose( $gz );
		}
		return $out;
	}

	private static function matches( array $r, array $a ) {
		foreach ( [ 'area', 'category', 'level', 'source', 'via' ] as $f ) {
			if ( ! empty( $a[ $f ] ) && ! in_array( (string) ( $r[ $f ] ?? '' ), explode( ',', (string) $a[ $f ] ), true ) ) {
				return false;
			}
		}
		if ( isset( $a['q'] ) && '' !== (string) $a['q'] ) {
			$hay = ( $r['message'] ?? '' ) . ' ' . ( $r['object_name'] ?? '' ) . ' ' . wp_json_encode( $r['details'] ?? null );
			if ( false === stripos( $hay, (string) $a['q'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Löscht Archivmonate (und Datenbankeinträge), die älter als keep_days() sind. Rückgabe: Anzahl. */
	public static function purge_expired( $now = null ) {
		global $wpdb;
		$keep = self::keep_days();
		if ( ! $keep ) {
			return 0;
		}
		$cut   = gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - $keep * DAY_IN_SECONDS );
		$count = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', $cut ) );
		$dir   = self::archive_dir( false );
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.jsonl.gz' ) as $file ) {
				// Ein Monat fällt erst weg, wenn er vollständig abgelaufen ist.
				if ( basename( $file, '.jsonl.gz' ) < substr( $cut, 0, 7 ) && @unlink( $file ) ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Abrechnungszeiträume                                                */
	/* ------------------------------------------------------------------ */

	public static function rhythm() {
		$r = (string) get_option( self::OPT_RHYTHM, 'quarter' );
		return isset( self::RHYTHMS[ $r ] ) ? $r : 'quarter';
	}

	/** Vertragsbeginn (Y-m-d); ohne Angabe der 1. Jänner des laufenden Jahres (Kalenderquartale). */
	public static function contract_start() {
		$s = (string) get_option( self::OPT_START, '' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ? $s : null;
	}

	/**
	 * Zeitraum berechnen. $which: current, previous (Abrechnungszeitraum laut Rhythmus), year, previous-year
	 * (Vertragsjahr). $today: 'Y-m-d' in Ortszeit der Website. Rückgabe: from, to (inklusive, 'Y-m-d'), label, kind.
	 */
	public static function period( $which = 'current', $today = null, $start = null, $rhythm = null ) {
		$today  = $today ?: self::local_today();
		$start  = $start ?: ( self::contract_start() ?: substr( $today, 0, 4 ) . '-01-01' );
		$rhythm = $rhythm ?: self::rhythm();
		$months = in_array( $which, [ 'year', 'previous-year' ], true ) ? 12 : self::RHYTHMS[ $rhythm ];
		$shift  = in_array( $which, [ 'previous', 'previous-year' ], true ) ? -1 : 0;

		$s = new DateTimeImmutable( $start );
		$t = new DateTimeImmutable( $today );
		$diff = ( (int) $t->format( 'Y' ) - (int) $s->format( 'Y' ) ) * 12 + (int) $t->format( 'n' ) - (int) $s->format( 'n' );
		$n    = (int) floor( $diff / $months );
		// Feinabgleich am Monatsende (31. → 28./30.): der Zeitraum, dessen Beginn der letzte vor bzw. am Stichtag ist.
		while ( self::add_months( $s, $n * $months ) > $t ) {
			$n--;
		}
		while ( self::add_months( $s, ( $n + 1 ) * $months ) <= $t ) {
			$n++;
		}
		$n += $shift;
		$from = self::add_months( $s, $n * $months );
		$to   = self::add_months( $s, ( $n + 1 ) * $months )->modify( '-1 day' );
		return [
			'kind'   => $which,
			'from'   => $from->format( 'Y-m-d' ),
			'to'     => $to->format( 'Y-m-d' ),
			'label'  => self::label( $from, $to, $months ),
			'rhythm' => $months < 12 ? $rhythm : 'year',
		];
	}

	private static function add_months( DateTimeImmutable $d, $m ) {
		$y   = (int) $d->format( 'Y' );
		$mo  = (int) $d->format( 'n' ) + $m;
		$y  += (int) floor( ( $mo - 1 ) / 12 );
		$mo  = ( ( $mo - 1 ) % 12 + 12 ) % 12 + 1;
		$day = min( (int) $d->format( 'j' ), (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $mo ) ) )->format( 't' ) );
		return new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $y, $mo, $day ) );
	}

	private static function label( DateTimeImmutable $from, DateTimeImmutable $to, $months ) {
		if ( '01' === $from->format( 'd' ) && 3 === $months && in_array( (int) $from->format( 'n' ), [ 1, 4, 7, 10 ], true ) ) {
			return 'Q' . ( ( (int) $from->format( 'n' ) + 2 ) / 3 ) . ' ' . $from->format( 'Y' );
		}
		if ( '01-01' === $from->format( 'm-d' ) && 12 === $months ) {
			return 'Jahr ' . $from->format( 'Y' );
		}
		return $from->format( 'd.m.Y' ) . ' – ' . $to->format( 'd.m.Y' );
	}

	public static function local_today() {
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** Ortszeit-Datum (Beginn bzw. Ende des Tages) in UTC 'Y-m-d H:i:s' für die Abfrage. */
	public static function local_to_utc( $date, $end = false ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$d  = new DateTimeImmutable( $date . ( $end ? ' 23:59:59' : ' 00:00:00' ), $tz );
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Zeitraum aus Anfrageparametern: period=current|previous|year|previous-year oder from/to (Y-m-d). */
	public static function resolve_range( $period = null, $from = null, $to = null ) {
		if ( $from || $to ) {
			$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? $from : '2000-01-01';
			$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? $to : self::local_today();
			$f     = new DateTimeImmutable( $from );
			$t     = new DateTimeImmutable( $to );
			$label = $f->format( 'd.m.Y' ) . ' – ' . $t->format( 'd.m.Y' );
			foreach ( [ 3, 12 ] as $m ) {
				if ( self::add_months( $f, $m )->modify( '-1 day' )->format( 'Y-m-d' ) === $to ) {
					$label = self::label( $f, $t, $m );
				}
			}
			$p = [ 'kind' => 'custom', 'from' => $from, 'to' => $to, 'label' => $label, 'rhythm' => null ];
		} else {
			$p = self::period( in_array( $period, [ 'current', 'previous', 'year', 'previous-year' ], true ) ? $period : 'current' );
		}
		$p['from_utc'] = self::local_to_utc( $p['from'] );
		$p['to_utc']   = self::local_to_utc( $p['to'], true );
		return $p;
	}
}
