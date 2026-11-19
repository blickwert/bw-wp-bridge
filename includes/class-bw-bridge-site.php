<?php
defined( 'ABSPATH' ) || exit;

/**
 * Website einrichten: Themes installieren, aktivieren und löschen, Permalinks setzen, Mailversand testen,
 * PHP-Fehlerprotokoll lesen. Alles nur für Administratoren (manage_options).
 *
 *  - Themes installieren/löschen: dieselbe Freigabe wie bei Plugins (Einstellungen › BW WP Bridge
 *    „Plugins und Themes installieren und aktualisieren“) plus install_themes bzw. delete_themes; fällt weg
 *    bei DISALLOW_FILE_MODS. Quellen wie bei Plugins: slug (wordpress.org), url (https-ZIP) oder zip_base64.
 *  - Theme aktivieren: switch_themes (wie im Backend, keine eigene Freigabe).
 *  - Permalinks: permalink_structure setzen und die Rewrite-Regeln neu aufbauen.
 *  - Testmail: wp_mail an eine Adresse (Standard: Administrator-Adresse), höchstens 10 pro Stunde.
 *  - Fehlerprotokoll: die letzten Zeilen aus debug.log (nur lesen, höchstens 500 Zeilen / 1 MB vom Ende).
 */
final class BW_Bridge_Site {

	const MAIL_LIMIT = 10; // Testmails pro Stunde
	const LOG_LINES  = 500;
	const LOG_BYTES  = 1048576;

