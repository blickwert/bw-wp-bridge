<?php
/**
 * Elementor-Texte: alle Texte einer Seite mit Widget-ID und Pfad auslesen, einzelne Texte gezielt setzen,
 * zwei Layouts textweise vergleichen. Die Kernfunktionen (extract, apply, diff) sind reine PHP-Funktionen ohne
 * WordPress-Aufrufe und deshalb ohne WordPress testbar (tests/test-elementor-texts.php).
 *
 * Pfade sind relativ zum Element, z. B. "settings.title", "settings.editor" oder "settings.items[11].item_title".
 * Neue (atomare) Widgets speichern Texte als { "$$type": "escaped-html", "value": "…" }, klassische Widgets als String;
 * beides wird einheitlich als Text behandelt.
 */

defined( 'ABSPATH' ) || exit;

final class BW_Bridge_Elementor_Texts {

	/** Einstellungsnamen, die Text enthalten. */
	const TEXT_KEYS = [
		'title', 'paragraph', 'text', 'editor', 'description', 'description_text', 'title_text', 'sub_title', 'sub_heading', 'heading',
		'caption', 'content', 'tab_title', 'tab_content', 'item_title', 'item_text', 'item_description', 'label', 'placeholder',
		'before_text', 'after_text', 'rotating_text', 'highlighted_text', 'button_text', 'alert_title', 'alert_description',
		'html', 'shortcode', 'message', 'testimonial_content', 'testimonial_name', 'testimonial_job',
		'load_more_no_posts_custom_message', 'nothing_found_message_text',
		// Elementor-Pro-Formular: Beschriftungen, Meldungen, Mails (inkl. Empfänger/Absender, die beim Livegang angepasst werden)
		'field_label', 'field_options', 'acceptance_text', 'success_message', 'error_message', 'required_field_message',
		'email_to', 'email_to_2', 'email_from', 'email_from_2', 'email_from_name', 'email_from_name_2',
		'email_subject', 'email_subject_2', 'email_content', 'email_content_2',
	];

	/** Einstellungen, in die nicht hineingesehen wird (Stile, CSS-Klassen, globale Verweise). */
	const SKIP_KEYS = [ 'styles', 'classes', '__globals__', '__dynamic__', '_css_classes', 'custom_css', '_element_id', 'link', 'url', 'interactions', 'editor_settings' ];

