<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugins auflisten, installieren und aktualisieren (Installieren/Aktualisieren optional, standardmäßig AUS).
 *
 * Freischaltung nur im Backend: Einstellungen › BW WP Bridge (Option "Plugins installieren und aktualisieren").
 * Die Option ist nicht über die REST-API änderbar. Zusätzlich gilt: Benutzer mit manage_options und
 * install_plugins (Überschreiben/Aktualisieren: update_plugins, Aktivieren: activate_plugins); fällt weg bei
 * DISALLOW_FILE_MODS in der wp-config.php. Hart abschalten: define( 'BW_WP_BRIDGE_PLUGINS_DISABLED', true );
 *
 * Quellen für die Installation (genau eine): "slug" (wordpress.org), "url" (https-ZIP) oder "zip_base64".
 * Installiert wird mit dem WordPress-Upgrader (Plugin_Upgrader), also wie im Backend. Das ZIP wird vorher
 * geprüft (Größe, ZIP-Kennung, keine Pfade mit ".." oder absolute Pfade).
 */
final class BW_Bridge_Plugins {

	const MAX_ZIP = 52428800; // 50 MB

	public static function register_routes() {
		register_rest_route( BW_WP_Bridge::NS, '/plugins', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_plugins' ],
			'permission_callback' => [ __CLASS__, 'perm_list' ],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/plugins/install', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'install' ],
			'permission_callback' => [ __CLASS__, 'perm_install' ],
			'args'                => [
				'slug'       => [ 'type' => 'string' ],
				'url'        => [ 'type' => 'string' ],
				'zip_base64' => [ 'type' => 'string' ],
				'activate'   => [ 'type' => 'boolean', 'default' => false ],
				'overwrite'  => [ 'type' => 'boolean', 'default' => false ],
			],
		] );
		register_rest_route( BW_WP_Bridge::NS, '/plugins/update', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update' ],
			'permission_callback' => [ __CLASS__, 'perm_update' ],
			'args'                => [ 'plugin' => [ 'type' => 'string', 'required' => true ] ],
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Rechte                                                              */
	/* ------------------------------------------------------------------ */

	public static function perm_list() {
		return current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
	}

	public static function perm_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! BW_Bridge_Settings::can_install_plugins_enabled() ) {
			return new WP_Error( 'bw_bridge_plugins_disabled', 'Plugin-Installation ist aus. Im Backend unter Einstellungen › BW WP Bridge „Plugins installieren und aktualisieren“ aktivieren.', [ 'status' => 403 ] );
		}
		if ( BW_Bridge_Settings::config_blocks_plugins() || ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'bw_bridge_plugins_forbidden', 'Der Benutzer darf keine Plugins installieren (Recht install_plugins fehlt oder DISALLOW_FILE_MODS ist gesetzt).', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function perm_update() {
		$ok = self::perm_install();
		if ( true !== $ok ) {
			return $ok;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'bw_bridge_plugins_forbidden', 'Der Benutzer darf keine Plugins aktualisieren (Recht update_plugins fehlt).', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Liste                                                               */
	/* ------------------------------------------------------------------ */

	public static function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$updates = get_site_transient( 'update_plugins' );
		$out     = [];
		foreach ( get_plugins() as $file => $data ) {
			$new   = isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : null;
			$out[] = [
				'plugin'  => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => $new && version_compare( $new, $data['Version'], '>' ) ? $new : null,
			];
		}
		return rest_ensure_response( $out );
	}

	/* ------------------------------------------------------------------ */
	/* Installieren                                                        */
	/* ------------------------------------------------------------------ */

	public static function install( $request ) {
		$p        = self::params( $request );
		$sources  = array_filter( [ 'slug' => (string) ( $p['slug'] ?? '' ), 'url' => (string) ( $p['url'] ?? '' ), 'zip_base64' => (string) ( $p['zip_base64'] ?? '' ) ], 'strlen' );
		$activate = ! empty( $p['activate'] );
		$over     = ! empty( $p['overwrite'] );
		if ( 1 !== count( $sources ) ) {
			return new WP_Error( 'bw_bridge_plugins_source', 'Genau eine Quelle angeben: slug, url oder zip_base64.', [ 'status' => 400 ] );
		}
		if ( $over && ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'bw_bridge_plugins_forbidden', 'Überschreiben braucht das Recht update_plugins.', [ 'status' => 403 ] );
		}
		if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'bw_bridge_plugins_forbidden', 'Aktivieren braucht das Recht activate_plugins.', [ 'status' => 403 ] );
		}
		self::load_admin_files();

