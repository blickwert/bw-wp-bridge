<?php
/**
 * Sicherheits- und SEO-Schnappschuss (nur lesend). Liefert Fakten mit einer Einstufung je Prüfung:
 * ok, info, warn (Empfehlung), fail (dringend). Die Bewertung im Bericht und die Empfehlungen schreibt Claude.
 *
 * Alle Prüfungen sind günstig: Optionen, Konstanten, Update-Transients und wenige COUNT-Abfragen, keine HTTP-Anfragen.
 * Einmal pro Woche legt der Cron einen kompakten Schnappschuss im Protokoll ab (Bereich system), damit der Jahresbericht
 * die Entwicklung zeigen kann.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Audit {

	const STATUSES = [ 'ok', 'info', 'warn', 'fail' ];

	public static function register_routes() {
		register_rest_route( BW_WP_Bridge::NS, '/audit', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'route' ],
			'permission_callback' => [ 'BW_WP_Bridge', 'can_manage' ],
		] );
	}

	public static function route() {
		return rest_ensure_response( self::run() );
	}

	/** Alle Prüfungen: [ security => [...], seo => [...], counts => [...], time ]. */
	public static function run() {
		$sec = self::security();
		$seo = self::seo();
		return [
			'time'     => gmdate( 'c' ),
			'security' => $sec,
			'seo'      => $seo,
			'counts'   => [ 'security' => self::count( $sec ), 'seo' => self::count( $seo ) ],
		];
	}

	public static function count( array $checks ) {
		$c = array_fill_keys( self::STATUSES, 0 );
		foreach ( $checks as $x ) {
			$c[ $x['status'] ]++;
		}
		return $c;
	}

	private static function check( $id, $status, $title, $detail = '', $data = null ) {
		$r = [ 'id' => $id, 'status' => $status, 'title' => $title, 'detail' => $detail ];
		if ( null !== $data ) {
			$r['data'] = $data;
		}
		return $r;
	}

	/* ------------------------------------------------------------------ */
	/* Sicherheit                                                          */
	/* ------------------------------------------------------------------ */

	public static function security() {
		global $wpdb, $wp_version;
		$out = [];

		// WordPress-Version
		$core   = get_site_transient( 'update_core' );
		$latest = null;
		if ( $core && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$latest = $u->current ?? $u->version ?? null;
					break;
				}
			}
		}
		$out[] = $latest
			? self::check( 'core_version', 'fail', 'WordPress ist nicht aktuell', sprintf( 'Installiert %s, verfügbar %s.', $wp_version, $latest ), [ 'installed' => $wp_version, 'available' => $latest ] )
			: self::check( 'core_version', 'ok', 'WordPress ist aktuell', 'Version ' . $wp_version . ( $core ? '' : ' (Update-Prüfung noch nicht gelaufen)' ), [ 'installed' => $wp_version ] );

		// PHP
		$php    = PHP_VERSION;
		$status = version_compare( $php, '8.1', '<' ) ? 'fail' : ( version_compare( $php, '8.2', '<' ) ? 'warn' : 'ok' );
		$out[]  = self::check( 'php_version', $status, 'PHP ' . $php, 'fail' === $status ? 'Diese PHP-Version bekommt keine Sicherheitsupdates mehr.' : ( 'warn' === $status ? 'Nur noch Sicherheitsupdates; Umstieg auf eine neuere Version einplanen.' : 'Unterstützte Version.' ), [ 'version' => $php ] );

		// Plugin- und Theme-Updates
		$plugins = self::plugins();
		$pending = array_values( array_filter( $plugins, static fn( $p ) => $p['update'] ) );
		$out[]   = self::check( 'plugin_updates', $pending ? 'fail' : 'ok', $pending ? count( $pending ) . ' Plugin-Update(s) offen' : 'Alle Plugins aktuell', implode( ', ', array_map( static fn( $p ) => $p['name'] . ' ' . $p['version'] . ' → ' . $p['update'], $pending ) ), $pending );
		$themes  = get_site_transient( 'update_themes' );
		$tpend   = $themes && ! empty( $themes->response ) ? array_keys( (array) $themes->response ) : [];
		$out[]   = self::check( 'theme_updates', $tpend ? 'warn' : 'ok', $tpend ? count( $tpend ) . ' Theme-Update(s) offen' : 'Alle Themes aktuell', implode( ', ', $tpend ), $tpend ?: null );
		$inactive = array_values( array_filter( $plugins, static fn( $p ) => ! $p['active'] ) );
		$out[]    = self::check( 'inactive_plugins', $inactive ? 'warn' : 'ok', $inactive ? count( $inactive ) . ' inaktive(s) Plugin(s)' : 'Keine inaktiven Plugins', $inactive ? 'Nicht genutzte Plugins löschen (auch inaktiver Code kann angreifbar sein): ' . implode( ', ', array_column( $inactive, 'name' ) ) : '' );

		// Automatische Updates
		$auto      = (array) get_site_option( 'auto_update_plugins', [] );
		$auto_on   = count( array_intersect( $auto, array_column( $plugins, 'plugin' ) ) );
		$out[]     = self::check( 'auto_updates', 'info', sprintf( 'Automatische Updates: %d von %d Plugins', $auto_on, count( $plugins ) ), 'Core: ' . ( defined( 'WP_AUTO_UPDATE_CORE' ) ? var_export( WP_AUTO_UPDATE_CORE, true ) : 'Standard (Sicherheitsupdates)' ) . ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ? ' – AUTOMATIC_UPDATER_DISABLED ist gesetzt' : '' ) );

		// Administratoren
		$admins = get_users( [ 'role' => 'administrator', 'fields' => [ 'ID', 'user_login', 'user_email' ] ] );
		$names  = array_map( static fn( $u ) => $u->user_login, $admins );
		$out[]  = self::check( 'admins', count( $admins ) > 3 ? 'warn' : 'info', count( $admins ) . ' Administrator(en)', implode( ', ', $names ), $names );
		if ( in_array( 'admin', array_map( 'strtolower', $names ), true ) ) {
			$out[] = self::check( 'admin_username', 'warn', 'Benutzername „admin“ vorhanden', 'Häufigstes Ziel von Brute-Force-Angriffen; umbenennen bzw. neuen Admin anlegen und diesen löschen.' );
		}

		// Anwendungspasswörter der Administratoren
		$app = [];
		if ( class_exists( 'WP_Application_Passwords' ) ) {
			foreach ( $admins as $u ) {
				foreach ( WP_Application_Passwords::get_user_application_passwords( $u->ID ) as $item ) {
					$app[] = [ 'user' => $u->user_login, 'name' => $item['name'], 'last_used' => ! empty( $item['last_used'] ) ? gmdate( 'Y-m-d', (int) $item['last_used'] ) : null ];
				}
			}
		}
		$stale = array_filter( $app, static fn( $a ) => ! $a['last_used'] || strtotime( $a['last_used'] ) < time() - 180 * DAY_IN_SECONDS );
		$out[] = self::check( 'app_passwords', $stale ? 'warn' : 'info', count( $app ) . ' Anwendungspasswort/-wörter für Administratoren', $stale ? count( $stale ) . ' davon seit über 180 Tagen nicht benutzt – widerrufen, wenn nicht mehr nötig.' : '', $app );

		// Registrierung
		$reg  = (bool) get_option( 'users_can_register' );
		$role = (string) get_option( 'default_role' );
		$out[] = self::check( 'registration', $reg && in_array( $role, [ 'administrator', 'editor' ], true ) ? 'fail' : ( $reg ? 'info' : 'ok' ), $reg ? 'Registrierung offen (Standardrolle: ' . $role . ')' : 'Registrierung geschlossen' );

		// Konfiguration
		$edit  = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		$out[] = self::check( 'file_edit', $edit ? 'ok' : 'warn', $edit ? 'Datei-Editor im Backend gesperrt' : 'Datei-Editor im Backend erlaubt', $edit ? '' : 'In der wp-config.php define( \'DISALLOW_FILE_EDIT\', true ); setzen.' );
		$debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display = $debug && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) && ini_get( 'display_errors' );
		$out[]   = self::check( 'debug', $display ? 'fail' : ( $debug ? 'warn' : 'ok' ), $display ? 'Fehlermeldungen werden öffentlich angezeigt' : ( $debug ? 'WP_DEBUG ist an' : 'Debug-Modus aus' ), $display ? 'WP_DEBUG_DISPLAY auf false setzen.' : '' );
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( is_file( $log ) ) {
			$size  = (int) filesize( $log );
			$out[] = self::check( 'debug_log', 'warn', 'debug.log im wp-content-Ordner (' . size_format( $size ) . ')', 'Liegt im öffentlich erreichbaren Ordner und kann Pfade und Daten verraten. Löschen oder per WP_DEBUG_LOG an einen Ort außerhalb des Webroots schreiben.', [ 'bytes' => $size ] );
		}
		$https = 0 === strpos( (string) get_option( 'home' ), 'https://' ) && 0 === strpos( (string) get_option( 'siteurl' ), 'https://' );
		$out[] = self::check( 'https', $https ? 'ok' : 'fail', $https ? 'HTTPS aktiv' : 'Website-Adresse ohne HTTPS', $https ? '' : 'home/siteurl auf https:// umstellen.' );
		$xmlrpc = (bool) apply_filters( 'xmlrpc_enabled', true );
		$out[]  = self::check( 'xmlrpc', $xmlrpc ? 'info' : 'ok', $xmlrpc ? 'XML-RPC aktiv' : 'XML-RPC abgeschaltet', $xmlrpc ? 'Wird nur von Jetpack und alten Apps gebraucht; sonst abschalten (Angriffsfläche für Brute-Force).' : '' );
		if ( 'wp_' === $wpdb->prefix ) {
			$out[] = self::check( 'db_prefix', 'info', 'Standard-Tabellenpräfix wp_', 'Kein Risiko für sich, erschwert aber automatisierte SQL-Angriffe nicht.' );
		}

		// Site Health (Ergebnis des letzten Laufs im Backend)
		$health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
		if ( is_array( $health ) ) {
			$crit  = (int) ( $health['critical'] ?? 0 );
			$out[] = self::check( 'site_health', $crit ? 'fail' : ( (int) ( $health['recommended'] ?? 0 ) ? 'warn' : 'ok' ), sprintf( 'Website-Zustand: %d kritisch, %d empfohlen, %d gut', $crit, (int) ( $health['recommended'] ?? 0 ), (int) ( $health['good'] ?? 0 ) ), 'Letzter Lauf unter Werkzeuge › Website-Zustand.', $health );
		}

		// Datei- und Schwachstellen-Scan
		if ( ! BW_Bridge_Scan::disabled() ) {
			foreach ( self::scan_checks( BW_Bridge_Scan::status() ) as $c ) {
				$out[] = $c;
			}
		}

		// Freigaben der Bridge selbst
		$bridge = [];
		if ( BW_Bridge_Settings::can_write_enabled() ) {
			$bridge[] = 'Theme-Dateien schreiben';
		}
		if ( BW_Bridge_Settings::can_install_plugins_enabled() ) {
			$bridge[] = 'Plugins installieren';
		}
		$out[] = self::check( 'bridge_permissions', $bridge ? 'warn' : 'ok', $bridge ? 'Bridge-Freigaben eingeschaltet: ' . implode( ', ', $bridge ) : 'Keine riskanten Bridge-Freigaben', $bridge ? 'Nach der Arbeit wieder ausschalten (Einstellungen › BW WP Bridge).' : '' );
		$out[] = self::check( 'bridge_key', BW_Bridge_Key::is_active() ? 'ok' : 'warn', BW_Bridge_Key::is_active() ? 'Verbindungsschlüssel aktiv' : 'Kein Verbindungsschlüssel', BW_Bridge_Key::is_active() ? '' : 'Unter Einstellungen › BW WP Bridge einen Schlüssel erzeugen.' );

		// Fehlgeschlagene Anmeldungen der letzten 30 Tage (aus dem Protokoll)
		if ( BW_Bridge_Log::ready() ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cnt),0) FROM ' . BW_Bridge_Log::table() . " WHERE category = 'auth' AND action IN ('login_failed','app_password_failed') AND created >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			$out[] = self::check( 'failed_logins', $n > 500 ? 'warn' : 'info', $n . ' fehlgeschlagene Anmeldungen in 30 Tagen', $n > 500 ? 'Viele Versuche: Anmeldeschutz (Limit Login Attempts, 2FA, Captcha) prüfen.' : '', [ 'count' => $n ] );
		}
		return $out;
	}

	/** Prüfpunkte aus dem Scan (reine Logik, testbar): Dateiüberwachung, Integrität, Schwachstellen. */
	public static function scan_checks( array $st, $changes = null ) {
		$out = [];
		if ( $st['enabled']['files'] ) {
			$age = $st['finished'] ? ( time() - strtotime( $st['finished'] . ' UTC' ) ) / DAY_IN_SECONDS : null;
			$out[] = null === $age
				? self::check( 'file_scan', 'warn', $st['running'] ? 'Dateiüberwachung: erster Abgleich läuft' : 'Dateiüberwachung: noch kein Abgleich', 'Läuft täglich per Cron; sofort starten mit wp_bridge.py scan --start.' )
				: self::check( 'file_scan', $age > 3 ? 'warn' : 'ok', sprintf( 'Dateiüberwachung: %s Dateien in %d Bereichen, letzter Abgleich %s', number_format( $st['files'], 0, ',', '.' ), $st['units'], gmdate( 'd.m.Y', strtotime( $st['finished'] . ' UTC' ) ) ), $age > 3 ? 'Der tägliche Abgleich ist überfällig – läuft der WordPress-Cron?' : '' );
			if ( null === $changes && BW_Bridge_Log::ready() ) {
				$rows    = BW_Bridge_Log::query( [ 'from' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'category' => 'files', 'level' => 'warning', 'limit' => 200, 'archive' => false ] )['items'];
				$changes = [ 'unexpected' => count( $rows ), 'suspicious' => count( array_filter( $rows, static fn( $r ) => 'suspicious' === $r['action'] || ! empty( $r['details']['php_unusual'] ) ) ) ];
			}
			if ( $changes ) {
				$st_c  = $changes['suspicious'] ? 'fail' : ( $changes['unexpected'] ? 'warn' : 'ok' );
				$out[] = self::check( 'file_changes', $st_c, $changes['unexpected'] ? sprintf( '%d unerwartete Dateiänderung(en) in 30 Tagen%s', $changes['unexpected'], $changes['suspicious'] ? ', davon ' . $changes['suspicious'] . ' mit verdächtigem Code oder PHP an ungewöhnlicher Stelle' : '' ) : 'Keine unerwarteten Dateiänderungen in 30 Tagen', $changes['unexpected'] ? 'Einzelheiten im Wartungsprotokoll (Sicherheit). Unerwartet heißt: nicht durch ein protokolliertes Update oder eine neue Version erklärt.' : '' );
			}
			$iss   = $st['integrity']['issues'];
			$core  = array_filter( $iss, static fn( $i ) => 'core' === $i['unit'] );
			$out[] = $iss
				? self::check( 'integrity', $core ? 'fail' : 'warn', count( $iss ) . ' Bereich(e) weichen vom Original ab', implode( '; ', array_map( static fn( $i ) => $i['label'] . ': ' . count( $i['modified'] ) . ' geändert, ' . count( $i['extra'] ) . ' zusätzlich', $iss ) ), $iss )
				: self::check( 'integrity', $st['integrity']['verified'] ? 'ok' : 'info', $st['integrity']['verified'] ? $st['integrity']['verified'] . ' Bereich(e) stimmen mit dem Original von wordpress.org überein' : 'Integritätsprüfung noch nicht gelaufen' );
		}
		if ( $st['enabled']['vulns'] ) {
			$v = $st['vulns'];
			if ( ! $v['checked'] ) {
				$out[] = self::check( 'vulnerabilities', 'warn', 'Schwachstellen-Prüfung noch nicht gelaufen' );
			} else {
				$c     = $v['counts'];
				$bad   = $c['c'] + $c['h'];
				$unfix = count( array_filter( $v['items'], static fn( $i ) => $i['unfixed'] ) );
				$out[] = self::check(
					'vulnerabilities',
					$bad || $unfix ? 'fail' : ( $v['items'] ? 'warn' : 'ok' ),
					$v['items'] ? sprintf( '%d bekannte Schwachstelle(n): %d kritisch, %d hoch, %d mittel, %d niedrig', count( $v['items'] ), $c['c'], $c['h'], $c['m'], $c['l'] ) : 'Keine bekannten Schwachstellen in Core, Plugins und Themes',
					implode( '; ', array_map( static fn( $i ) => $i['name'] . ' ' . $i['version'] . ': ' . $i['title'] . ( $i['fixed_in'] ? ' (Update auf ' . $i['fixed_in'] . ')' : ( $i['unfixed'] ? ' (kein Update verfügbar – ersetzen oder entfernen)' : '' ) ), array_slice( $v['items'], 0, 10 ) ) ) . ( $v['errors'] ? ( $v['items'] ? ' · ' : '' ) . count( $v['errors'] ) . ' Abfrage(n) fehlgeschlagen' : '' ),
					$v['items'] ?: null
				);
			}
		}
		return $out;
	}

	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$upd = get_site_transient( 'update_plugins' );
		$out = [];
		foreach ( get_plugins() as $file => $d ) {
			$out[] = [
				'plugin'  => $file,
				'name'    => $d['Name'],
				'version' => $d['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => isset( $upd->response[ $file ]->new_version ) ? $upd->response[ $file ]->new_version : null,
			];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* SEO                                                                 */
	/* ------------------------------------------------------------------ */

	public static function seo() {
		global $wpdb;
		$out = [];
		$public = (bool) get_option( 'blog_public' );
		$out[]  = self::check( 'indexing', $public ? 'ok' : 'fail', $public ? 'Suchmaschinen dürfen indexieren' : 'Suchmaschinen werden ausgesperrt', $public ? '' : 'Einstellungen › Lesen › „Suchmaschinen davon abhalten …“ – auf einer Live-Seite abschalten.' );
		$perma  = (string) get_option( 'permalink_structure' );
		$out[]  = self::check( 'permalinks', '' === $perma ? 'warn' : 'ok', '' === $perma ? 'Einfache Permalinks (?p=123)' : 'Sprechende Permalinks', $perma );
		$tag    = (string) get_option( 'blogdescription' );
		$out[]  = self::check( 'tagline', in_array( $tag, [ 'Just another WordPress site', 'Eine weitere WordPress-Website', 'Noch eine WordPress-Website' ], true ) ? 'warn' : 'ok', 'Untertitel: ' . ( '' !== $tag ? '„' . $tag . '“' : '(leer)' ) );
		$out[]  = self::check( 'site_icon', get_option( 'site_icon' ) ? 'ok' : 'warn', get_option( 'site_icon' ) ? 'Website-Icon (Favicon) gesetzt' : 'Kein Website-Icon (Favicon)' );

		$seo_plugin = self::seo_plugin();
		$out[] = self::check( 'seo_plugin', $seo_plugin ? 'ok' : 'warn', $seo_plugin ? 'SEO-Plugin: ' . $seo_plugin['name'] : 'Kein SEO-Plugin erkannt', $seo_plugin ? '' : 'Ohne Plugin fehlen Meta-Beschreibungen, Social-Vorschau und strukturierte Daten.' );
		$sitemaps = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled();
		$out[] = self::check( 'sitemap', $sitemaps || $seo_plugin ? 'ok' : 'warn', $sitemaps || $seo_plugin ? 'XML-Sitemap vorhanden' . ( $seo_plugin ? ' (über ' . $seo_plugin['name'] . ')' : ' (WordPress-Standard)' ) : 'Keine XML-Sitemap' );

		// Veröffentlichte Seiten/Beiträge ohne Meta-Beschreibung bzw. auf noindex
		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)", $types ) );
		if ( $seo_plugin && $seo_plugin['desc'] ) {
			$missing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'publish' AND p.post_type IN ($in) AND ( m.meta_value IS NULL OR m.meta_value = '' )",
				array_merge( [ $seo_plugin['desc'] ], $types )
			) );
			$out[] = self::check( 'meta_description', $missing > $total / 2 ? 'warn' : ( $missing ? 'info' : 'ok' ), sprintf( '%d von %d veröffentlichten Inhalten ohne eigene Meta-Beschreibung', $missing, $total ), $missing ? 'Das Plugin erzeugt dann eine aus einer Vorlage; für wichtige Seiten eigene Texte schreiben.' : '', [ 'missing' => $missing, 'total' => $total ] );
		}
		if ( $seo_plugin && $seo_plugin['noindex'] ) {
			[ $key, $like ] = $seo_plugin['noindex'];
			$noindex = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'publish' AND p.post_type IN ($in) AND m.meta_value LIKE %s",
				array_merge( [ $key ], $types, [ $like ] )
			) );
			$out[] = self::check( 'noindex', $noindex ? 'info' : 'ok', $noindex . ' veröffentlichte Inhalte auf noindex', $noindex ? 'Prüfen, ob das gewollt ist (z. B. Danke-Seiten).' : '', [ 'count' => $noindex ] );
		}

		// Bilder ohne Alternativtext
		$img     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$no_alt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND ( m.meta_value IS NULL OR m.meta_value = '' )" );
		$out[]   = self::check( 'image_alt', $img && $no_alt > $img / 3 ? 'warn' : ( $no_alt ? 'info' : 'ok' ), sprintf( '%d von %d Bildern ohne Alternativtext', $no_alt, $img ), $no_alt ? 'Alternativtexte helfen bei der Bildersuche und sind für Barrierefreiheit nötig.' : '', [ 'missing' => $no_alt, 'total' => $img ] );

		// Seiten ohne Titel
		$untitled = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND post_title = ''", $types ) );
		if ( $untitled ) {
			$out[] = self::check( 'untitled', 'warn', $untitled . ' veröffentlichte Inhalte ohne Titel' );
		}
		$out[] = self::check( 'content_count', 'info', $total . ' veröffentlichte Inhalte', implode( ', ', $types ) );
		return $out;
	}

	/** Erkanntes SEO-Plugin: name, Meta-Schlüssel für Beschreibung und noindex. */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'name' => 'Yoast SEO ' . WPSEO_VERSION, 'desc' => '_yoast_wpseo_metadesc', 'noindex' => [ '_yoast_wpseo_meta-robots-noindex', '1' ] ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return [ 'name' => 'Rank Math ' . RANK_MATH_VERSION, 'desc' => 'rank_math_description', 'noindex' => [ 'rank_math_robots', '%noindex%' ] ];
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return [ 'name' => 'SEOPress ' . SEOPRESS_VERSION, 'desc' => '_seopress_titles_desc', 'noindex' => [ '_seopress_robots_index', 'yes' ] ];
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return [ 'name' => 'All in One SEO ' . AIOSEO_VERSION, 'desc' => null, 'noindex' => null ];
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return [ 'name' => 'The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION, 'desc' => '_genesis_description', 'noindex' => [ '_genesis_noindex', '1' ] ];
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Schnappschüsse                                                      */
	/* ------------------------------------------------------------------ */

	/** Einmal pro Woche (vom Cron des Protokolls) einen kompakten Schnappschuss ablegen. */
	public static function maybe_snapshot( $force = false ) {
		if ( ! $force && (int) get_option( BW_Bridge_Log::OPT_AUDIT_LAST, 0 ) > time() - 7 * DAY_IN_SECONDS ) {
			return;
		}
		update_option( BW_Bridge_Log::OPT_AUDIT_LAST, time(), false );
		$a   = self::run();
		$sum = static function ( $checks ) {
			$r = [];
			foreach ( $checks as $c ) {
				$r[ $c['id'] ] = [ $c['status'], $c['title'] ];
			}
			return $r;
		};
		BW_Bridge_Log::add( [
			'area' => 'system', 'category' => 'audit', 'action' => 'snapshot', 'via' => 'cron', 'user_id' => 0,
			'message' => sprintf( 'Prüfung: Sicherheit %d dringend / %d Empfehlungen, SEO %d dringend / %d Empfehlungen', $a['counts']['security']['fail'], $a['counts']['security']['warn'], $a['counts']['seo']['fail'], $a['counts']['seo']['warn'] ),
			'details' => [ 'counts' => $a['counts'], 'security' => $sum( $a['security'] ), 'seo' => $sum( $a['seo'] ) ],
		] );
	}
}
