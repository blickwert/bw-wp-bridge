<?php
defined( 'ABSPATH' ) || exit;

/**
 * Verbindungsschlüssel (optional).
 *
 * Im Backend (Einstellungen › BW WP Bridge) wird ein Schlüssel erzeugt und einmalig angezeigt – wie bei den
 * Anwendungspasswörtern. In der Datenbank liegt nur ein Hash. Der Client (z. B. Claude Code) sendet den Schlüssel
 * bei jeder Anfrage im Header X-BW-Bridge-Key (Umgebungsvariable WP_BRIDGE_KEY). Ohne den passenden Schlüssel
 * lehnt die Bridge ab, auch wenn das Anwendungspasswort stimmt.
 *
 *  - Schützt vor Verwechslungen: Ein Schlüssel gilt nur für diese Website. Zeigt eine Umgebung versehentlich auf
 *    eine andere Website, schlägt die Anfrage mit einer eindeutigen Meldung fehl, bevor etwas geändert wird.
 *  - Zweiter Faktor: Ein gestohlenes Anwendungspasswort allein reicht nicht für die Bridge-Routen.
 *  - Kennung: Eine 8-stellige, nicht geheime Kennung (site id) macht die Website erkennbar (status, Fehlermeldung).
 *
 * Standardmäßig prüft die Bridge den Schlüssel nur bei den eigenen Routen (bw-bridge/v1). Optional lässt sich das auf alle
 * REST-Anfragen ausdehnen, die per Anwendungspasswort kommen. Ist kein Schlüssel erzeugt, ändert sich nichts.
 * Die Optionen sind nicht über die REST-API änderbar.
 */
final class BW_Bridge_Key {

	const OPT_HASH   = 'bw_bridge_key_hash';
	const OPT_SITE   = 'bw_bridge_site_id';
	const OPT_ALL    = 'bw_bridge_key_all';
	const HEADER     = 'x_bw_bridge_key';
	const PUBLIC_ROUTES = [ '/bw-bridge/v1/status', '/bw-bridge/v1/auth-check' ];

