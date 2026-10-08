<?php
/**
 * Einstellungsseite (Einstellungen › BW WP Bridge) und Freigaben für den Theme-Dateizugriff.
 * Die Optionen sind nicht über die REST-API änderbar. Hart abschalten: define( 'BW_WP_BRIDGE_FILES_DISABLED', true );
 * bzw. für die Plugin-Installation define( 'BW_WP_BRIDGE_PLUGINS_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Settings {

	const OPT_READ   = 'bw_bridge_files_read';
	const OPT_WRITE  = 'bw_bridge_files_write';
	const OPT_PARENT = 'bw_bridge_files_parent';
	const OPT_PLUGINS = 'bw_bridge_plugins_install';
	const PAGE       = 'bw-wp-bridge';
	const GROUP      = 'bw_bridge_files';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
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

	public static function plugins_hard_disabled() {
		return defined( 'BW_WP_BRIDGE_PLUGINS_DISABLED' ) && BW_WP_BRIDGE_PLUGINS_DISABLED;
	}

	public static function can_install_plugins_enabled() {
		return ! self::plugins_hard_disabled() && (bool) get_option( self::OPT_PLUGINS, 0 );
	}

	/** Plugin-Installation ist durch die wp-config.php gesperrt (DISALLOW_FILE_MODS). */
	public static function config_blocks_plugins() {
		return defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
	}

	public static function plugins_summary() {
		return [
			'install'             => self::can_install_plugins_enabled() && ! self::config_blocks_plugins(),
			'config_blocks_write' => self::config_blocks_plugins(),
			'hard_disabled'       => self::plugins_hard_disabled(),
		];
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
		foreach ( [ self::OPT_READ, self::OPT_WRITE, self::OPT_PARENT, self::OPT_PLUGINS, BW_Bridge_Key::OPT_ALL ] as $opt ) {
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
		if ( self::plugins_hard_disabled() ) {
			echo '<div class="notice notice-warning inline"><p>In der wp-config.php steht <code>BW_WP_BRIDGE_PLUGINS_DISABLED</code>. Die Plugin-Installation ist fest abgeschaltet.</p></div>';
		}
		if ( self::config_blocks_writing() ) {
			echo '<div class="notice notice-warning inline"><p>Die wp-config.php sperrt Dateiänderungen (<code>DISALLOW_FILE_EDIT</code> / <code>DISALLOW_FILE_MODS</code>). Schreiben ist deshalb nicht möglich, Lesen schon.</p></div>';
		}
		BW_Bridge_Key::render_section();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( self::OPT_READ, 'Theme-Dateien lesen', 'Dateien und Ordner des aktiven Themes auflisten und lesen.' );
		self::checkbox_row( self::OPT_WRITE, 'Theme-Dateien schreiben', 'Dateien anlegen, ändern und löschen. Vor jeder Änderung wird eine Sicherung unter <code>wp-content/uploads/bw-bridge-backups-…/</code> (Ordnername nicht erratbar) angelegt, PHP-Dateien werden auf Syntaxfehler geprüft. Setzt „lesen“ voraus und braucht zusätzlich das Recht <code>edit_themes</code>.' );
		if ( $parent && $parent->exists() ) {
			self::checkbox_row( self::OPT_PARENT, 'Parent-Theme einbeziehen', 'Zusätzlich das Parent-Theme „' . esc_html( $parent->get( 'Name' ) ) . '“ (Ordner <code>' . esc_html( $parent->get_stylesheet() ) . '</code>) freigeben.' );
		}
		self::checkbox_row( self::OPT_PLUGINS, 'Plugins installieren und aktualisieren', 'Plugins aus dem wordpress.org-Verzeichnis, aus einer https-ZIP-Adresse oder aus einem hochgeladenen ZIP installieren (auf Wunsch aktivieren) und Plugins aktualisieren. Braucht zusätzlich die Rechte <code>install_plugins</code> / <code>update_plugins</code>. Auflisten der Plugins ist immer möglich.' );
		self::checkbox_row( BW_Bridge_Key::OPT_ALL, 'Schlüssel für alle REST-Anfragen', 'Den Verbindungsschlüssel nicht nur für die Bridge-Routen verlangen, sondern für jede REST-Anfrage, die per Anwendungspasswort kommt (z. B. auch andere Programme mit einem Anwendungspasswort). Wirkt nur, wenn oben ein Schlüssel eingerichtet ist.' );
		echo '</tbody></table>';
		echo '<p class="description">Aktives Theme: <strong>' . esc_html( $child->get( 'Name' ) ) . '</strong> (Ordner <code>' . esc_html( $child->get_stylesheet() ) . '</code>)</p>';
		submit_button();
		echo '</form>';
		echo '<p class="description">Die Freigabe lässt sich nur hier ändern, nicht über die API. Wer das Anwendungspasswort hat, kann mit „schreiben“ PHP-Code im Theme ändern und mit „Plugins installieren“ beliebigen Plugin-Code ausführen – nur auf Dev-/Staging-Servern einschalten und nach der Arbeit wieder ausschalten.</p></div>';
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
}
