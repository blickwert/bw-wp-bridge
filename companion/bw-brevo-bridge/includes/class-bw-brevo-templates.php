<?php
/**
 * Layout-Trennung: Das Layout liegt in Brevo (Vorlage oder bestehende Kampagne), der Inhalt kommt aus WordPress.
 *
 * Im Brevo-Layout stehen Platzhalter in doppelten eckigen Klammern (kollidieren nicht mit Brevos {{ … }}):
 *   [[bw_content]]      Inhalt des Newsletter-Beitrags (HTML; Pflicht)
 *   [[bw_title]]        Titel des Beitrags
 *   [[bw_subject]]      Betreff
 *   [[bw_preheader]]    Vorschautext
 *   [[bw_date]]         Versanddatum (aus „Versandzeit“, sonst heute), Format der Website
 *   [[bw_var:name]]     eigener Wert aus dem Feld „Weitere Platzhalter“ (Zeilen name=wert);
 *                       Endung _url → als Adresse bereinigt, Endung _html → als HTML erlaubt, sonst Text
 *
 * Beim Übertragen wird das Layout aus Brevo geholt, die Platzhalter werden ersetzt und die fertige Kampagne
 * neu angelegt. Fehlt [[bw_content]] oder bleibt ein Platzhalter ohne Wert, wird nichts übertragen.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Brevo_Templates {

	const MARKER = '/\[\[\s*bw_([a-z0-9_:\-]+)\s*\]\]/i';

	/** "t12", "template:12", "12" → t12; "c55", "campaign:55" → c55; sonst ''. */
	public static function normalize_ref( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( preg_match( '/^(?:t|template:?)?\s*(\d+)$/', $v, $m ) ) {
			return 't' . (int) $m[1];
		}
		if ( preg_match( '/^(?:c|campaign:?)\s*(\d+)$/', $v, $m ) ) {
			return 'c' . (int) $m[1];
		}
		return '';
	}

	/** Reine Funktion. Gibt [ 'html' => …, 'error' => null|Text ] zurück. */
	public static function fill( $html, $vars ) {
		preg_match_all( self::MARKER, $html, $found );
		$keys = array_map( 'strtolower', $found[1] );
		if ( ! in_array( 'content', $keys, true ) ) {
			return [ 'html' => $html, 'error' => 'Das Brevo-Layout enthält keinen Platzhalter [[bw_content]]. Dort wird der Inhalt eingesetzt.' ];
		}
		$bad = [];
		foreach ( array_unique( $keys ) as $k ) {
			if ( ! array_key_exists( $k, $vars ) || ( '' === $vars[ $k ] && 'preheader' !== $k ) ) {
				$bad[] = '[[bw_' . $k . ']]';
			}
		}
		if ( $bad ) {
			return [ 'html' => $html, 'error' => 'Für diese Platzhalter fehlt ein Wert: ' . implode( ', ', $bad ) . '. Unter „Weitere Platzhalter“ (Zeilen name=wert) ergänzen oder aus dem Layout entfernen.' ];
		}
		$out = preg_replace_callback( self::MARKER, static function ( $m ) use ( $vars ) {
			return (string) $vars[ strtolower( $m[1] ) ];
		}, $html );
		return [ 'html' => $out, 'error' => null ];
	}

	/** Zeilen "name=wert" → [ name => wert ]. */
	public static function parse_vars( $text ) {
		$out = [];
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			if ( preg_match( '/^\s*([a-z0-9_\-]+)\s*=\s*(.*?)\s*$/i', $line, $m ) ) {
				$out[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		return $out;
	}

	/** Werte je Platzhalter, bereits für die Einfügestelle bereinigt. */
	public static function vars( $post_id, $f, $body_html ) {
		$when = '' !== $f['scheduled_at'] ? strtotime( $f['scheduled_at'] ) : false;
		$vars = [
			'content'   => $body_html,
			'title'     => esc_html( $f['title'] ),
			'subject'   => esc_html( $f['subject'] ),
			'preheader' => esc_html( $f['preheader'] ),
			'date'      => esc_html( date_i18n( get_option( 'date_format' ) ?: 'd.m.Y', $when ?: time() ) ),
		];
		foreach ( self::parse_vars( $f['vars'] ?? '' ) as $name => $value ) {
			if ( '_url' === substr( $name, -4 ) ) {
				$vars[ 'var:' . $name ] = esc_url( $value );
			} elseif ( '_html' === substr( $name, -5 ) ) {
				$vars[ 'var:' . $name ] = wp_kses_post( $value );
			} else {
				$vars[ 'var:' . $name ] = esc_html( $value );
			}
		}
		return apply_filters( 'bwb_template_vars', $vars, $post_id, $f );
	}

	/** Layout-HTML aus Brevo (kurz zwischengespeichert; $fresh = immer neu holen). */
	public static function base_html( $ref, $fresh = false ) {
		$ref = self::normalize_ref( $ref );
		if ( '' === $ref ) {
			return new WP_Error( 'bwb_base', 'Ungültige Basis-Vorlage.', [ 'status' => 422 ] );
		}
		$ck = 'bwb_base_' . $ref;
		if ( ! $fresh ) {
			$c = get_transient( $ck );
			if ( is_string( $c ) && '' !== $c ) {
				return $c;
			}
		}
		$id  = (int) substr( $ref, 1 );
		$r   = BW_Brevo_API::request( 'GET', 't' === $ref[0] ? "/smtp/templates/$id" : "/emailCampaigns/$id" );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$html = (string) ( $r['htmlContent'] ?? '' );
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'bwb_base', 'Die gewählte Brevo-Vorlage/Kampagne hat keinen HTML-Inhalt (Drag-&-Drop-Layouts liefern ihn je nach Konto nicht aus).', [ 'status' => 422 ] );
		}
		set_transient( $ck, $html, 300 );
		return $html;
	}

	/** Auswahlliste für das Backend: [ 't12' => 'Vorlage: …', 'c55' => 'Kampagne: …' ] oder WP_Error. */
	public static function options() {
		$c = get_transient( 'bwb_base_options' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$out = [];
		$t   = BW_Brevo_API::request( 'GET', '/smtp/templates', null, [ 'templateStatus' => 'true', 'limit' => 50, 'sort' => 'desc' ] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		foreach ( $t['templates'] ?? [] as $x ) {
			$out[ 't' . (int) $x['id'] ] = 'Vorlage: ' . ( $x['name'] ?? '?' ) . ' (' . (int) $x['id'] . ')';
		}
		$k = BW_Brevo_API::request( 'GET', '/emailCampaigns', null, [ 'limit' => 30, 'sort' => 'desc' ] );
		if ( ! is_wp_error( $k ) ) {
			foreach ( $k['campaigns'] ?? [] as $x ) {
				$out[ 'c' . (int) $x['id'] ] = 'Kampagne: ' . ( $x['name'] ?? '?' ) . ' (' . (int) $x['id'] . ', ' . ( $x['status'] ?? '?' ) . ')';
			}
		}
		set_transient( 'bwb_base_options', $out, 600 );
		return $out;
	}

	public static function rest_bases() {
		$o = self::options();
		return is_wp_error( $o ) ? $o : rest_ensure_response( $o );
	}
}
