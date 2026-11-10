<?php
/**
 * Backend: Werkzeuge › Wartungsprotokoll. Zeigt den Bericht eines Zeitraums (Kennzahlen, Aktivität als Grafik,
 * Sicherheits- und SEO-Prüfung, Updates, manuelle Änderungen, Notizen), das vollständige Protokoll und ein Formular
 * für Notizen. Daten aus BW_Bridge_History::report() – dieselben, die Claude über die REST-API bekommt.
 * Drucken bzw. als PDF speichern über den Browser; „Daten für Claude“ lädt das JSON herunter.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Maintenance {

	const PAGE = 'bw-bridge-maintenance';

	const AREA_LABELS = [ 'updates' => 'Updates', 'security' => 'Sicherheit', 'seo' => 'SEO', 'changes' => 'Manuelle Änderungen' ];
	const VIA_LABELS  = [ 'claude' => 'Claude', 'backend' => 'Backend', 'web' => 'Website/Anmeldeseite', 'app' => 'Anwendungspasswort', 'rest' => 'REST-API', 'cron' => 'Automatisch (Cron)', 'cli' => 'WP-CLI', '' => 'unbekannt' ];
	const STATUS      = [ 'ok' => [ '✓', 'In Ordnung' ], 'info' => [ 'i', 'Hinweis' ], 'warn' => [ '!', 'Empfehlung' ], 'fail' => [ '✕', 'Dringend' ] ];
	const PERIODS     = [ 'current' => 'Laufender Zeitraum', 'previous' => 'Vorheriger Zeitraum', 'year' => 'Laufendes Vertragsjahr', 'previous-year' => 'Vorheriges Vertragsjahr' ];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_bw_bridge_note', [ __CLASS__, 'handle_note' ] );
		add_action( 'admin_post_bw_bridge_report_json', [ __CLASS__, 'handle_json' ] );
		add_action( 'admin_post_bw_bridge_scan_start', [ __CLASS__, 'handle_scan' ] );
	}

	public static function admin_menu() {
		add_management_page( 'Wartungsprotokoll', 'Wartungsprotokoll', 'manage_options', self::PAGE, [ __CLASS__, 'render_page' ] );
	}

	private static function url( array $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'tools.php' ) );
	}

	/** Zeitraum aus der Adresszeile. */
	private static function range_from_request() {
		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '';
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		return BW_Bridge_Log::resolve_range( $period ?: 'current', $from ?: null, $to ?: null );
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_note() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_note' );
		$r = BW_Bridge_History::add_note( [
			'text'    => isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '',
			'area'    => isset( $_POST['area'] ) ? sanitize_key( wp_unslash( $_POST['area'] ) ) : 'changes',
			'minutes' => isset( $_POST['minutes'] ) && '' !== $_POST['minutes'] ? (int) $_POST['minutes'] : null,
			'ticket'  => isset( $_POST['ticket'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket'] ) ) : '',
			'tags'    => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
			'date'    => ! empty( $_POST['date'] ) && sanitize_text_field( wp_unslash( $_POST['date'] ) ) !== BW_Bridge_Log::local_today() ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : null,
		] );
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : self::url();
		wp_safe_redirect( add_query_arg( 'bw_note', is_wp_error( $r ) ? rawurlencode( $r->get_error_message() ) : 'ok', $back ) );
		exit;
	}

	public static function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_scan_start' );
		BW_Bridge_Scan::start( true );
		wp_safe_redirect( add_query_arg( 'bw_scan', '1', self::url() ) );
		exit;
	}

	public static function handle_json() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bw_bridge_report_json' );
		$r    = self::range_from_request();
		$data = BW_Bridge_History::report( $r, true );
		$name = sanitize_file_name( 'wartung-' . ( BW_Bridge_Key::site_id() ?: sanitize_title( get_bloginfo( 'name' ) ) ) . '-' . $r['from'] . '_' . $r['to'] . '.json' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Seite                                                               */
	/* ------------------------------------------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap bwm">';
		self::styles();
		if ( ! BW_Bridge_Log::ready() ) {
			echo '<h1>Wartungsprotokoll</h1><div class="notice notice-warning inline"><p>' . ( BW_Bridge_Log::disabled() ? 'Das Wartungsprotokoll ist in der wp-config.php abgeschaltet (<code>BW_WP_BRIDGE_LOG_DISABLED</code>).' : 'Die Protokoll-Tabelle ist noch nicht angelegt. Seite neu laden.' ) . '</p></div></div>';
			return;
		}
		$tab = isset( $_GET['tab'] ) && 'log' === $_GET['tab'] ? 'log' : 'report';
		$r   = self::range_from_request();
		if ( isset( $_GET['bw_note'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['bw_note'] ) );
			echo 'ok' === $msg ? '<div class="notice notice-success is-dismissible bw-no-print"><p>Notiz gespeichert.</p></div>' : '<div class="notice notice-error bw-no-print"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper bw-no-print">';
		foreach ( [ 'report' => 'Bericht', 'log' => 'Protokoll' ] as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( [ 'tab' => $k, 'period' => $r['kind'] !== 'custom' ? $r['kind'] : null, 'from' => 'custom' === $r['kind'] ? $r['from'] : null, 'to' => 'custom' === $r['kind'] ? $r['to'] : null ] ) ), $tab === $k ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		'log' === $tab ? self::render_log( $r ) : self::render_report( $r );
		echo '</div>';
	}

	private static function period_bar( array $r, $tab ) {
		echo '<div class="bwm-periods bw-no-print">';
		foreach ( self::PERIODS as $k => $label ) {
			printf( '<a class="bwm-pill%s" href="%s">%s</a>', $r['kind'] === $k ? ' is-active' : '', esc_url( self::url( [ 'tab' => $tab, 'period' => $k ] ) ), esc_html( $label ) );
		}
		echo '<form method="get" class="bwm-custom"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		printf( '<input type="date" name="from" value="%s" aria-label="von"> – <input type="date" name="to" value="%s" aria-label="bis"> <button class="button">Anzeigen</button></form>', esc_attr( $r['from'] ), esc_attr( $r['to'] ) );
		echo '</div>';
	}

	private static function render_report( array $r ) {
		$d    = BW_Bridge_History::report( $r, true );
		$k    = $d['kpis'];
		$site = $d['site'];
		$rh   = [ 'month' => 'Monat', 'quarter' => 'Quartal', 'half' => 'Halbjahr', 'year' => 'Jahr' ];

		echo '<header class="bwm-head"><div><p class="bwm-eyebrow">Wartungsbericht · ' . esc_html( $site['name'] ) . '</p>';
		echo '<h1>' . esc_html( $d['period']['label'] ) . '</h1>';
		echo '<p class="bwm-sub">' . esc_html( date_i18n( 'd.m.Y', strtotime( $d['period']['from'] ) ) . ' – ' . date_i18n( 'd.m.Y', strtotime( $d['period']['to'] ) ) ) . ' · ' . esc_html( $site['url'] ) . ' · Abrechnung je ' . esc_html( $rh[ $d['contract']['rhythm'] ] ?? 'Quartal' ) . ( $d['contract']['start'] ? ' ab ' . esc_html( date_i18n( 'd.m.Y', strtotime( $d['contract']['start'] ) ) ) : '' ) . '</p></div>';
		echo '<div class="bwm-actions bw-no-print"><button type="button" class="button" onclick="window.print()">Drucken / PDF</button> ';
		printf( '<a class="button button-primary" href="%s">Daten für Claude (JSON)</a></div></header>', esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'bw_bridge_report_json', 'period' => $r['kind'], 'from' => 'custom' === $r['kind'] ? $r['from'] : null, 'to' => 'custom' === $r['kind'] ? $r['to'] : null ], admin_url( 'admin-post.php' ) ), 'bw_bridge_report_json' ) ) );
		self::period_bar( $r, 'report' );
		if ( $d['truncated'] ) {
			echo '<div class="notice notice-warning inline"><p>Sehr viele Einträge in diesem Zeitraum – ausgewertet wurden die neuesten 5000.</p></div>';
		}

		// Kennzahlen
		$sec_warn = $d['audit']['counts']['security']['fail'] + $d['audit']['counts']['security']['warn'];
		$seo_warn = $d['audit']['counts']['seo']['fail'] + $d['audit']['counts']['seo']['warn'];
		echo '<section class="bwm-kpis">';
		self::tile( 'Updates', $k['updates'] + $k['installs'], $k['installs'] ? $k['installs'] . ' Neuinstallation(en)' : 'Core, Plugins, Themes', 'updates' );
		self::tile( 'Sicherheit', $k['security_events'], number_format_i18n( $k['failed_logins'] ) . ' fehlgeschlagene Anmeldungen', 'security' );
		self::tile( 'SEO', $k['seo'], $seo_warn . ' offene Punkte in der Prüfung', 'seo' );
		self::tile( 'Manuelle Änderungen', $k['changes'], count( $d['sections']['changes']['objects'] ) . ' Inhalte/Routen betroffen', 'changes' );
		self::tile( 'Notizen', $k['notes'], $k['minutes'] ? 'Aufwand ' . self::hours( $k['minutes'] ) : 'ohne Zeitangabe', 'notes' );
		self::tile( 'Prüfung Sicherheit', $sec_warn, $d['audit']['counts']['security']['fail'] . ' dringend, ' . $d['audit']['counts']['security']['warn'] . ' Empfehlungen', 'audit', $sec_warn ? 'warn' : 'ok' );
		echo '</section>';

		// Aktivität
		echo '<section class="bwm-grid"><div class="bwm-card bwm-span2"><div class="bwm-card-head"><h2>Aktivität</h2><span class="bwm-muted">je ' . ( 'week' === $d['timeline']['unit'] ? 'Woche' : 'Monat' ) . ', nach Bereich</span></div>';
		self::chart( $d['timeline']['buckets'] );
		echo '</div><div class="bwm-card"><div class="bwm-card-head"><h2>Wer und wie</h2></div>';
		self::via_bars( $d['by_via'] );
		echo '</div></section>';

		// Prüfung
		echo '<section class="bwm-grid">';
		foreach ( [ 'security' => 'Sicherheitsprüfung (aktueller Stand)', 'seo' => 'SEO-Prüfung (aktueller Stand)' ] as $key => $title ) {
			echo '<div class="bwm-card"' . ( 'security' === $key ? ' id="bwm-audit"' : '' ) . '><div class="bwm-card-head"><h2>' . esc_html( $title ) . '</h2></div><ul class="bwm-checks">';
			$checks = $d['audit'][ $key ];
			usort( $checks, static fn( $a, $b ) => array_search( $a['status'], [ 'fail', 'warn', 'info', 'ok' ], true ) <=> array_search( $b['status'], [ 'fail', 'warn', 'info', 'ok' ], true ) );
			foreach ( $checks as $c ) {
				[ $icon, $label ] = self::STATUS[ $c['status'] ];
				printf( '<li class="is-%1$s"><span class="bwm-status" title="%3$s"><b aria-hidden="true">%2$s</b> %3$s</span><span><strong>%4$s</strong>%5$s</span></li>', esc_attr( $c['status'] ), esc_html( $icon ), esc_html( $label ), esc_html( $c['title'] ), $c['detail'] ? '<br><span class="bwm-muted">' . esc_html( $c['detail'] ) . '</span>' : '' );
			}
			echo '</ul></div>';
		}
		echo '</section>';

		if ( ! empty( $d['scan'] ) ) {
			self::scan_card( $d['scan'] );
		}

		// Updates
		echo '<section class="bwm-card" id="bwm-updates"><div class="bwm-card-head"><h2><i class="bwm-dot is-updates"></i>Updates</h2><span class="bwm-muted">' . count( $d['sections']['updates'] ) . ' Einträge</span></div>';
		self::table( $d['sections']['updates'], [ 'date' => 'Datum', 'message' => 'Was', 'via' => 'Wie', 'user' => 'Benutzer' ], 'Keine Updates in diesem Zeitraum.' );
		echo '</section>';

		// Sicherheit
		$s = $d['sections']['security'];
		echo '<section class="bwm-card" id="bwm-security"><div class="bwm-card-head"><h2><i class="bwm-dot is-security"></i>Sicherheit</h2></div><div class="bwm-cols">';
		echo '<div><h3>Anmeldungen</h3>';
		if ( $s['logins'] ) {
			echo '<table class="bwm-table"><thead><tr><th>Benutzer</th><th class="num">Anmeldungen</th><th>zuletzt</th></tr></thead><tbody>';
			foreach ( array_slice( $s['logins'], 0, 15, true ) as $u => $x ) {
				printf( '<tr><td>%s%s</td><td class="num">%d</td><td>%s</td></tr>', esc_html( $u ), $x['admin'] ? ' <span class="bwm-tag">Admin</span>' : '', (int) $x['count'], esc_html( self::d( $x['last'] ) ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="bwm-muted">Keine Anmeldungen protokolliert.</p>';
		}
		echo '</div><div><h3>Fehlgeschlagene Anmeldungen: ' . esc_html( number_format_i18n( $s['failed_logins']['total'] ) ) . '</h3>';
		if ( $s['failed_logins']['total'] ) {
			echo '<p class="bwm-muted">An ' . count( $s['failed_logins']['days'] ) . ' Tagen, am meisten am ' . esc_html( self::d( $s['failed_logins']['peak_day'] ) ) . '. Häufigste Benutzernamen:</p><ul class="bwm-list">';
			foreach ( $s['failed_logins']['top_names'] as $n => $c ) {
				printf( '<li><code>%s</code> <span class="bwm-muted">%s×</span></li>', esc_html( $n ), esc_html( number_format_i18n( $c ) ) );
			}
			echo '</ul>';
		}
		echo '</div></div><h3>Ereignisse</h3>';
		self::table( $s['events'], [ 'date' => 'Datum', 'message' => 'Was', 'via' => 'Wie', 'user' => 'Benutzer' ], 'Keine sicherheitsrelevanten Änderungen.' );
		echo '</section>';

		// SEO
		echo '<section class="bwm-card" id="bwm-seo"><div class="bwm-card-head"><h2><i class="bwm-dot is-seo"></i>SEO</h2></div>';
		self::table( $d['sections']['seo'], [ 'date' => 'Datum', 'message' => 'Was', 'via' => 'Wie', 'user' => 'Benutzer' ], 'Keine SEO-relevanten Einstellungsänderungen. Den aktuellen Stand zeigt die SEO-Prüfung oben.' );
		echo '</section>';

		// Manuelle Änderungen
		$c = $d['sections']['changes'];
		echo '<section class="bwm-card" id="bwm-changes"><div class="bwm-card-head"><h2><i class="bwm-dot is-changes"></i>Manuelle Änderungen</h2><span class="bwm-muted">' . (int) $c['objects_total'] . ' Inhalte/Routen</span></div>';
		if ( $c['objects'] ) {
			echo '<table class="bwm-table"><thead><tr><th>Inhalt</th><th>Art</th><th>Aktionen</th><th class="num">Anzahl</th><th>Zeitraum</th><th>Wie / wer</th></tr></thead><tbody>';
			foreach ( array_slice( $c['objects'], 0, 40 ) as $o ) {
				$acts = [];
				foreach ( $o['actions'] as $a => $n ) {
					$acts[] = self::action_label( $a ) . ( $n > 1 ? ' ×' . $n : '' );
				}
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td class="num">%d</td><td>%s</td><td>%s</td></tr>', esc_html( $o['name'] ), esc_html( 'bridge' === $o['kind'] ? 'REST ' . $o['id'] : $o['type'] ), esc_html( implode( ', ', $acts ) ), (int) $o['count'], esc_html( self::d( $o['first'] ) . ( $o['first'] !== $o['last'] ? ' – ' . self::d( $o['last'] ) : '' ) ), esc_html( implode( ', ', array_map( [ __CLASS__, 'via_label' ], $o['via'] ) ) . ( $o['users'] ? ' · ' . implode( ', ', $o['users'] ) : '' ) ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="bwm-muted">Keine Inhaltsänderungen protokolliert.</p>';
		}
		if ( $c['events'] ) {
			echo '<h3>Einstellungen und Sonstiges</h3>';
			self::table( $c['events'], [ 'date' => 'Datum', 'message' => 'Was', 'via' => 'Wie', 'user' => 'Benutzer' ], '' );
		}
		echo '</section>';

		// Notizen
		echo '<section class="bwm-card" id="bwm-notes"><div class="bwm-card-head"><h2>Notizen</h2><span class="bwm-muted">' . count( $d['notes'] ) . ( $k['minutes'] ? ' · Aufwand ' . esc_html( self::hours( $k['minutes'] ) ) : '' ) . '</span></div>';
		if ( $d['notes'] ) {
			echo '<table class="bwm-table"><thead><tr><th>Datum</th><th>Bereich</th><th>Notiz</th><th class="num">Min.</th><th>Ticket</th><th>Von</th></tr></thead><tbody>';
			foreach ( $d['notes'] as $n ) {
				printf( '<tr><td>%s</td><td><i class="bwm-dot is-%s"></i>%s</td><td>%s%s</td><td class="num">%s</td><td>%s</td><td>%s</td></tr>', esc_html( self::d( $n['date'] ) ), esc_attr( $n['area'] ), esc_html( self::AREA_LABELS[ $n['area'] ] ?? $n['area'] ), nl2br( esc_html( $n['text'] ) ), $n['tags'] ? ' <span class="bwm-muted">#' . esc_html( implode( ' #', $n['tags'] ) ) . '</span>' : '', esc_html( (string) ( $n['minutes'] ?? '' ) ), esc_html( (string) ( $n['ticket'] ?? '' ) ), esc_html( $n['user'] ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="bwm-muted">Noch keine Notizen in diesem Zeitraum.</p>';
		}
		self::note_form( $r );
		echo '</section>';

		// Prompt für Claude
		echo '<section class="bwm-card bw-no-print"><div class="bwm-card-head"><h2>Abschlussbericht mit Claude</h2></div>';
		echo '<p>Diesen Text in Claude Code einfügen (die Umgebung braucht die Zugangsdaten dieser Website). Ohne Zugang: „Daten für Claude (JSON)“ herunterladen und mit dem Text zusammen hochladen.</p>';
		printf( '<textarea readonly class="large-text code" rows="5" onfocus="this.select()">%s</textarea>', esc_textarea( self::prompt( $d ) ) );
		echo '</section>';
		echo '<p class="bwm-muted">Erstellt ' . esc_html( date_i18n( 'd.m.Y H:i' ) ) . ' · WordPress ' . esc_html( $d['versions']['wordpress'] ) . ' · PHP ' . esc_html( $d['versions']['php'] ) . ' · BW WP Bridge ' . esc_html( $d['versions']['bridge'] ) . '</p>';
	}

	/** Dateiüberwachung, Integrität und Schwachstellen (aktueller Stand). */
	private static function scan_card( array $sc ) {
		$fmt = static fn( $utc ) => $utc ? get_date_from_gmt( $utc, 'd.m.Y H:i' ) : '–';
		echo '<section class="bwm-card"><div class="bwm-card-head"><h2>Dateien und Schwachstellen (aktueller Stand)</h2><span class="bw-no-print">';
		if ( $sc['running'] ) {
			echo '<span class="bwm-muted">Abgleich läuft (' . (int) $sc['pending'] . ' Bereiche offen) </span>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bw_bridge_scan_start' );
		echo '<input type="hidden" name="action" value="bw_bridge_scan_start"><button class="button">Jetzt prüfen</button></form></span></div>';
		if ( isset( $_GET['bw_scan'] ) ) {
			echo '<p class="bwm-muted bw-no-print">Prüfung gestartet; größere Websites werden in Schritten im Hintergrund fertig.</p>';
		}
		echo '<div class="bwm-cols"><div><h3>Dateiüberwachung</h3>';
		if ( $sc['enabled']['files'] ) {
			printf( '<p>%s Dateien in %d Bereichen · letzter vollständiger Abgleich: %s</p>', esc_html( number_format_i18n( $sc['files'] ) ), (int) $sc['units'], esc_html( $fmt( $sc['finished'] ) ) );
			$iss = $sc['integrity']['issues'];
			echo '<p><strong>Vergleich mit dem Original:</strong> ' . esc_html( $sc['integrity']['verified'] . ' Bereich(e) unverändert' ) . ( $iss ? ', <strong>' . count( $iss ) . ' mit Abweichungen</strong>' : '' ) . '</p>';
			if ( $iss ) {
				echo '<ul class="bwm-list" style="columns:1">';
				foreach ( $iss as $i ) {
					printf( '<li><b class="bwm-status-inline is-warn" aria-hidden="true">!</b> %s %s: %d geändert, %d zusätzlich <span class="bwm-muted">%s</span></li>', esc_html( $i['label'] ), esc_html( $i['version'] ), count( $i['modified'] ), count( $i['extra'] ), esc_html( implode( ', ', array_slice( array_merge( $i['modified'], $i['extra'] ), 0, 5 ) ) ) );
				}
				echo '</ul>';
			}
			echo '<p class="bwm-muted">Änderungen stehen im Abschnitt Sicherheit unten und im Protokoll.</p>';
		} else {
			echo '<p class="bwm-muted">Abgeschaltet (Einstellungen › BW WP Bridge).</p>';
		}
		echo '</div><div><h3>Bekannte Schwachstellen</h3>';
		$v = $sc['vulns'];
		if ( ! $sc['enabled']['vulns'] ) {
			echo '<p class="bwm-muted">Abgeschaltet (Einstellungen › BW WP Bridge).</p>';
		} elseif ( ! $v['checked'] ) {
			echo '<p class="bwm-muted">Noch nicht geprüft.</p>';
		} elseif ( ! $v['items'] ) {
			echo '<p><b class="bwm-status-inline is-ok" aria-hidden="true">✓</b> Keine bekannten Schwachstellen. <span class="bwm-muted">Stand ' . esc_html( $fmt( $v['checked'] ) ) . ', Quelle wpvulnerability.net</span></p>';
		} else {
			echo '<table class="bwm-table"><thead><tr><th>Komponente</th><th>Schwachstelle</th><th>Schwere</th><th>Lösung</th></tr></thead><tbody>';
			foreach ( $v['items'] as $i ) {
				printf(
					'<tr class="%s"><td>%s %s</td><td>%s</td><td>%s%s</td><td>%s</td></tr>',
					in_array( $i['severity'], [ 'c', 'h' ], true ) ? 'lvl-warning' : '',
					esc_html( $i['name'] ),
					esc_html( $i['version'] ),
					$i['link'] ? '<a href="' . esc_url( $i['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $i['title'] ) . '</a>' : esc_html( $i['title'] ),
					esc_html( ucfirst( BW_Bridge_Scan::severity_label( $i['severity'] ) ) ),
					null !== $i['score'] ? ' <span class="bwm-muted">(' . esc_html( number_format_i18n( $i['score'], 1 ) ) . ')</span>' : '',
					esc_html( $i['fixed_in'] ? 'Update auf ' . $i['fixed_in'] : ( $i['unfixed'] ? 'kein Update – ersetzen/entfernen' : 'Update prüfen' ) )
				);
			}
			echo '</tbody></table><p class="bwm-muted">Stand ' . esc_html( $fmt( $v['checked'] ) ) . ', Quelle wpvulnerability.net</p>';
		}
		if ( $v['errors'] ) {
			echo '<p class="bwm-muted">' . count( $v['errors'] ) . ' Abfrage(n) fehlgeschlagen, werden beim nächsten Lauf wiederholt.</p>';
		}
		echo '</div></div></section>';
	}

	/** Text, mit dem Claude den Abschlussbericht erstellt (Langfassung in prompts/wartungsbericht.md). */
	public static function prompt( array $d ) {
		$period = $d['period']['kind'];
		$arg    = 'custom' === $period ? sprintf( '--from %s --to %s', $d['period']['from'], $d['period']['to'] ) : '--period ' . $period;
		$year   = in_array( $period, [ 'year', 'previous-year' ], true );
		return sprintf(
			"Erstelle den Wartungsbericht für %s (%s), Zeitraum %s (%s bis %s).\n"
			. "Daten: wp_bridge.py report %s -o wartung.json (oder die angehängte JSON-Datei).\n"
			. "Gehe nach prompts/wartungsbericht.md im Repo blickwert/bw-wp-bridge vor: Zusammenfassung, durchgeführte Arbeiten (Updates, Sicherheit, SEO, manuelle Änderungen, Notizen mit Aufwand), Sicherheits- und SEO-Bewertung, offene Punkte und Empfehlungen für die nächste Laufzeit%s.\n"
			. "Ausgabe als Markdown zum Weiterverarbeiten; nichts auf der Website ändern.",
			$d['site']['name'], $d['site']['url'], $d['period']['label'], $d['period']['from'], $d['period']['to'], $arg,
			$year ? ', dazu die jährliche Sicherheitsanalyse (ausführlich, mit Vergleich zum Jahresbeginn)' : ''
		);
	}

	private static function note_form( array $r ) {
		echo '<details class="bwm-note bw-no-print"' . ( isset( $_GET['bw_note'] ) && 'ok' !== $_GET['bw_note'] ? ' open' : '' ) . '><summary class="button">Notiz hinzufügen</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bw_bridge_note' );
		echo '<input type="hidden" name="action" value="bw_bridge_note"><input type="hidden" name="back" value="' . esc_attr( self::url( [ 'period' => 'custom' === $r['kind'] ? null : $r['kind'], 'from' => 'custom' === $r['kind'] ? $r['from'] : null, 'to' => 'custom' === $r['kind'] ? $r['to'] : null ] ) ) . '">';
		echo '<p><label>Was wurde gemacht?<br><textarea name="text" rows="3" class="large-text" required></textarea></label></p><p class="bwm-row">';
		echo '<label>Bereich<br><select name="area">';
		foreach ( self::AREA_LABELS as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( 'changes', $k, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		echo '<label>Aufwand (Minuten)<br><input type="number" name="minutes" min="0" step="5" class="small-text"></label>';
		echo '<label>Ticket<br><input type="text" name="ticket" class="regular-text" style="width:10em"></label>';
		echo '<label>Schlagworte<br><input type="text" name="tags" placeholder="kommagetrennt" style="width:12em"></label>';
		echo '<label>Datum<br><input type="date" name="date" value="' . esc_attr( BW_Bridge_Log::local_today() ) . '"></label>';
		echo '</p>';
		submit_button( 'Notiz speichern', 'primary', 'submit', false );
		echo '</form></details>';
	}

	private static function render_log( array $r ) {
		$area  = isset( $_GET['area'] ) ? sanitize_key( wp_unslash( $_GET['area'] ) ) : '';
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per   = 50;
		echo '<header class="bwm-head"><div><p class="bwm-eyebrow">Wartungsprotokoll</p><h1>' . esc_html( $r['label'] ) . '</h1></div></header>';
		self::period_bar( $r, 'log' );
		echo '<form method="get" class="bwm-filter bw-no-print"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="log">';
		printf( '<input type="hidden" name="from" value="%s"><input type="hidden" name="to" value="%s">', esc_attr( $r['from'] ), esc_attr( $r['to'] ) );
		echo '<select name="area"><option value="">Alle Bereiche</option>';
		foreach ( self::AREA_LABELS + [ 'system' => 'System' ] as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $area, $k, false ), esc_html( $label ) );
		}
		echo '</select> <input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Suchen …"> <button class="button">Filtern</button></form>';

		$res = BW_Bridge_Log::query( [ 'from' => $r['from_utc'], 'to' => $r['to_utc'], 'area' => $area, 'q' => $q, 'limit' => $per, 'offset' => ( $paged - 1 ) * $per ] );
		echo '<div class="bwm-card"><table class="bwm-table"><thead><tr><th>Zeit</th><th>Bereich</th><th>Was</th><th class="num">×</th><th>Wie</th><th>Benutzer</th><th>IP (gekürzt)</th></tr></thead><tbody>';
		if ( ! $res['items'] ) {
			echo '<tr><td colspan="7" class="bwm-muted">Keine Einträge.</td></tr>';
		}
		foreach ( $res['items'] as $e ) {
			printf(
				'<tr class="lvl-%s"><td>%s</td><td><i class="bwm-dot is-%s"></i>%s</td><td>%s</td><td class="num">%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_attr( $e['level'] ),
				esc_html( get_date_from_gmt( $e['created'], 'd.m.Y H:i' ) ),
				esc_attr( $e['area'] ),
				esc_html( self::AREA_LABELS[ $e['area'] ] ?? 'System' ),
				esc_html( 'note' === $e['source'] ? ( $e['details']['text'] ?? $e['message'] ) : $e['message'] ),
				$e['cnt'] > 1 ? (int) $e['cnt'] : '',
				esc_html( self::via_label( $e['via'] ) ),
				esc_html( $e['user_login'] ),
				esc_html( $e['ip'] ?? '' )
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p class="bwm-pages bw-no-print">';
			for ( $i = 1; $i <= min( $pages, 50 ); $i++ ) {
				printf( '<a class="bwm-pill%s" href="%s">%d</a> ', $i === $paged ? ' is-active' : '', esc_url( self::url( [ 'tab' => 'log', 'from' => $r['from'], 'to' => $r['to'], 'area' => $area ?: null, 'q' => $q ?: null, 'paged' => $i ] ) ), $i );
			}
			echo '</p>';
		}
		$st = BW_Bridge_Log::stats();
		echo '<p class="bwm-muted">' . esc_html( sprintf( '%s Einträge in der Datenbank (älteste %s), %d Archivdatei(en) mit %s. In der Datenbank: %d Tage, Aufbewahrung gesamt: %s.', number_format_i18n( $st['rows'] ), $st['oldest'] ? get_date_from_gmt( $st['oldest'], 'd.m.Y' ) : '–', $st['archive_files'], size_format( $st['archive_bytes'] ?: 0 ), $st['db_days'], $st['keep_days'] ? $st['keep_days'] . ' Tage' : 'unbegrenzt' ) ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Bausteine                                                           */
	/* ------------------------------------------------------------------ */

	private static function tile( $label, $value, $sub, $key, $status = null ) {
		printf(
			'<a class="bwm-tile is-%1$s" href="#bwm-%1$s"><span class="bwm-tile-label">%2$s</span><span class="bwm-tile-value">%3$s</span><span class="bwm-tile-sub">%4$s%5$s</span></a>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_html( number_format_i18n( (int) $value ) ),
			$status ? '<b class="bwm-status-inline is-' . esc_attr( $status ) . '" aria-hidden="true">' . esc_html( self::STATUS[ $status ][0] ) . '</b> ' : '',
			esc_html( $sub )
		);
	}

	/** Gestapelte Säulen je Zeitabschnitt (inline SVG), mit Tooltip je Segment und Tabelle als Alternative. */
	private static function chart( array $buckets ) {
		$areas = array_keys( self::AREA_LABELS );
		$max   = 0;
		foreach ( $buckets as $b ) {
			$max = max( $max, array_sum( array_intersect_key( $b, array_flip( $areas ) ) ) );
		}
		$top = self::nice_max( $max );
		$w   = 760;
		$h   = 230;
		$l   = 40;
		$b0  = 26;
		$pw  = $w - $l - 8;
		$ph  = $h - $b0 - 10;
		$n   = max( 1, count( $buckets ) );
		$slot = $pw / $n;
		$bw   = min( 28, max( 4, $slot * 0.6 ) );
		$every = (int) ceil( $n / 14 );

		echo '<div class="bwm-legend">';
		foreach ( self::AREA_LABELS as $k => $label ) {
			echo '<span><i class="bwm-dot is-' . esc_attr( $k ) . '"></i>' . esc_html( $label ) . '</span>';
		}
		echo '</div>';
		printf( '<svg class="bwm-chart" viewBox="0 0 %d %d" role="img" aria-label="Aktivität je Zeitabschnitt nach Bereich">', $w, $h );
		for ( $i = 0; $i <= 4; $i++ ) {
			$y = 10 + $ph - $ph * $i / 4;
			printf( '<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" class="grid"/><text x="%d" y="%.1f" class="tick" text-anchor="end">%s</text>', $l, $w - 8, $y, $y, $l - 6, $y + 4, esc_html( number_format_i18n( $top * $i / 4 ) ) );
		}
		foreach ( array_values( $buckets ) as $i => $bk ) {
			$x     = $l + $slot * $i + ( $slot - $bw ) / 2;
			$y     = 10 + $ph;
			$segs  = array_values( array_filter( $areas, static fn( $a ) => $bk[ $a ] > 0 ) );
			$total = array_sum( array_intersect_key( $bk, array_flip( $areas ) ) );
			foreach ( $segs as $j => $a ) {
				$sh  = $ph * $bk[ $a ] / $top;
				$gap = $j > 0 ? 2 : 0;
				$y  -= $sh;
				$hh  = max( 1, $sh - $gap );
				$tip = sprintf( '%s · %s: %s (gesamt %s)', $bk['label'], self::AREA_LABELS[ $a ], number_format_i18n( $bk[ $a ] ), number_format_i18n( $total ) );
				if ( $j === count( $segs ) - 1 ) {
					printf( '<path class="seg is-%s" d="%s"><title>%s</title></path>', esc_attr( $a ), self::rounded_top( $x, $y, $bw, $hh, min( 4, $bw / 2, $hh ) ), esc_html( $tip ) );
				} else {
					printf( '<rect class="seg is-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"><title>%s</title></rect>', esc_attr( $a ), $x, $y, $bw, $hh, esc_html( $tip ) );
				}
			}
			if ( 0 === $i % $every ) {
				printf( '<text x="%.1f" y="%d" class="tick" text-anchor="middle">%s</text>', $x + $bw / 2, $h - 8, esc_html( $bk['label'] ) );
			}
		}
		printf( '<line x1="%d" x2="%d" y1="%d" y2="%d" class="axis"/>', $l, $w - 8, 10 + $ph, 10 + $ph );
		echo '</svg>';

		echo '<details class="bwm-tableview"><summary>Als Tabelle</summary><table class="bwm-table"><thead><tr><th>Abschnitt</th>';
		foreach ( self::AREA_LABELS as $label ) {
			echo '<th class="num">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $buckets as $bk ) {
			echo '<tr><td>' . esc_html( $bk['label'] ) . '</td>';
			foreach ( $areas as $a ) {
				echo '<td class="num">' . (int) $bk[ $a ] . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></details>';
	}

	private static function rounded_top( $x, $y, $w, $h, $r ) {
		return sprintf( 'M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ', $x, $y + $h, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $y + $h );
	}

	/** Obergrenze der y-Achse: vier gleiche, runde Schritte (1, 2, 5 × 10^n). */
	public static function nice_max( $v ) {
		if ( $v <= 4 ) {
			return 4;
		}
		$raw = $v / 4;
		$p   = pow( 10, floor( log10( $raw ) ) );
		foreach ( [ 1, 2, 5, 10 ] as $m ) {
			if ( $raw <= $m * $p ) {
				return (int) ceil( $m * $p ) * 4;
			}
		}
		return (int) ceil( $raw ) * 4;
	}

	/** Horizontale Balken: Anteil je Weg (Claude, Backend, Cron …). */
	private static function via_bars( array $by_via ) {
		if ( ! $by_via ) {
			echo '<p class="bwm-muted">Noch keine Einträge.</p>';
			return;
		}
		$max = max( $by_via );
		echo '<ul class="bwm-bars">';
		foreach ( $by_via as $via => $n ) {
			printf( '<li><span class="bwm-bar-label">%s</span><span class="bwm-bar-track"><span class="bwm-bar" style="width:%.1f%%" title="%s"></span></span><span class="bwm-bar-value">%s</span></li>', esc_html( self::via_label( $via ) ), max( 1, 100 * $n / $max ), esc_attr( self::via_label( $via ) . ': ' . $n ), esc_html( number_format_i18n( $n ) ) );
		}
		echo '</ul>';
	}

	private static function table( array $rows, array $cols, $empty ) {
		if ( ! $rows ) {
			if ( $empty ) {
				echo '<p class="bwm-muted">' . esc_html( $empty ) . '</p>';
			}
			return;
		}
		echo '<table class="bwm-table"><thead><tr>';
		foreach ( $cols as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 200 ) as $row ) {
			echo '<tr class="lvl-' . esc_attr( $row['level'] ?? 'info' ) . '">';
			foreach ( array_keys( $cols ) as $c ) {
				$v = $row[ $c ] ?? '';
				if ( 'date' === $c ) {
					$v = self::d( $v );
				} elseif ( 'via' === $c ) {
					$v = self::via_label( $v );
				} elseif ( 'message' === $c && ( $row['count'] ?? 1 ) > 1 ) {
					$v .= ' (' . $row['count'] . '×)';
				}
				echo '<td>' . esc_html( (string) $v ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function via_label( $v ) {
		return self::VIA_LABELS[ $v ] ?? (string) $v;
	}

	private static function action_label( $a ) {
		$map = [ 'edited' => 'bearbeitet', 'published' => 'veröffentlicht', 'created' => 'angelegt', 'trashed' => 'Papierkorb', 'deleted' => 'gelöscht', 'unpublished' => 'zurückgezogen', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE' ];
		return $map[ $a ] ?? $a;
	}

	private static function d( $date ) {
		return $date ? date_i18n( 'd.m.Y', strtotime( $date ) ) : '';
	}

	private static function hours( $min ) {
		return sprintf( '%d:%02d h', intdiv( (int) $min, 60 ), (int) $min % 60 );
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen (auf der Seite Einstellungen › BW WP Bridge)          */
	/* ------------------------------------------------------------------ */

	public static function register_settings( $group ) {
		register_setting( $group, BW_Bridge_Log::OPT_DB_DAYS, [ 'type' => 'integer', 'default' => BW_Bridge_Log::DEFAULT_DB_DAYS, 'show_in_rest' => false, 'sanitize_callback' => static fn( $v ) => max( 30, min( 3650, (int) $v ) ) ] );
		register_setting( $group, BW_Bridge_Log::OPT_KEEP_DAYS, [ 'type' => 'integer', 'default' => BW_Bridge_Log::DEFAULT_KEEP, 'show_in_rest' => false, 'sanitize_callback' => static fn( $v ) => max( 0, min( 36500, (int) $v ) ) ] );
		register_setting( $group, BW_Bridge_Log::OPT_START, [ 'type' => 'string', 'default' => '', 'show_in_rest' => false, 'sanitize_callback' => static fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '' ] );
		if ( ! BW_Bridge_Scan::disabled() ) {
			$bool = static fn( $v ) => $v ? 1 : 0;
			register_setting( $group, BW_Bridge_Scan::OPT_FILES_ON, [ 'type' => 'boolean', 'default' => 1, 'show_in_rest' => false, 'sanitize_callback' => $bool ] );
			register_setting( $group, BW_Bridge_Scan::OPT_VULNS_ON, [ 'type' => 'boolean', 'default' => 1, 'show_in_rest' => false, 'sanitize_callback' => $bool ] );
		}
		register_setting( $group, BW_Bridge_Log::OPT_RHYTHM, [ 'type' => 'string', 'default' => 'quarter', 'show_in_rest' => false, 'sanitize_callback' => static fn( $v ) => isset( BW_Bridge_Log::RHYTHMS[ $v ] ) ? $v : 'quarter' ] );
	}

	/** Zeilen für die Einstellungstabelle. */
	public static function settings_rows() {
		$rh = [ 'month' => 'Monat', 'quarter' => 'Quartal', 'half' => 'Halbjahr', 'year' => 'Jahr' ];
		echo '<tr><th scope="row" colspan="2"><h2 style="margin:0">Wartungsprotokoll</h2><p class="description" style="font-weight:normal">Bericht unter <a href="' . esc_url( self::url() ) . '">Werkzeuge › Wartungsprotokoll</a>. IP-Adressen werden nur gekürzt gespeichert.</p></th></tr>';
		echo '<tr><th scope="row">Abrechnungszeitraum</th><td><select name="' . esc_attr( BW_Bridge_Log::OPT_RHYTHM ) . '">';
		foreach ( $rh as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( BW_Bridge_Log::rhythm(), $k, false ), esc_html( $label ) );
		}
		echo '</select> ab <input type="date" name="' . esc_attr( BW_Bridge_Log::OPT_START ) . '" value="' . esc_attr( (string) get_option( BW_Bridge_Log::OPT_START, '' ) ) . '"><p class="description">Vertragsbeginn. Leer = Kalenderzeiträume (Quartale ab 1. Jänner). Das Vertragsjahr für die jährliche Sicherheitsanalyse rechnet ab diesem Datum.</p></td></tr>';
		printf( '<tr><th scope="row">Tage in der Datenbank</th><td><input type="number" min="30" max="3650" name="%s" value="%d" class="small-text"> <p class="description">Ältere Einträge wandern monatsweise in komprimierte Archivdateien unter <code>wp-content/uploads/bw-bridge-log-…/</code> (gesperrt) und werden für Berichte von dort mitgelesen.</p></td></tr>', esc_attr( BW_Bridge_Log::OPT_DB_DAYS ), (int) BW_Bridge_Log::db_days() );
		if ( ! BW_Bridge_Scan::disabled() ) {
			printf( '<tr><th scope="row">Dateien überwachen</th><td><label><input type="checkbox" name="%s" value="1" %s> aktivieren</label><p class="description">Täglicher Abgleich der Code-Dateien von Core, Plugins und Themes sowie der PHP-Dateien in uploads; Vergleich mit den Originaldateien von wordpress.org. Unerwartete Änderungen und PHP-Dateien an ungewöhnlicher Stelle erscheinen als Warnung (es wird nichts verschoben oder gelöscht).</p></td></tr>', esc_attr( BW_Bridge_Scan::OPT_FILES_ON ), checked( BW_Bridge_Scan::files_enabled(), true, false ) );
			printf( '<tr><th scope="row">Schwachstellen prüfen</th><td><label><input type="checkbox" name="%s" value="1" %s> aktivieren</label><p class="description">Täglich gegen die öffentliche Datenbank von <a href="https://www.wpvulnerability.net/" target="_blank" rel="noopener noreferrer">wpvulnerability.net</a> (ohne Schlüssel). Übermittelt werden nur die Namen (Slugs) der Plugins und Themes und die WordPress-Version.</p></td></tr>', esc_attr( BW_Bridge_Scan::OPT_VULNS_ON ), checked( BW_Bridge_Scan::vulns_enabled(), true, false ) );
		}
		printf( '<tr><th scope="row">Aufbewahrung gesamt</th><td><input type="number" min="0" max="36500" name="%s" value="%d" class="small-text"> Tage <p class="description">Danach werden Einträge und Archivdateien gelöscht. 0 = unbegrenzt. Mindestens so lang wie die Tage in der Datenbank.</p></td></tr>', esc_attr( BW_Bridge_Log::OPT_KEEP_DAYS ), (int) get_option( BW_Bridge_Log::OPT_KEEP_DAYS, BW_Bridge_Log::DEFAULT_KEEP ) );
	}

	/* ------------------------------------------------------------------ */
	/* Gestaltung                                                          */
	/* ------------------------------------------------------------------ */

	private static function styles() {
		?>
<style>
.bwm{--bwm-surface:#fff;--bwm-bg:#f3f5f9;--bwm-ink:#0b1b33;--bwm-ink-2:#52606f;--bwm-line:#e3e8ef;--bwm-accent:#1d4ed8;
--bwm-updates:#2a78d6;--bwm-security:#eb6834;--bwm-seo:#1baf7a;--bwm-changes:#eda100;
--bwm-ok:#0ca30c;--bwm-info:#2a78d6;--bwm-warn:#fab219;--bwm-fail:#d03b3b;color:var(--bwm-ink);max-width:1280px}
.bwm .nav-tab-wrapper{margin-bottom:16px}
.bwm-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin:8px 0 12px}
.bwm-head h1{font-size:28px;line-height:1.2;margin:0;padding:0;font-weight:600}
.bwm-eyebrow{margin:0 0 4px;color:var(--bwm-ink-2);font-size:13px}
.bwm-sub,.bwm-muted{color:var(--bwm-ink-2)}
.bwm-sub{margin:6px 0 0}
.bwm-periods{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 16px}
.bwm-pill{display:inline-block;padding:4px 12px;border:1px solid var(--bwm-line);border-radius:999px;background:var(--bwm-surface);text-decoration:none;color:var(--bwm-ink)}
.bwm-pill.is-active{background:var(--bwm-accent);border-color:var(--bwm-accent);color:#fff}
.bwm-custom{margin-left:auto;display:flex;gap:6px;align-items:center}
.bwm-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:12px}
.bwm-tile,.bwm-card{background:var(--bwm-surface);border:1px solid var(--bwm-line);border-radius:12px}
.bwm-tile{padding:14px 16px;display:flex;flex-direction:column;gap:2px;border-left:3px solid var(--bwm-line);color:inherit;text-decoration:none;cursor:pointer;transition:box-shadow .15s}
a.bwm-tile:hover,a.bwm-tile:focus-visible{box-shadow:0 2px 10px rgba(0,0,0,.08);color:inherit}
.bwm-card[id]{scroll-margin-top:48px}
.bwm-tile.is-updates{border-left-color:var(--bwm-updates)}.bwm-tile.is-security{border-left-color:var(--bwm-security)}.bwm-tile.is-seo{border-left-color:var(--bwm-seo)}.bwm-tile.is-changes{border-left-color:var(--bwm-changes)}.bwm-tile.is-notes,.bwm-tile.is-audit{border-left-color:var(--bwm-ink-2)}
.bwm-tile-label{font-size:13px;color:var(--bwm-ink-2)}
.bwm-tile-value{font-size:30px;font-weight:600;line-height:1.2;font-variant-numeric:tabular-nums}
.bwm-tile-sub{font-size:12px;color:var(--bwm-ink-2)}
.bwm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:12px}
.bwm-span2{grid-column:span 2}
@media (max-width:900px){.bwm-span2{grid-column:auto}}
.bwm-card{padding:16px 18px;margin-bottom:12px}
.bwm-grid .bwm-card{margin-bottom:0}
.bwm-card-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.bwm-card h2{font-size:16px;margin:0}
.bwm-card h3{font-size:14px;margin:14px 0 6px}
.bwm-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.bwm-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px;background:var(--bwm-ink-2)}
.bwm-dot.is-updates{background:var(--bwm-updates)}.bwm-dot.is-security{background:var(--bwm-security)}.bwm-dot.is-seo{background:var(--bwm-seo)}.bwm-dot.is-changes{background:var(--bwm-changes)}
.bwm-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--bwm-ink-2);margin-bottom:4px}
.bwm-chart{width:100%;height:auto;display:block}
.bwm-chart .grid{stroke:var(--bwm-line);stroke-width:1}
.bwm-chart .axis{stroke:#c5ccd6;stroke-width:1}
.bwm-chart .tick{fill:var(--bwm-ink-2);font-size:11px}
.bwm-chart .seg.is-updates{fill:var(--bwm-updates)}.bwm-chart .seg.is-security{fill:var(--bwm-security)}.bwm-chart .seg.is-seo{fill:var(--bwm-seo)}.bwm-chart .seg.is-changes{fill:var(--bwm-changes)}
.bwm-chart .seg:hover{opacity:.8}
.bwm-tableview summary{cursor:pointer;color:var(--bwm-ink-2);font-size:12px;margin-top:6px}
.bwm-bars{margin:0}
.bwm textarea.large-text{width:100%}
.bwm-bars li{display:grid;grid-template-columns:11em 1fr 3.5em;gap:8px;align-items:center;margin:0 0 8px}
.bwm-bar-track{background:var(--bwm-bg);border-radius:4px;height:10px;overflow:hidden}
.bwm-bar{display:block;height:100%;background:var(--bwm-updates);border-radius:0 4px 4px 0}
.bwm-bar-value{text-align:right;font-variant-numeric:tabular-nums}
.bwm-checks{margin:0}
.bwm-checks li{display:grid;grid-template-columns:8.5em 1fr;gap:10px;padding:7px 0;border-top:1px solid var(--bwm-line);margin:0}
.bwm-checks li:first-child{border-top:0}
.bwm-status{font-size:12px;white-space:nowrap}
.bwm-status b,.bwm-status-inline{display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:50%;color:#fff;font-size:11px;margin-right:4px}
.is-ok .bwm-status b,.bwm-status-inline.is-ok{background:var(--bwm-ok)}
.is-info .bwm-status b{background:var(--bwm-info)}
.is-warn .bwm-status b,.bwm-status-inline.is-warn{background:var(--bwm-warn);color:#3a2a00}
.is-fail .bwm-status b{background:var(--bwm-fail)}
.bwm-table{width:100%;border-collapse:collapse;font-size:13px}
.bwm-table th{text-align:left;font-weight:600;color:var(--bwm-ink-2);border-bottom:1px solid var(--bwm-line);padding:6px 8px}
.bwm-table td{border-bottom:1px solid var(--bwm-line);padding:6px 8px;vertical-align:top}
.bwm-table .num{text-align:right;font-variant-numeric:tabular-nums}
.bwm-table tr.lvl-warning td:first-child{box-shadow:inset 3px 0 0 var(--bwm-fail)}
.bwm-tag{font-size:11px;background:var(--bwm-bg);border-radius:4px;padding:1px 6px;color:var(--bwm-ink-2)}
.bwm-list{margin:0;columns:2}
.bwm-note{margin-top:12px}
.bwm-note summary{list-style:none;display:inline-block}
.bwm-note form{margin-top:10px;padding:12px;background:var(--bwm-bg);border-radius:8px}
.bwm-row{display:flex;flex-wrap:wrap;gap:14px}
.bwm-filter{margin:0 0 12px}
@media print{
#adminmenumain,#wpadminbar,#wpfooter,.bw-no-print,.notice,.update-nag{display:none!important}
#wpcontent,#wpbody-content{margin:0!important;padding:0!important}
.bwm{max-width:none}.bwm-card,.bwm-tile{break-inside:avoid;border-color:#ccc}
.bwm-tableview{display:none}
}
</style>
		<?php
	}
}
