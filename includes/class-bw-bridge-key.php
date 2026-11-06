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
		add_action( 'admin_post_bw_bridge_rename_service', [ __CLASS__, 'handle_rename' ] );
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
		$uid = self::service_user_id();
		$new = [ 'key' => $key, 'app_password' => self::create_app_password( $uid ), 'user' => self::service_user_login( $uid ) ];
		set_transient( 'bw_bridge_key_new_' . get_current_user_id(), $new, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
	}

	const SERVICE_LOGIN  = 'support';
	const SERVICE_EMAIL  = 'support@blickwert.at';
	const SERVICE_APP    = 'Wartung';
	/** Frühere Namen des Anwendungspassworts; werden beim Neu-Erzeugen ebenfalls ersetzt. */
	const LEGACY_APPS    = [ 'claude-code', 'BW WP Bridge' ];
	const SERVICE_META   = '_bw_bridge_service';
	const LEGACY_LOGINS  = [ 'claude' ];

	/**
	 * Anmeldename des Dienstbenutzers: Standard „support“ (neutral für Kunden), per Konstante
	 * BW_WP_BRIDGE_SERVICE_LOGIN änderbar.
	 */
	public static function service_login() {
		if ( defined( 'BW_WP_BRIDGE_SERVICE_LOGIN' ) ) {
			$login = sanitize_user( (string) BW_WP_BRIDGE_SERVICE_LOGIN, true );
			if ( '' !== $login ) {
				return $login;
			}
		}
		return self::SERVICE_LOGIN;
	}

	/** Dienstbenutzer als Objekt (Benutzer-Meta, sonst Anmeldename, sonst früherer Name) oder null. */
	public static function service_user() {
		$found = get_users( [ 'meta_key' => self::SERVICE_META, 'meta_value' => '1', 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return $found[0];
		}
		foreach ( array_merge( [ self::service_login() ], self::LEGACY_LOGINS ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				return $user;
			}
		}
		return null;
	}

	/**
	 * ID des Dienstbenutzers (Administrator); wird bei Bedarf angelegt. 0, wenn das nicht gelingt.
	 * Ein vorhandener Benutzer „claude“ aus früheren Versionen wird weiterverwendet (kein zweiter Benutzer).
	 */
	public static function service_user_id() {
		$user = self::service_user();
		if ( $user ) {
			if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$user->add_role( 'administrator' );
			}
			if ( '1' !== (string) get_user_meta( $user->ID, self::SERVICE_META, true ) ) {
				update_user_meta( $user->ID, self::SERVICE_META, 1 );
			}
			return (int) $user->ID;
		}
		$id = wp_insert_user( [
			'user_login'   => self::service_login(),
			'user_email'   => self::SERVICE_EMAIL,
			'display_name' => 'Support',
			'nickname'     => 'Support',
			'first_name'   => 'Support',
			'user_pass'    => wp_generate_password( 32, true, true ),
			'role'         => 'administrator',
		] );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_user_meta( (int) $id, self::SERVICE_META, 1 );
		return (int) $id;
	}

	/** Anmeldename des Dienstbenutzers (für die Umgebungsvariablen). */
	public static function service_user_login( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->user_login : self::service_login();
	}

	/** Zeigt die Einrichtung noch einen früheren Anmeldenamen (z. B. „claude“)? Dann Umbenennen anbieten. */
	public static function legacy_service_user() {
		$user = self::service_user();
		return $user && in_array( $user->user_login, self::LEGACY_LOGINS, true ) && $user->user_login !== self::service_login() ? $user : null;
	}

	public static function handle_rename() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_rename_service' );
		global $wpdb;
		$back = admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE );
		$user = self::legacy_service_user();
		$to   = self::service_login();
		$err  = '';
		if ( ! $user ) {
			$err = 'Kein Dienstbenutzer mit altem Namen gefunden.';
		} elseif ( (int) $user->ID === get_current_user_id() ) {
			$err = 'Bitte mit einem anderen Administrator-Benutzer umbenennen – der Dienstbenutzer kann sich nicht selbst umbenennen.';
		} elseif ( get_user_by( 'login', $to ) ) {
			$err = 'Der Benutzername „' . $to . '“ ist bereits vergeben.';
		}
		if ( '' === $err ) {
			$from = (string) $user->user_login;
			$ok   = $wpdb->update( $wpdb->users, [ 'user_login' => $to, 'user_nicename' => sanitize_title( $to ) ], [ 'ID' => (int) $user->ID ] );
			if ( false === $ok ) {
				$err = 'Der Benutzer konnte nicht umbenannt werden.';
			} else {
				wp_update_user( [ 'ID' => (int) $user->ID, 'display_name' => 'Support' ] );
				update_user_meta( (int) $user->ID, self::SERVICE_META, 1 );
				clean_user_cache( (int) $user->ID );
				BW_Bridge_Log::add( [
					'area' => 'system', 'category' => 'user', 'action' => 'renamed', 'level' => 'notice',
					'object_type' => 'user', 'object_id' => (string) $user->ID, 'object_name' => $to,
					'message' => 'Dienstbenutzer von ' . $from . ' in ' . $to . ' umbenannt',
				] );
			}
		}
		set_transient( 'bw_bridge_rename_' . get_current_user_id(), '' === $err ? [ 'ok' => true, 'to' => $to ] : [ 'ok' => false, 'msg' => $err ], 120 );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Legt für den Benutzer das Anwendungspasswort „Wartung“ an (alte gleichen oder früheren Namens werden ersetzt).
	 * Gibt das Klartext-Passwort zurück oder '' , wenn Anwendungspasswörter hier nicht verfügbar sind.
	 */
	public static function create_app_password( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return '';
		}
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( in_array( $item['name'], array_merge( [ self::SERVICE_APP ], self::LEGACY_APPS ), true ) ) {
				WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
			}
		}
		$created = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => self::SERVICE_APP ] );
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
		self::render_rename_notice();
		echo '<p>Der Schlüssel verbindet genau diese Website mit dem Client (z. B. Claude). Er wird als Umgebungsvariable <code>WP_BRIDGE_KEY</code> eingetragen. Ohne ihn lehnt die Bridge ab – auch mit richtigem Anwendungspasswort. So landen Änderungen nie versehentlich auf der falschen Website.</p>';
		if ( $new ) {
			if ( ! is_array( $new ) ) {
				$new = [ 'key' => (string) $new, 'app_password' => '' ];
			}
			$login = isset( $new['user'] ) && '' !== (string) $new['user'] ? (string) $new['user'] : self::service_user_login( self::service_user() ? self::service_user()->ID : 0 );
			$block = self::env_block( $new, $login );
			echo '<div class="notice notice-success inline"><p><strong>Zugangsdaten – werden nur jetzt angezeigt:</strong></p><p><textarea readonly class="large-text code" rows="5" style="max-width:48em" onfocus="this.select()">' . esc_textarea( $block ) . '</textarea></p><p>Jetzt kopieren und als Umgebungsvariablen eintragen. Nicht in einen Chat einfügen. Das Anwendungspasswort „' . esc_html( self::SERVICE_APP ) . '“ gehört dem Administrator-Benutzer „' . esc_html( $login ) . '“ und steht unter Benutzer › ' . esc_html( $login ) . ' › Anwendungspasswörter.</p></div>';
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
		self::render_rename_form();
		echo '<hr>';
	}

	/** Ergebnis des Umbenennens (einmalig). */
	private static function render_rename_notice() {
		$res = get_transient( 'bw_bridge_rename_' . get_current_user_id() );
		if ( ! is_array( $res ) ) {
			return;
		}
		delete_transient( 'bw_bridge_rename_' . get_current_user_id() );
		if ( ! empty( $res['ok'] ) ) {
			echo '<div class="notice notice-success inline"><p>Der Dienstbenutzer heißt jetzt „' . esc_html( $res['to'] ) . '“. Die Umgebungsvariable <code>WP_&lt;NAME&gt;_USER</code> im Client muss auf diesen Namen geändert werden; das Anwendungspasswort bleibt gültig.</p></div>';
		} else {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) ( $res['msg'] ?? 'Umbenennen fehlgeschlagen.' ) ) . '</p></div>';
		}
	}

	/** Hinweis und Schaltfläche, wenn der Dienstbenutzer noch einen früheren Namen trägt. */
	private static function render_rename_form() {
		$user = self::legacy_service_user();
		if ( ! $user ) {
			return;
		}
		$to = self::service_login();
		echo '<h3>Dienstbenutzer umbenennen</h3>';
		echo '<p>Der Dienstbenutzer heißt noch „' . esc_html( $user->user_login ) . '“. Neue Einrichtungen nutzen den neutralen Namen „' . esc_html( $to ) . '“.</p>';
		if ( (int) $user->ID === get_current_user_id() ) {
			echo '<p class="description">Zum Umbenennen mit einem anderen Administrator-Benutzer anmelden.</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bw_bridge_rename_service' );
		echo '<input type="hidden" name="action" value="bw_bridge_rename_service">';
		submit_button( 'Dienstbenutzer in „' . $to . '“ umbenennen', 'secondary', 'submit', false );
		echo '<p class="description"><strong>Achtung:</strong> Danach muss die Umgebungsvariable <code>WP_&lt;NAME&gt;_USER</code> im Client auf „' . esc_html( $to ) . '“ geändert werden, sonst schlagen Anfragen fehl. Das Anwendungspasswort bleibt gültig, es gehört zur Benutzer-ID.</p>';
		echo '</form>';
	}
}