	public static function init() {
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'enforce' ], 5, 3 );
		add_action( 'admin_post_bw_bridge_key_generate', [ __CLASS__, 'handle_generate' ] );
		add_action( 'admin_post_bw_bridge_key_remove', [ __CLASS__, 'handle_remove' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Schlüssel                                                           */
	/* ------------------------------------------------------------------ */

	public static function is_active() {
		return '' !== (string) get_option( self::OPT_HASH, '' );
	}

	public static function site_id() {
		return (string) get_option( self::OPT_SITE, '' );
	}

	public static function hash( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
	}

	/** Neuen Schlüssel erzeugen und den Hash speichern. Gibt den Klartext zurück (nur jetzt sichtbar). */
	public static function generate() {
		$key = 'bwk_' . wp_generate_password( 40, false );
		update_option( self::OPT_HASH, self::hash( $key ), false );
		if ( '' === self::site_id() ) {
			update_option( self::OPT_SITE, bin2hex( random_bytes( 4 ) ), false );
		}
		return $key;
	}

	public static function remove() {
		delete_option( self::OPT_HASH );
		delete_option( self::OPT_SITE );
	}

	public static function is_valid( $key ) {
		$stored = (string) get_option( self::OPT_HASH, '' );
		return '' !== $stored && '' !== (string) $key && hash_equals( $stored, self::hash( $key ) );
	}

	/** Angaben zur Website für status (nicht geheim). */
	public static function summary( $request = null ) {
		$sent = $request && method_exists( $request, 'get_header' ) ? (string) $request->get_header( self::HEADER ) : '';
		return [
			'site'  => [ 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'id' => self::site_id() ?: null ],
			'key'   => [ 'required' => self::is_active(), 'valid' => ! self::is_active() || self::is_valid( $sent ), 'all_requests' => (bool) get_option( self::OPT_ALL, 0 ) ],
		];
	}

	/* ------------------------------------------------------------------ */
	/* Prüfung bei jeder REST-Anfrage                                      */
	/* ------------------------------------------------------------------ */

	public static function enforce( $result, $server = null, $request = null ) {
		if ( null !== $result || ! $request || ! self::is_active() || ! is_user_logged_in() ) {
			return $result;
		}
		$route = $request->get_route();
		if ( in_array( $route, self::PUBLIC_ROUTES, true ) ) {
			return $result;
		}
		$is_bridge = 0 === strpos( $route, '/' . BW_WP_Bridge::NS . '/' );
		if ( ! $is_bridge ) {
			// Andere REST-Routen: nur auf Wunsch und nur bei Anmeldung per Anwendungspasswort.
			if ( ! get_option( self::OPT_ALL, 0 ) || ! did_action( 'application_password_did_authenticate' ) ) {
				return $result;
			}
		}
		if ( self::is_valid( (string) $request->get_header( self::HEADER ) ) ) {
			return $result;
		}
		return new WP_Error(
			'bw_bridge_key',
			sprintf(
				'Verbindungsschlüssel fehlt oder passt nicht zu dieser Website (%s, Kennung %s). Den Schlüssel unter Einstellungen › BW WP Bridge erzeugen und als WP_BRIDGE_KEY setzen.',
				get_bloginfo( 'name' ),
				self::site_id()
			),
			[ 'status' => 403, 'site_id' => self::site_id() ]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Backend                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_key' );
		$key = self::generate();
		$new = [ 'key' => $key, 'app_password' => self::create_app_password( get_current_user_id() ) ];
		set_transient( 'bw_bridge_key_new_' . get_current_user_id(), $new, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
	}

	/**
	 * Legt für den Benutzer ein Anwendungspasswort „BW WP Bridge“ an (ein altes gleichen Namens wird ersetzt).
	 * Gibt das Klartext-Passwort zurück oder '' , wenn Anwendungspasswörter hier nicht verfügbar sind.
	 */
	public static function create_app_password( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return '';
		}
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( 'BW WP Bridge' === $item['name'] ) {
				WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
			}
		}
		$created = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => 'BW WP Bridge' ] );
		return is_wp_error( $created ) ? '' : (string) $created[0];
	}

	/** Zeilen für die Umgebungsvariablen des Clients, Namensteil aus dem Website-Titel (z. B. WP_BEWORK_URL). */
	public static function env_block( $new, $user_login ) {
		$name = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '', remove_accents( get_bloginfo( 'name' ) ) ) );
		$p    = 'WP_' . ( '' !== $name ? $name : 'SITE' ) . '_';
		return implode( "\n", [
			$p . 'URL=' . untrailingslashit( home_url() ),
			$p . 'USER=' . $user_login,
			$p . 'APP_PASSWORD=' . ( '' !== $new['app_password'] ? $new['app_password'] : '<Anwendungspasswort unter Benutzer › Profil erzeugen>' ),
			$p . 'BRIDGE_KEY=' . $new['key'],
			$p . 'BRIDGE_SITE=' . self::site_id(),
		] );
	}

	public static function handle_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_key' );
		self::remove();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
	}

	/** Bereich auf der Einstellungsseite. */
	public static function render_section() {
		$new = get_transient( 'bw_bridge_key_new_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'bw_bridge_key_new_' . get_current_user_id() );
		}
		echo '<h2>Verbindungsschlüssel</h2>';
		echo '<p>Der Schlüssel verbindet genau diese Website mit dem Client (z. B. Claude). Er wird als Umgebungsvariable <code>WP_BRIDGE_KEY</code> eingetragen. Ohne ihn lehnt die Bridge ab – auch mit richtigem Anwendungspasswort. So landen Änderungen nie versehentlich auf der falschen Website.</p>';
		if ( $new ) {
			if ( ! is_array( $new ) ) {
				$new = [ 'key' => (string) $new, 'app_password' => '' ];
			}
			$block = self::env_block( $new, wp_get_current_user()->user_login );
			echo '<div class="notice notice-success inline"><p><strong>Zugangsdaten – werden nur jetzt angezeigt:</strong></p><p><textarea readonly class="large-text code" rows="5" style="max-width:48em" onfocus="this.select()">' . esc_textarea( $block ) . '</textarea></p><p>Jetzt kopieren und als Umgebungsvariablen eintragen. Nicht in einen Chat einfügen. Das Anwendungspasswort gehört dem aktuellen Benutzer und steht unter Benutzer › Profil › Anwendungspasswörter („BW WP Bridge“).</p></div>';
		}
		if ( self::is_active() ) {
			echo '<p>Status: <strong>aktiv</strong>. Kennung dieser Website: <code>' . esc_html( self::site_id() ) . '</code> (nicht geheim; zum Gegenprüfen als <code>WP_BRIDGE_SITE</code> im Client).</p>';
		} else {
			echo '<p>Status: <strong>nicht eingerichtet</strong> – die Bridge arbeitet wie bisher nur mit dem Anwendungspasswort.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'bw_bridge_key' );
		echo '<input type="hidden" name="action" value="bw_bridge_key_generate">';
		submit_button( self::is_active() ? 'Neu erzeugen (alter Schlüssel und altes Anwendungspasswort werden ungültig)' : 'Schlüssel und Zugangsdaten erzeugen', 'secondary', 'submit', false );
		echo '</form>';
		if ( self::is_active() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block" onsubmit="return confirm(\'Schlüssel entfernen? Die Bridge prüft dann nur noch das Anwendungspasswort.\')">';
			wp_nonce_field( 'bw_bridge_key' );
			echo '<input type="hidden" name="action" value="bw_bridge_key_remove">';
			submit_button( 'Schlüssel entfernen', 'delete', 'submit', false );
			echo '</form>';
		}
		echo '<hr>';
	}
}
