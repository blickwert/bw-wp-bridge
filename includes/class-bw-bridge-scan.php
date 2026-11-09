<?php
/**
 * Datei- und Schwachstellen-Scan (Teil des Wartungsprotokolls).
 *
 * 1. Dateiänderungs-Erkennung: täglich Pfad, Größe, Änderungszeit und SHA-1 der Code-Dateien von Core, Plugins,
 *    Themes, Must-Use-Plugins, Drop-ins/Sprachdateien sowie der PHP-Dateien in uploads und sonstigen wp-content-Ordnern
 *    mit dem letzten Stand vergleichen. Neu gerechnet wird nur, wo sich Größe oder Zeit geändert haben. Änderungen,
 *    die zu einem protokollierten Update (oder einer neuen Version) passen, gelten als erwartet, alle anderen als
 *    unerwartet (Warnung). PHP-Dateien in uploads werden immer gemeldet (nur melden, nichts verschieben).
 * 2. Integritätsprüfung: Core-Dateien gegen die Prüfsummen von wordpress.org, Plugins aus dem Verzeichnis gegen
 *    downloads.wordpress.org/plugin-checksums; zusätzliche PHP-Dateien werden gemeldet.
 * 3. Schwachstellen: Core, Plugins und Themes gegen die öffentliche WPVulnerability-API (www.wpvulnerability.net,
 *    ohne Schlüssel; übermittelt werden nur Slugs und die Core-Version).
 * 4. Einfache Malware-Hinweise: eindeutige Muster in neuen/geänderten PHP-Dateien (eval(base64_decode(…)) usw.).
 *
 * Der Scan läuft in Schritten mit Zeitbudget (Standard 20 s, Filter bw_bridge_scan_budget) und setzt sich per Cron fort.
 * Ergebnisse landen im Wartungsprotokoll (Bereich security, Kategorien files, integrity, vuln) und in der Prüfung.
 * Hart abschalten: define( 'BW_WP_BRIDGE_SCAN_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Scan {

	const DB_VERSION   = 1;
	const OPT_DB       = 'bw_bridge_scan_db';
	const OPT_STATE    = 'bw_bridge_scan_state';
	const OPT_VULNS    = 'bw_bridge_scan_vulns';
	const OPT_FILES_ON = 'bw_bridge_scan_files';
	const OPT_VULNS_ON = 'bw_bridge_scan_vulns_on';
	const CRON_DAILY   = 'bw_bridge_scan_daily';
	const CRON_STEP    = 'bw_bridge_scan_step';
	const LOCK         = 'bw_bridge_scan_lock';
	const VULN_API     = 'https://www.wpvulnerability.net/';
	const MAX_HASH     = 5242880; // größere Dateien nur über Größe und Zeit vergleichen
	const MAX_FILES    = 60000;   // je Bereich

	/** Endungen, die in Core/Plugins/Themes überwacht werden. */
	const CODE_EXT = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps', 'inc', 'js', 'mjs', 'html', 'htm', 'htaccess', 'ini' ];
	/** Ausführbares: in uploads und sonstigen Ordnern nur das. */
	const PHP_EXT = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps', 'inc', 'htaccess', 'ini' ];
	/** Ordner, die nie durchsucht werden (Name irgendwo im Pfad). */
	const SKIP_DIRS = [ '.git', '.svn', 'node_modules', 'cache', 'upgrade', 'upgrade-temp-backup', 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backup', 'backups' ];

	/** Speicher der Datei-Liste (austauschbar für Tests). */
	public static $store = null;

	public static function disabled() {
		return BW_Bridge_Log::disabled() || ( defined( 'BW_WP_BRIDGE_SCAN_DISABLED' ) && BW_WP_BRIDGE_SCAN_DISABLED );
	}

	public static function files_enabled() {
		return ! self::disabled() && (bool) get_option( self::OPT_FILES_ON, 1 );
	}

	public static function vulns_enabled() {
		return ! self::disabled() && (bool) get_option( self::OPT_VULNS_ON, 1 );
	}

	public static function init() {
		if ( self::disabled() ) {
			return;
		}
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 2 );
		add_action( self::CRON_DAILY, [ __CLASS__, 'cron_daily' ] );
		add_action( self::CRON_STEP, [ __CLASS__, 'step' ] );
	}

	public static function register_routes() {
		register_rest_route( BW_WP_Bridge::NS, '/scan', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'status_route' ], 'permission_callback' => [ 'BW_WP_Bridge', 'can_read' ] ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'start_route' ], 'permission_callback' => [ 'BW_WP_Bridge', 'can_read' ] ],
		] );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bw_bridge_files';
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== (int) get_option( self::OPT_DB, 0 ) ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$t = self::table();
			dbDelta( "CREATE TABLE $t (
  path_key char(32) NOT NULL,
  unit varchar(120) NOT NULL DEFAULT '',
  path text NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  mtime int(10) unsigned NOT NULL DEFAULT 0,
  sha1 char(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (path_key),
  KEY unit (unit)
) {$wpdb->get_charset_collate()};" );
			update_option( self::OPT_DB, self::DB_VERSION, true );
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	private static function store() {
		if ( null === self::$store ) {
			self::$store = new BW_Bridge_Scan_Db_Store();
		}
		return self::$store;
	}

	/* ------------------------------------------------------------------ */
	/* Ablauf                                                              */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$s = get_option( self::OPT_STATE, [] );
		return is_array( $s ) ? $s + [ 'queue' => [], 'meta' => [], 'started' => null, 'finished' => null, 'run' => 0 ] : [ 'queue' => [], 'meta' => [], 'started' => null, 'finished' => null, 'run' => 0 ];
	}

	private static function save_state( array $s ) {
		update_option( self::OPT_STATE, $s, false );
	}

	public static function cron_daily() {
		self::start();
	}

	/** Neuen Lauf beginnen (läuft schon einer seit weniger als 2 Stunden, wird er fortgesetzt). */
	public static function start( $manual = false ) {
		if ( ! self::files_enabled() && ! self::vulns_enabled() ) {
			return self::status();
		}
		$s = self::state();
		if ( ! $s['queue'] || ! $s['started'] || strtotime( $s['started'] . ' UTC' ) < time() - 2 * HOUR_IN_SECONDS ) {
			// Auch Bereiche, die es nicht mehr gibt (gelöschte Plugins/Themes): einmal abgleichen, dann vergessen.
			$units         = self::files_enabled() ? array_values( array_unique( array_merge( self::units(), array_keys( $s['meta'] ) ) ) ) : [];
			$s['queue']    = array_merge( $units, self::vulns_enabled() ? [ 'vulns' ] : [] );
			$s['started']  = gmdate( 'Y-m-d H:i:s' );
			$s['finished'] = null;
			$s['run']      = (int) $s['run'] + 1;
			$s['manual']   = (bool) $manual;
			$s['vuln_pos'] = 0;
			$s['vuln_items'] = [];
			$s['vuln_errors'] = [];
			self::save_state( $s );
		}
		self::step();
		return self::status();
	}

	/** Arbeitet die Warteschlange ab, bis das Zeitbudget verbraucht ist; plant sonst den nächsten Schritt ein. */
	public static function step() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		$budget = (float) apply_filters( 'bw_bridge_scan_budget', 20 );
		$t0     = microtime( true );
		$s      = self::state();
		while ( $s['queue'] && microtime( true ) - $t0 < $budget ) {
			$item = $s['queue'][0];
			if ( 'vulns' === $item ) {
				$done = self::process_vulns( $s, $t0, $budget );
			} else {
				self::process_unit( $item, $s );
				$done = true;
			}
			if ( $done ) {
				array_shift( $s['queue'] );
			}
			self::save_state( $s );
		}
		if ( ! $s['queue'] && ! $s['finished'] && $s['started'] ) {
			$s['finished'] = gmdate( 'Y-m-d H:i:s' );
			self::save_state( $s );
		} elseif ( $s['queue'] && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_STEP ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_STEP );
		}
		delete_transient( self::LOCK );
	}

	/* ------------------------------------------------------------------ */
	/* Bereiche (Units)                                                    */
	/* ------------------------------------------------------------------ */

	/** Alle Bereiche: core, content, mu-plugins, plugins-root, plugin:<ordner>, theme:<ordner>, uploads, other. */
	public static function units() {
		$u = [ 'core', 'content' ];
		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$u[] = 'mu-plugins';
		}
		$u[] = 'plugins-root';
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $d ) {
			$u[] = 'plugin:' . basename( $d );
		}
		foreach ( (array) glob( self::theme_root() . '/*', GLOB_ONLYDIR ) as $d ) {
			$u[] = 'theme:' . basename( $d );
		}
		$u[] = 'uploads';
		$u[] = 'other';
		return $u;
	}

	private static function theme_root() {
		return function_exists( 'get_theme_root' ) ? get_theme_root() : WP_CONTENT_DIR . '/themes';
	}

	private static function uploads_dir() {
		$u = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : [];
		return ! empty( $u['basedir'] ) ? rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' ) : WP_CONTENT_DIR . '/uploads';
	}

	/**
	 * Was zu einem Bereich gehört: Liste von [ Ordner, rekursiv, Endungen, Präfix ] und Bezeichnung.
	 */
	public static function unit_def( $unit ) {
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$abs     = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		if ( 'core' === $unit ) {
			return [ 'label' => 'WordPress-Core', 'parts' => [ [ $abs, false, self::CODE_EXT, '' ], [ $abs . '/wp-admin', true, self::CODE_EXT, 'wp-admin/' ], [ $abs . '/wp-includes', true, self::CODE_EXT, 'wp-includes/' ] ] ];
		}
		if ( 'content' === $unit ) {
			return [ 'label' => 'wp-content (Drop-ins, Sprachdateien)', 'parts' => [ [ $content, false, self::PHP_EXT, '' ], [ $content . '/languages', true, self::PHP_EXT, 'languages/' ] ] ];
		}
		if ( 'mu-plugins' === $unit ) {
			return [ 'label' => 'Must-Use-Plugins', 'parts' => [ [ WPMU_PLUGIN_DIR, true, self::CODE_EXT, '' ] ] ];
		}
		if ( 'plugins-root' === $unit ) {
			return [ 'label' => 'Plugins (einzelne Dateien)', 'parts' => [ [ WP_PLUGIN_DIR, false, self::CODE_EXT, '' ] ] ];
		}
		if ( 0 === strpos( $unit, 'plugin:' ) ) {
			$dir = substr( $unit, 7 );
			return [ 'label' => 'Plugin ' . self::plugin_name( $dir ), 'parts' => [ [ WP_PLUGIN_DIR . '/' . $dir, true, self::CODE_EXT, '' ] ] ];
		}
		if ( 0 === strpos( $unit, 'theme:' ) ) {
			$dir = substr( $unit, 6 );
			$t   = function_exists( 'wp_get_theme' ) ? wp_get_theme( $dir ) : null;
			return [ 'label' => 'Theme ' . ( $t && $t->exists() ? $t->get( 'Name' ) : $dir ), 'parts' => [ [ self::theme_root() . '/' . $dir, true, self::CODE_EXT, '' ] ] ];
		}
		if ( 'uploads' === $unit ) {
			return [ 'label' => 'Uploads (nur PHP und Serverkonfiguration)', 'parts' => [ [ self::uploads_dir(), true, self::PHP_EXT, '' ] ] ];
		}
		// Sonstige Ordner in wp-content (z. B. von Plugins angelegt): nur Ausführbares.
		$parts = [];
		$known = [ 'plugins', 'themes', 'uploads', 'mu-plugins', 'languages', 'cache', 'upgrade', 'upgrade-temp-backup' ];
		foreach ( (array) glob( $content . '/*', GLOB_ONLYDIR ) as $d ) {
			$name = basename( $d );
			$real = str_replace( '\\', '/', $d );
			if ( in_array( $name, $known, true ) || $real === self::uploads_dir() || $real === str_replace( '\\', '/', WP_PLUGIN_DIR ) || $real === str_replace( '\\', '/', self::theme_root() ) ) {
				continue;
			}
			$parts[] = [ $d, true, self::PHP_EXT, $name . '/' ];
		}
		return [ 'label' => 'Sonstige Ordner in wp-content', 'parts' => $parts ];
	}

	private static function plugin_name( $dir ) {
		foreach ( self::plugins() as $file => $d ) {
			if ( 0 === strpos( $file, $dir . '/' ) ) {
				return $d['Name'];
			}
		}
		return $dir;
	}

	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins(); // WordPress speichert das Ergebnis selbst zwischen
	}

	/** Version eines Bereichs (für „erwartete“ Änderungen und die Integritätsprüfung). */
	public static function unit_version( $unit ) {
		global $wp_version;
		if ( 'core' === $unit ) {
			return (string) $wp_version;
		}
		if ( 0 === strpos( $unit, 'plugin:' ) ) {
			foreach ( self::plugins() as $file => $d ) {
				if ( 0 === strpos( $file, substr( $unit, 7 ) . '/' ) ) {
					return (string) $d['Version'];
				}
			}
		}
		if ( 0 === strpos( $unit, 'theme:' ) && function_exists( 'wp_get_theme' ) ) {
			$t = wp_get_theme( substr( $unit, 6 ) );
			return $t->exists() ? (string) $t->get( 'Version' ) : '';
		}
		return '';
	}

	/** Dateien eines Bereichs: [ relativer Pfad => [ Größe, mtime, absoluter Pfad ] ]. */
	public static function walk( array $def ) {
		$out    = [];
		$skip   = apply_filters( 'bw_bridge_scan_skip_dirs', self::SKIP_DIRS );
		$uploads_own = '#/bw-bridge-(backups|log)-#';
		foreach ( $def['parts'] as [ $dir, $recursive, $exts, $prefix ] ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
			if ( $recursive ) {
				$it = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
						static function ( $f ) use ( $skip, $uploads_own ) {
							if ( $f->isLink() ) {
								return false; // keinen Verknüpfungen folgen
							}
							return ! $f->isDir() || ( ! in_array( $f->getFilename(), $skip, true ) && ! preg_match( $uploads_own, '/' . $f->getFilename() ) );
						}
					),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
			} else {
				$it = new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS );
			}
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->isLink() || ! in_array( strtolower( $f->getExtension() ), $exts, true ) ) {
					continue;
				}
				$path = str_replace( '\\', '/', $f->getPathname() );
				$rel  = $prefix . ltrim( substr( $path, strlen( $dir ) ), '/' );
				$out[ $rel ] = [ (int) $f->getSize(), (int) $f->getMTime(), $path ];
				if ( count( $out ) >= self::MAX_FILES ) {
					break 2;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	public static function is_php( $path ) {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps', 'inc' ], true );
	}

	/* ------------------------------------------------------------------ */
	/* Dateiänderungen                                                     */
	/* ------------------------------------------------------------------ */

	/** Einen Bereich abgleichen, Änderungen protokollieren, ggf. Integrität prüfen. */
	public static function process_unit( $unit, array &$s ) {
		$def   = self::unit_def( $unit );
		$files = self::walk( $def );
		$known = self::store()->load( $unit );
		$meta  = $s['meta'][ $unit ] ?? [];
		$first = ! $known && empty( $meta['last_scan'] );

		$added = $modified = $suspicious = $upserts = [];
		foreach ( $files as $rel => [ $size, $mtime, $abs ] ) {
			$k = $known[ $rel ] ?? null;
			if ( $k && (int) $k['size'] === $size && (int) $k['mtime'] === $mtime ) {
				continue;
			}
			$content = $size <= self::MAX_HASH ? @file_get_contents( $abs ) : null;
			$sha     = is_string( $content ) ? sha1( $content ) : '';
			$upserts[ $rel ] = [ 'size' => $size, 'mtime' => $mtime, 'sha1' => $sha ];
			if ( $k && '' !== $sha && $k['sha1'] === $sha ) {
				continue; // nur Zeitstempel geändert
			}
			$k ? $modified[] = $rel : $added[] = $rel;
			if ( is_string( $content ) && self::is_php( $rel ) && ( $hits = self::patterns( $content ) ) ) {
				$suspicious[ $rel ] = $hits;
			}
		}
		$deleted = array_values( array_diff( array_keys( $known ), array_keys( $files ) ) );
		self::store()->save( $unit, $upserts, $deleted );

		$version = self::unit_version( $unit );
		$prev    = $meta;
		$s['meta'][ $unit ] = array_merge( $meta, [ 'last_scan' => gmdate( 'Y-m-d H:i:s' ), 'files' => count( $files ), 'version' => $version, 'label' => $def['label'] ] );

		// PHP in uploads/sonstigen Ordnern: auch bei der Ersterfassung melden.
		$exec_dirs = in_array( $unit, [ 'uploads', 'other' ], true );
		$php_new   = $exec_dirs ? array_values( array_filter( $first ? array_keys( $files ) : array_merge( $added, $modified ), [ __CLASS__, 'is_php' ] ) ) : [];

		if ( $first ) {
			if ( $suspicious || $php_new ) {
				self::log_files( $unit, $def['label'], 'baseline', [], [], [], $suspicious, $php_new, null, true );
			}
		} elseif ( $added || $modified || $deleted ) {
			$why = self::expected( $unit, $prev['last_scan'] ?? null, $prev['version'] ?? '', $version );
			self::log_files( $unit, $def['label'], 'changed', $added, $modified, $deleted, $suspicious, $php_new, $why, false );
		}

		// Integrität: bei Änderungen, neuer Version oder spätestens nach 7 Tagen.
		$int = $meta['integrity'] ?? null;
		if ( $files && ( 'core' === $unit || 0 === strpos( $unit, 'plugin:' ) ) && ( $added || $modified || $deleted || ! $int || ( $int['version'] ?? '' ) !== $version || strtotime( ( $int['checked'] ?? '2000-01-01' ) . ' UTC' ) < time() - 7 * DAY_IN_SECONDS ) ) {
			$res = self::integrity( $unit, $version, $files );
			$s['meta'][ $unit ]['integrity'] = $res;
			self::log_integrity( $unit, $def['label'], $res, $int );
		}
		if ( ! $files && ! in_array( $unit, self::units(), true ) ) {
			unset( $s['meta'][ $unit ] );
		}
	}

	/** Ein Protokolleintrag je Bereich und Lauf. */
	private static function log_files( $unit, $label, $kind, $added, $modified, $deleted, $suspicious, $php_new, $why, $baseline ) {
		$n     = count( $added ) + count( $modified ) + count( $deleted );
		$level = ( $suspicious || $php_new || ( ! $why && ! $baseline ) ) ? 'warning' : 'info';
		if ( $baseline && ! $suspicious && 'other' === $unit ) {
			$level = 'notice'; // PHP in eigenen Ordnern von Plugins (Konfiguration, Logs) ist häufig: einmal zur Kenntnis
		}
		$parts = [];
		foreach ( [ 'geändert' => $modified, 'neu' => $added, 'gelöscht' => $deleted ] as $w => $list ) {
			if ( $list ) {
				$parts[] = count( $list ) . ' ' . $w;
			}
		}
		if ( $baseline ) {
			$msg = sprintf( '%s: bei der Ersterfassung aufgefallen – %s', $label, implode( ', ', array_filter( [ $php_new ? count( $php_new ) . ' PHP-Datei(en)' : '', $suspicious ? count( $suspicious ) . ' Datei(en) mit verdächtigem Code' : '' ] ) ) );
		} else {
			$msg = sprintf( '%s: %s – %s', $label, implode( ', ', $parts ), $why ? 'erwartet (' . $why . ')' : 'nicht durch ein Update erklärt' );
			if ( $suspicious ) {
				$msg .= ', verdächtiger Code in ' . count( $suspicious ) . ' Datei(en)';
			} elseif ( $php_new ) {
				$msg .= ', PHP-Dateien an ungewöhnlicher Stelle';
			}
		}
		BW_Bridge_Log::add( [
			'area' => 'security', 'category' => 'files', 'action' => $suspicious ? 'suspicious' : ( $baseline ? 'baseline' : ( $why ? 'expected' : 'unexpected' ) ),
			'level' => $level, 'object_type' => 'files', 'object_id' => $unit, 'object_name' => $label, 'message' => $msg,
			'details' => array_filter( [
				'unit' => $unit, 'expected' => $why, 'counts' => [ 'modified' => count( $modified ), 'added' => count( $added ), 'deleted' => count( $deleted ) ],
				'modified' => array_slice( $modified, 0, 50 ), 'added' => array_slice( $added, 0, 50 ), 'deleted' => array_slice( $deleted, 0, 50 ),
				'php_unusual' => array_slice( $php_new, 0, 50 ), 'suspicious' => array_slice( $suspicious, 0, 20, true ), 'total' => $n,
			] ),
			'user_id' => 0,
		] );
	}

	/** Erklärung für Änderungen: neue Version oder protokolliertes Update seit dem letzten Scan, sonst null. */
	public static function expected( $unit, $since, $prev_version, $version ) {
		$logged = null;
		if ( $since ) {
			$rows = BW_Bridge_Log::query( [ 'from' => $since, 'category' => 'plugin,theme,core,translation,bridge', 'limit' => 500, 'archive' => false ] )['items'];
			foreach ( $rows as $r ) {
				$oid = (string) ( $r['object_id'] ?? '' );
				$hit = false;
				if ( 0 === strpos( $unit, 'plugin:' ) ) {
					$hit = 'plugin' === $r['category'] && 0 === strpos( $oid, substr( $unit, 7 ) . '/' );
				} elseif ( 0 === strpos( $unit, 'theme:' ) ) {
					$hit = ( 'theme' === $r['category'] && ( $oid === substr( $unit, 6 ) || 'installed' === $r['action'] ) ) || ( 'bridge' === $r['category'] && 0 === strpos( $oid, '/bw-bridge/v1/theme/' ) );
				} elseif ( 'core' === $unit ) {
					$hit = 'core' === $r['category'];
				} elseif ( 'content' === $unit ) {
					$hit = 'translation' === $r['category'];
				} elseif ( 'plugins-root' === $unit ) {
					$hit = 'plugin' === $r['category'] && false === strpos( $oid, '/' );
				}
				if ( $hit ) {
					$logged = $r['message'] ?: $r['action'];
					break;
				}
			}
		}
		if ( '' !== (string) $prev_version && '' !== (string) $version && $prev_version !== $version ) {
			return sprintf( 'Version %s → %s%s', $prev_version, $version, $logged ? '' : ', Update nicht im Protokoll' );
		}
		return $logged;
	}

	/** Eindeutige Malware-Muster (bewusst wenige, damit es kaum Fehlalarme gibt). Rückgabe: Liste der Treffer. */
	public static function patterns( $code ) {
		$checks = [
			'eval-encoded'  => '/\beval\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin)\s*\(/i',
			'eval-input'    => '/\b(?:eval|assert)\s*\(\s*(?:@\s*)?(?:stripslashes\s*\(\s*)?\$_(?:POST|GET|REQUEST|COOKIE|SERVER)\b/i',
			'input-call'    => '/\$_(?:POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)/i',
			'preg-e'        => '/preg_replace\s*\(\s*([\'"])(.).*?\2[imsxu]*e[imsxu]*\1\s*,/i',
			'shell-names'   => '/FilesMan|WSOsetcookie|c99shell|r57shell|b374k|Webshell/i',
			'long-base64'   => '/[\'"][A-Za-z0-9+\/]{3000,}={0,2}[\'"]/',
			'create-func'   => '/create_function\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*(?:base64_decode|gzinflate|\$_)/i',
		];
		$hits = [];
		foreach ( $checks as $name => $re ) {
			if ( preg_match( $re, $code ) ) {
				$hits[] = $name;
			}
		}
		return $hits;
	}

	/* ------------------------------------------------------------------ */
	/* Integrität                                                          */
	/* ------------------------------------------------------------------ */

	/** Vergleich mit den Original-Prüfsummen. Rückgabe: version, checked, status (ok|modified|unavailable), modified, extra, missing. */
	public static function integrity( $unit, $version, array $files ) {
		$res = [ 'version' => $version, 'checked' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'unavailable', 'modified' => [], 'extra' => [], 'missing' => 0, 'source' => null ];
		$sums = 'core' === $unit ? self::core_checksums( $version ) : self::plugin_checksums( substr( $unit, 7 ), $version );
		if ( ! $sums ) {
			return $res;
		}
		$res['source'] = 'core' === $unit ? 'api.wordpress.org' : 'downloads.wordpress.org';
		$base = 'core' === $unit ? rtrim( ABSPATH, '/' ) : WP_PLUGIN_DIR . '/' . substr( $unit, 7 );
		foreach ( $sums as $rel => $md5s ) {
			if ( 'core' === $unit && 0 === strpos( $rel, 'wp-content/' ) ) {
				continue;
			}
			$abs = $base . '/' . $rel;
			if ( ! is_file( $abs ) ) {
				$res['missing']++;
				continue;
			}
			if ( ! in_array( md5_file( $abs ), (array) $md5s, true ) ) {
				$res['modified'][] = $rel;
			}
		}
		foreach ( array_keys( $files ) as $rel ) {
			if ( ! isset( $sums[ $rel ] ) && self::is_php( $rel ) && ! ( 'core' === $unit && in_array( $rel, [ 'wp-config.php' ], true ) ) ) {
				$res['extra'][] = $rel;
			}
		}
		$res['status']   = $res['modified'] || $res['extra'] ? 'modified' : 'ok';
		$res['modified'] = array_slice( $res['modified'], 0, 50 );
		$res['extra']    = array_slice( $res['extra'], 0, 50 );
		return $res;
	}

	/** [ Pfad => md5 ] für die Core-Version (offizielle API von wordpress.org). */
	private static function core_checksums( $version ) {
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$sums   = get_core_checksums( $version, $locale );
		if ( ! $sums && 'en_US' !== $locale ) {
			$sums = get_core_checksums( $version, 'en_US' );
		}
		return is_array( $sums ) ? $sums : null;
	}

	/** [ Pfad => md5|[md5…] ] für ein Plugin aus dem wordpress.org-Verzeichnis (nicht verfügbar → null). */
	private static function plugin_checksums( $slug, $version ) {
		if ( '' === $version || ! preg_match( '/^[a-z0-9_.-]+$/i', $slug ) ) {
			return null;
		}
		$key    = 'bw_bridge_sums_' . md5( $slug . '|' . $version );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		$r    = wp_safe_remote_get( 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json', [ 'timeout' => 10 ] );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$sums = [];
		if ( 200 === $code ) {
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			foreach ( (array) ( $data['files'] ?? [] ) as $rel => $f ) {
				if ( isset( $f['md5'] ) ) {
					$sums[ $rel ] = $f['md5'];
				}
			}
		}
		if ( $code ) {
			set_transient( $key, $sums, 200 === $code ? 30 * DAY_IN_SECONDS : 7 * DAY_IN_SECONDS ); // 404 = nicht im Verzeichnis
		}
		return $sums ?: null;
	}

	private static function log_integrity( $unit, $label, array $res, $prev ) {
		$sig  = md5( wp_json_encode( [ $res['status'], $res['modified'], $res['extra'] ] ) );
		$psig = $prev ? md5( wp_json_encode( [ $prev['status'] ?? '', $prev['modified'] ?? [], $prev['extra'] ?? [] ] ) ) : null;
		if ( $sig === $psig || ( 'modified' !== $res['status'] && ( ! $prev || 'modified' !== ( $prev['status'] ?? '' ) ) ) ) {
			return; // nur Wechsel protokollieren
		}
		$bad = 'modified' === $res['status'];
		BW_Bridge_Log::add( [
			'area' => 'security', 'category' => 'integrity', 'action' => $bad ? 'modified' : 'restored', 'level' => $bad ? 'warning' : 'info',
			'object_type' => 'files', 'object_id' => $unit, 'object_name' => $label, 'user_id' => 0,
			'message' => $bad
				? sprintf( '%s %s weicht vom Original ab: %d geänderte, %d zusätzliche PHP-Datei(en)', $label, $res['version'], count( $res['modified'] ), count( $res['extra'] ) )
				: sprintf( '%s %s stimmt wieder mit dem Original überein', $label, $res['version'] ),
			'details' => [ 'modified' => $res['modified'], 'extra' => $res['extra'], 'source' => $res['source'] ],
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Schwachstellen                                                      */
	/* ------------------------------------------------------------------ */

	/** Zu prüfende Komponenten: [ type, slug, name, version ]. */
	public static function vuln_targets() {
		global $wp_version;
		$t    = [ [ 'core', (string) $wp_version, 'WordPress', (string) $wp_version ] ];
		$seen = [];
		foreach ( self::plugins() as $file => $d ) {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$t[]           = [ 'plugin', $slug, $d['Name'], (string) $d['Version'] ];
		}
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $slug => $th ) {
				$t[] = [ 'theme', (string) $slug, (string) $th->get( 'Name' ), (string) $th->get( 'Version' ) ];
			}
		}
		return $t;
	}

	/** Arbeitet die Komponenten ab (mit Zwischenstand im Zustand). Rückgabe: true, wenn fertig. */
	private static function process_vulns( array &$s, $t0, $budget ) {
		$targets = self::vuln_targets();
		for ( $i = (int) ( $s['vuln_pos'] ?? 0 ); $i < count( $targets ); $i++ ) {
			if ( microtime( true ) - $t0 >= $budget ) {
				$s['vuln_pos'] = $i;
				return false;
			}
			[ $type, $slug, $name, $version ] = $targets[ $i ];
			$data = self::vuln_fetch( $type, $slug );
			if ( null === $data ) {
				$s['vuln_errors'][] = $type . ':' . $slug;
				continue;
			}
			foreach ( self::vuln_parse( $type, $slug, $name, $version, $data ) as $v ) {
				$s['vuln_items'][] = $v;
			}
		}
		self::finish_vulns( $s['vuln_items'] ?? [], $s['vuln_errors'] ?? [], count( $targets ) );
		return true;
	}

	/** Antwort der API (12 h zwischengespeichert). null bei Fehler. */
	private static function vuln_fetch( $type, $slug ) {
		$key    = 'bw_bridge_vuln_' . md5( $type . '|' . $slug );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$r = wp_safe_remote_get( self::VULN_API . $type . '/' . rawurlencode( $slug ) . '/', [ 'timeout' => 8, 'user-agent' => 'BW-WP-Bridge/' . BW_WP_Bridge::VERSION ] );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Treffer für eine installierte Version aus der API-Antwort (Format von www.wpvulnerability.net). */
	public static function vuln_parse( $type, $slug, $name, $version, array $resp ) {
		$out = [];
		if ( ! empty( $resp['error'] ) || empty( $resp['data'] ) ) {
			return $out;
		}
		$data = $resp['data'];
		if ( 'plugin' === $type && ! empty( $data['closed'] ) ) {
			$out[] = [ 'type' => $type, 'slug' => $slug, 'name' => $name, 'version' => $version, 'id' => 'closed', 'title' => 'Plugin wurde im wordpress.org-Verzeichnis geschlossen', 'severity' => 'm', 'score' => null, 'fixed_in' => null, 'unfixed' => true, 'link' => 'https://wordpress.org/plugins/' . $slug . '/' ];
		}
		foreach ( (array) ( $data['vulnerability'] ?? [] ) as $v ) {
			$op = (array) ( $v['operator'] ?? [] );
			if ( 'core' !== $type && ! self::applies( $op, $version ) ) {
				continue;
			}
			$src = (array) ( $v['source'][0] ?? [] );
			$out[] = [
				'type'     => $type,
				'slug'     => $slug,
				'name'     => $name,
				'version'  => $version,
				'id'       => (string) ( $src['id'] ?? md5( (string) ( $v['name'] ?? '' ) ) ),
				'title'    => wp_strip_all_tags( (string) ( $v['name'] ?? '' ) ),
				'severity' => strtolower( (string) ( $v['impact']['cvss']['severity'] ?? '' ) ) ?: null,
				'score'    => isset( $v['impact']['cvss']['score'] ) ? (float) $v['impact']['cvss']['score'] : null,
				'fixed_in' => ( $op['max_operator'] ?? '' ) === 'lt' && ! empty( $op['max_version'] ) ? (string) $op['max_version'] : null,
				'unfixed'  => ! empty( $op['unfixed'] ),
				'link'     => isset( $src['link'] ) ? (string) $src['link'] : null,
			];
		}
		return $out;
	}

	/** Gilt eine Schwachstelle für die Version? (Operatoren wie version_compare: lt, le, gt, ge, eq) */
	public static function applies( array $op, $version ) {
		if ( '' === (string) $version ) {
			return false;
		}
		$ok = false;
		if ( ! empty( $op['min_operator'] ) && isset( $op['min_version'] ) ) {
			if ( ! version_compare( $version, (string) $op['min_version'], (string) $op['min_operator'] ) ) {
				return false;
			}
			$ok = true;
		}
		if ( ! empty( $op['max_operator'] ) && isset( $op['max_version'] ) ) {
			if ( ! version_compare( $version, (string) $op['max_version'], (string) $op['max_operator'] ) ) {
				return false;
			}
			$ok = true;
		}
		return $ok;
	}

	/** Ergebnis speichern; neue und behobene Schwachstellen protokollieren. */
	public static function finish_vulns( array $items, array $errors, $targets ) {
		$prev = get_option( self::OPT_VULNS, [] );
		$key  = static fn( $v ) => $v['type'] . ':' . $v['slug'] . ':' . $v['id'];
		$old  = [];
		foreach ( (array) ( $prev['items'] ?? [] ) as $v ) {
			$old[ $key( $v ) ] = $v;
		}
		$new = [];
		foreach ( $items as $v ) {
			$new[ $key( $v ) ] = $v;
		}
		$first = ! isset( $prev['checked'] );
		foreach ( array_diff_key( $new, $old ) as $v ) {
			$sev = self::severity_label( $v['severity'] );
			BW_Bridge_Log::add( [
				'area' => 'security', 'category' => 'vuln', 'action' => 'found', 'level' => in_array( $v['severity'], [ 'h', 'c' ], true ) || $v['unfixed'] ? 'warning' : 'notice', 'user_id' => 0,
				'object_type' => $v['type'], 'object_id' => $v['slug'], 'object_name' => $v['name'],
				'message' => sprintf( '%sSchwachstelle in %s %s: %s (%s)%s', $first ? 'Bekannte ' : 'Neue ', $v['name'], $v['version'], $v['title'], $sev, $v['fixed_in'] ? ', behoben in ' . $v['fixed_in'] : ( $v['unfixed'] ? ', noch nicht behoben' : '' ) ),
				'details' => $v,
			] );
		}
		foreach ( array_diff_key( $old, $new ) as $v ) {
			BW_Bridge_Log::add( [
				'area' => 'security', 'category' => 'vuln', 'action' => 'resolved', 'user_id' => 0,
				'object_type' => $v['type'], 'object_id' => $v['slug'], 'object_name' => $v['name'],
				'message' => sprintf( 'Schwachstelle nicht mehr vorhanden: %s – %s', $v['name'], $v['title'] ),
				'details' => $v,
			] );
		}
		usort( $items, static fn( $a, $b ) => self::severity_rank( $b['severity'] ) <=> self::severity_rank( $a['severity'] ) );
		update_option( self::OPT_VULNS, [ 'checked' => gmdate( 'Y-m-d H:i:s' ), 'items' => array_values( $items ), 'errors' => array_values( array_unique( $errors ) ), 'targets' => $targets, 'source' => 'wpvulnerability.net' ], false );
	}

	public static function severity_rank( $s ) {
		return [ 'c' => 4, 'h' => 3, 'm' => 2, 'l' => 1 ][ $s ] ?? 0;
	}

	public static function severity_label( $s ) {
		return [ 'c' => 'kritisch', 'h' => 'hoch', 'm' => 'mittel', 'l' => 'niedrig', 'n' => 'keine' ][ (string) $s ] ?? 'unbekannt';
	}

	/* ------------------------------------------------------------------ */
	/* Auswertung                                                          */
	/* ------------------------------------------------------------------ */

	/** Zustand für status, Prüfung und Bericht. */
	public static function status() {
		$s      = self::state();
		$vulns  = get_option( self::OPT_VULNS, [] );
		$issues = [];
		$files  = 0;
		foreach ( $s['meta'] as $unit => $m ) {
			$files += (int) ( $m['files'] ?? 0 );
			if ( 'modified' === ( $m['integrity']['status'] ?? '' ) ) {
				$issues[] = [ 'unit' => $unit, 'label' => $m['label'] ?? $unit ] + $m['integrity'];
			}
		}
		$verified = count( array_filter( $s['meta'], static fn( $m ) => 'ok' === ( $m['integrity']['status'] ?? '' ) ) );
		return [
			'enabled'   => [ 'files' => self::files_enabled(), 'vulns' => self::vulns_enabled() ],
			'running'   => (bool) $s['queue'],
			'pending'   => count( $s['queue'] ),
			'started'   => $s['started'],
			'finished'  => $s['finished'],
			'units'     => count( $s['meta'] ),
			'files'     => $files,
			'integrity' => [ 'verified' => $verified, 'issues' => $issues ],
			'vulns'     => [
				'checked' => $vulns['checked'] ?? null,
				'items'   => $vulns['items'] ?? [],
				'errors'  => $vulns['errors'] ?? [],
				'counts'  => self::vuln_counts( $vulns['items'] ?? [] ),
				'source'  => 'wpvulnerability.net',
			],
		];
	}

	public static function vuln_counts( array $items ) {
		$c = [ 'c' => 0, 'h' => 0, 'm' => 0, 'l' => 0, 'other' => 0 ];
		foreach ( $items as $v ) {
			$c[ isset( $c[ $v['severity'] ] ) ? $v['severity'] : 'other' ]++;
		}
		return $c;
	}

	public static function status_route() {
		return rest_ensure_response( self::status() );
	}

	public static function start_route() {
		if ( ! self::files_enabled() && ! self::vulns_enabled() ) {
			return new WP_Error( 'bw_bridge_scan_off', 'Datei- und Schwachstellen-Scan sind abgeschaltet (Einstellungen › BW WP Bridge).', [ 'status' => 409 ] );
		}
		return rest_ensure_response( self::start( true ) );
	}
}

/** Datei-Liste in der Tabelle {prefix}bw_bridge_files. */
final class BW_Bridge_Scan_Db_Store {

	public function load( $unit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT path, size, mtime, sha1 FROM ' . BW_Bridge_Scan::table() . ' WHERE unit = %s', $unit ), ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$out[ $r['path'] ] = $r;
		}
		return $out;
	}

	public function save( $unit, array $upserts, array $deleted ) {
		global $wpdb;
		$t = BW_Bridge_Scan::table();
		foreach ( array_chunk( $upserts, 200, true ) as $chunk ) {
			$vals = [];
			$args = [];
			foreach ( $chunk as $path => $r ) {
				$vals[] = '(%s,%s,%s,%d,%d,%s)';
				array_push( $args, md5( $unit . '|' . $path ), $unit, $path, $r['size'], $r['mtime'], $r['sha1'] );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO $t (path_key, unit, path, size, mtime, sha1) VALUES " . implode( ',', $vals ) . ' ON DUPLICATE KEY UPDATE size = VALUES(size), mtime = VALUES(mtime), sha1 = VALUES(sha1)', $args ) );
		}
		foreach ( array_chunk( $deleted, 200 ) as $chunk ) {
			$keys = array_map( static fn( $p ) => md5( $unit . '|' . $p ), $chunk );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE path_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ) );
		}
	}
}
