<?php
/**
 * Einstellungen (Newsletter › Brevo). Die Freigabe für Live-Aktionen ist nur hier änderbar, nicht über die REST-API.
 * Hart sperren: define( 'BW_BREVO_LIVE_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Settings {

	const GROUP = 'bwb_settings';
	const PAGE  = 'bwb-settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	/** Versand, Planen, Testmails an Fremde, Automations-Ereignisse – nur mit Freigabe. */
	public static function live() {
		return ! ( defined( 'BW_BREVO_LIVE_DISABLED' ) && BW_BREVO_LIVE_DISABLED ) && (bool) get_option( 'bwb_live', 0 );
	}

	public static function default_lists() {
		return self::ids( get_option( 'bwb_default_lists', '' ) );
	}

	public static function ids( $v ) {
		$out = [];
		foreach ( is_array( $v ) ? $v : preg_split( '/[\s,;]+/', (string) $v ) as $x ) {
			if ( is_numeric( $x ) && (int) $x > 0 ) {
				$out[] = (int) $x;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function emails( $v ) {
		$out = [];
		foreach ( is_array( $v ) ? $v : preg_split( '/[\s,;]+/', (string) $v ) as $x ) {
			$x = sanitize_email( trim( (string) $x ) );
			if ( '' !== $x && is_email( $x ) ) {
				$out[] = strtolower( $x );
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function register() {
		$bool = static function ( $v ) {
			return $v ? 1 : 0;
		};
		$text = [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'show_in_rest' => false ];
		register_setting( self::GROUP, 'bwb_sender_name', $text );
		register_setting( self::GROUP, 'bwb_sender_email', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'show_in_rest' => false ] );
		register_setting( self::GROUP, 'bwb_default_lists', [ 'type' => 'string', 'sanitize_callback' => static function ( $v ) { return implode( ',', self::ids( $v ) ); }, 'show_in_rest' => false ] );
		register_setting( self::GROUP, 'bwb_test_emails', [ 'type' => 'string', 'sanitize_callback' => static function ( $v ) { return implode( ',', self::emails( $v ) ); }, 'show_in_rest' => false ] );
		register_setting( self::GROUP, 'bwb_footer', [ 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'show_in_rest' => false ] );
		register_setting( self::GROUP, 'bwb_live', [ 'type' => 'boolean', 'sanitize_callback' => $bool, 'default' => 0, 'show_in_rest' => false ] );
		register_setting( self::GROUP, 'bwb_consent_required', [ 'type' => 'boolean', 'sanitize_callback' => $bool, 'default' => 1, 'show_in_rest' => false ] );
		// Leeres Feld = bisherigen Schlüssel behalten.
		register_setting( self::GROUP, 'bwb_api_key', [ 'type' => 'string', 'show_in_rest' => false, 'sanitize_callback' => static function ( $v ) {
			$v = trim( (string) $v );
			return '' === $v ? (string) get_option( 'bwb_api_key', '' ) : sanitize_text_field( $v );
		} ] );
		register_setting( self::GROUP, 'bwb_rules', [ 'type' => 'array', 'show_in_rest' => false, 'sanitize_callback' => [ 'BW_Brevo_Automations', 'sanitize_rules' ] ] );
	}

	public static function admin_menu() {
		add_submenu_page( 'edit.php?post_type=' . BW_Brevo_Campaigns::CPT, 'Brevo', 'Brevo', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$const = defined( 'BW_BREVO_API_KEY' ) && BW_BREVO_API_KEY;
		echo '<div class="wrap"><h1>Brevo Bridge</h1>';
		if ( defined( 'BW_BREVO_LIVE_DISABLED' ) && BW_BREVO_LIVE_DISABLED ) {
			echo '<div class="notice notice-warning inline"><p>In der wp-config.php steht <code>BW_BREVO_LIVE_DISABLED</code>: Versand und Automationen sind fest gesperrt.</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">API-Schlüssel</th><td>';
		if ( $const ) {
			echo '<em>kommt aus der wp-config.php (<code>BW_BREVO_API_KEY</code>)</em>';
		} else {
			echo '<input type="password" name="bwb_api_key" class="regular-text" autocomplete="off" placeholder="' . ( get_option( 'bwb_api_key' ) ? '•••••• (gesetzt – leer lassen zum Behalten)' : 'xkeysib-…' ) . '">';
			echo '<p class="description">Besser in der wp-config.php: <code>define( \'BW_BREVO_API_KEY\', \'xkeysib-…\' );</code> Der Schlüssel braucht Zugriff auf die Marketing-API (Kampagnen, Kontakte, Ereignisse, Webhooks).</p>';
		}
		echo '</td></tr>';
		self::text_row( 'bwb_sender_name', 'Absendername' );
		self::text_row( 'bwb_sender_email', 'Absender-E-Mail', 'In Brevo als Absender verifiziert (SPF/DKIM/DMARC der Domain eingerichtet – sonst leidet die Zustellbarkeit).' );
		self::text_row( 'bwb_default_lists', 'Standard-Listen (IDs)', 'Kommagetrennt, z. B. <code>3,7</code>. Gilt, wenn ein Newsletter keine eigenen Listen hat.' );
		self::text_row( 'bwb_test_emails', 'Testempfänger', 'Kommagetrennt. Testmails gehen immer nur an diese Adressen.' );
		echo '<tr><th scope="row">Fußzeile</th><td><textarea name="bwb_footer" rows="4" class="large-text">' . esc_textarea( get_option( 'bwb_footer', '' ) ) . '</textarea><p class="description">Anbieterangaben/Impressum für das Mail-Ende. Der Abmeldelink wird automatisch ergänzt.</p></td></tr>';
		self::check_row( 'bwb_consent_required', 'Einwilligung prüfen', 'Automations-Ereignisse nur für Personen senden, bei denen die Einwilligung gespeichert ist (Benutzer-/Bestell-Meta <code>bwb_consent</code> = 1 oder Filter <code>bwb_has_consent</code>). Nur abschalten, wenn die Einwilligung anderweitig belegt ist.' );
		self::check_row( 'bwb_live', 'Live-Aktionen freigeben', '<strong>Versenden, Planen und Automations-Ereignisse.</strong> Aus = Probelauf: Entwürfe und Testmails an die Testempfänger funktionieren, alles andere wird nur im Protokoll („outbox“) vermerkt. Nicht über die API änderbar.' );
		echo '</tbody></table>';
		BW_Brevo_Automations::render_rules();
		submit_button();
		echo '</form>';
		echo '<h2>Webhook</h2><p>Adresse für Brevo (Zustellbarkeits-Ereignisse):</p><p><input type="text" readonly class="large-text code" value="' . esc_attr( BW_Brevo_Webhook::url() ) . '" onfocus="this.select()"></p>';
		echo '<p class="description">Anlegen per Klick oder Claude: <code>wp_bridge.py post bw-bridge/v1/brevo/webhook/register</code>. Der Token in der Adresse ist geheim.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwb_webhook' );
		echo '<input type="hidden" name="action" value="bwb_webhook_register">';
		submit_button( 'Webhook bei Brevo anlegen', 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private static function text_row( $opt, $label, $help = '' ) {
		printf( '<tr><th scope="row">%s</th><td><input type="text" name="%s" value="%s" class="regular-text">%s</td></tr>', esc_html( $label ), esc_attr( $opt ), esc_attr( (string) get_option( $opt, '' ) ), $help ? '<p class="description">' . $help . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- feste Texte
	}

	private static function check_row( $opt, $label, $help ) {
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s> aktivieren</label><p class="description">%s</p></td></tr>', esc_html( $label ), esc_attr( $opt ), checked( (bool) get_option( $opt, 'bwb_consent_required' === $opt ? 1 : 0 ), true, false ), $help ); // phpcs:ignore WordPress.Security.EscapeOutput -- feste Texte
	}
}