		$tmp = '';
		if ( isset( $sources['slug'] ) ) {
			$package = self::wporg_package( $sources['slug'] );
		} elseif ( isset( $sources['url'] ) ) {
			$package = self::download( $sources['url'] );
			$tmp     = is_wp_error( $package ) ? '' : $package;
		} else {
			$package = self::store_base64( $sources['zip_base64'] );
			$tmp     = is_wp_error( $package ) ? '' : $package;
		}
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( '' !== $tmp ) {
			$check = self::check_zip_file( $tmp );
			if ( is_wp_error( $check ) ) {
				wp_delete_file( $tmp );
				return $check;
			}
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package, [ 'overwrite_package' => $over, 'clear_update_cache' => true ] );
		if ( '' !== $tmp ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'bw_bridge_plugins_install', $result->get_error_message(), [ 'status' => 500 ] );
		}
		$errors = self::skin_errors( $skin );
		if ( ! $result || $errors ) {
			return new WP_Error( 'bw_bridge_plugins_install', $errors ? $errors : 'Installation fehlgeschlagen.', [ 'status' => 500 ] );
		}

		$file = $upgrader->plugin_info();
		if ( ! $file ) {
			return new WP_Error( 'bw_bridge_plugins_install', 'Installiert, aber die Plugin-Datei wurde nicht gefunden.', [ 'status' => 500 ] );
		}
		$activated = false;
		if ( $activate ) {
			$act = activate_plugin( $file );
			if ( is_wp_error( $act ) ) {
				return new WP_Error( 'bw_bridge_plugins_activate', 'Installiert, aber nicht aktiviert: ' . $act->get_error_message(), [ 'status' => 500 ] );
			}
			$activated = true;
		}
		return rest_ensure_response( self::describe( $file, [ 'installed' => true, 'activated' => $activated, 'messages' => self::skin_messages( $skin ) ] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Aktualisieren                                                       */
	/* ------------------------------------------------------------------ */

	public static function update( $request ) {
		$p    = self::params( $request );
		$file = isset( $p['plugin'] ) ? (string) $p['plugin'] : '';
		self::load_admin_files();
		$all = get_plugins();
		if ( '' === $file || ! isset( $all[ $file ] ) ) {
			return new WP_Error( 'bw_bridge_plugins_unknown', 'Plugin nicht gefunden: ' . $file . ' (Form: ordner/datei.php, siehe GET plugins).', [ 'status' => 404 ] );
		}
		$old = $all[ $file ]['Version'];
		wp_clean_plugins_cache( true );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( empty( $updates->response[ $file ] ) ) {
			return rest_ensure_response( self::describe( $file, [ 'updated' => false, 'message' => 'Keine Aktualisierung verfügbar.' ] ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'bw_bridge_plugins_update', $result->get_error_message(), [ 'status' => 500 ] );
		}
		$errors = self::skin_errors( $skin );
		if ( ! $result || $errors ) {
			return new WP_Error( 'bw_bridge_plugins_update', $errors ? $errors : 'Aktualisierung fehlgeschlagen.', [ 'status' => 500 ] );
		}
		return rest_ensure_response( self::describe( $file, [ 'updated' => true, 'old_version' => $old, 'messages' => self::skin_messages( $skin ) ] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Quellen und Prüfungen                                               */
	/* ------------------------------------------------------------------ */

	/** Download-Adresse eines Plugins aus dem wordpress.org-Verzeichnis. */
	private static function wporg_package( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{0,99}$/', $slug ) ) {
			return new WP_Error( 'bw_bridge_plugins_slug', 'Ungültiger slug (nur Kleinbuchstaben, Ziffern, Bindestrich).', [ 'status' => 400 ] );
		}
		$api = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'bw_bridge_plugins_slug', 'wordpress.org: ' . $api->get_error_message(), [ 'status' => 404 ] );
		}
		return (string) $api->download_link;
	}

	/**
	 * Prüft eine ZIP-Adresse: nur https, keine Zugangsdaten, optional nur erlaubte Hosts
	 * (Filter bw_bridge_plugin_allowed_hosts, Standard: alle). Gibt true oder WP_Error zurück.
	 */
	public static function check_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'bw_bridge_plugins_url', 'Nur https-Adressen sind erlaubt.', [ 'status' => 400 ] );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'bw_bridge_plugins_url', 'Adressen mit Zugangsdaten sind nicht erlaubt.', [ 'status' => 400 ] );
		}
		$allowed = (array) apply_filters( 'bw_bridge_plugin_allowed_hosts', [] );
		if ( $allowed && ! in_array( strtolower( $parts['host'] ), array_map( 'strtolower', $allowed ), true ) ) {
			return new WP_Error( 'bw_bridge_plugins_url', 'Dieser Host ist nicht erlaubt: ' . $parts['host'], [ 'status' => 403 ] );
		}
		return true;
	}

	/** Lädt ein ZIP herunter (wp_safe_remote_get: keine internen Adressen) und gibt den Pfad der Temp-Datei zurück. */
	private static function download( $url ) {
		$ok = self::check_url( $url );
		if ( true !== $ok ) {
			return $ok;
		}
		$file = download_url( $url, 300 );
		if ( is_wp_error( $file ) ) {
			return new WP_Error( 'bw_bridge_plugins_download', 'Download fehlgeschlagen: ' . $file->get_error_message(), [ 'status' => 502 ] );
		}
		return $file;
	}

	/** Speichert ein base64-kodiertes ZIP in einer Temp-Datei. */
	private static function store_base64( $b64 ) {
		if ( strlen( $b64 ) > (int) ceil( self::MAX_ZIP * 4 / 3 ) + 8 ) {
			return new WP_Error( 'bw_bridge_plugins_zip', 'ZIP ist zu groß (max. 50 MB).', [ 'status' => 413 ] );
		}
		$bin = base64_decode( $b64, true );
		if ( false === $bin || '' === $bin ) {
			return new WP_Error( 'bw_bridge_plugins_zip', 'zip_base64 ist kein gültiges base64.', [ 'status' => 400 ] );
		}
		$file = wp_tempnam( 'bw-bridge-plugin.zip' );
		if ( ! $file || false === file_put_contents( $file, $bin ) ) {
			return new WP_Error( 'bw_bridge_plugins_zip', 'Temp-Datei konnte nicht geschrieben werden.', [ 'status' => 500 ] );
		}
		return $file;
	}

	/** Prüft eine lokale ZIP-Datei: Größe, Kennung, Pfade. Gibt true oder WP_Error zurück. */
	public static function check_zip_file( $file ) {
		$size = @filesize( $file );
		if ( ! $size || $size > self::MAX_ZIP ) {
			return new WP_Error( 'bw_bridge_plugins_zip', 'ZIP ist leer oder zu groß (max. 50 MB).', [ 'status' => 413 ] );
		}
		$fh   = @fopen( $file, 'rb' );
		$head = $fh ? fread( $fh, 4 ) : '';
		if ( $fh ) {
			fclose( $fh );
		}
		if ( "PK\x03\x04" !== $head ) {
			return new WP_Error( 'bw_bridge_plugins_zip', 'Die Datei ist kein ZIP.', [ 'status' => 400 ] );
		}
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				return new WP_Error( 'bw_bridge_plugins_zip', 'ZIP lässt sich nicht öffnen.', [ 'status' => 400 ] );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) );
				if ( '' === $name || '/' === $name[0] || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
					$zip->close();
					return new WP_Error( 'bw_bridge_plugins_zip', 'ZIP enthält einen unzulässigen Pfad: ' . $name, [ 'status' => 400 ] );
				}
			}
			$zip->close();
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Hilfen                                                              */
	/* ------------------------------------------------------------------ */

	private static function params( $request ) {
		$json = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		$p    = is_array( $json ) ? $json : [];
		foreach ( [ 'slug', 'url', 'zip_base64', 'activate', 'overwrite', 'plugin' ] as $k ) {
			if ( ! isset( $p[ $k ] ) && null !== $request->get_param( $k ) ) {
				$p[ $k ] = $request->get_param( $k );
			}
		}
		return $p;
	}

	private static function load_admin_files() {
		foreach ( [ 'file', 'plugin', 'misc', 'class-wp-upgrader', 'plugin-install' ] as $f ) {
			require_once ABSPATH . 'wp-admin/includes/' . $f . '.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}

	private static function describe( $file, array $extra = [] ) {
		wp_clean_plugins_cache( true );
		$all  = get_plugins();
		$data = $all[ $file ] ?? [ 'Name' => '', 'Version' => '' ];
		return $extra + [ 'plugin' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active( $file ) ];
	}

	private static function skin_errors( $skin ) {
		$err = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
		return ( $err && is_wp_error( $err ) && $err->has_errors() ) ? implode( ' ', $err->get_error_messages() ) : '';
	}

	private static function skin_messages( $skin ) {
		$m = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : [];
		return array_values( array_map( 'wp_strip_all_tags', (array) $m ) );
	}
}
