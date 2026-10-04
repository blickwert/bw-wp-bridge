<?php
defined( 'ABSPATH' ) || exit;

/**
 * Theme-Dateien lesen und schreiben (optional, standardmäßig AUS).
 *
 * Freischaltung nur im Backend: Einstellungen › BW WP Bridge (Option "Theme-Dateien lesen",
 * "… schreiben", "Parent-Theme einbeziehen"). Die Optionen sind nicht über die REST-API änderbar.
 * Zusätzlich gilt: Benutzer mit manage_options; Schreiben, Löschen und Wiederherstellen nur
 * mit edit_themes (fällt weg bei DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS in der wp-config.php).
 * Hart abschalten: define( 'BW_WP_BRIDGE_FILES_DISABLED', true );
 */
final class BW_Bridge_Theme_Files {

	const MAX_READ   = 2097152; // 2 MB
	const MAX_WRITE  = 1048576; // 1 MB
	const MAX_LIST   = 3000;
	const WRITE_EXT  = [ 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'po', 'pot', 'mo', 'svg', 'xml', 'twig' ];
	const SKIP_DIRS  = [ '.git', '.svn', 'node_modules', 'vendor' ];

	/* ------------------------------------------------------------------ */
	/* Routen                                                              */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		$args = [
			'theme' => [ 'type' => 'string', 'default' => 'child', 'enum' => [ 'child', 'parent' ] ],
			'path'  => [ 'type' => 'string', 'default' => '' ],
		];
		register_rest_route( BW_WP_Bridge::NS, '/theme/files', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get' ], 'permission_callback' => [ __CLASS__, 'perm_read' ], 'args' => $args ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'put' ], 'permission_callback' => [ __CLASS__, 'perm_write' ], 'args' => $args ],
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete' ], 'permission_callback' => [ __CLASS__, 'perm_write' ], 'args' => $args ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/theme/backups', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'backups' ], 'permission_callback' => [ __CLASS__, 'perm_read' ], 'args' => $args ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'restore' ], 'permission_callback' => [ __CLASS__, 'perm_write' ], 'args' => $args ],
		] );
	}

	public static function perm_read() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! BW_Bridge_Settings::can_read_enabled() ) {
			return new WP_Error( 'bw_bridge_files_disabled', 'Theme-Dateizugriff ist aus. Im Backend unter Einstellungen › BW WP Bridge „Theme-Dateien lesen“ aktivieren.', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function perm_write() {
		$read = self::perm_read();
		if ( true !== $read ) {
			return $read;
		}
		if ( ! BW_Bridge_Settings::can_write_enabled() ) {
			return new WP_Error( 'bw_bridge_files_readonly', 'Schreiben ist aus. Im Backend unter Einstellungen › BW WP Bridge „Theme-Dateien schreiben“ aktivieren.', [ 'status' => 403 ] );
		}
		if ( BW_Bridge_Settings::config_blocks_writing() || ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error( 'bw_bridge_files_forbidden', 'Der Benutzer darf keine Theme-Dateien ändern (Recht edit_themes fehlt oder DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS ist gesetzt).', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Pfade                                                               */
	/* ------------------------------------------------------------------ */

	/** Wurzelverzeichnis des gewählten Themes (child = aktives Theme, parent = dessen Parent). */
	public static function root( $which ) {
		if ( 'parent' === $which ) {
			if ( ! BW_Bridge_Settings::parent_enabled() ) {
				return new WP_Error( 'bw_bridge_files_parent_off', 'Parent-Theme ist nicht freigegeben (Einstellungen › BW WP Bridge).', [ 'status' => 403 ] );
			}
			if ( get_template() === get_stylesheet() ) {
				return new WP_Error( 'bw_bridge_files_no_parent', 'Das aktive Theme hat kein Parent-Theme.', [ 'status' => 404 ] );
			}
			$dir  = get_template_directory();
			$slug = get_template();
		} else {
			$dir  = get_stylesheet_directory();
			$slug = get_stylesheet();
		}
		$real = realpath( $dir );
		if ( ! $real || ! is_dir( $real ) ) {
			return new WP_Error( 'bw_bridge_files_no_theme', 'Theme-Ordner nicht gefunden.', [ 'status' => 404 ] );
		}
		return [ 'dir' => rtrim( str_replace( '\\', '/', $real ), '/' ), 'slug' => $slug ];
	}

	/**
	 * Bereinigt einen relativen Pfad. Erlaubt: Buchstaben, Ziffern, _ - . / ; keine "..", keine
	 * versteckten Segmente (außer erlaubte Endungen), keine absoluten Pfade.
	 */
	public static function clean_rel( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( false !== strpos( $rel, "\0" ) ) {
			return new WP_Error( 'bw_bridge_files_path', 'Ungültiger Pfad.', [ 'status' => 400 ] );
		}
		$rel = trim( $rel, '/' );
		if ( '' === $rel ) {
			return '';
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '' === $seg || '.' === $seg[0] || ! preg_match( '/^[A-Za-z0-9 _\-\.\x{00C0}-\x{024F}]+$/u', $seg ) ) {
				return new WP_Error( 'bw_bridge_files_path', 'Ungültiger Pfad (nicht erlaubt: "..", versteckte Dateien, Sonderzeichen): ' . $rel, [ 'status' => 400 ] );
			}
			if ( in_array( $seg, self::SKIP_DIRS, true ) ) {
				return new WP_Error( 'bw_bridge_files_path', 'Dieser Ordner ist gesperrt: ' . $seg, [ 'status' => 403 ] );
			}
		}
		return $rel;
	}

	/** Absoluter Pfad innerhalb des Theme-Ordners; schützt gegen ".." und Symlinks nach außen. */
	public static function resolve( $root, $rel ) {
		$rel = self::clean_rel( $rel );
		if ( is_wp_error( $rel ) ) {
			return $rel;
		}
		$target = '' === $rel ? $root['dir'] : $root['dir'] . '/' . $rel;
		// nächstgelegenen existierenden Vorfahren auflösen
		$probe = $target;
		while ( ! file_exists( $probe ) && $probe !== $root['dir'] ) {
			$probe = dirname( $probe );
		}
		$real = realpath( $probe );
		$base = $root['dir'];
		if ( ! $real ) {
			return new WP_Error( 'bw_bridge_files_path', 'Pfad nicht auflösbar.', [ 'status' => 400 ] );
		}
		$real = str_replace( '\\', '/', $real );
		if ( $real !== $base && 0 !== strpos( $real . '/', $base . '/' ) ) {
			return new WP_Error( 'bw_bridge_files_path', 'Pfad liegt außerhalb des Theme-Ordners.', [ 'status' => 403 ] );
		}
		return [ 'abs' => $target, 'rel' => $rel ];
	}

	private static function ext( $rel ) {
		return strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lesen                                                               */
	/* ------------------------------------------------------------------ */

	public static function get( WP_REST_Request $r ) {
		$root = self::root( $r['theme'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$p = self::resolve( $root, $r['path'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ! file_exists( $p['abs'] ) ) {
			return new WP_Error( 'bw_bridge_files_not_found', 'Nicht gefunden: ' . $p['rel'], [ 'status' => 404 ] );
		}
		if ( is_dir( $p['abs'] ) ) {
			return self::listing( $root, $p, (bool) $r->get_param( 'recursive' ) );
		}
		$size = filesize( $p['abs'] );
		if ( $size > self::MAX_READ ) {
			return new WP_Error( 'bw_bridge_files_too_large', 'Datei ist größer als 2 MB.', [ 'status' => 413 ] );
		}
		$raw = file_get_contents( $p['abs'] );
		if ( false === $raw ) {
			return new WP_Error( 'bw_bridge_files_read', 'Datei nicht lesbar.', [ 'status' => 500 ] );
		}
		$utf8 = ( 1 === preg_match( '//u', $raw ) );
		return rest_ensure_response( [
			'theme'    => $root['slug'],
			'path'     => $p['rel'],
			'type'     => 'file',
			'size'     => $size,
			'modified' => gmdate( 'c', filemtime( $p['abs'] ) ),
			'sha1'     => sha1( $raw ),
			'encoding' => $utf8 ? 'utf8' : 'base64',
			'content'  => $utf8 ? $raw : base64_encode( $raw ),
		] );
	}

	private static function listing( $root, $p, $recursive ) {
		$entries = [];
		$walk    = static function ( $abs, $rel, $depth ) use ( &$walk, &$entries, $recursive ) {
			$names = scandir( $abs );
			if ( ! is_array( $names ) ) {
				return;
			}
			sort( $names );
			foreach ( $names as $n ) {
				if ( '.' === $n[0] || in_array( $n, self::SKIP_DIRS, true ) ) {
					continue;
				}
				if ( count( $entries ) >= self::MAX_LIST ) {
					return;
				}
				$child = $abs . '/' . $n;
				$crel  = '' === $rel ? $n : $rel . '/' . $n;
				$isdir = is_dir( $child );
				$entries[] = [
					'path'     => $crel,
					'type'     => $isdir ? 'dir' : 'file',
					'size'     => $isdir ? null : filesize( $child ),
					'modified' => gmdate( 'c', filemtime( $child ) ),
				];
				if ( $isdir && $recursive && $depth < 8 && ! is_link( $child ) ) {
					$walk( $child, $crel, $depth + 1 );
				}
			}
		};
		$walk( $p['abs'], $p['rel'], 0 );
		return rest_ensure_response( [
			'theme'     => $root['slug'],
			'path'      => $p['rel'],
			'type'      => 'dir',
			'truncated' => count( $entries ) >= self::MAX_LIST,
			'entries'   => $entries,
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Schreiben                                                           */
	/* ------------------------------------------------------------------ */

	public static function put( WP_REST_Request $r ) {
		$root = self::root( $r['theme'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$p = self::resolve( $root, $r['path'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( '' === $p['rel'] || is_dir( $p['abs'] ) ) {
			return new WP_Error( 'bw_bridge_files_path', 'Ein Dateipfad ist nötig.', [ 'status' => 400 ] );
		}
		if ( ! in_array( self::ext( $p['rel'] ), self::WRITE_EXT, true ) ) {
			return new WP_Error( 'bw_bridge_files_ext', 'Dateityp nicht erlaubt. Erlaubt: ' . implode( ', ', self::WRITE_EXT ), [ 'status' => 415 ] );
		}
		$body = $r->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['content'] ) || ! is_string( $body['content'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"content" (String) fehlt.', [ 'status' => 400 ] );
		}
		$encoding = isset( $body['encoding'] ) ? $body['encoding'] : 'utf8';
		if ( 'base64' === $encoding ) {
			$data = base64_decode( $body['content'], true );
			if ( false === $data ) {
				return new WP_Error( 'bw_bridge_invalid', 'Ungültiges Base64.', [ 'status' => 400 ] );
			}
		} elseif ( 'utf8' === $encoding ) {
			$data = $body['content'];
			if ( 1 !== preg_match( '//u', $data ) ) {
				return new WP_Error( 'bw_bridge_invalid', 'Inhalt ist kein gültiges UTF-8.', [ 'status' => 400 ] );
			}
		} else {
			return new WP_Error( 'bw_bridge_invalid', 'encoding: utf8 oder base64.', [ 'status' => 400 ] );
		}
		if ( strlen( $data ) > self::MAX_WRITE ) {
			return new WP_Error( 'bw_bridge_files_too_large', 'Inhalt ist größer als 1 MB.', [ 'status' => 413 ] );
		}
		$exists = is_file( $p['abs'] );
		if ( isset( $body['expected_sha1'] ) && $exists && sha1_file( $p['abs'] ) !== $body['expected_sha1'] ) {
			return new WP_Error( 'bw_bridge_files_conflict', 'Die Datei wurde seit dem Lesen geändert (sha1 stimmt nicht).', [ 'status' => 409 ] );
		}
		if ( 'php' === self::ext( $p['rel'] ) && empty( $body['skip_lint'] ) ) {
			$lint = self::lint_php( $data );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}
		$backup = $exists ? self::backup( $root, $p ) : null;
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$written = self::write_file( $p['abs'], $data );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		return rest_ensure_response( [
			'theme'   => $root['slug'],
			'path'    => $p['rel'],
			'created' => ! $exists,
			'size'    => strlen( $data ),
			'sha1'    => sha1( $data ),
			'backup'  => $backup,
		] );
	}

	public static function delete( WP_REST_Request $r ) {
		$root = self::root( $r['theme'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$p = self::resolve( $root, $r['path'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( '' === $p['rel'] || ! is_file( $p['abs'] ) ) {
			return new WP_Error( 'bw_bridge_files_not_found', 'Nur Dateien können gelöscht werden (nicht gefunden: ' . $p['rel'] . ').', [ 'status' => 404 ] );
		}
		$backup = self::backup( $root, $p );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		if ( ! unlink( $p['abs'] ) ) {
			return new WP_Error( 'bw_bridge_files_write', 'Löschen fehlgeschlagen.', [ 'status' => 500 ] );
		}
		self::invalidate( $p['abs'] );
		return rest_ensure_response( [ 'theme' => $root['slug'], 'path' => $p['rel'], 'deleted' => true, 'backup' => $backup ] );
	}

	/** Syntaxprüfung ohne Ausführung (PHP 7+: token_get_all mit TOKEN_PARSE). */
	public static function lint_php( $code ) {
		if ( ! defined( 'TOKEN_PARSE' ) ) {
			return true;
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error( 'bw_bridge_files_php_syntax', 'PHP-Syntaxfehler in Zeile ' . $e->getLine() . ': ' . $e->getMessage(), [ 'status' => 422 ] );
		}
		return true;
	}

	private static function write_file( $abs, $data ) {
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'bw_bridge_files_write', 'Ordner konnte nicht angelegt werden.', [ 'status' => 500 ] );
		}
		$tmp = $dir . '/.bw-bridge-' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $data, LOCK_EX ) ) {
			return new WP_Error( 'bw_bridge_files_write', 'Schreiben fehlgeschlagen (Dateirechte?).', [ 'status' => 500 ] );
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, $abs ) ) {
			@unlink( $tmp );
			return new WP_Error( 'bw_bridge_files_write', 'Datei konnte nicht ersetzt werden.', [ 'status' => 500 ] );
		}
		self::invalidate( $abs );
		return true;
	}

	private static function invalidate( $abs ) {
		if ( function_exists( 'opcache_invalidate' ) && 'php' === self::ext( $abs ) ) {
			@opcache_invalidate( $abs, true );
		}
		if ( function_exists( 'wp_get_theme' ) ) {
			wp_get_theme()->cache_delete();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sicherungen                                                         */
	/* ------------------------------------------------------------------ */

	private static function backup_dir( $root, $rel = null ) {
		$u = wp_upload_dir( null, false );
		if ( ! empty( $u['error'] ) ) {
			return new WP_Error( 'bw_bridge_files_backup', 'Upload-Ordner nicht verfügbar: ' . $u['error'], [ 'status' => 500 ] );
		}
		// Nicht erratbarer Ordnername: schützt auch dort, wo die .htaccess nicht greift (z. B. nginx).
		$base = rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' ) . '/bw-bridge-backups-' . substr( wp_hash( 'bw_bridge_backups' ), 0, 12 );
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			@file_put_contents( $base . '/index.html', '' );
			@file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		$dir = $base . '/' . $root['slug'];
		return null === $rel ? $dir : $dir . '/' . $rel;
	}

	/** Kopiert die bestehende Datei vor dem Überschreiben/Löschen; Rückgabe: Name der Sicherung. */
	private static function backup( $root, $p ) {
		$target = self::backup_dir( $root, $p['rel'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'bw_bridge_files_backup', 'Sicherungsordner konnte nicht angelegt werden.', [ 'status' => 500 ] );
		}
		$name = basename( $p['rel'] ) . '.' . gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 4, false ), 0, 4 ) . '.bak';
		if ( ! copy( $p['abs'], $dir . '/' . $name ) ) {
			return new WP_Error( 'bw_bridge_files_backup', 'Sicherung fehlgeschlagen, nichts wurde geändert.', [ 'status' => 500 ] );
		}
		return $name;
	}

	private static function backup_list( $root, $rel ) {
		$target = self::backup_dir( $root, $rel );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$dir = dirname( $target );
		$out = [];
		if ( is_dir( $dir ) ) {
			$prefix = basename( $rel ) . '.';
			foreach ( scandir( $dir ) as $n ) {
				if ( 0 === strpos( $n, $prefix ) && '.bak' === substr( $n, -4 ) ) {
					$out[] = $n;
				}
			}
			rsort( $out );
		}
		return $out;
	}

	public static function backups( WP_REST_Request $r ) {
		$root = self::root( $r['theme'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$p = self::resolve( $root, $r['path'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$list = self::backup_list( $root, $p['rel'] );
		return is_wp_error( $list ) ? $list : rest_ensure_response( [ 'theme' => $root['slug'], 'path' => $p['rel'], 'backups' => $list ] );
	}

	/** Stellt eine Sicherung wieder her (Body: {"backup": "name.bak"}; ohne Angabe die neueste). */
	public static function restore( WP_REST_Request $r ) {
		$root = self::root( $r['theme'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$p = self::resolve( $root, $r['path'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$list = self::backup_list( $root, $p['rel'] );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$body = (array) $r->get_json_params();
		$name = isset( $body['backup'] ) ? basename( (string) $body['backup'] ) : ( $list ? $list[0] : '' );
		if ( '' === $name || ! in_array( $name, $list, true ) ) {
			return new WP_Error( 'bw_bridge_files_not_found', 'Keine passende Sicherung gefunden.', [ 'status' => 404 ] );
		}
		$src  = dirname( self::backup_dir( $root, $p['rel'] ) ) . '/' . $name;
		$data = file_get_contents( $src );
		if ( false === $data ) {
			return new WP_Error( 'bw_bridge_files_read', 'Sicherung nicht lesbar.', [ 'status' => 500 ] );
		}
		$now = is_file( $p['abs'] ) ? self::backup( $root, $p ) : null;
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		$written = self::write_file( $p['abs'], $data );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		return rest_ensure_response( [ 'theme' => $root['slug'], 'path' => $p['rel'], 'restored' => $name, 'previous_backup' => $now ] );
	}
}
