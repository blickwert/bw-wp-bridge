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
		set_transient( 'bw_bridge_key_new_' . get_current_user_id(), $key, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
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
			echo '<div class="notice notice-success inline"><p><strong>Neuer Schlüssel – wird nur jetzt angezeigt:</strong></p><p><input type="text" readonly class="large-text code" style="max-width:36em" value="' . esc_attr( $new ) . '" onfocus="this.select()"></p><p>Jetzt kopieren und als <code>WP_BRIDGE_KEY</code> eintragen. Nicht in einen Chat einfügen.</p></div>';
		}
		if ( self::is_active() ) {
			echo '<p>Status: <strong>aktiv</strong>. Kennung dieser Website: <code>' . esc_html( self::site_id() ) . '</code> (nicht geheim; zum Gegenprüfen als <code>WP_BRIDGE_SITE</code> im Client).</p>';
		} else {
			echo '<p>Status: <strong>nicht eingerichtet</strong> – die Bridge arbeitet wie bisher nur mit dem Anwendungspasswort.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'bw_bridge_key' );
		echo '<input type="hidden" name="action" value="bw_bridge_key_generate">';
		submit_button( self::is_active() ? 'Neuen Schlüssel erzeugen (alter wird ungültig)' : 'Schlüssel erzeugen', 'secondary', 'submit', false );
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