	public static function register_routes() {
		$admin = [ __CLASS__, 'perm_admin' ];
		register_rest_route( BW_WP_Bridge::NS, '/themes', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_themes' ],
			'permission_callback' => $admin,
		] );
		register_rest_route( BW_WP_Bridge::NS, '/themes/install', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'install_theme' ],
			'permission_callback' => [ __CLASS__, 'perm_install' ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/themes/activate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'activate_theme' ],
			'permission_callback' => [ __CLASS__, 'perm_switch' ],
			'args'                => [ 'stylesheet' => [ 'type' => 'string', 'required' => true ] ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/themes/delete', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'delete_theme' ],
			'permission_callback' => [ __CLASS__, 'perm_delete' ],
			'args'                => [ 'stylesheet' => [ 'type' => 'string', 'required' => true ] ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/permalinks', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_permalinks' ],
				'permission_callback' => $admin,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'set_permalinks' ],
				'permission_callback' => $admin,
				'args'                => [ 'structure' => [ 'type' => 'string', 'required' => true ] ],
			],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/mail-test', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'mail_test' ],
			'permission_callback' => $admin,
			'args'                => [ 'to' => [ 'type' => 'string' ] ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/debug-log', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'debug_log' ],
			'permission_callback' => $admin,
			'args'                => [ 'lines' => [ 'type' => 'integer', 'default' => 100 ] ],
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Rechte                                                              */
	/* ------------------------------------------------------------------ */

	public static function perm_admin() {
		return current_user_can( 'manage_options' );
	}

	public static function perm_install() {
		return self::perm_files( 'install_themes' );
	}

	public static function perm_delete() {
		return self::perm_files( 'delete_themes' );
	}

	public static function perm_switch() {
		return current_user_can( 'manage_options' ) && current_user_can( 'switch_themes' );
	}

	private static function perm_files( $cap ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! BW_Bridge_Settings::can_install_plugins_enabled() ) {
			return new WP_Error( 'bw_bridge_themes_disabled', 'Theme-Installation ist aus. Im Backend unter Einstellungen › BW WP Bridge „Plugins und Themes installieren und aktualisieren“ aktivieren.', [ 'status' => 403 ] );
		}
		if ( BW_Bridge_Settings::config_blocks_plugins() || ! current_user_can( $cap ) ) {
			return new WP_Error( 'bw_bridge_themes_forbidden', 'Der Benutzer darf das nicht (Recht ' . $cap . ' fehlt oder DISALLOW_FILE_MODS ist gesetzt).', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Themes                                                              */
	/* ------------------------------------------------------------------ */

	public static function list_themes() {
		$active = get_stylesheet();
		$out    = [];
		$themes = wp_get_themes( [ 'errors' => null ] );
		foreach ( $themes as $theme ) {
			$out[] = self::describe_theme( $theme, $active );
		}
		// Aktives Theme, dessen Ordner fehlt (die Website zeigt dann nichts an).
		if ( ! isset( $themes[ $active ] ) ) {
			$out[] = [ 'stylesheet' => $active, 'name' => null, 'version' => null, 'active' => true, 'missing' => true, 'block_theme' => null, 'parent' => null, 'errors' => 'Theme-Ordner fehlt' ];
		}
		return rest_ensure_response( $out );
	}

	private static function describe_theme( $theme, $active ) {
		$err = $theme->errors();
		return [
			'stylesheet'  => $theme->get_stylesheet(),
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'active'      => $theme->get_stylesheet() === $active,
			'missing'     => false,
			'block_theme' => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : null,
			'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			'errors'      => is_wp_error( $err ) ? $err->get_error_message() : null,
		];
	}

	public static function install_theme( $request ) {
		$p        = self::params( $request, [ 'slug', 'url', 'zip_base64', 'activate', 'overwrite' ] );
		$sources  = array_filter( [ 'slug' => (string) ( $p['slug'] ?? '' ), 'url' => (string) ( $p['url'] ?? '' ), 'zip_base64' => (string) ( $p['zip_base64'] ?? '' ) ], 'strlen' );
		$activate = ! empty( $p['activate'] );
		$over     = ! empty( $p['overwrite'] );
		if ( 1 !== count( $sources ) ) {
			return new WP_Error( 'bw_bridge_themes_source', 'Genau eine Quelle angeben: slug, url oder zip_base64.', [ 'status' => 400 ] );
		}
		if ( $over && ! current_user_can( 'update_themes' ) ) {
			return new WP_Error( 'bw_bridge_themes_forbidden', 'Überschreiben braucht das Recht update_themes.', [ 'status' => 403 ] );
		}
		if ( $activate && ! current_user_can( 'switch_themes' ) ) {
			return new WP_Error( 'bw_bridge_themes_forbidden', 'Aktivieren braucht das Recht switch_themes.', [ 'status' => 403 ] );
		}
		BW_Bridge_Plugins::load_admin_files();
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$tmp = '';
		if ( isset( $sources['slug'] ) ) {
			$package = self::wporg_theme_package( $sources['slug'] );
		} elseif ( isset( $sources['url'] ) ) {
			$package = BW_Bridge_Plugins::download( $sources['url'] );
			$tmp     = is_wp_error( $package ) ? '' : $package;
		} else {
			$package = BW_Bridge_Plugins::store_base64( $sources['zip_base64'] );
			$tmp     = is_wp_error( $package ) ? '' : $package;
		}
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( '' !== $tmp ) {
			$check = BW_Bridge_Plugins::check_zip_file( $tmp );
			if ( is_wp_error( $check ) ) {
				wp_delete_file( $tmp );
				return $check;
			}
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package, [ 'overwrite_package' => $over, 'clear_update_cache' => true ] );
		if ( '' !== $tmp ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'bw_bridge_themes_install', $result->get_error_message(), [ 'status' => 500 ] );
		}
		$errors = BW_Bridge_Plugins::skin_errors( $skin );
		if ( ! $result || $errors ) {
			return new WP_Error( 'bw_bridge_themes_install', $errors ? $errors : 'Installation fehlgeschlagen.', [ 'status' => 500 ] );
		}
		$theme = $upgrader->theme_info();
		if ( ! $theme ) {
			return new WP_Error( 'bw_bridge_themes_install', 'Installiert, aber das Theme wurde nicht gefunden.', [ 'status' => 500 ] );
		}
		$activated = false;
		if ( $activate ) {
			$act = self::switch_to( $theme->get_stylesheet() );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
			$activated = true;
		}
		return rest_ensure_response( self::describe_theme( wp_get_theme( $theme->get_stylesheet() ), get_stylesheet() ) + [ 'installed' => true, 'activated' => $activated, 'messages' => BW_Bridge_Plugins::skin_messages( $skin ) ] );
	}

	public static function activate_theme( $request ) {
		$slug = (string) self::params( $request, [ 'stylesheet' ] )['stylesheet'];
		$act  = self::switch_to( $slug );
		if ( is_wp_error( $act ) ) {
			return $act;
		}
		return rest_ensure_response( self::describe_theme( wp_get_theme( $slug ), get_stylesheet() ) );
	}

	/** Prüft das Theme (vorhanden, fehlerfrei, Eltern-Theme da) und aktiviert es. */
	private static function switch_to( $slug ) {
		$theme = '' !== $slug ? wp_get_theme( $slug ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return new WP_Error( 'bw_bridge_themes_unknown', 'Theme nicht gefunden: ' . $slug . ' (siehe GET themes).', [ 'status' => 404 ] );
		}
		$err = $theme->errors();
		if ( is_wp_error( $err ) ) {
			return new WP_Error( 'bw_bridge_themes_broken', 'Theme ist fehlerhaft: ' . $err->get_error_message(), [ 'status' => 409 ] );
		}
		switch_theme( $theme->get_stylesheet() );
		return true;
	}

	public static function delete_theme( $request ) {
		$slug  = (string) self::params( $request, [ 'stylesheet' ] )['stylesheet'];
		$theme = '' !== $slug ? wp_get_theme( $slug ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return new WP_Error( 'bw_bridge_themes_unknown', 'Theme nicht gefunden: ' . $slug, [ 'status' => 404 ] );
		}
		if ( $slug === get_stylesheet() || $slug === get_template() ) {
			return new WP_Error( 'bw_bridge_themes_active', 'Das aktive Theme (oder sein Eltern-Theme) kann nicht gelöscht werden.', [ 'status' => 409 ] );
		}
		BW_Bridge_Plugins::load_admin_files();
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$result = delete_theme( $slug );
		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'bw_bridge_themes_delete', is_wp_error( $result ) ? $result->get_error_message() : 'Löschen fehlgeschlagen.', [ 'status' => 500 ] );
		}
		return rest_ensure_response( [ 'stylesheet' => $slug, 'deleted' => true ] );
	}

	private static function wporg_theme_package( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{0,99}$/', $slug ) ) {
			return new WP_Error( 'bw_bridge_themes_slug', 'Ungültiger slug (nur Kleinbuchstaben, Ziffern, Bindestrich).', [ 'status' => 400 ] );
		}
		$api = themes_api( 'theme_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'bw_bridge_themes_slug', 'wordpress.org: ' . $api->get_error_message(), [ 'status' => 404 ] );
		}
		return (string) $api->download_link;
	}

	/* ------------------------------------------------------------------ */
	/* Permalinks                                                          */
	/* ------------------------------------------------------------------ */

	public static function get_permalinks() {
		return rest_ensure_response( [
			'structure'     => (string) get_option( 'permalink_structure' ),
			'category_base' => (string) get_option( 'category_base' ),
			'tag_base'      => (string) get_option( 'tag_base' ),
			'example'       => self::example_url(),
		] );
	}

	/** Gültige Struktur: leer (einfache Links) oder beginnt mit „/“ und enthält mindestens ein %tag%. */
	public static function valid_structure( $s ) {
		return '' === $s || ( '/' === $s[0] && (bool) preg_match( '/%[a-z_]+%/', $s ) && ! preg_match( '/[\s"\'<>\\\\]/', $s ) );
	}

	public static function set_permalinks( $request ) {
		$s = trim( (string) self::params( $request, [ 'structure' ] )['structure'] );
		if ( ! self::valid_structure( $s ) ) {
			return new WP_Error( 'bw_bridge_permalinks', 'Ungültige Struktur. Beispiele: "/%postname%/", "/%year%/%monthnum%/%postname%/" oder "" (einfache Links).', [ 'status' => 400 ] );
		}
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $s );
		flush_rewrite_rules( false );
		return self::get_permalinks();
	}

	private static function example_url() {
		$pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] );
		return $pages ? get_permalink( $pages[0] ) : home_url( '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Testmail                                                            */
	/* ------------------------------------------------------------------ */

	public static function mail_test( $request ) {
		$to = trim( (string) ( self::params( $request, [ 'to' ] )['to'] ?? '' ) );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'bw_bridge_mail', 'Ungültige Empfängeradresse.', [ 'status' => 400 ] );
		}
		$count = (int) get_transient( 'bw_bridge_mail_test' );
		if ( $count >= self::MAIL_LIMIT ) {
			return new WP_Error( 'bw_bridge_mail_limit', 'Höchstens ' . self::MAIL_LIMIT . ' Testmails pro Stunde.', [ 'status' => 429 ] );
		}
		set_transient( 'bw_bridge_mail_test', $count + 1, HOUR_IN_SECONDS );

		$failed = null;
		$catch  = static function ( $error ) use ( &$failed ) {
			$failed = $error;
		};
		add_action( 'wp_mail_failed', $catch );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( 'Testmail von %s (BW WP Bridge)', $site );
		$body    = sprintf( "Diese Testmail wurde über die BW WP Bridge von %s gesendet.\nZeit: %s", home_url( '/' ), wp_date( 'Y-m-d H:i:s' ) );
		$sent    = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $catch );

		return rest_ensure_response( [
			'to'      => $to,
			'sent'    => (bool) $sent,
			'error'   => is_wp_error( $failed ) ? $failed->get_error_message() : null,
			'from'    => apply_filters( 'wp_mail_from', 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'mailer'  => self::mailer(),
			'note'    => $sent ? 'Von WordPress übergeben. Ob die Mail ankommt (oder im Spam landet), zeigt nur das Postfach.' : 'WordPress konnte die Mail nicht übergeben.',
		] );
	}

	/** Grobe Angabe, wer die Mails verschickt (SMTP-Plugin oder PHP mail()). */
	private static function mailer() {
		foreach ( [ 'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP', 'fluent-smtp/fluent-smtp.php' => 'FluentSMTP', 'post-smtp/postman-smtp.php' => 'Post SMTP', 'mailin/sendinblue.php' => 'Brevo (falls dort SMTP aktiviert)' ] as $file => $name ) {
			if ( in_array( $file, (array) get_option( 'active_plugins', [] ), true ) ) {
				return $name;
			}
		}
		return 'PHP mail()';
	}

	/* ------------------------------------------------------------------ */
	/* Fehlerprotokoll                                                     */
	/* ------------------------------------------------------------------ */

	/** Pfad zum debug.log nach WP_DEBUG_LOG (true → wp-content/debug.log, Pfad → dieser), sonst ''. */
	public static function log_path() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return '';
		}
		return is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), [ '1', 'true' ], true ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log'; // phpcs:ignore
	}

	public static function debug_log( $request ) {
		$n    = max( 1, min( self::LOG_LINES, (int) $request->get_param( 'lines' ) ) );
		$path = self::log_path();
		$info = [
			'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log' => '' !== $path,
			'exists'       => false,
			'size'         => 0,
			'modified'     => null,
			'lines'        => [],
		];
		if ( '' === $path ) {
			$info['note'] = 'WP_DEBUG_LOG ist aus. In der wp-config.php define( \'WP_DEBUG\', true ); define( \'WP_DEBUG_LOG\', true ); define( \'WP_DEBUG_DISPLAY\', false ); setzen.';
			return rest_ensure_response( $info );
		}
		if ( ! is_readable( $path ) ) {
			$info['note'] = 'Noch keine Einträge (Datei fehlt oder ist nicht lesbar).';
			return rest_ensure_response( $info );
		}
		$info['exists']   = true;
		$info['size']     = (int) filesize( $path );
		$info['modified'] = gmdate( 'c', (int) filemtime( $path ) );
		$info['lines']    = self::tail( $path, $n );
		return rest_ensure_response( $info );
	}

	/** Letzte $n Zeilen einer Datei (liest höchstens LOG_BYTES vom Ende). */
	public static function tail( $path, $n ) {
		$size = (int) filesize( $path );
		$fh   = fopen( $path, 'rb' );
		if ( ! $fh ) {
			return [];
		}
		$read = min( $size, self::LOG_BYTES );
		fseek( $fh, $size - $read );
		$data = (string) fread( $fh, $read );
		fclose( $fh );
		$lines = preg_split( '/\r\n|\n|\r/', rtrim( $data, "\r\n" ) );
		if ( $read < $size ) {
			array_shift( $lines ); // erste Zeile ist abgeschnitten
		}
		return array_values( array_slice( array_filter( $lines, 'strlen' ), -$n ) );
	}

	/* ------------------------------------------------------------------ */

	private static function params( $request, array $keys ) {
		$json = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		$p    = is_array( $json ) ? $json : [];
		foreach ( $keys as $k ) {
			if ( ! isset( $p[ $k ] ) && null !== $request->get_param( $k ) ) {
				$p[ $k ] = $request->get_param( $k );
			}
		}
		return $p + array_fill_keys( $keys, null );
	}
}