	public static function register_routes() {
		$can = [ 'BW_Bridge_Elementor', 'can_edit_post' ];
		register_rest_route( BW_WP_Bridge::NS, '/elementor/(?P<id>\d+)/texts', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_texts' ], 'permission_callback' => $can ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'set_texts' ], 'permission_callback' => $can ],
		] );
	}

	/* ---------------------------------------------------------------- reine Funktionen */

	/**
	 * Liefert alle Texte eines Layouts: [ { widget_id, widget_type, path, value }, … ].
	 *
	 * @param array $elements Elementor-Elementbaum.
	 */
	public static function extract( array $elements ) {
		$out = [];
		self::walk( $elements, $out );
		return $out;
	}

	private static function walk( array $elements, array &$out ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) && ! empty( $el['id'] ) ) {
				self::walk_settings( $el['settings'], 'settings', (string) $el['id'], (string) ( $el['widgetType'] ?? $el['elType'] ?? '' ), '', $out );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $out );
			}
		}
	}

	private static function walk_settings( $node, $path, $id, $type, $key, array &$out ) {
		if ( is_array( $node ) && isset( $node['$$type'] ) ) {
			// Atomare Eigenschaft: nur Text, wenn der Name zu den Textfeldern gehört.
			if ( in_array( $key, self::TEXT_KEYS, true ) && isset( $node['value'] ) && is_string( $node['value'] ) && self::has_text( $node['value'] ) ) {
				$out[] = [ 'widget_id' => $id, 'widget_type' => $type, 'path' => $path, 'value' => $node['value'] ];
			}
			return;
		}
		if ( is_string( $node ) ) {
			if ( in_array( $key, self::TEXT_KEYS, true ) && self::has_text( $node ) ) {
				$out[] = [ 'widget_id' => $id, 'widget_type' => $type, 'path' => $path, 'value' => $node ];
			}
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $k => $v ) {
			if ( is_int( $k ) ) {
				self::walk_settings( $v, $path . '[' . $k . ']', $id, $type, $key, $out );
			} elseif ( ! in_array( $k, self::SKIP_KEYS, true ) ) {
				self::walk_settings( $v, $path . '.' . $k, $id, $type, $k, $out );
			}
		}
	}

	/** Groß-/Kleinschreibung ignorierende Suche (UTF-8); fällt ohne mbstring auf stripos zurück. */
	public static function ipos( $haystack, $needle ) {
		return function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle ) : stripos( $haystack, $needle );
	}

	private static function has_text( $s ) {
		return 1 === preg_match( '/\p{L}|\p{N}/u', $s );
	}

	/**
	 * Pfad zerlegen: "settings.items[2].item_title" => [ 'settings', 'items', 2, 'item_title' ].
	 */
	public static function parse_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! preg_match_all( '/\[(\d+)\]|([^.\[\]]+)/', $path, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$segs = [];
		foreach ( $m as $x ) {
			$segs[] = ( isset( $x[2] ) && '' !== $x[2] ) ? $x[2] : (int) $x[1];
		}
		return $segs;
	}

	/** Referenz auf das Element mit dieser ID (oder null). */
	private static function &find_element( array &$elements, $id ) {
		$null = null;
		foreach ( $elements as &$el ) {
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = &self::find_element( $el['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return $null;
	}

	/**
	 * Setzt Texte. $changes: [ { widget_id, path, value, expect? }, … ]. "expect" (optional) bricht die Änderung ab,
	 * wenn der aktuelle Text anders lautet. $elements wird nur verändert, wenn $dry_run false ist.
	 * Ergebnis je Änderung: { widget_id, path, status: ok|error, old, new, message? }.
	 */
	public static function apply( array &$elements, array $changes, $dry_run = false ) {
		$results = [];
		$work    = $elements; // bei Fehlern/Probelauf unverändert lassen
		foreach ( $changes as $c ) {
			$id   = is_array( $c ) ? ( $c['widget_id'] ?? null ) : null;
			$path = is_array( $c ) ? ( $c['path'] ?? null ) : null;
			$res  = [ 'widget_id' => $id, 'path' => $path, 'status' => 'error' ];
			if ( ! is_array( $c ) || null === $id || ! array_key_exists( 'value', $c ) || ! is_string( $c['value'] ) ) {
				$results[] = $res + [ 'message' => 'widget_id, path und value (Text) erwartet.' ];
				continue;
			}
			$segs = self::parse_path( $path );
			if ( ! $segs || 'settings' !== $segs[0] ) {
				$results[] = $res + [ 'message' => 'Pfad muss mit "settings" beginnen.' ];
				continue;
			}
			$el = &self::find_element( $work, $id );
			if ( null === $el ) {
				$results[] = $res + [ 'message' => 'Widget nicht gefunden.' ];
				unset( $el );
				continue;
			}
			$ref = &$el;
			$ok  = true;
			foreach ( $segs as $s ) {
				if ( ! is_array( $ref ) || ! array_key_exists( $s, $ref ) ) {
					$ok = false;
					break;
				}
				$ref = &$ref[ $s ];
			}
			if ( ! $ok ) {
				$results[] = $res + [ 'message' => 'Pfad nicht vorhanden (neue Felder werden nicht angelegt).' ];
				unset( $ref, $el );
				continue;
			}
			$atomic = is_array( $ref ) && isset( $ref['$$type'] ) && array_key_exists( 'value', $ref );
			$old    = $atomic ? $ref['value'] : $ref;
			if ( ! is_string( $old ) ) {
				$results[] = $res + [ 'message' => 'Pfad zeigt nicht auf einen Text.' ];
				unset( $ref, $el );
				continue;
			}
			if ( array_key_exists( 'expect', $c ) && $c['expect'] !== $old ) {
				$results[] = $res + [ 'message' => 'Aktueller Text weicht von "expect" ab.', 'old' => $old ];
				unset( $ref, $el );
				continue;
			}
			if ( $atomic ) {
				$ref['value'] = $c['value'];
			} else {
				$ref = $c['value'];
			}
			unset( $ref, $el );
			$results[] = [ 'widget_id' => $id, 'path' => $path, 'status' => 'ok', 'old' => $old, 'new' => $c['value'] ];
		}
		if ( ! $dry_run ) {
			$elements = $work;
		}
		return $results;
	}

	/**
	 * Textvergleich zweier Layouts: { changed: [ { widget_id, path, old, new } ], added: [...], removed: [...] }.
	 */
	public static function diff( array $before, array $after ) {
		$index = static function ( array $list ) {
			$m = [];
			foreach ( $list as $t ) {
				$m[ $t['widget_id'] . '|' . $t['path'] ] = $t;
			}
			return $m;
		};
		$a = $index( self::extract( $before ) );
		$b = $index( self::extract( $after ) );
		$r = [ 'changed' => [], 'added' => [], 'removed' => [] ];
		foreach ( $b as $k => $t ) {
			if ( ! isset( $a[ $k ] ) ) {
				$r['added'][] = [ 'widget_id' => $t['widget_id'], 'widget_type' => $t['widget_type'], 'path' => $t['path'], 'new' => $t['value'] ];
			} elseif ( $a[ $k ]['value'] !== $t['value'] ) {
				$r['changed'][] = [ 'widget_id' => $t['widget_id'], 'widget_type' => $t['widget_type'], 'path' => $t['path'], 'old' => $a[ $k ]['value'], 'new' => $t['value'] ];
			}
		}
		foreach ( $a as $k => $t ) {
			if ( ! isset( $b[ $k ] ) ) {
				$r['removed'][] = [ 'widget_id' => $t['widget_id'], 'widget_type' => $t['widget_type'], 'path' => $t['path'], 'old' => $t['value'] ];
			}
		}
		return $r;
	}

	/* ---------------------------------------------------------------- Routen */

	/** GET elementor/{id}/texts – optional ?q=Suchtext (nur passende Texte). */
	public static function get_texts( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$data = BW_Bridge_Elementor::layout( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$texts = self::extract( $data );
		$q     = (string) $r->get_param( 'q' );
		if ( '' !== $q ) {
			$texts = array_values( array_filter( $texts, static function ( $t ) use ( $q ) {
				return false !== self::ipos( $t['value'], $q );
			} ) );
		}
		return rest_ensure_response( [ 'id' => $id, 'count' => count( $texts ), 'texts' => $texts ] );
	}

	/**
	 * POST elementor/{id}/texts – Body: { "changes": [ { widget_id, path, value, expect? } ], "dry_run": false }.
	 * Legt vor dem Speichern eine Sicherung an. Bei einem Fehler in einer Änderung wird nichts gespeichert.
	 */
	public static function set_texts( WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$body = $r->get_json_params();
		if ( ! is_array( $body ) || empty( $body['changes'] ) || ! is_array( $body['changes'] ) ) {
			return new WP_Error( 'bw_bridge_invalid', '"changes" (Liste) fehlt.', [ 'status' => 400 ] );
		}
		$elements = BW_Bridge_Elementor::layout( $id );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}
		$dry     = ! empty( $body['dry_run'] );
		$results = self::apply( $elements, $body['changes'], true );
		$failed  = array_filter( $results, static function ( $x ) {
			return 'ok' !== $x['status'];
		} );
		if ( $dry || $failed ) {
			return rest_ensure_response( [ 'id' => $id, 'dry_run' => $dry, 'saved' => false, 'results' => $results ] );
		}
		self::apply( $elements, $body['changes'], false );
		$saved = BW_Bridge_Elementor::persist( $id, $elements, [], 'Texte ändern' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return rest_ensure_response( [ 'id' => $id, 'dry_run' => false, 'saved' => true, 'results' => $results ] );
	}
}
