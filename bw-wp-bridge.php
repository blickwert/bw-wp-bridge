<?php
/**
 * Plugin Name: BW WP Bridge
 * Description: Erweitert die WordPress-REST-API um Elementor-Layouts, Elementor-Kit (Global Colors/Fonts), Vorlagen-Import, CPT- und Taxonomie-Definitionen sowie (optional, im Backend freizuschalten) das Lesen und Schreiben von Theme-Dateien – für die Arbeit mit Claude Code auf Dev-/Staging-Servern.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: blickwert
 * License: GPL-2.0-or-later
 *
 * Zugriff nur für Administratoren (Capability manage_options), Authentifizierung
 * über WordPress-Anwendungspasswörter. Abschalten: define( 'BW_WP_BRIDGE_DISABLED', true );
 * Theme-Dateizugriff ist standardmäßig aus (Einstellungen › BW WP Bridge); hart abschalten:
 * define( 'BW_WP_BRIDGE_FILES_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BW_WP_BRIDGE_DISABLED' ) && BW_WP_BRIDGE_DISABLED ) {
	return;
}

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

	const OPT_READ   = 'bw_bridge_files_read';
	const OPT_WRITE  = 'bw_bridge_files_write';
	const OPT_PARENT = 'bw_bridge_files_parent';
	const PAGE       = 'bw-wp-bridge';
	const GROUP      = 'bw_bridge_files';
	const MAX_READ   = 2097152; // 2 MB
	const MAX_WRITE  = 1048576; // 1 MB
	const MAX_LIST   = 3000;
	const WRITE_EXT  = [ 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'po', 'pot', 'mo', 'svg', 'xml', 'twig' ];
	const SKIP_DIRS  = [ '.git', '.svn', 'node_modules', 'vendor' ];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen                                                       */
	/* ------------------------------------------------------------------ */

	public static function hard_disabled() {
		return defined( 'BW_WP_BRIDGE_FILES_DISABLED' ) && BW_WP_BRIDGE_FILES_DISABLED;
	}

	public static function can_read_enabled() {
		return ! self::hard_disabled() && (bool) get_option( self::OPT_READ, 0 );
	}

	public static function can_write_enabled() {
		return self::can_read_enabled() && (bool) get_option( self::OPT_WRITE, 0 );
	}

	public static function parent_enabled() {
		return (bool) get_option( self::OPT_PARENT, 0 );
	}

	/** Schreiben ist durch die wp-config.php gesperrt (DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS). */
	public static function config_blocks_writing() {
		return ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}

	public static function summary() {
		$child  = get_stylesheet();
		$parent = get_template();
		return [
			'read'              => self::can_read_enabled(),
			'write'             => self::can_write_enabled(),
			'parent_theme'      => self::parent_enabled() && $child !== $parent,
			'config_blocks_write' => self::config_blocks_writing(),
			'hard_disabled'     => self::hard_disabled(),
			'theme'             => $child,
			'parent'            => $child !== $parent ? $parent : null,
		];
	}

	public static function register_settings() {
		$bool = static function ( $v ) {
			return $v ? 1 : 0;
		};
		foreach ( [ self::OPT_READ, self::OPT_WRITE, self::OPT_PARENT ] as $opt ) {
			register_setting( self::GROUP, $opt, [ 'type' => 'boolean', 'sanitize_callback' => $bool, 'default' => 0, 'show_in_rest' => false ] );
		}
	}

	public static function admin_menu() {
		add_options_page( 'BW WP Bridge', 'BW WP Bridge', 'manage_options', self::PAGE, [ __CLASS__, 'render_page' ] );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$child  = wp_get_theme();
		$parent = $child->parent();
		echo '<div class="wrap"><h1>BW WP Bridge</h1>';
		echo '<p>Hier schaltest du den Zugriff auf Theme-Dateien über die REST-API frei. Gedacht für Dev-/Staging-Server. Standardmäßig ist alles aus.</p>';
		if ( self::hard_disabled() ) {
			echo '<div class="notice notice-warning inline"><p>In der wp-config.php steht <code>BW_WP_BRIDGE_FILES_DISABLED</code>. Der Dateizugriff ist fest abgeschaltet.</p></div>';
		}
		if ( self::config_blocks_writing() ) {
			echo '<div class="notice notice-warning inline"><p>Die wp-config.php sperrt Dateiänderungen (<code>DISALLOW_FILE_EDIT</code> / <code>DISALLOW_FILE_MODS</code>). Schreiben ist deshalb nicht möglich, Lesen schon.</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( self::OPT_READ, 'Theme-Dateien lesen', 'Dateien und Ordner des aktiven Themes auflisten und lesen.' );
		self::checkbox_row( self::OPT_WRITE, 'Theme-Dateien schreiben', 'Dateien anlegen, ändern und löschen. Vor jeder Änderung wird eine Sicherung unter <code>wp-content/uploads/bw-bridge-backups-…/</code> (Ordnername nicht erratbar) angelegt, PHP-Dateien werden auf Syntaxfehler geprüft. Setzt „lesen“ voraus und braucht zusätzlich das Recht <code>edit_themes</code>.' );
		if ( $parent && $parent->exists() ) {
			self::checkbox_row( self::OPT_PARENT, 'Parent-Theme einbeziehen', 'Zusätzlich das Parent-Theme „' . esc_html( $parent->get( 'Name' ) ) . '“ (Ordner <code>' . esc_html( $parent->get_stylesheet() ) . '</code>) freigeben.' );
		}
		echo '</tbody></table>';
		echo '<p class="description">Aktives Theme: <strong>' . esc_html( $child->get( 'Name' ) ) . '</strong> (Ordner <code>' . esc_html( $child->get_stylesheet() ) . '</code>)</p>';
		submit_button();
		echo '</form>';
		echo '<p class="description">Die Freigabe lässt sich nur hier ändern, nicht über die API. Wer das Anwendungspasswort hat, kann mit „schreiben“ PHP-Code im Theme ändern – nur auf Dev-/Staging-Servern einschalten und nach der Arbeit wieder ausschalten.</p></div>';
	}

	private static function checkbox_row( $opt, $label, $help ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s" value="1" %3$s> aktivieren</label><p class="description">%4$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $opt ),
			checked( (bool) get_option( $opt, 0 ), true, false ),
			$help // enthält bewusst nur eigene, feste HTML-Texte
		);
	}

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
		if ( ! self::can_read_enabled() ) {
			return new WP_Error( 'bw_bridge_files_disabled', 'Theme-Dateizugriff ist aus. Im Backend unter Einstellungen › BW WP Bridge „Theme-Dateien lesen“ aktivieren.', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function perm_write() {
		$read = self::perm_read();
		if ( true !== $read ) {
			return $read;
		}
		if ( ! self::can_write_enabled() ) {
			return new WP_Error( 'bw_bridge_files_readonly', 'Schreiben ist aus. Im Backend unter Einstellungen › BW WP Bridge „Theme-Dateien schreiben“ aktivieren.', [ 'status' => 403 ] );
		}
		if ( self::config_blocks_writing() || ! current_user_can( 'edit_themes' ) ) {
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
			if ( ! self::parent_enabled() ) {
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

final class BW_WP_Bridge {

	const VERSION        = '1.1.0';
	const NS             = 'bw-bridge/v1';
	const OPT_POST_TYPES = 'bw_bridge_post_types';
	const OPT_TAXONOMIES = 'bw_bridge_taxonomies';
	const OPT_FLUSH      = 'bw_bridge_flush_rewrite';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_content_types' ], 5 );
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite' ], 999 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		BW_Bridge_Theme_Files::init();
	}

	/* ------------------------------------------------------------------ */
	/* CPTs & Taxonomien aus Optionen registrieren                         */
	/* ------------------------------------------------------------------ */

	public static function register_content_types() {
		foreach ( (array) get_option( self::OPT_POST_TYPES, [] ) as $slug => $args ) {
			if ( ! post_type_exists( $slug ) ) {
				register_post_type( $slug, self::post_type_args( $args ) );
			}
		}
		foreach ( (array) get_option( self::OPT_TAXONOMIES, [] ) as $slug => $def ) {
			if ( ! taxonomy_exists( $slug ) ) {
				$object_types = isset( $def['object_types'] ) ? (array) $def['object_types'] : [];
				register_taxonomy( $slug, $object_types, self::taxonomy_args( $def['args'] ?? [] ) );
			}
		}
	}

	private static function post_type_args( array $args ) {
		return array_merge( [
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => true,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
		], $args );
	}

	private static function taxonomy_args( array $args ) {
		return array_merge( [
			'public'            => true,
			'show_in_rest'      => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
		], $args );
	}

	public static function maybe_flush_rewrite() {
		if ( get_option( self::OPT_FLUSH ) ) {
			delete_option( self::OPT_FLUSH );
			flush_rewrite_rules( false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* REST-Routen                                                         */
	/* ------------------------------------------------------------------ */

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

	public static function register_routes() {
		$admin = [ __CLASS__, 'can_manage' ];

		register_rest_route( self::NS, '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'status' ],
			'permission_callback' => $admin,
		] );

		// Öffentlich, gibt nur Ja/Nein-Werte aus: hilft, wenn die Anmeldung nicht ankommt.
		register_rest_route( self::NS, '/auth-check', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'auth_check' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NS, '/elementor/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_elementor' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'save_elementor' ],
				'permission_callback' => [ __CLASS__, 'can_edit_post' ],
			],
		] );

		register_rest_route( self::NS, '/elementor/kit', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_kit' ], 'permission_callback' => $admin ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_kit' ], 'permission_callback' => $admin ],
		] );

		register_rest_route( self::NS, '/elementor/templates', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_template' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::NS, '/elementor/clear-cache', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'clear_cache' ],
			'permission_callback' => $admin,
		] );

		foreach ( [ 'post-types' => self::OPT_POST_TYPES, 'taxonomies' => self::OPT_TAXONOMIES ] as $route => $option ) {
			register_rest_route( self::NS, '/' . $route, [
				'methods'             => 'GET',
				'callback'            => function () use ( $option ) {
					return rest_ensure_response( (object) get_option( $option, [] ) );
				},
				'permission_callback' => $admin,
			] );
			register_rest_route( self::NS, '/' . $route . '/(?P<slug>[a-z0-9_-]{1,32})', [
				[
					'methods'             => 'POST',
					'callback'            => function ( WP_REST_Request $r ) use ( $option ) {
						return self::save_definition( $option, $r );
					},
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => function ( WP_REST_Request $r ) use ( $option ) {
						return self::delete_definition( $option, $r );
					},
					'permission_callback' => $admin,
				],
			] );
		}
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function can_edit_post( WP_REST_Request $r ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $r['id'] );
	}

	private static function elementor() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'bw_bridge_no_elementor', 'Elementor ist nicht aktiv.', [ 'status' => 409 ] );
		}
		return \Elementor\Plugin::$instance;
	}

	public static function status() {
		$el = self::elementor();
		return rest_ensure_response( [
			'bridge'        => self::VERSION,
			'wordpress'     => get_bloginfo( 'version' ),
			'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'active_kit'    => is_wp_error( $el ) ? null : (int) $el->kits_manager->get_active_id(),
			'theme'         => get_stylesheet(),
			'theme_files'   => BW_Bridge_Theme_Files::summary(),
			'post_types'    => array_keys( (array) get_option( self::OPT_POST_TYPES, [] ) ),
			'taxonomies'    => array_keys( (array) get_option( self::OPT_TAXONOMIES, [] ) ),
		] );
	}

	/* ---- Elementor-Layout einer Seite / eines Beitrags / einer Vorlage ---- */

	public static function get_elementor( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		$data = get_post_meta( $id, '_elementor_data', true );
		return rest_ensure_response( [
			'id'            => $id,
			'post_type'     => $post->post_type,
			'title'         => $post->post_title,
			'edit_mode'     => get_post_meta( $id, '_elementor_edit_mode', true ),
			'template_type' => get_post_meta( $id, '_elementor_template_type', true ),
			'page_template' => get_page_template_slug( $id ),
			'settings'      => (object) ( get_post_meta( $id, '_elementor_page_settings', true ) ?: [] ),
			'elements'      => $data ? json_decode( is_string( $data ) ? $data : wp_json_encode( $data ), true ) : [],
		] );
	}

	/**
	 * Body: { "elements": [...], "settings": {...}, "template_type": "wp-page" }
	 * "settings" wird gemerged (z. B. { "template": "elementor_canvas", "hide_title": "yes" }).
	 */
	public static function save_elementor( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$id   = (int) $r['id'];
		$body = $r->get_json_params();
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'bw_bridge_not_found', 'Beitrag nicht gefunden.', [ 'status' => 404 ] );
		}
		if ( ! isset( $body['elements'] ) || ! is_array( $body['elements'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"elements" (Array) fehlt.', [ 'status' => 400 ] );
		}
		if ( ! empty( $body['template_type'] ) ) {
			update_post_meta( $id, '_elementor_template_type', sanitize_key( $body['template_type'] ) );
		}

		$document = $el->documents->get( $id, false );
		if ( ! $document ) {
			return new WP_Error( 'bw_bridge_document', 'Elementor-Dokument konnte nicht geladen werden.', [ 'status' => 500 ] );
		}
		$document->set_is_built_with_elementor( true );

		$settings = (array) ( get_post_meta( $id, '_elementor_page_settings', true ) ?: [] );
		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$settings = array_merge( $settings, $body['settings'] );
		}

		$saved = $document->save( [
			'elements' => $body['elements'],
			'settings' => $settings,
		] );
		if ( ! $saved ) {
			return new WP_Error( 'bw_bridge_save', 'Speichern fehlgeschlagen.', [ 'status' => 500 ] );
		}
		return self::get_elementor( $r );
	}

	/* ---- Kit: Global Colors, Global Fonts, Theme Style, Layout ---- */

	public static function get_kit() {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$kit_id = (int) $el->kits_manager->get_active_id();
		return rest_ensure_response( [
			'id'       => $kit_id,
			'settings' => (object) ( get_post_meta( $kit_id, '_elementor_page_settings', true ) ?: [] ),
		] );
	}

	/** Body: { "settings": {...}, "replace": false } – standardmäßig wird gemerged. */
	public static function save_kit( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$body = $r->get_json_params();
		if ( ! isset( $body['settings'] ) || ! is_array( $body['settings'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"settings" (Objekt) fehlt.', [ 'status' => 400 ] );
		}
		$kit_id   = (int) $el->kits_manager->get_active_id();
		$current  = (array) ( get_post_meta( $kit_id, '_elementor_page_settings', true ) ?: [] );
		$settings = empty( $body['replace'] ) ? array_merge( $current, $body['settings'] ) : $body['settings'];
		$kit      = $el->documents->get( $kit_id, false );
		$kit->save( [ 'settings' => $settings ] );
		$el->files_manager->clear_cache();
		return self::get_kit();
	}

	/* ---- Vorlage (Elementor-Export-JSON) in die Vorlagen-Bibliothek importieren ---- */

	/** Body: das JSON einer Elementor-Vorlage ({ "title", "type", "content", "page_settings" }). */
	public static function import_template( WP_REST_Request $r ) {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$body = $r->get_json_params();
		if ( empty( $body['content'] ) || empty( $body['type'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', 'Vorlagen-JSON mit "type" und "content" erwartet.', [ 'status' => 400 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'bw-bridge-template' );
		file_put_contents( $tmp, wp_json_encode( $body ) );
		// Der Name (nicht der Pfad) entscheidet bei Elementor über JSON vs. ZIP.
		$result = $el->templates_manager->get_source( 'local' )->import_template( 'template.json', $tmp );
		@unlink( $tmp );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function clear_cache() {
		$el = self::elementor();
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$el->files_manager->clear_cache();
		return rest_ensure_response( [ 'cleared' => true ] );
	}

	/* ---- CPT-/Taxonomie-Definitionen ---- */

	/**
	 * post-types/{slug}: Body = register_post_type()-Argumente.
	 * taxonomies/{slug}: Body = { "object_types": ["post", "event"], "args": {...} }.
	 */
	private static function save_definition( $option, WP_REST_Request $r ) {
		$slug = sanitize_key( $r['slug'] );
		$body = $r->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'bw_bridge_invalid', 'JSON-Objekt erwartet.', [ 'status' => 400 ] );
		}
		if ( self::OPT_POST_TYPES === $option && strlen( $slug ) > 20 ) {
			return new WP_Error( 'bw_bridge_invalid', 'Post-Type-Slug darf höchstens 20 Zeichen haben.', [ 'status' => 400 ] );
		}
		$defs          = (array) get_option( $option, [] );
		$defs[ $slug ] = $body;
		update_option( $option, $defs, true );
		update_option( self::OPT_FLUSH, 1, true );
		return rest_ensure_response( [ 'slug' => $slug, 'definition' => $body ] );
	}

	private static function delete_definition( $option, WP_REST_Request $r ) {
		$slug = sanitize_key( $r['slug'] );
		$defs = (array) get_option( $option, [] );
		$had  = isset( $defs[ $slug ] );
		unset( $defs[ $slug ] );
		update_option( $option, $defs, true );
		update_option( self::OPT_FLUSH, 1, true );
		return rest_ensure_response( [ 'slug' => $slug, 'deleted' => $had ] );
	}
}

BW_WP_Bridge::init();
