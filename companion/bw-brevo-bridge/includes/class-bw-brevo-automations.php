<?php
/**
 * Automationen: Brevo-Workflows selbst werden in Brevo gebaut (die API legt keine Workflows an). Gesteuert wird, WANN sie starten:
 * Ereignisse (POST /events) und Kontakte aus WordPress lösen die in Brevo hinterlegten Workflows aus.
 *
 *  - Regeln (Einstellungen): WordPress-Ereignis → Brevo-Ereignisname, z. B. user_register → "wp_user_register".
 *  - REST: POST brevo/events für einzelne Ereignisse (z. B. von Claude).
 *  - Sicherungen: nur mit Freigabe "Live-Aktionen", nur mit Einwilligung, Protokoll ohne E-Mail-Klartext (outbox).
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Automations {

	const OUTBOX_MAX = 50;

	/** @return array<string,array{label:string,hook:string}> erweiterbar über den Filter bwb_triggers */
	public static function triggers() {
		return apply_filters( 'bwb_triggers', [
			'user_register'      => [ 'label' => 'Benutzer registriert', 'hook' => 'user_register' ],
			'wp_login'           => [ 'label' => 'Benutzer meldet sich an', 'hook' => 'wp_login' ],
			'wc_order_processing' => [ 'label' => 'WooCommerce: Bestellung in Bearbeitung', 'hook' => 'woocommerce_order_status_processing' ],
			'wc_order_completed' => [ 'label' => 'WooCommerce: Bestellung abgeschlossen', 'hook' => 'woocommerce_order_status_completed' ],
		] );
	}

	public static function rules() {
		$saved = (array) get_option( 'bwb_rules', [] );
		$out   = [];
		foreach ( array_keys( self::triggers() ) as $t ) {
			$out[ $t ] = [
				'enabled' => ! empty( $saved[ $t ]['enabled'] ),
				'event'   => ! empty( $saved[ $t ]['event'] ) ? (string) $saved[ $t ]['event'] : self::default_event( $t ),
			];
		}
		return $out;
	}

	public static function default_event( $trigger ) {
		return 0 === strpos( $trigger, 'wp_' ) ? $trigger : 'wp_' . $trigger;
	}

	public static function sanitize_rules( $in ) {
		$out = [];
		foreach ( array_keys( self::triggers() ) as $t ) {
			$ev = isset( $in[ $t ]['event'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in[ $t ]['event'] ) : '';
			$out[ $t ] = [ 'enabled' => ! empty( $in[ $t ]['enabled'] ) ? 1 : 0, 'event' => substr( $ev, 0, 60 ) ?: self::default_event( $t ) ];
		}
		return $out;
	}

	public static function init() {
		foreach ( self::rules() as $t => $rule ) {
			if ( $rule['enabled'] ) {
				add_action( self::triggers()[ $t ]['hook'], static function () use ( $t ) {
					self::on_trigger( $t, func_get_args() );
				}, 20, 2 );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Auslöser                                                            */
	/* ------------------------------------------------------------------ */

	/** Liefert [ email, properties, einwilligung(bool) ] oder null. */
	public static function context( $trigger, $args ) {
		if ( 'user_register' === $trigger || 'wp_login' === $trigger ) {
			$user = 'wp_login' === $trigger ? ( $args[1] ?? null ) : get_userdata( (int) ( $args[0] ?? 0 ) );
			if ( ! $user || empty( $user->user_email ) ) {
				return null;
			}
			return [ $user->user_email, [ 'user_id' => (int) $user->ID, 'role' => (string) ( $user->roles[0] ?? '' ) ], (bool) get_user_meta( $user->ID, 'bwb_consent', true ) ];
		}
		if ( 0 === strpos( $trigger, 'wc_order' ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) ( $args[0] ?? 0 ) );
			if ( ! $order ) {
				return null;
			}
			$uid     = (int) $order->get_user_id();
			$consent = (bool) $order->get_meta( 'bwb_consent' ) || ( $uid && (bool) get_user_meta( $uid, 'bwb_consent', true ) );
			return [ $order->get_billing_email(), [ 'order_id' => (int) $order->get_id(), 'total' => (float) $order->get_total(), 'currency' => (string) $order->get_currency() ], $consent ];
		}
		return apply_filters( 'bwb_trigger_context', null, $trigger, $args );
	}

	public static function on_trigger( $trigger, $args ) {
		$rule = self::rules()[ $trigger ] ?? null;
		$ctx  = $rule ? self::context( $trigger, $args ) : null;
		if ( ! $ctx || ! is_email( $ctx[0] ) ) {
			return;
		}
		self::fire( $ctx[0], $rule['event'], $ctx[1], $ctx[2], $trigger );
	}

	/**
	 * Ereignis an Brevo senden (nach Prüfung). Gibt ein Ergebnis-Array zurück, nie eine Ausnahme.
	 *
	 * @param bool $consent Einwilligung liegt vor
	 */
	public static function fire( $email, $event, $props, $consent, $source ) {
		$email = strtolower( trim( (string) $email ) );
		$event = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $event );
		$consent = (bool) apply_filters( 'bwb_has_consent', $consent, $email, $event, $source );
		$entry = [ 'event' => $event, 'source' => $source, 'to' => self::mask( $email ) ];

		if ( '' === $event || ! is_email( $email ) ) {
			return [ 'ok' => false, 'result' => 'invalid' ];
		}
		if ( get_option( 'bwb_consent_required', 1 ) && ! $consent ) {
			self::log( 'event', $entry, 'skipped_no_consent' );
			return [ 'ok' => false, 'result' => 'skipped_no_consent', 'note' => 'Keine gespeicherte Einwilligung (Meta bwb_consent).' ];
		}
		if ( ! BW_Brevo_Settings::live() ) {
			self::log( 'event', $entry, 'dry_run' );
			return [ 'ok' => true, 'dry_run' => true, 'note' => 'Probelauf: Live-Aktionen nicht freigegeben, nichts gesendet.' ];
		}
		$clean = [];
		foreach ( array_slice( (array) $props, 0, 20, true ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$clean[ substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 40 ) ] = is_string( $v ) ? mb_substr( $v, 0, 255 ) : $v;
			}
		}
		$c = BW_Brevo_API::request( 'POST', '/contacts', [ 'email' => $email, 'updateEnabled' => true ] );
		if ( is_wp_error( $c ) && 'duplicate_parameter' !== ( $c->get_error_data()['brevo_code'] ?? '' ) ) {
			self::log( 'event', $entry, 'error: ' . $c->get_error_message() );
			return [ 'ok' => false, 'result' => 'error', 'error' => $c->get_error_message() ];
		}
		$r = BW_Brevo_API::request( 'POST', '/events', [ 'event_name' => $event, 'identifiers' => [ 'email_id' => $email ], 'event_properties' => (object) $clean ] );
		if ( is_wp_error( $r ) ) {
			self::log( 'event', $entry, 'error: ' . $r->get_error_message() );
			return [ 'ok' => false, 'result' => 'error', 'error' => $r->get_error_message() ];
		}
		self::log( 'event', $entry, 'sent' );
		return [ 'ok' => true, 'result' => 'sent' ];
	}

	private static function mask( $email ) {
		$at = strpos( $email, '@' );
		return $at ? substr( $email, 0, 1 ) . '***' . substr( $email, $at ) : '***';
	}

	/** Protokoll (Ringpuffer, ohne E-Mail-Klartext). */
	public static function log( $type, $data, $result ) {
		$box   = (array) get_option( 'bwb_outbox', [] );
		$box[] = [ 't' => gmdate( 'c' ), 'type' => $type, 'data' => $data, 'result' => $result ];
		update_option( 'bwb_outbox', array_slice( $box, -self::OUTBOX_MAX ), false );
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function rest_event( $request ) {
		$email = (string) $request->get_param( 'email' );
		$event = (string) $request->get_param( 'event' );
		if ( '' === $event || ! is_email( $email ) ) {
			return new WP_Error( 'bwb_invalid', 'email und event sind Pflicht.', [ 'status' => 422 ] );
		}
		$r = self::fire( $email, $event, (array) $request->get_param( 'properties' ), (bool) $request->get_param( 'consent_confirmed' ), 'rest' );
		return rest_ensure_response( $r );
	}

	public static function rest_rules() {
		$out = [];
		foreach ( self::rules() as $t => $rule ) {
			$out[] = [ 'trigger' => $t, 'label' => self::triggers()[ $t ]['label'], 'hook' => self::triggers()[ $t ]['hook'] ] + $rule;
		}
		return rest_ensure_response( [ 'live' => BW_Brevo_Settings::live(), 'consent_required' => (bool) get_option( 'bwb_consent_required', 1 ), 'rules' => $out ] );
	}

	public static function rest_outbox() {
		return rest_ensure_response( array_reverse( (array) get_option( 'bwb_outbox', [] ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Backend                                                             */
	/* ------------------------------------------------------------------ */

	public static function render_rules() {
		echo '<h2>Automations-Auslöser</h2><p class="description">Welches Ereignis aus WordPress soll in Brevo einen Workflow starten? Den Workflow baust du in Brevo (Automation › Auslöser „Ereignis“, Name wie unten). Es werden nur Ereignisse gesendet, wenn „Live-Aktionen“ freigegeben sind.</p>';
		echo '<table class="widefat striped" style="max-width:48em"><thead><tr><th>Auslöser in WordPress</th><th>aktiv</th><th>Ereignisname in Brevo</th></tr></thead><tbody>';
		foreach ( self::rules() as $t => $rule ) {
			printf(
				'<tr><td>%s <code>%s</code></td><td><input type="checkbox" name="bwb_rules[%s][enabled]" value="1" %s></td><td><input type="text" name="bwb_rules[%s][event]" value="%s" class="regular-text"></td></tr>',
				esc_html( self::triggers()[ $t ]['label'] ), esc_html( self::triggers()[ $t ]['hook'] ), esc_attr( $t ), checked( $rule['enabled'], true, false ), esc_attr( $t ), esc_attr( $rule['event'] )
			);
		}
		echo '</tbody></table>';
	}
}
