<?php
/**
 * Newsletter-Kampagnen als Beitragstyp "bw_newsletter" mit Tab-Feldern; Übertragung nach Brevo.
 *
 * Ablauf: schreiben (Editor) → push (Entwurf in Brevo anlegen/aktualisieren) → test (Testmail an die Testempfänger)
 * → send/schedule (nur mit Freigabe "Live-Aktionen" und nur, wenn genau dieser Inhalt vorher als Test verschickt wurde).
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Campaigns {

	const CPT = 'bw_newsletter';

	/** Felder des Tabs "Inhalt"/"Versand" (Meta-Schlüssel bwb_<name>) */
	const FIELDS = [
		'subject'      => 'Betreff',
		'preheader'    => 'Vorschautext',
		'sender_name'  => 'Absendername',
		'sender_email' => 'Absender-E-Mail',
		'reply_to'     => 'Antwortadresse',
		'lists'        => 'Listen-IDs',
		'scheduled_at' => 'Versandzeit',
		'tag'          => 'Tag',
		'base'         => 'Layout (Brevo-Vorlage/-Kampagne)',
		'vars'         => 'Weitere Platzhalter',
	];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_post_bwb_campaign', [ __CLASS__, 'handle_admin_action' ] );
	}

	public static function register() {
		register_post_type( self::CPT, [
			'labels'       => [ 'name' => 'Newsletter', 'singular_name' => 'Newsletter', 'add_new_item' => 'Neuen Newsletter anlegen', 'edit_item' => 'Newsletter bearbeiten', 'menu_name' => 'Newsletter' ],
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-email-alt',
			'supports'     => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
		] );
		foreach ( array_keys( self::FIELDS ) as $f ) {
			register_post_meta( self::CPT, 'bwb_' . $f, [
				'type' => 'string', 'single' => true, 'show_in_rest' => true,
				'sanitize_callback' => static function ( $v ) use ( $f ) { return self::sanitize_field( $f, $v ); },
				'auth_callback'     => static function ( $allowed, $key, $post_id ) { return current_user_can( 'edit_post', $post_id ); },
			] );
		}
	}

	public static function sanitize_field( $f, $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		switch ( $f ) {
			case 'sender_email':
			case 'reply_to':
				return sanitize_email( $v );
			case 'lists':
				return implode( ',', BW_Brevo_Settings::ids( $v ) );
			case 'preheader':
				return mb_substr( sanitize_text_field( $v ), 0, 150 );
			case 'base':
				return BW_Brevo_Templates::normalize_ref( $v );
			case 'vars':
				return mb_substr( sanitize_textarea_field( $v ), 0, 4000 );
			default:
				return sanitize_text_field( $v );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Daten                                                               */
	/* ------------------------------------------------------------------ */

	/** Alle Felder mit Rückfall auf die Einstellungen. */
	public static function fields( $post_id ) {
		$p = get_post( $post_id );
		$f = [];
		foreach ( array_keys( self::FIELDS ) as $k ) {
			$f[ $k ] = (string) get_post_meta( $post_id, 'bwb_' . $k, true );
		}
		$f['title']        = $p ? $p->post_title : '';
		$f['subject']      = '' !== $f['subject'] ? $f['subject'] : $f['title'];
		$f['sender_name']  = '' !== $f['sender_name'] ? $f['sender_name'] : (string) get_option( 'bwb_sender_name', '' );
		$f['sender_email'] = '' !== $f['sender_email'] ? $f['sender_email'] : (string) get_option( 'bwb_sender_email', '' );
		$f['list_ids']     = BW_Brevo_Settings::ids( $f['lists'] ) ?: BW_Brevo_Settings::default_lists();
		$f['base']         = '' !== $f['base'] ? $f['base'] : BW_Brevo_Templates::normalize_ref( get_option( 'bwb_default_base', '' ) );
		return $f;
	}

	/**
	 * Mail-HTML: mit Basis-Layout aus Brevo (Platzhalter ersetzen) oder mit dem eingebauten Standardlayout.
	 * Filter bwb_email_html erlaubt Anpassungen. Gibt String oder WP_Error zurück.
	 */
	public static function build_html( $post_id, $f = null, $fresh = false ) {
		$post   = get_post( $post_id );
		$f      = $f ?: self::fields( $post_id );
		$body   = apply_filters( 'the_content', $post->post_content );
		if ( '' !== $f['base'] ) {
			$base = BW_Brevo_Templates::base_html( $f['base'], $fresh );
			if ( is_wp_error( $base ) ) {
				return $base;
			}
			$r = BW_Brevo_Templates::fill( $base, BW_Brevo_Templates::vars( $post_id, $f, $body ) );
			if ( $r['error'] ) {
				return new WP_Error( 'bwb_template', $r['error'], [ 'status' => 422 ] );
			}
			return (string) apply_filters( 'bwb_email_html', $r['html'], $post_id, $f );
		}
		$pre    = '' !== $f['preheader'] ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html( $f['preheader'] ) . '</div>' : '';
		$footer = wpautop( (string) get_option( 'bwb_footer', '' ) );
		$html   = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $f['subject'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f4"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
			. $pre
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#222"><tr><td style="padding:24px">'
			. $body
			. '</td></tr><tr><td style="padding:16px 24px;font-size:12px;color:#777;border-top:1px solid #eee">' . $footer
			. '<p><a href="{{ unsubscribe }}" style="color:#777">Newsletter abbestellen</a></p></td></tr></table></td></tr></table></body></html>';
		return (string) apply_filters( 'bwb_email_html', $html, $post_id, $f );
	}

	/** Reine Funktion: Brevo-Nutzlast für eine Kampagne. scheduledAt nur auf ausdrücklichen Wunsch. */
	public static function build_payload( $f, $html, $scheduled_at = null ) {
		$p = [
			'name'        => $f['title'] ?: $f['subject'],
			'subject'     => $f['subject'],
			'sender'      => [ 'name' => $f['sender_name'], 'email' => $f['sender_email'] ],
			'htmlContent' => $html,
			'recipients'  => [ 'listIds' => array_values( $f['list_ids'] ) ],
		];
		if ( '' !== $f['preheader'] ) {
			$p['previewText'] = $f['preheader'];
		}
		if ( '' !== $f['reply_to'] ) {
			$p['replyTo'] = $f['reply_to'];
		}
		if ( '' !== $f['tag'] ) {
			$p['tag'] = $f['tag'];
		}
		if ( $scheduled_at ) {
			$p['scheduledAt'] = $scheduled_at;
		}
		return $p;
	}

	/** Pflichtangaben prüfen; gibt Fehlertext oder null zurück. */
	public static function validate( $f, $html ) {
		$miss = [];
		foreach ( [ 'subject' => 'Betreff', 'sender_name' => 'Absendername', 'sender_email' => 'Absender-E-Mail' ] as $k => $l ) {
			if ( '' === trim( (string) $f[ $k ] ) ) {
				$miss[] = $l;
			}
		}
		if ( empty( $f['list_ids'] ) ) {
			$miss[] = 'Listen-IDs';
		}
		if ( $miss ) {
			return 'Fehlende Angaben: ' . implode( ', ', $miss );
		}
		if ( false === stripos( $html, 'unsubscribe' ) ) {
			return 'Im Mail-HTML fehlt der Abmeldelink ({{ unsubscribe }}). Ohne ihn darf nicht versendet werden.';
		}
		if ( preg_match( '/\{\{\s*(?!unsubscribe|contact|mirror|update_profile|params)/i', $html ) && ! apply_filters( 'bwb_allow_unknown_placeholders', true ) ) {
			return 'Unbekannte Platzhalter im Mail-HTML.';
		}
		return null;
	}

	public static function payload_hash( $payload ) {
		unset( $payload['scheduledAt'] );
		return md5( wp_json_encode( $payload ) );
	}

	/* ------------------------------------------------------------------ */
	/* Aktionen (liefern Array oder WP_Error)                              */
	/* ------------------------------------------------------------------ */

	private static function prepare( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::CPT !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'bwb_not_found', 'Kein Newsletter mit dieser ID.', [ 'status' => 404 ] );
		}
		$f    = self::fields( $post_id );
		$html = self::build_html( $post_id, $f, true );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$err  = self::validate( $f, $html );
		if ( $err ) {
			return new WP_Error( 'bwb_invalid', $err, [ 'status' => 422 ] );
		}
		return [ $f, $html, self::build_payload( $f, $html ) ];
	}

	public static function push( $post_id ) {
		$prep = self::prepare( $post_id );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		list( , , $payload ) = $prep;
		$cid = (int) get_post_meta( $post_id, 'bwb_campaign_id', true );
		if ( $cid ) {
			$cur = BW_Brevo_API::request( 'GET', "/emailCampaigns/$cid" );
			if ( is_wp_error( $cur ) ) {
				return $cur;
			}
			$status = (string) ( $cur['status'] ?? '' );
			update_post_meta( $post_id, 'bwb_status', $status );
			if ( 'draft' !== $status ) {
				return new WP_Error( 'bwb_locked', "Die Kampagne in Brevo hat den Status „{$status}“ und lässt sich nicht mehr ändern. Für eine neue Ausgabe den Newsletter duplizieren.", [ 'status' => 409 ] );
			}
			$r = BW_Brevo_API::request( 'PUT', "/emailCampaigns/$cid", $payload );
		} else {
			$r = BW_Brevo_API::request( 'POST', '/emailCampaigns', $payload );
			if ( ! is_wp_error( $r ) ) {
				$cid = (int) ( $r['id'] ?? 0 );
				update_post_meta( $post_id, 'bwb_campaign_id', $cid );
			}
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_post_meta( $post_id, 'bwb_status', 'draft' );
		update_post_meta( $post_id, 'bwb_pushed_hash', self::payload_hash( $payload ) );
		update_post_meta( $post_id, 'bwb_pushed_at', gmdate( 'c' ) );
		return [ 'ok' => true, 'campaign_id' => $cid, 'status' => 'draft', 'url' => self::brevo_url( $cid ), 'note' => 'Entwurf in Brevo gespeichert. Es wurde nichts versendet.' ];
	}

	public static function test( $post_id, $emails = [] ) {
		$prep = self::prepare( $post_id );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		$allowed = BW_Brevo_Settings::emails( get_option( 'bwb_test_emails', '' ) );
		$asked   = BW_Brevo_Settings::emails( $emails );
		$emails  = $asked ?: $allowed;
		if ( ! BW_Brevo_Settings::live() ) {
			$emails = array_values( array_intersect( $emails, $allowed ) ); // ohne Freigabe nur an die Testempfänger
		}
		if ( ! $emails ) {
			return new WP_Error( 'bwb_no_test_recipient', $asked ? 'Ohne Freigabe „Live-Aktionen“ gehen Testmails nur an die Testempfänger aus den Einstellungen.' : 'Kein Testempfänger. Unter Newsletter › Brevo eintragen.', [ 'status' => 422 ] );
		}
		if ( count( $emails ) > 20 ) {
			return new WP_Error( 'bwb_too_many', 'Höchstens 20 Testempfänger.', [ 'status' => 422 ] );
		}
		$pushed = self::push( $post_id );   // Test immer mit dem aktuellen Stand
		if ( is_wp_error( $pushed ) ) {
			return $pushed;
		}
		$cid = (int) $pushed['campaign_id'];
		$r   = BW_Brevo_API::request( 'POST', "/emailCampaigns/$cid/sendTest", [ 'emailTo' => $emails ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_post_meta( $post_id, 'bwb_tested_hash', self::payload_hash( $prep[2] ) );
		update_post_meta( $post_id, 'bwb_tested_at', gmdate( 'c' ) );
		return [ 'ok' => true, 'campaign_id' => $cid, 'sent_to' => $emails ];
	}

	/** Senden (sofort) oder planen ($at gesetzt). Ohne Freigabe: Probelauf. */
	public static function send( $post_id, $at = null ) {
		$prep = self::prepare( $post_id );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		list( $f, , $payload ) = $prep;
		$when = null;
		if ( null !== $at && '' !== $at ) {
			$when = self::parse_time( $at );
			if ( is_wp_error( $when ) ) {
				return $when;
			}
		}
		$summary = [ 'newsletter' => (int) $post_id, 'subject' => $f['subject'], 'lists' => $f['list_ids'], 'when' => $when ?: 'sofort' ];
		if ( ! BW_Brevo_Settings::live() ) {
			BW_Brevo_Automations::log( 'campaign_send', $summary, 'dry_run' );
			return [ 'ok' => true, 'dry_run' => true, 'would_send' => $summary, 'note' => 'Probelauf: Live-Aktionen sind nicht freigegeben (Newsletter › Brevo). Es wurde nichts versendet.' ];
		}
		if ( get_post_meta( $post_id, 'bwb_tested_hash', true ) !== self::payload_hash( $payload ) && apply_filters( 'bwb_require_test_before_send', true ) ) {
			return new WP_Error( 'bwb_untested', 'Dieser Inhalt wurde noch nicht als Testmail verschickt (oder wurde seitdem geändert). Erst „test“ ausführen und prüfen.', [ 'status' => 409 ] );
		}
		$pushed = self::push( $post_id );
		if ( is_wp_error( $pushed ) ) {
			return $pushed;
		}
		$cid = (int) $pushed['campaign_id'];
		if ( $when ) {
			$r = BW_Brevo_API::request( 'PUT', "/emailCampaigns/$cid", [ 'scheduledAt' => $when ] );
		} else {
			$r = BW_Brevo_API::request( 'POST', "/emailCampaigns/$cid/sendNow" );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_post_meta( $post_id, 'bwb_status', $when ? 'queued' : 'in_process' );
		BW_Brevo_Automations::log( 'campaign_send', $summary, 'sent' );
		return [ 'ok' => true, 'campaign_id' => $cid, 'status' => $when ? 'queued' : 'in_process', 'when' => $when ?: 'sofort' ];
	}

	/** Zeitangabe in Website-Zeit oder ISO 8601 → ISO 8601; muss in der Zukunft liegen. */
	public static function parse_time( $v ) {
		try {
			$d = new DateTime( (string) $v, wp_timezone() );
		} catch ( Exception $e ) {
			return new WP_Error( 'bwb_time', 'Ungültige Zeitangabe.', [ 'status' => 422 ] );
		}
		if ( $d->getTimestamp() < time() + 300 ) {
			return new WP_Error( 'bwb_time', 'Die Versandzeit muss mindestens 5 Minuten in der Zukunft liegen.', [ 'status' => 422 ] );
		}
		return $d->format( DATE_ATOM );
	}

	public static function sync( $post_id ) {
		$cid = (int) get_post_meta( $post_id, 'bwb_campaign_id', true );
		if ( ! $cid ) {
			return new WP_Error( 'bwb_no_campaign', 'Noch nicht nach Brevo übertragen.', [ 'status' => 409 ] );
		}
		$r = BW_Brevo_API::request( 'GET', "/emailCampaigns/$cid", null, [ 'statistics' => 'globalStats' ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_post_meta( $post_id, 'bwb_status', (string) ( $r['status'] ?? '' ) );
		return BW_Brevo_Stats::store( $post_id, $r );
	}

	public static function brevo_url( $cid ) {
		return 'https://app.brevo.com/marketing-campaign/edit/' . (int) $cid;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function rest_action( $request ) {
		$id = (int) $request['id'];
		switch ( $request['action'] ) {
			case 'preview':
				$r = self::preview( $id );
				break;
			case 'push':
				$r = self::push( $id );
				break;
			case 'test':
				$r = self::test( $id, $request->get_param( 'emails' ) ?: [] );
				break;
			case 'send':
				$r = self::send( $id );
				break;
			case 'schedule':
				$r = $request->get_param( 'at' ) ? self::send( $id, $request->get_param( 'at' ) ) : new WP_Error( 'bwb_time', 'Parameter "at" fehlt (z. B. 2026-11-03 09:00 Website-Zeit).', [ 'status' => 422 ] );
				break;
			default:
				$r = self::sync( $id );
		}
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function rest_list() {
		$out = [];
		foreach ( get_posts( [ 'post_type' => self::CPT, 'post_status' => [ 'publish', 'draft', 'private', 'pending', 'future' ], 'numberposts' => 100, 'orderby' => 'modified', 'order' => 'DESC' ] ) as $p ) {
			$cid   = (int) get_post_meta( $p->ID, 'bwb_campaign_id', true );
			$stats = get_post_meta( $p->ID, 'bwb_stats', true );
			$out[] = [
				'id' => $p->ID, 'title' => $p->post_title, 'subject' => self::fields( $p->ID )['subject'],
				'campaign_id' => $cid ?: null, 'brevo_status' => get_post_meta( $p->ID, 'bwb_status', true ) ?: null,
				'layout' => self::fields( $p->ID )['base'] ?: 'standard',
				'in_sync' => $cid ? self::in_sync( $p->ID ) : false,
				'tested' => (bool) get_post_meta( $p->ID, 'bwb_tested_at', true ),
				'stats' => is_array( $stats ) ? $stats : null,
			];
		}
		return rest_ensure_response( $out );
	}

	/** Stimmt der in Brevo gespeicherte Stand mit dem aktuellen WordPress-Stand überein? null = nicht prüfbar. */
	public static function in_sync( $post_id ) {
		$f    = self::fields( $post_id );
		$html = self::build_html( $post_id, $f );
		return is_wp_error( $html ) ? null : get_post_meta( $post_id, 'bwb_pushed_hash', true ) === self::payload_hash( self::build_payload( $f, $html ) );
	}

	/** Fertiges Mail-HTML ansehen, ohne etwas zu übertragen. */
	public static function preview( $post_id ) {
		$prep = self::prepare( $post_id );
		if ( is_wp_error( $prep ) ) {
			return $prep;
		}
		return [ 'ok' => true, 'subject' => $prep[0]['subject'], 'layout' => $prep[0]['base'] ?: 'standard', 'length' => strlen( $prep[1] ), 'html' => $prep[1] ];
	}

	public static function rest_lists() {
		$r = BW_Brevo_API::request( 'GET', '/contacts/lists', null, [ 'limit' => 50 ] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array_map( static function ( $l ) {
			return [ 'id' => $l['id'] ?? null, 'name' => $l['name'] ?? null, 'subscribers' => $l['uniqueSubscribers'] ?? null ];
		}, $r['lists'] ?? [] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Backend: Tab-Feld                                                   */
	/* ------------------------------------------------------------------ */

	public static function add_box() {
		add_meta_box( 'bwb_box', 'Newsletter · Brevo', [ __CLASS__, 'render_box' ], self::CPT, 'normal', 'high' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'bwb_save', 'bwb_nonce' );
		$f      = self::fields( $post->ID );
		$cid    = (int) get_post_meta( $post->ID, 'bwb_campaign_id', true );
		$status = (string) get_post_meta( $post->ID, 'bwb_status', true );
		$stats  = get_post_meta( $post->ID, 'bwb_stats', true );
		$tab    = static function ( $id, $label, $active = false ) {
			printf( '<a href="#" class="nav-tab%s" data-bwb-tab="%s">%s</a>', $active ? ' nav-tab-active' : '', esc_attr( $id ), esc_html( $label ) );
		};
		echo '<div id="bwb-tabs"><h2 class="nav-tab-wrapper">';
		$tab( 'inhalt', 'Inhalt', true );
		$tab( 'versand', 'Versand' );
		$tab( 'analyse', 'Brevo & Analyse' );
		echo '</h2>';

		echo '<div data-bwb-pane="inhalt"><table class="form-table"><tbody>';
		self::input_row( 'subject', 'Betreff', $f['subject'], 'Standard: Titel. Der Text des Newsletters kommt aus dem Editor oben.' );
		self::input_row( 'preheader', 'Vorschautext', $f['preheader'], 'Wird im Posteingang neben dem Betreff angezeigt (max. 150 Zeichen).' );
		self::base_row( $f['base'] );
		printf(
			'<tr><th scope="row"><label for="bwb_vars">Weitere Platzhalter</label></th><td><textarea id="bwb_vars" name="bwb_vars" rows="3" class="large-text code" placeholder="button_url=https://…&#10;intro=Kurztext">%s</textarea><p class="description">Eine Zeile je Wert: <code>name=wert</code> → im Layout <code>[[bw_var:name]]</code> (Endung <code>_url</code> = Adresse, <code>_html</code> = HTML erlaubt).</p></td></tr>',
			esc_textarea( $f['vars'] )
		);
		echo '</tbody></table></div>';

		echo '<div data-bwb-pane="versand" hidden><table class="form-table"><tbody>';
		self::input_row( 'sender_name', 'Absendername', $f['sender_name'], 'Leer = Einstellung.' );
		self::input_row( 'sender_email', 'Absender-E-Mail', $f['sender_email'], 'Muss in Brevo verifiziert sein.' );
		self::input_row( 'reply_to', 'Antwortadresse', $f['reply_to'] );
		self::input_row( 'lists', 'Listen-IDs', $f['lists'], 'Kommagetrennt. Leer = Standard-Listen (' . esc_html( implode( ',', BW_Brevo_Settings::default_lists() ) ) . ').' );
		self::input_row( 'scheduled_at', 'Versandzeit', $f['scheduled_at'], 'Website-Zeit, z. B. 2026-11-03 09:00. Wird erst bei „Planen“ an Brevo gegeben.' );
		self::input_row( 'tag', 'Tag', $f['tag'], 'Zum Gruppieren der Auswertung in Brevo.' );
		echo '</tbody></table></div>';

		echo '<div data-bwb-pane="analyse" hidden>';
		echo '<p>Brevo: ' . ( $cid ? '<a href="' . esc_url( self::brevo_url( $cid ) ) . '" target="_blank" rel="noopener">Kampagne ' . (int) $cid . '</a> · Status <strong>' . esc_html( $status ?: '?' ) . '</strong>' : '<em>noch nicht übertragen</em>' ) . '</p>';
		echo '<p>Live-Aktionen: <strong>' . ( BW_Brevo_Settings::live() ? 'freigegeben' : 'gesperrt (Probelauf)' ) . '</strong>' . ( get_post_meta( $post->ID, 'bwb_tested_at', true ) ? ' · letzter Test: ' . esc_html( get_post_meta( $post->ID, 'bwb_tested_at', true ) ) : '' ) . '</p>';
		if ( is_array( $stats ) ) {
			BW_Brevo_Admin::stats_table( $stats );
		}
		if ( $post->ID && 'auto-draft' !== $post->post_status ) {
			foreach ( [ 'preview' => 'Vorschau', 'push' => 'Als Entwurf nach Brevo', 'test' => 'Testmail senden', 'sync' => 'Statistik abgleichen', 'send' => 'Jetzt senden', 'schedule' => 'Planen' ] as $a => $l ) {
				$url = wp_nonce_url( add_query_arg( [ 'action' => 'bwb_campaign', 'do' => $a, 'post' => $post->ID ], admin_url( 'admin-post.php' ) ), 'bwb_campaign_' . $post->ID );
				$js  = in_array( $a, [ 'send', 'schedule' ], true ) ? ' onclick="return confirm(\'Wirklich ' . esc_js( $l ) . '? Das betrifft echte Empfänger.\')"' : '';
				printf( '<a class="button%s" href="%s"%s style="margin-right:6px"' . ( 'preview' === $a ? ' target="_blank" rel="noopener"' : '' ) . '>%s</a>', in_array( $a, [ 'send', 'schedule' ], true ) ? '' : ' button-secondary', esc_url( $url ), $js, esc_html( $l ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- $js ist fester Text
			}
			echo '<p class="description">Erst speichern, dann übertragen – es wird der gespeicherte Stand verwendet.</p>';
		}
		echo '</div></div>';
		?>
		<script>
		(function () {
			var root = document.getElementById('bwb-tabs');
			if (!root) { return; }
			var tabs = root.querySelectorAll('[data-bwb-tab]'), panes = root.querySelectorAll('[data-bwb-pane]');
			function show(id) {
				tabs.forEach(function (t) { t.classList.toggle('nav-tab-active', t.dataset.bwbTab === id); });
				panes.forEach(function (p) { p.hidden = p.dataset.bwbPane !== id; });
			}
			tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); show(t.dataset.bwbTab); }); });
		})();
		</script>
		<?php
	}

	private static function base_row( $current ) {
		$opts = BW_Brevo_API::key() ? BW_Brevo_Templates::options() : new WP_Error( 'x', 'kein API-Schlüssel' );
		echo '<tr><th scope="row"><label for="bwb_base">Layout</label></th><td>';
		if ( is_wp_error( $opts ) ) {
			printf( '<input type="text" id="bwb_base" name="bwb_base" value="%s" class="regular-text" placeholder="t12 (Vorlage) oder c55 (Kampagne)"><p class="description">Liste aus Brevo nicht abrufbar (%s). ID eintragen: <code>t12</code> = Vorlage 12, <code>c55</code> = Kampagne 55.</p>', esc_attr( $current ), esc_html( $opts->get_error_message() ) );
		} else {
			if ( $current && ! isset( $opts[ $current ] ) ) {
				$opts[ $current ] = $current . ' (nicht in der Liste)';
			}
			echo '<select id="bwb_base" name="bwb_base"><option value="">Standardlayout dieses Plugins</option>';
			foreach ( $opts as $k => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
			}
			echo '</select>';
		}
		echo '<p class="description">Das Layout bleibt in Brevo. Dort steht <code>[[bw_content]]</code> an der Stelle des Inhalts (weitere: <code>[[bw_title]]</code>, <code>[[bw_subject]]</code>, <code>[[bw_preheader]]</code>, <code>[[bw_date]]</code>). Beim Übertragen wird eine neue Kampagne aus Layout + Inhalt erzeugt.</p></td></tr>';
	}

	private static function input_row( $name, $label, $value, $help = '' ) {
		printf(
			'<tr><th scope="row"><label for="bwb_%1$s">%2$s</label></th><td><input type="text" id="bwb_%1$s" name="bwb_%1$s" value="%3$s" class="large-text">%4$s</td></tr>',
			esc_attr( $name ), esc_html( $label ), esc_attr( $value ), $help ? '<p class="description">' . $help . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput -- feste Texte
		);
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['bwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bwb_nonce'] ) ), 'bwb_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array_keys( self::FIELDS ) as $k ) {
			if ( isset( $_POST[ 'bwb_' . $k ] ) ) {
				$v = self::sanitize_field( $k, wp_unslash( $_POST[ 'bwb_' . $k ] ) );
				// Vorbelegte Standardwerte nicht fest speichern.
				if ( 'subject' === $k && $v === $post->post_title ) {
					$v = '';
				}
				update_post_meta( $post_id, 'bwb_' . $k, $v );
			}
		}
	}

	public static function handle_admin_action() {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bwb_campaign_' . $id );
		switch ( $do ) {
			case 'preview':
				$r = self::preview( $id );
				if ( ! is_wp_error( $r ) ) {
					header( 'Content-Type: text/html; charset=utf-8' );
					header( "Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'; font-src *" );
					header( 'X-Content-Type-Options: nosniff' );
					echo $r['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- bewusst Roh-HTML, durch CSP sandbox ohne Skripte
					exit;
				}
				break;
			case 'push':
				$r = self::push( $id );
				break;
			case 'test':
				$r = self::test( $id );
				break;
			case 'sync':
				$r = self::sync( $id );
				break;
			case 'send':
			case 'schedule':
				$at = 'schedule' === $do ? (string) get_post_meta( $id, 'bwb_scheduled_at', true ) : null;
				if ( ! current_user_can( 'publish_posts' ) ) {
					$r = new WP_Error( 'bwb_cap', 'Keine Berechtigung zum Versenden.' );
				} elseif ( 'schedule' === $do && '' === $at ) {
					$r = new WP_Error( 'bwb_time', 'Im Tab „Versand“ keine Versandzeit eingetragen.' );
				} else {
					$r = self::send( $id, $at );
				}
				break;
			default:
				$r = new WP_Error( 'bwb_action', 'Unbekannte Aktion.' );
		}
		BW_Brevo_Admin::flash( is_wp_error( $r ) ? [ 'error', $r->get_error_message() ] : [ 'success', ! empty( $r['dry_run'] ) ? $r['note'] : ( $r['note'] ?? 'Erledigt.' ) ] );
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}
}
