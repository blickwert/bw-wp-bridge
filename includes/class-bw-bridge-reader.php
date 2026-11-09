<?php
/**
 * Lesezugang für das Verwaltungstool (BW Office) und den Support-Assistenten auf echten Kundenseiten.
 *
 * Eigene Rolle „BW Bridge Lesezugang“ (bw_bridge_reader) mit dem Recht bw_bridge_read, ohne Administratorrechte.
 * Sie darf nur:
 *   lesen:   status, history, history/report, audit, scan, plugins (Liste), search (ohne Post-Meta), render/{id}
 *   handeln: history/notes (Notiz schreiben), scan (Prüfung starten), elementor/clear-cache (CSS neu erzeugen)
 * Alles andere bleibt Administratoren vorbehalten. Der Verbindungsschlüssel gilt auch für diesen Zugang.
 *
 * Im Backend (Einstellungen › BW WP Bridge › Lesezugang) legt ein Knopf den Benutzer „bw-office“ mit dieser Rolle
 * und das Anwendungspasswort „bw-office“ an und zeigt die Zugangsdaten einmal an.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Reader {

	const ROLE  = 'bw_bridge_reader';
	const CAP   = 'bw_bridge_read';
	const LOGIN = 'bw-office';
	const EMAIL = 'bw-office@blickwert.at';
	const APP   = 'bw-office';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'ensure_role' ] );
		add_action( 'admin_post_bw_bridge_reader_generate', [ __CLASS__, 'handle_generate' ] );
		add_action( 'admin_post_bw_bridge_reader_remove', [ __CLASS__, 'handle_remove' ] );
	}

	/** Legt die Rolle an, falls sie fehlt (einmal je Website, danach nur ein Lesezugriff). */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'BW Bridge Lesezugang', [ 'read' => true, self::CAP => true ] );
		}
	}

	/** Administrator oder Lesezugang. */
	public static function can_read() {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP );
	}

	/** Nur Lesezugang, kein Administrator (z. B. um die Suche einzuschränken). */
	public static function is_reader_only() {
		return ! current_user_can( 'manage_options' ) && current_user_can( self::CAP );
	}

	/** ID des Benutzers „bw-office“ mit der Lese-Rolle; wird bei Bedarf angelegt. 0, wenn das nicht gelingt. */
	public static function user_id() {
		self::ensure_role();
		$user = get_user_by( 'login', self::LOGIN );
		if ( $user ) {
			// Nie mehr Rechte als die Lese-Rolle, auch wenn jemand die Rolle von Hand geändert hat.
			$user->set_role( self::ROLE );
			return (int) $user->ID;
		}
		$id = wp_insert_user( [
			'user_login'   => self::LOGIN,
			'user_email'   => self::EMAIL,
			'display_name' => 'BW Office',
			'user_pass'    => wp_generate_password( 32, true, true ),
			'role'         => self::ROLE,
		] );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** Anwendungspasswort „bw-office“ (ein altes gleichen Namens wird ersetzt). Klartext oder ''. */
	public static function create_app_password( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return '';
		}
		self::revoke( $user_id );
		$created = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => self::APP ] );
		return is_wp_error( $created ) ? '' : (string) $created[0];
	}

	private static function revoke( $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return;
		}
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( self::APP === $item['name'] ) {
				WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
			}
		}
	}

	public static function exists() {
		return (bool) get_user_by( 'login', self::LOGIN );
	}

	/* ------------------------------------------------------------------ */
	/* Backend                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_reader' );
		$password = self::create_app_password( self::user_id() );
		set_transient( 'bw_bridge_reader_new_' . get_current_user_id(), [ 'app_password' => $password ], 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
	}

	/** Zugang sperren: Anwendungspasswort löschen. Der Benutzer bleibt (Protokolleinträge verweisen auf ihn). */
	public static function handle_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_reader' );
		$user = get_user_by( 'login', self::LOGIN );
		if ( $user ) {
			self::revoke( (int) $user->ID );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BW_Bridge_Settings::PAGE ) );
		exit;
	}

	public static function render_section() {
		$new = get_transient( 'bw_bridge_reader_new_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'bw_bridge_reader_new_' . get_current_user_id() );
		}
		echo '<h2>Lesezugang (BW Office)</h2>';
		echo '<p>Für das Verwaltungstool und den Support-Assistenten auf der echten Website: ein eigener Benutzer <code>' . esc_html( self::LOGIN ) . '</code> ohne Administratorrechte. '
			. 'Er darf nur lesen (Status, Wartungsprotokoll und Bericht, Prüfung, Scan, Plugin-Liste, Suche ohne Post-Meta, sichtbarer Text einer Seite) '
			. 'und drei Dinge tun: eine Notiz ins Wartungsprotokoll schreiben, eine Prüfung starten und den Elementor-Cache leeren. Alles wird protokolliert.</p>';
		if ( $new ) {
			$lines = [
				'URL=' . untrailingslashit( home_url() ),
				'USER=' . self::LOGIN,
				'APP_PASSWORD=' . ( '' !== $new['app_password'] ? $new['app_password'] : '<Anwendungspasswörter sind hier nicht verfügbar>' ),
			];
			if ( BW_Bridge_Key::is_active() ) {
				$lines[] = 'BRIDGE_SITE=' . BW_Bridge_Key::site_id();
			}
			echo '<div class="notice notice-success inline"><p><strong>Zugangsdaten – werden nur jetzt angezeigt:</strong></p><p><textarea readonly class="large-text code" rows="4" style="max-width:48em" onfocus="this.select()">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></p><p>Im Verwaltungstool beim Wartungsauftrag eintragen. Nicht in einen Chat einfügen.';
			if ( BW_Bridge_Key::is_active() ) {
				echo ' Der Verbindungsschlüssel gilt auch für diesen Zugang: denselben Schlüssel wie für Claude eintragen.';
			}
			echo '</p></div>';
		}
		echo '<p>Status: <strong>' . ( self::exists() ? 'Benutzer vorhanden' : 'nicht eingerichtet' ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'bw_bridge_reader' );
		echo '<input type="hidden" name="action" value="bw_bridge_reader_generate">';
		submit_button( self::exists() ? 'Neues Anwendungspasswort (das alte wird ungültig)' : 'Lesezugang anlegen', 'secondary', 'submit', false );
		echo '</form>';
		if ( self::exists() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block" onsubmit="return confirm(\'Lesezugang sperren? Das Anwendungspasswort wird gelöscht.\')">';
			wp_nonce_field( 'bw_bridge_reader' );
			echo '<input type="hidden" name="action" value="bw_bridge_reader_remove">';
			submit_button( 'Zugang sperren', 'delete', 'submit', false );
			echo '</form>';
		}
		echo '<hr>';
	}
}
