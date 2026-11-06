<?php
/**
 * Wartungsbericht per E-Mail: am ersten Tag nach Ende eines Abrechnungszeitraums wird eine HTML-Zusammenfassung
 * samt JSON-Datei („Daten für Claude“) an die eingestellten Empfänger gesendet. Zusätzlich lässt sich ein Testbericht
 * manuell auslösen (Einstellungen bzw. Wartungsprotokoll).
 *
 * - Automatischer Versand: täglicher Cron des Protokolls ruft maybe_send() auf. Beim allerersten Lauf wird nur der
 *   vorherige Zeitraum als „bereits erledigt“ markiert (OPT_LAST), damit nach Installation/Update kein Bericht für einen
 *   Zeitraum vor der Einrichtung verschickt wird.
 * - Schlägt der Versand fehl, wird am nächsten Tag erneut versucht, höchstens MAX_TRIES-mal je Zeitraum. Die Versuche
 *   zählt das Transient bw_bridge_report_tries_<md5 des Zeitraum-Schlüssels> (läuft nach 30 Tagen ab).
 * - Das Protokoll der Website (area system, category report) hält Versand und Fehlschläge fest.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Report_Mail {

	const OPT_ON     = 'bw_bridge_report_mail';
	const OPT_TO     = 'bw_bridge_report_to';
	const OPT_LAST   = 'bw_bridge_report_last';
	const DEFAULT_TO = 'maintenancereport@blickwert.at';
	const ACTION     = 'bw_bridge_report_send';
	const MAX_TRIES  = 3;
	const TRIES_KEY  = 'bw_bridge_report_tries_';
	/** Schrift im Inhalt: am body allein reicht nicht, Gmail und andere entfernen dessen Stil. */
	const FONT       = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	/** Für Tests: Callable ( array $range ) → Berichtsdaten. Standard (null): BW_Bridge_History::report(). */
	public static $report_builder = null;

	public static function init() {
		if ( BW_Bridge_Log::disabled() ) {
			return;
		}
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_send' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen                                                       */
	/* ------------------------------------------------------------------ */

	public static function enabled() {
		return ! BW_Bridge_Log::disabled() && (bool) get_option( self::OPT_ON, 1 ) && [] !== self::recipients();
	}

	/** @return string[] */
	public static function recipients() {
		$to = self::sanitize_recipients( (string) get_option( self::OPT_TO, self::DEFAULT_TO ) );
		return '' === $to ? [] : explode( ', ', $to );
	}

	/** Trennt an Komma, Semikolon und Leerraum, behält nur gültige, eindeutige Adressen. */
	public static function sanitize_recipients( $v ) {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY ) as $mail ) {
			if ( is_email( $mail ) && ! in_array( $mail, $out, true ) ) {
				$out[] = $mail;
			}
		}
		return implode( ', ', $out );
	}

	public static function register_settings( $group ) {
		register_setting( $group, self::OPT_ON, [ 'type' => 'boolean', 'default' => 1, 'show_in_rest' => false, 'sanitize_callback' => static fn( $v ) => $v ? 1 : 0 ] );
		register_setting( $group, self::OPT_TO, [ 'type' => 'string', 'default' => self::DEFAULT_TO, 'show_in_rest' => false, 'sanitize_callback' => [ __CLASS__, 'sanitize_recipients' ] ] );
	}

	/** Zeile für die Einstellungstabelle (innerhalb des Formulars zu options.php, daher ohne eigenes Formular). */
	public static function settings_rows() {
		$rh   = [ 'month' => 'Monat', 'quarter' => 'Quartal', 'half' => 'Halbjahr', 'year' => 'Jahr' ];
		$last = (string) get_option( self::OPT_LAST, '' );
		$when = 'noch keiner';
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $last, $m ) ) {
			$when = 'Zeitraum ' . self::date( $m[1] ) . ' – ' . self::date( $m[2] );
		}
		$test = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&period=current&back=settings' ), self::ACTION );

		echo '<tr><th scope="row">Bericht per E-Mail</th><td>';
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> am Ende jedes Abrechnungszeitraums automatisch senden</label><br>',
			esc_attr( self::OPT_ON ),
			checked( (bool) get_option( self::OPT_ON, 1 ), true, false )
		);
		printf(
			'<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" style="margin-top:6px"> ',
			esc_attr( self::OPT_TO ),
			esc_attr( (string) get_option( self::OPT_TO, self::DEFAULT_TO ) ),
			esc_attr( self::DEFAULT_TO )
		);
		printf( '<a class="button" href="%s">Testbericht jetzt senden</a>', esc_url( $test ) );
		printf(
			'<p class="description">Empfänger kommagetrennt. Gesendet wird am ersten Tag nach Ende des Abrechnungszeitraums (Rhythmus: %s, siehe oben) – als HTML-Zusammenfassung mit der JSON-Datei „Daten für Claude“ im Anhang. Der Testbericht enthält den laufenden Zeitraum und nutzt die gespeicherten Empfänger (erst speichern, dann testen). Letzter automatischer Versand: %s.</p>',
			esc_html( $rh[ BW_Bridge_Log::rhythm() ] ?? '' ),
			esc_html( $when )
		);
		echo '</td></tr>';
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( self::ACTION );
		$period = isset( $_REQUEST['period'] ) ? sanitize_key( wp_unslash( $_REQUEST['period'] ) ) : '';
		$from   = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '';
		$to     = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '';
		$range  = BW_Bridge_Log::resolve_range( $period ?: 'current', $from ?: null, $to ?: null );
		$result = self::send( $range, 'manual' );

		if ( isset( $_REQUEST['back'] ) && 'settings' === sanitize_key( wp_unslash( $_REQUEST['back'] ) ) ) {
			$back = admin_url( 'options-general.php?page=bw-wp-bridge' );
		} else {
			$args = 'custom' === $range['kind'] ? [ 'from' => $range['from'], 'to' => $range['to'] ] : [ 'period' => $range['kind'] ];
			$back = add_query_arg( array_merge( [ 'page' => 'bw-bridge-maintenance' ], $args ), admin_url( 'tools.php' ) );
		}
		wp_safe_redirect( add_query_arg( 'bw_mail', is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : 'ok', $back ) );
		exit;
	}

	/**
	 * Täglich vom Cron: Bericht des gerade beendeten Abrechnungszeitraums senden (einmal je Zeitraum).
	 * Rückgabe: true, wenn gesendet wurde.
	 */
	public static function maybe_send( $today = null ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$prev = BW_Bridge_Log::period( 'previous', $today );
		$key  = $prev['from'] . '_' . $prev['to'];
		$last = (string) get_option( self::OPT_LAST, '' );
		if ( '' === $last ) {
			update_option( self::OPT_LAST, $key, false );
			return false;
		}
		if ( $last === $key ) {
			return false;
		}
		$tries_key = self::TRIES_KEY . md5( $key );
		$tries     = (int) get_transient( $tries_key );
		if ( $tries >= self::MAX_TRIES ) {
			return false;
		}
		set_transient( $tries_key, $tries + 1, 30 * DAY_IN_SECONDS );

		$range         = BW_Bridge_Log::resolve_range( null, $prev['from'], $prev['to'] );
		$range['kind'] = 'previous';
		$range['label'] = $prev['label'];
		if ( is_wp_error( self::send( $range, 'auto' ) ) ) {
			return false;
		}
		update_option( self::OPT_LAST, $key, false );
		delete_transient( $tries_key );
		return true;
	}

	/** Bericht bauen und senden. $reason: auto (Cron) oder manual. Rückgabe: true oder WP_Error. */
	public static function send( array $range, $reason = 'auto' ) {
		$to = self::recipients();
		if ( ! $to ) {
			return new WP_Error( 'bw_bridge_report_no_recipient', 'Kein gültiger Empfänger eingestellt.' );
		}
		$data = is_callable( self::$report_builder ) ? call_user_func( self::$report_builder, $range ) : BW_Bridge_History::report( $range, true );
		$from = $range['from'];
		$end  = $range['to'];
		$url  = admin_url( 'tools.php?page=bw-bridge-maintenance&from=' . rawurlencode( $from ) . '&to=' . rawurlencode( $end ) );
		$name = sanitize_file_name( 'wartung-' . ( BW_Bridge_Key::site_id() ?: sanitize_title( get_bloginfo( 'name' ) ) ) . '-' . $from . '_' . $end . '.json' );

		$dir  = rtrim( get_temp_dir(), '/\\' ) . '/bw-bridge-report-' . bin2hex( random_bytes( 6 ) );
		$file = $dir . '/' . $name;
		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ) {
			self::cleanup( $dir );
			return new WP_Error( 'bw_bridge_report_file', 'Die Datei für den Anhang konnte nicht erstellt werden.' );
		}

		$error   = '';
		$capture = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $capture );
		$ok = wp_mail( $to, self::subject( $data ), self::html( $data, $url ), [ 'Content-Type: text/html; charset=UTF-8' ], [ $file ] );
		remove_action( 'wp_mail_failed', $capture );
		self::cleanup( $dir );

		$label = (string) ( $data['period']['label'] ?? $range['label'] ?? $from . ' – ' . $end );
		if ( BW_Bridge_Log::ready() ) {
			BW_Bridge_Log::add( [
				'area'     => 'system',
				'category' => 'report',
				'action'   => $ok ? 'sent' : 'failed',
				'level'    => $ok ? 'info' : 'warning',
				'via'      => 'auto' === $reason ? 'cron' : BW_Bridge_Log::via(),
				'message'  => $ok ? sprintf( 'Wartungsbericht %s an %s gesendet', $label, implode( ', ', $to ) ) : sprintf( 'Wartungsbericht %s konnte nicht gesendet werden', $label ),
				'details'  => [ 'period' => [ 'from' => $from, 'to' => $end ], 'to' => $to, 'reason' => $reason ] + ( $ok || '' === $error ? [] : [ 'error' => $error ] ),
			] );
		}
		if ( $ok ) {
			return true;
		}
		$msg = '' !== $error ? 'E-Mail konnte nicht gesendet werden: ' . $error : 'E-Mail konnte nicht gesendet werden.';
		if ( ! self::smtp_plugin() ) {
			$msg .= ' Hinweis: Kein SMTP-Plugin erkannt – viele Server können selbst keine E-Mails versenden. Ein SMTP-Plugin (z. B. WP Mail SMTP, FluentSMTP, Post SMTP) mit einem Postfach einrichten.';
		}
		return new WP_Error( 'bw_bridge_report_failed', $msg );
	}

	/** Name eines erkannten SMTP-Plugins oder null (nur für den Hinweis bei Fehlschlägen). */
	public static function smtp_plugin() {
		$known = [ 'WPMS_PLUGIN_VER' => 'WP Mail SMTP', 'FLUENTMAIL' => 'FluentSMTP', 'POST_SMTP_VER' => 'Post SMTP', 'EASY_WP_SMTP_PLUGIN_VERSION' => 'Easy WP SMTP', 'SMTP_MAILER_VERSION' => 'SMTP Mailer', 'SENDLAYER_PLUGIN_VERSION' => 'SendLayer', 'BREVO_MAIL_VERSION' => 'Brevo' ];
		foreach ( $known as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		return has_action( 'phpmailer_init' ) ? 'phpmailer_init' : null;
	}

	private static function cleanup( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			@unlink( $f );
		}
		@rmdir( $dir );
	}

	public static function subject( array $d ) {
		$s = 'Wartungsbericht ' . ( $d['period']['label'] ?? '' ) . ' – ' . ( $d['site']['name'] ?? '' );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/* ------------------------------------------------------------------ */
	/* HTML (ohne WordPress-Funktionen, testbar)                           */
	/* ------------------------------------------------------------------ */

	private static function e( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function date( $ymd ) {
		try {
			return ( new DateTimeImmutable( (string) $ymd ) )->format( 'd.m.Y' );
		} catch ( Exception $e ) {
			return (string) $ymd;
		}
	}

	private static function num( $n ) {
		return number_format( (int) $n, 0, ',', '.' );
	}

	private static function h2( $t ) {
		return '<h2 style="margin:28px 0 8px;font-size:16px;color:#0b1b33;border-bottom:1px solid #e3e8ef;padding-bottom:6px">' . self::e( $t ) . '</h2>';
	}

	private static function p( $t, $muted = false ) {
		return '<p style="margin:6px 0;font-size:14px;line-height:1.5;color:' . ( $muted ? '#52606f' : '#0b1b33' ) . '">' . self::e( $t ) . '</p>';
	}

	/** Liste von Prüfpunkten (fail = Dringend/rot, warn = Empfehlung/gelb). */
	private static function checks( array $checks ) {
		$out = '';
		foreach ( [ 'fail' => [ 'Dringend', '#d03b3b' ], 'warn' => [ 'Empfehlung', '#b7791f' ] ] as $st => [ $label, $color ] ) {
			foreach ( $checks as $c ) {
				if ( ( $c['status'] ?? '' ) !== $st ) {
					continue;
				}
				$out .= '<tr><td width="96" style="width:96px;padding:6px 8px 6px 0;vertical-align:top;white-space:nowrap;font-size:12px;font-weight:bold;color:' . $color . '">' . self::e( $label ) . '</td>'
					. '<td style="padding:6px 0;font-size:14px;line-height:1.4;color:#0b1b33"><strong>' . self::e( $c['title'] ?? '' ) . '</strong>'
					. ( ! empty( $c['detail'] ) ? '<br><span style="color:#52606f">' . self::e( $c['detail'] ) . '</span>' : '' ) . '</td></tr>';
			}
		}
		return '' === $out ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">' . $out . '</table>';
	}

	public static function html( array $d, $admin_url ) {
		$k      = (array) ( $d['kpis'] ?? [] );
		$kv     = static fn( $key ) => (int) ( $k[ $key ] ?? 0 );
		$site   = (array) ( $d['site'] ?? [] );
		$period = (array) ( $d['period'] ?? [] );
		$td     = 'padding:8px 0;border-bottom:1px solid #e3e8ef;font-size:14px;color:#0b1b33';

		$h  = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e( self::subject( $d ) ) . '</title></head>';
		$h .= '<body style="margin:0;padding:0;background:#f3f5f9;' . self::FONT . '">';
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f3f5f9"><tr><td align="center" style="padding:16px">';
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;background:#ffffff;border:1px solid #e3e8ef;border-radius:8px"><tr><td style="padding:24px;' . self::FONT . '">';

		// 1. Kopf
		$h .= '<h1 style="margin:0 0 4px;font-size:22px;color:#0b1b33">Wartungsbericht · ' . self::e( $site['name'] ?? '' ) . '</h1>';
		$h .= self::p( ( $period['label'] ?? '' ) . ' (' . self::date( $period['from'] ?? '' ) . ' – ' . self::date( $period['to'] ?? '' ) . ')' );
		if ( ! empty( $site['url'] ) ) {
			$h .= '<p style="margin:2px 0;font-size:13px"><a href="' . self::e( $site['url'] ) . '" style="color:#1d4ed8">' . self::e( $site['url'] ) . '</a></p>';
		}

		// 2. Kennzahlen
		$h .= self::h2( 'Kennzahlen' );
		$minutes = $kv( 'minutes' );
		$rows = [
			[ 'Updates (inkl. Neuinstallationen)', self::num( $kv( 'updates' ) + $kv( 'installs' ) ) ],
			[ 'Sicherheitsereignisse', self::num( $kv( 'security_events' ) ) ],
			[ 'Fehlgeschlagene Anmeldungen', self::num( $kv( 'failed_logins' ) ) ],
			[ 'Manuelle Änderungen', self::num( $kv( 'changes' ) ) ],
			[ 'Notizen', self::num( $kv( 'notes' ) ) . ' · Aufwand ' . intdiv( $minutes, 60 ) . ':' . sprintf( '%02d', $minutes % 60 ) . ' h' ],
			[ 'Aktionen über Claude', self::num( $kv( 'claude' ) ) ],
		];
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">';
		foreach ( $rows as [ $label, $value ] ) {
			$h .= '<tr><td style="' . $td . '">' . self::e( $label ) . '</td><td align="right" style="' . $td . ';font-weight:bold">' . self::e( $value ) . '</td></tr>';
		}
		$h .= '</table>';

		// 3. Sicherheitsprüfung
		$audit = (array) ( $d['audit'] ?? [] );
		$h    .= self::h2( 'Sicherheitsprüfung' );
		if ( $audit ) {
			$c    = (array) ( $audit['counts']['security'] ?? [] );
			$list = self::checks( (array) ( $audit['security'] ?? [] ) );
			$h   .= self::p( (int) ( $c['fail'] ?? 0 ) . ' dringend · ' . (int) ( $c['warn'] ?? 0 ) . ' Empfehlungen', true );
			$h   .= '' !== $list ? $list : self::p( 'Keine offenen Punkte.' );
		} else {
			$h .= self::p( 'Keine Prüfung enthalten.', true );
		}

		// 4. SEO
		$h  .= self::h2( 'SEO' );
		$seo = (array) ( $d['seo_analysis'] ?? [] );
		if ( isset( $seo['score'] ) && null !== $seo['score'] ) {
			$h .= '<p style="margin:6px 0;font-size:14px;color:#0b1b33">SEO-Score: <strong style="font-size:18px">' . self::e( (int) $seo['score'] ) . ' %</strong></p>';
			if ( ! empty( $seo['categories'] ) ) {
				$h .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:6px 0">';
				foreach ( (array) $seo['categories'] as $cat ) {
					$h .= '<tr><td style="padding:4px 0;font-size:13px;color:#0b1b33">' . self::e( $cat['label'] ?? '' ) . '</td><td align="right" style="padding:4px 0;font-size:13px;color:#52606f">'
						. self::e( (int) ( $cat['fail'] ?? 0 ) ) . ' dringend, ' . self::e( (int) ( $cat['warn'] ?? 0 ) ) . ' Empfehlungen</td><td align="right" style="padding:4px 0 4px 12px;font-size:13px;font-weight:bold;color:#0b1b33">' . self::e( (int) ( $cat['score'] ?? 0 ) ) . ' %</td></tr>';
				}
				$h .= '</table>';
			}
			$tasks = array_slice( (array) ( $seo['tasks'] ?? [] ), 0, 5 );
			if ( $tasks ) {
				$h .= '<p style="margin:12px 0 4px;font-size:13px;font-weight:bold;color:#0b1b33">Wichtigste Aufgaben</p><ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.5;color:#0b1b33">';
				foreach ( $tasks as $t ) {
					$n  = count( (array) ( $t['pages'] ?? [] ) );
					$h .= '<li>' . self::e( $t['title'] ?? '' ) . ' <span style="color:#52606f">(' . ( $n ? self::e( $n . ( 1 === $n ? ' Seite' : ' Seiten' ) ) : 'Website' ) . ')</span></li>';
				}
				$h .= '</ul>';
			}
		} else {
			$h .= self::p( 'SEO-Analyse noch nicht gelaufen.', true );
		}
		$seo_checks = self::checks( (array) ( $audit['seo'] ?? [] ) );
		if ( '' !== $seo_checks ) {
			$h .= '<p style="margin:12px 0 4px;font-size:13px;font-weight:bold;color:#0b1b33">SEO-Einstellungen</p>' . $seo_checks;
		}

		// 5. Dateien und Schwachstellen
		$scan = (array) ( $d['scan'] ?? [] );
		if ( $scan ) {
			$vc   = (array) ( $scan['vulns']['counts'] ?? [] );
			$vn   = array_sum( array_map( 'intval', $vc ) );
			$iss  = count( (array) ( $scan['integrity']['issues'] ?? [] ) );
			$h   .= self::h2( 'Dateien und Schwachstellen' );
			if ( $vn ) {
				$parts = [];
				foreach ( [ 'c' => 'kritisch', 'h' => 'hoch', 'm' => 'mittel', 'l' => 'niedrig', 'other' => 'sonstige' ] as $key => $label ) {
					if ( ! empty( $vc[ $key ] ) ) {
						$parts[] = (int) $vc[ $key ] . ' ' . $label;
					}
				}
				$h .= self::p( 'Bekannte Schwachstellen: ' . $vn . ' (' . implode( ', ', $parts ) . ')' );
			} else {
				$h .= self::p( 'Keine bekannten Schwachstellen.' );
			}
			$h .= self::p( $iss ? $iss . ' Bereich(e) weichen von den Originaldateien ab (Einzelheiten in der Sicherheitsprüfung oben).' : 'Alle geprüften Bereiche stimmen mit den Originaldateien überein.' );
		}

		// 6. Updates
		$updates = (array) ( $d['sections']['updates'] ?? [] );
		$h      .= self::h2( 'Updates im Zeitraum' );
		if ( $updates ) {
			$h .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">';
			// Gleiche Einträge am selben Tag zusammenfassen (z. B. mehrfach dasselbe Plugin installiert).
			$grouped = [];
			foreach ( $updates as $u ) {
				$key = ( $u['date'] ?? '' ) . '|' . ( $u['message'] ?? '' );
				if ( isset( $grouped[ $key ] ) ) {
					$grouped[ $key ]['n'] += max( 1, (int) ( $u['count'] ?? 1 ) );
				} else {
					$grouped[ $key ] = [ 'date' => $u['date'] ?? '', 'message' => $u['message'] ?? '', 'n' => max( 1, (int) ( $u['count'] ?? 1 ) ) ];
				}
			}
			$grouped = array_values( $grouped );
			foreach ( array_slice( $grouped, 0, 20 ) as $u ) {
				$h .= '<tr><td style="padding:3px 10px 3px 0;vertical-align:top;white-space:nowrap;font-size:13px;color:#52606f">' . self::e( self::date( $u['date'] ) ) . '</td><td style="padding:3px 0;font-size:13px;color:#0b1b33">' . self::e( $u['message'] ) . ( $u['n'] > 1 ? ' <span style="color:#52606f">(' . (int) $u['n'] . '×)</span>' : '' ) . '</td></tr>';
			}
			$h .= '</table>';
			if ( count( $grouped ) > 20 ) {
				$h .= self::p( '… und ' . ( count( $grouped ) - 20 ) . ' weitere', true );
			}
		} else {
			$h .= self::p( 'Keine Updates im Zeitraum.', true );
		}

		// 7. Notizen
		$notes = (array) ( $d['notes'] ?? [] );
		if ( $notes ) {
			$h .= self::h2( 'Notizen' ) . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">';
			foreach ( $notes as $n ) {
				$h .= '<tr><td style="padding:3px 10px 3px 0;vertical-align:top;white-space:nowrap;font-size:13px;color:#52606f">' . self::e( self::date( $n['date'] ?? '' ) ) . '</td><td style="padding:3px 0;font-size:13px;color:#0b1b33">' . self::e( $n['text'] ?? '' )
					. ( ! empty( $n['minutes'] ) ? ' <span style="color:#52606f">(' . self::e( (int) $n['minutes'] ) . ' min)</span>' : '' ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		// 8. Link und Fuß
		$h .= '<p style="margin:28px 0 8px"><a href="' . self::e( $admin_url ) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:10px 18px;border-radius:6px">Bericht im Backend öffnen</a></p>';
		$v  = (array) ( $d['versions'] ?? [] );
		$jn = 'wartung-' . ( $site['id'] ?? '' ?: 'website' ) . '-' . ( $period['from'] ?? '' ) . '_' . ( $period['to'] ?? '' ) . '.json';
		$h .= '<p style="margin:20px 0 4px;font-size:12px;line-height:1.5;color:#52606f">WordPress ' . self::e( $v['wordpress'] ?? '' ) . ' · PHP ' . self::e( $v['php'] ?? '' ) . ' · BW WP Bridge ' . self::e( $v['bridge'] ?? '' ) . '</p>';
		$h .= '<p style="margin:4px 0;font-size:12px;line-height:1.5;color:#52606f">Die Datei ' . self::e( $jn ) . ' im Anhang enthält alle Daten für den Abschlussbericht mit Claude (prompts/wartungsbericht.md).</p>';

		return $h . '</td></tr></table></td></tr></table></body></html>';
	}
}
