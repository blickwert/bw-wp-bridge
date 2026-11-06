<?php
/**
 * Wartungsprotokoll: zeichnet auf, was in WordPress passiert (angelehnt an die Hooks von Simple History, aber nur
 * das, was für Wartungsberichte zählt). Jeder Hook macht nur wenig Arbeit; häufige Ereignisse werden je Tag
 * zusammengefasst (Zähler statt neuer Zeile).
 *
 * Bereiche: updates (Core, Plugins, Themes), security (Anmeldung, Benutzer, Anwendungspasswörter, kritische
 * Einstellungen, Datei-Editor), seo (Indexierung, Permalinks, Titel), changes (Inhalte, Einstellungen, Bridge-Zugriffe).
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Log_WP {

	/** Einstellungen, deren Änderung protokolliert wird, mit Bereich. */
	const OPTIONS = [
		'siteurl'              => 'security',
		'home'                 => 'security',
		'admin_email'          => 'security',
		'users_can_register'   => 'security',
		'default_role'         => 'security',
		'auto_update_plugins'  => 'updates',
		'auto_update_themes'   => 'updates',
		'blog_public'          => 'seo',
		'permalink_structure'  => 'seo',
		'blogname'             => 'seo',
		'blogdescription'      => 'seo',
		'show_on_front'        => 'seo',
		'page_on_front'        => 'seo',
		'WPLANG'               => 'changes',
		'timezone_string'      => 'changes',
		'date_format'          => 'changes',
		'default_comment_status' => 'changes',
		'comment_moderation'   => 'changes',
	];

	/** Diese Post Types erzeugen keine Einträge. */
	const SKIP_TYPES = [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'custom_css', 'scheduled-action', 'shop_order_placehold', 'shop_order', 'shop_subscription', 'elementor_snippet' ];

	/** Bridge-Routen, die nicht als REST-Schreibzugriff zählen (eigene Einträge oder nur lesend). */
	const SKIP_ROUTES = '#^/bw-bridge/v1/(history|audit|batch|plugins|scan|seo)(/|$)#';

	/** Versionen vor einem Update (vom Upgrader gemerkt). */
	private static $before = [];

	public static function init() {
		// Anmeldung und Benutzer
		add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'wp_logout', [ __CLASS__, 'on_logout' ], 10, 1 );
		add_action( 'wp_login_failed', [ __CLASS__, 'on_login_failed' ], 10, 2 );
		add_action( 'application_password_failed_authentication', [ __CLASS__, 'on_app_password_failed' ], 10, 1 );
		add_action( 'wp_create_application_password', [ __CLASS__, 'on_app_password_created' ], 10, 2 );
		add_action( 'wp_delete_application_password', [ __CLASS__, 'on_app_password_deleted' ], 10, 2 );
		add_action( 'user_register', [ __CLASS__, 'on_user_register' ], 10, 1 );
		add_action( 'delete_user', [ __CLASS__, 'on_delete_user' ], 10, 1 );
		add_action( 'set_user_role', [ __CLASS__, 'on_set_user_role' ], 10, 3 );
		add_action( 'after_password_reset', [ __CLASS__, 'on_password_reset' ], 10, 1 );
		// Updates, Plugins, Themes
		add_filter( 'upgrader_pre_install', [ __CLASS__, 'remember_version' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'on_upgrader_complete' ], 10, 2 );
		add_action( '_core_updated_successfully', [ __CLASS__, 'on_core_updated' ], 10, 1 );
		add_action( 'activated_plugin', [ __CLASS__, 'on_plugin_activated' ], 10, 2 );
		add_action( 'deactivated_plugin', [ __CLASS__, 'on_plugin_deactivated' ], 10, 2 );
		add_action( 'deleted_plugin', [ __CLASS__, 'on_plugin_deleted' ], 10, 2 );
		add_action( 'switch_theme', [ __CLASS__, 'on_switch_theme' ], 10, 3 );
		add_action( 'deleted_theme', [ __CLASS__, 'on_theme_deleted' ], 10, 2 );
		add_action( 'wp_ajax_edit-theme-plugin-file', [ __CLASS__, 'on_file_editor' ], 1 );
		// Einstellungen und Inhalte
		add_action( 'updated_option', [ __CLASS__, 'on_option' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_post_status' ], 10, 3 );
		add_action( 'post_updated', [ __CLASS__, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_post_deleted' ], 10, 2 );
		// Schreibzugriffe über die REST-API (Bridge, Anwendungspasswort, Claude)
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'on_rest_write' ], 99, 3 );
	}

	/** In dieser Anfrage wurde schon ein Inhalt protokolliert (dann kein zusätzlicher Eintrag für wp/v2/…). */
	private static $content_logged = false;

	private static function log( array $e ) {
		if ( 'content' === ( $e['category'] ?? '' ) ) {
			self::$content_logged = true;
		}
		return BW_Bridge_Log::add( $e );
	}

	/* ------------------------------------------------------------------ */
	/* Anmeldung und Benutzer                                              */
	/* ------------------------------------------------------------------ */

	public static function on_login( $login, $user = null ) {
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}
		$admin = in_array( 'administrator', (array) $user->roles, true );
		self::log( [
			'area' => 'security', 'category' => 'auth', 'action' => 'login', 'user_id' => $user->ID,
			'level' => $admin ? 'notice' : 'info', 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $login,
			'message' => sprintf( '%s hat sich angemeldet%s', $login, $admin ? ' (Administrator)' : '' ),
			'aggregate' => 'login',
		] );
	}

	public static function on_logout( $user_id = 0 ) {
		$u = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $u ) {
			return;
		}
		self::log( [ 'area' => 'security', 'category' => 'auth', 'action' => 'logout', 'user_id' => $u->ID, 'object_type' => 'user', 'object_id' => $u->ID, 'object_name' => $u->user_login, 'message' => $u->user_login . ' hat sich abgemeldet', 'aggregate' => 'logout' ] );
	}

	/** Fehlgeschlagene Anmeldungen: ein Eintrag je Tag und Benutzername (mit Zähler). */
	public static function on_login_failed( $username, $error = null ) {
		$known = (bool) get_user_by( 'login', $username ) || (bool) get_user_by( 'email', $username );
		self::log( [
			'area' => 'security', 'category' => 'auth', 'action' => 'login_failed', 'level' => 'warning', 'user_id' => 0,
			'object_type' => 'user', 'object_name' => $username,
			'message' => sprintf( 'Fehlgeschlagene Anmeldung für „%s“%s', $username, $known ? '' : ' (Benutzer existiert nicht)' ),
			'details' => [ 'existing_user' => $known, 'code' => is_wp_error( $error ) ? $error->get_error_code() : null ],
			'aggregate' => strtolower( (string) $username ),
		] );
	}

	public static function on_app_password_failed( $error = null ) {
		self::log( [
			'area' => 'security', 'category' => 'auth', 'action' => 'app_password_failed', 'level' => 'warning', 'user_id' => 0,
			'message' => 'Fehlgeschlagene Anmeldung mit Anwendungspasswort',
			'details' => [ 'code' => is_wp_error( $error ) ? $error->get_error_code() : null ],
			'aggregate' => 'app',
		] );
	}

	public static function on_app_password_created( $user_id, $item = [] ) {
		$u = get_userdata( $user_id );
		self::log( [
			'area' => 'security', 'category' => 'user', 'action' => 'app_password_created', 'level' => 'notice',
			'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '',
			'message' => sprintf( 'Anwendungspasswort „%s“ für %s angelegt', $item['name'] ?? '', $u ? $u->user_login : '#' . $user_id ),
		] );
	}

	public static function on_app_password_deleted( $user_id, $item = [] ) {
		$u = get_userdata( $user_id );
		self::log( [
			'area' => 'security', 'category' => 'user', 'action' => 'app_password_deleted',
			'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '',
			'message' => sprintf( 'Anwendungspasswort „%s“ von %s gelöscht', $item['name'] ?? '', $u ? $u->user_login : '#' . $user_id ),
		] );
	}

	public static function on_user_register( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		$admin = in_array( 'administrator', (array) $u->roles, true );
		self::log( [
			'area' => 'security', 'category' => 'user', 'action' => 'created', 'level' => $admin ? 'warning' : 'info',
			'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u->user_login,
			'message' => sprintf( 'Benutzer %s angelegt (Rolle: %s)', $u->user_login, implode( ', ', (array) $u->roles ) ?: '–' ),
			'details' => [ 'roles' => array_values( (array) $u->roles ) ],
		] );
	}

	public static function on_delete_user( $user_id ) {
		$u = get_userdata( $user_id );
		self::log( [
			'area' => 'security', 'category' => 'user', 'action' => 'deleted', 'level' => 'notice',
			'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '',
			'message' => sprintf( 'Benutzer %s gelöscht', $u ? $u->user_login : '#' . $user_id ),
		] );
	}

	public static function on_set_user_role( $user_id, $role, $old_roles = [] ) {
		if ( ! $old_roles ) {
			return; // neuer Benutzer, steht schon unter „angelegt“
		}
		$u = get_userdata( $user_id );
		self::log( [
			'area' => 'security', 'category' => 'user', 'action' => 'role_changed', 'level' => 'administrator' === $role ? 'warning' : 'notice',
			'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '',
			'message' => sprintf( 'Rolle von %s: %s → %s', $u ? $u->user_login : '#' . $user_id, implode( ', ', (array) $old_roles ), $role ),
			'details' => [ 'from' => array_values( (array) $old_roles ), 'to' => $role ],
		] );
	}

	public static function on_password_reset( $user ) {
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}
		self::log( [ 'area' => 'security', 'category' => 'user', 'action' => 'password_reset', 'user_id' => 0, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $user->user_login, 'message' => 'Passwort zurückgesetzt: ' . $user->user_login ] );
	}

	/* ------------------------------------------------------------------ */
	/* Updates, Plugins, Themes                                            */
	/* ------------------------------------------------------------------ */

	/** Merkt sich die Version vor dem Update (Filter, gibt $return unverändert zurück). */
	public static function remember_version( $return, $extra = [] ) {
		if ( ! empty( $extra['plugin'] ) ) {
			$data = self::plugin_data( $extra['plugin'] );
			self::$before[ 'plugin:' . $extra['plugin'] ] = $data['Version'] ?? '';
		} elseif ( ! empty( $extra['theme'] ) && function_exists( 'wp_get_theme' ) ) {
			$t = wp_get_theme( $extra['theme'] );
			self::$before[ 'theme:' . $extra['theme'] ] = $t->exists() ? (string) $t->get( 'Version' ) : '';
		}
		return $return;
	}

	private static function plugin_data( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		return is_file( $path ) ? get_plugin_data( $path, false, false ) : [];
	}

	public static function on_upgrader_complete( $upgrader, $o = [] ) {
		$type   = $o['type'] ?? '';
		$action = $o['action'] ?? '';
		if ( 'plugin' === $type ) {
			if ( 'install' === $action ) {
				$d    = (array) ( $upgrader->new_plugin_data ?? [] );
				$file = method_exists( $upgrader, 'plugin_info' ) ? (string) $upgrader->plugin_info() : '';
				self::log( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'installed', 'level' => 'notice', 'object_type' => 'plugin', 'object_id' => $file, 'object_name' => $d['Name'] ?? $file, 'message' => sprintf( 'Plugin installiert: %s %s', $d['Name'] ?? $file, $d['Version'] ?? '' ), 'details' => [ 'to' => $d['Version'] ?? null ] ] );
				return;
			}
			$files = ! empty( $o['plugins'] ) ? (array) $o['plugins'] : ( ! empty( $o['plugin'] ) ? [ $o['plugin'] ] : [] );
			foreach ( $files as $file ) {
				$d    = self::plugin_data( $file );
				$from = self::$before[ 'plugin:' . $file ] ?? null;
				$to   = $d['Version'] ?? null;
				self::log( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'updated', 'object_type' => 'plugin', 'object_id' => $file, 'object_name' => $d['Name'] ?? $file, 'message' => sprintf( 'Plugin aktualisiert: %s %s → %s', $d['Name'] ?? $file, $from ?: '?', $to ?: '?' ), 'details' => [ 'from' => $from, 'to' => $to ] ] );
			}
		} elseif ( 'theme' === $type ) {
			if ( 'install' === $action ) {
				$d = (array) ( $upgrader->new_theme_data ?? [] );
				self::log( [ 'area' => 'updates', 'category' => 'theme', 'action' => 'installed', 'level' => 'notice', 'object_type' => 'theme', 'object_name' => $d['Name'] ?? '', 'message' => sprintf( 'Theme installiert: %s %s', $d['Name'] ?? '', $d['Version'] ?? '' ), 'details' => [ 'to' => $d['Version'] ?? null ] ] );
				return;
			}
			$slugs = ! empty( $o['themes'] ) ? (array) $o['themes'] : ( ! empty( $o['theme'] ) ? [ $o['theme'] ] : [] );
			foreach ( $slugs as $slug ) {
				$t    = wp_get_theme( $slug );
				$from = self::$before[ 'theme:' . $slug ] ?? null;
				$to   = $t->exists() ? (string) $t->get( 'Version' ) : null;
				self::log( [ 'area' => 'updates', 'category' => 'theme', 'action' => 'updated', 'object_type' => 'theme', 'object_id' => $slug, 'object_name' => $t->exists() ? (string) $t->get( 'Name' ) : $slug, 'message' => sprintf( 'Theme aktualisiert: %s %s → %s', $t->exists() ? $t->get( 'Name' ) : $slug, $from ?: '?', $to ?: '?' ), 'details' => [ 'from' => $from, 'to' => $to ] ] );
			}
		} elseif ( 'translation' === $type ) {
			self::log( [ 'area' => 'updates', 'category' => 'translation', 'action' => 'updated', 'message' => 'Übersetzungen aktualisiert', 'aggregate' => 'translations' ] );
		}
	}

	/** Beim Aufruf ist $wp_version noch die alte Version. */
	public static function on_core_updated( $new_version ) {
		global $wp_version;
		self::log( [ 'area' => 'updates', 'category' => 'core', 'action' => 'updated', 'object_type' => 'core', 'object_name' => 'WordPress', 'message' => sprintf( 'WordPress aktualisiert: %s → %s', $wp_version, $new_version ), 'details' => [ 'from' => $wp_version, 'to' => $new_version ] ] );
	}

	public static function on_plugin_activated( $file, $network = false ) {
		$d = self::plugin_data( $file );
		self::log( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'activated', 'object_type' => 'plugin', 'object_id' => $file, 'object_name' => $d['Name'] ?? $file, 'message' => 'Plugin aktiviert: ' . ( $d['Name'] ?? $file ) ] );
	}

	public static function on_plugin_deactivated( $file, $network = false ) {
		$d = self::plugin_data( $file );
		self::log( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'deactivated', 'level' => 'notice', 'object_type' => 'plugin', 'object_id' => $file, 'object_name' => $d['Name'] ?? $file, 'message' => 'Plugin deaktiviert: ' . ( $d['Name'] ?? $file ) ] );
	}

	public static function on_plugin_deleted( $file, $deleted = true ) {
		if ( $deleted ) {
			self::log( [ 'area' => 'updates', 'category' => 'plugin', 'action' => 'deleted', 'level' => 'notice', 'object_type' => 'plugin', 'object_id' => $file, 'object_name' => $file, 'message' => 'Plugin gelöscht: ' . $file ] );
		}
	}

	public static function on_switch_theme( $name, $new = null, $old = null ) {
		self::log( [ 'area' => 'updates', 'category' => 'theme', 'action' => 'switched', 'level' => 'notice', 'object_type' => 'theme', 'object_name' => $name, 'message' => sprintf( 'Theme gewechselt: %s → %s', $old ? $old->get( 'Name' ) : '?', $name ) ] );
	}

	public static function on_theme_deleted( $stylesheet, $deleted = true ) {
		if ( $deleted ) {
			self::log( [ 'area' => 'updates', 'category' => 'theme', 'action' => 'deleted', 'level' => 'notice', 'object_type' => 'theme', 'object_id' => $stylesheet, 'object_name' => $stylesheet, 'message' => 'Theme gelöscht: ' . $stylesheet ] );
		}
	}

	/** Theme-/Plugin-Datei-Editor im Backend. */
	public static function on_file_editor() {
		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$what = isset( $_POST['plugin'] ) ? 'Plugin' : 'Theme';
		if ( '' !== $file ) {
			self::log( [ 'area' => 'security', 'category' => 'file', 'action' => 'edited', 'level' => 'warning', 'object_type' => strtolower( $what ), 'object_name' => $file, 'message' => sprintf( '%s-Datei im Backend-Editor gespeichert: %s', $what, $file ) ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen und Inhalte                                           */
	/* ------------------------------------------------------------------ */

	public static function on_option( $option, $old, $new ) {
		if ( ! isset( self::OPTIONS[ $option ] ) || $old === $new ) {
			return;
		}
		$short = static function ( $v ) {
			$s = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			return strlen( $s ) > 120 ? substr( $s, 0, 117 ) . '…' : $s;
		};
		$level = ( 'blog_public' === $option && ! $new ) || ( 'default_role' === $option && 'administrator' === $new ) || ( 'users_can_register' === $option && $new ) ? 'warning' : 'info';
		self::log( [
			'area' => self::OPTIONS[ $option ], 'category' => 'settings', 'action' => 'changed', 'level' => $level,
			'object_type' => 'option', 'object_id' => $option, 'object_name' => $option,
			'message' => sprintf( 'Einstellung %s: „%s“ → „%s“', $option, $short( $old ), $short( $new ) ),
			'details' => [ 'from' => $short( $old ), 'to' => $short( $new ) ],
		] );
	}

	private static function skip_post( $post ) {
		return ! $post || in_array( $post->post_type, self::SKIP_TYPES, true ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' === $post->post_status;
	}

	private static function post_entry( $post, $action, $message, array $extra = [] ) {
		$type = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post->post_type ) : null;
		return $extra + [
			'area' => 'changes', 'category' => 'content', 'action' => $action,
			'object_type' => $post->post_type, 'object_id' => $post->ID, 'object_name' => $post->post_title,
			'message' => sprintf( $message, $type && isset( $type->labels->singular_name ) ? $type->labels->singular_name : $post->post_type, $post->post_title ?: '#' . $post->ID ),
		];
	}

	public static function on_post_status( $new, $old, $post ) {
		if ( $new === $old || self::skip_post( $post ) || 'auto-draft' === $new || 'inherit' === $new ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::log( self::post_entry( $post, 'published', '%s veröffentlicht: %s' ) );
		} elseif ( 'trash' === $new ) {
			self::log( self::post_entry( $post, 'trashed', '%s in den Papierkorb: %s', [ 'level' => 'notice' ] ) );
		} elseif ( 'publish' === $old ) {
			self::log( self::post_entry( $post, 'unpublished', '%s nicht mehr veröffentlicht (' . $new . '): %s', [ 'level' => 'notice' ] ) );
		} elseif ( 'new' === $old || 'auto-draft' === $old ) {
			self::log( self::post_entry( $post, 'created', '%s angelegt (' . $new . '): %s' ) );
		}
	}

	/** Bearbeitungen: ein Eintrag je Beitrag, Benutzer und Tag (Zähler = Anzahl Speicherungen). */
	public static function on_post_updated( $id, $after, $before ) {
		if ( self::skip_post( $after ) || $after->post_status !== $before->post_status || in_array( $after->post_status, [ 'trash', 'draft', 'auto-draft', 'inherit' ], true ) ) {
			return;
		}
		self::log( self::post_entry( $after, 'edited', '%s bearbeitet: %s', [ 'aggregate' => 'post:' . $id ] ) );
	}

	public static function on_post_deleted( $id, $post = null ) {
		$post = $post ?: get_post( $id );
		if ( self::skip_post( $post ) ) {
			return;
		}
		self::log( self::post_entry( $post, 'deleted', '%s endgültig gelöscht: %s', [ 'level' => 'notice' ] ) );
	}

	/* ------------------------------------------------------------------ */
	/* REST-Schreibzugriffe                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Erfolgreiche schreibende REST-Anfragen über die Bridge oder mit Anwendungspasswort (z. B. Claude).
	 * Ein Eintrag je Route, Benutzer und Tag; Probeläufe (dry_run) zählen nicht. Gibt $response unverändert zurück.
	 */
	public static function on_rest_write( $response, $handler = null, $request = null ) {
		if ( ! $request || ! self::should_log_rest( $request, $response ) ) {
			return $response;
		}
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		$path   = $request->get_param( 'path' );
		$name   = $path ? (string) $path : ( $request->get_param( 'id' ) ? '#' . (int) $request->get_param( 'id' ) : '' );
		$id     = $request->get_param( 'id' );
		if ( $id && function_exists( 'get_post' ) && ( $p = get_post( (int) $id ) ) ) {
			$name = $p->post_title . ' (#' . $p->ID . ')';
		}
		self::log( [
			'area' => 0 === strpos( $route, '/bw-bridge/v1/theme/' ) ? 'security' : 'changes',
			'category' => 'bridge', 'action' => strtolower( $method ), 'source' => 'bridge',
			'object_type' => 'route', 'object_id' => self::route_pattern( $route ), 'object_name' => $name,
			'message' => trim( sprintf( '%s %s %s', $method, $route, $name && false === strpos( $route, (string) $name ) ? '– ' . $name : '' ) ),
			'aggregate' => $method . ' ' . $route,
		] );
		return $response;
	}

	public static function should_log_rest( $request, $response ) {
		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
			return false;
		}
		if ( is_wp_error( $response ) || ( is_object( $response ) && method_exists( $response, 'get_status' ) && $response->get_status() >= 400 ) ) {
			return false;
		}
		$route = (string) $request->get_route();
		if ( preg_match( self::SKIP_ROUTES, $route ) || $request->get_param( 'dry_run' ) ) {
			return false;
		}
		if ( self::$content_logged && 0 === strpos( $route, '/wp/v2/' ) ) {
			return false; // steht schon als Inhaltsänderung im Protokoll
		}
		return 0 === strpos( $route, '/bw-bridge/v1/' ) || did_action( 'application_password_did_authenticate' );
	}

	/** /wp/v2/pages/12 → /wp/v2/pages/{id} (für Auswertungen). */
	public static function route_pattern( $route ) {
		return (string) preg_replace( '#/\d+(?=/|$)#', '/{id}', $route );
	}
}
