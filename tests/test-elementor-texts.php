<?php
/**
 * Test für BW_Bridge_Elementor_Texts (Texte auslesen, setzen, vergleichen) und BW_Bridge_Search::visible_lines.
 * Reine PHP-Logik, kein WordPress nötig. Aufruf: php tests/test-elementor-texts.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
require __DIR__ . '/../includes/class-bw-bridge-elementor-texts.php';
require __DIR__ . '/../includes/class-bw-bridge-search.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }

/* Layout mit atomaren Widgets, klassischen Widgets, Wiederholer (Akkordeon) und Stilen */
function layout() {
	return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [ '_element_id' => 'package' ], 'elements' => [
		[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [
			'classes' => [ '$$type' => 'classes', 'value' => [ 'g-1' ] ],
			'tag'     => [ '$$type' => 'string', 'value' => 'h3' ],
			'title'   => [ '$$type' => 'escaped-html', 'value' => 'Private Classes &amp; More' ],
			'link'    => [ '$$type' => 'link', 'value' => [ 'isTargetBlank' => null ] ],
		], 'styles' => [ 'e-h1' => [ 'variants' => [ [ 'props' => [ 'text-align' => [ '$$type' => 'string', 'value' => 'center' ] ] ] ] ] ] ],
		[ 'id' => 'p1', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [
			'paragraph' => [ '$$type' => 'escaped-html', 'value' => 'Mail: <a href="mailto:a@b.at">a@b.at</a>' ],
		] ],
		[ 'id' => 't1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [
			'editor' => '<p>Alt</p>', 'typography_font_family' => 'Poppins', 'typography_font_size' => [ 'unit' => 'px', 'size' => 20 ],
		] ],
		[ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'nested-accordion', 'settings' => [
			'items' => [ [ '_id' => 'x1', 'item_title' => 'Frage eins' ], [ '_id' => 'x2', 'item_title' => 'So funktioniert das Creditsystem' ] ],
			'accordion_border_normal_border' => 'none', '__globals__' => [ 'title' => 'globals/typography?id=primary' ],
		] ],
		[ 'id' => 'l1', 'elType' => 'widget', 'widgetType' => 'loop-grid', 'settings' => [ 'text' => 'Load More', 'template_id' => '1623' ] ],
		[ 'id' => 's1', 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => [ 'shortcode' => '[bw_credits_course_list]' ] ],
		[ 'id' => 'i1', 'elType' => 'widget', 'widgetType' => 'icon', 'settings' => [ 'selected_icon' => [ 'value' => 'far fa-calendar-alt', 'library' => 'fa-regular' ] ] ],
	] ] ];
}

/* --- extract --- */
$t   = BW_Bridge_Elementor_Texts::extract( layout() );
$map = [];
foreach ( $t as $x ) { $map[ $x['widget_id'] . '|' . $x['path'] ] = $x['value']; }
check( 'extract: atomare Überschrift', ( $map['h1|settings.title'] ?? '' ) === 'Private Classes &amp; More' );
check( 'extract: atomarer Absatz mit Link', isset( $map['p1|settings.paragraph'] ) );
check( 'extract: klassischer Editor', ( $map['t1|settings.editor'] ?? '' ) === '<p>Alt</p>' );
check( 'extract: Wiederholer mit Index im Pfad', ( $map['a1|settings.items[1].item_title'] ?? '' ) === 'So funktioniert das Creditsystem' );
check( 'extract: Button-/Loop-Text', ( $map['l1|settings.text'] ?? '' ) === 'Load More' );
check( 'extract: Shortcode', isset( $map['s1|settings.shortcode'] ) );
check( 'extract: ignoriert tag, classes, link, styles, __globals__, Typografie, Icons', count( $map ) === 7 );
check( 'extract: Widget-Typ wird mitgeliefert', $t[0]['widget_type'] === 'e-heading' );

/* --- Elementor-Pro-Formular --- */
$form = [ [ 'id' => 'f1', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [
	'form_name' => 'Anfrage', 'input_size' => 'sm',
	'form_fields' => [ [ 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Name', '_id' => 'a1b2c3d' ], [ 'custom_id' => 'x', 'field_type' => 'select', 'field_label' => 'Format', 'field_options' => "Online|online\nVor Ort|onsite", '_id' => 'e4f5a6b' ] ],
	'email_to' => 'bw-cms@blickwert.at', 'email_content_2' => 'Namaste [field id="name"]', 'success_message' => 'Danke!', 'button_text' => 'Senden',
] ] ];
$fm = []; foreach ( BW_Bridge_Elementor_Texts::extract( $form ) as $x ) { $fm[ $x['path'] ] = $x['value']; }
check( 'Formular: Feldbeschriftungen und Auswahloptionen', ( $fm['settings.form_fields[0].field_label'] ?? '' ) === 'Name' && isset( $fm['settings.form_fields[1].field_options'] ) );
check( 'Formular: Empfänger, Bestätigungsmail, Erfolgsmeldung, Button', ( $fm['settings.email_to'] ?? '' ) === 'bw-cms@blickwert.at' && isset( $fm['settings.email_content_2'], $fm['settings.success_message'], $fm['settings.button_text'] ) );
check( 'Formular: technische Felder (form_name, Typen, IDs) bleiben außen vor', ! isset( $fm['settings.form_name'] ) && ! isset( $fm['settings.input_size'] ) && count( $fm ) === 7 );
BW_Bridge_Elementor_Texts::apply( $form, [ [ 'widget_id' => 'f1', 'path' => 'settings.email_to', 'value' => 'helena@souldateyoga.com', 'expect' => 'bw-cms@blickwert.at' ] ] );
check( 'Formular: Empfänger per apply ändern', $form[0]['settings']['email_to'] === 'helena@souldateyoga.com' );

/* --- parse_path --- */
check( 'parse_path einfach', BW_Bridge_Elementor_Texts::parse_path( 'settings.title' ) === [ 'settings', 'title' ] );
check( 'parse_path mit Index', BW_Bridge_Elementor_Texts::parse_path( 'settings.items[11].item_title' ) === [ 'settings', 'items', 11, 'item_title' ] );
check( 'parse_path ungültig', BW_Bridge_Elementor_Texts::parse_path( '' ) === null );

/* --- apply --- */
$el  = layout();
$res = BW_Bridge_Elementor_Texts::apply( $el, [
	[ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'Private Sessions' ],
	[ 'widget_id' => 't1', 'path' => 'settings.editor', 'value' => '<p>Neu</p>', 'expect' => '<p>Alt</p>' ],
	[ 'widget_id' => 'a1', 'path' => 'settings.items[1].item_title', 'value' => 'Wie funktioniert das Yoga-Guthaben?' ],
] );
check( 'apply: alle ok', count( array_filter( $res, fn( $r ) => $r['status'] === 'ok' ) ) === 3 );
check( 'apply: atomarer Wert gesetzt, $$type bleibt', $el[0]['elements'][0]['settings']['title'] === [ '$$type' => 'escaped-html', 'value' => 'Private Sessions' ] );
check( 'apply: klassischer Wert gesetzt', $el[0]['elements'][2]['settings']['editor'] === '<p>Neu</p>' );
check( 'apply: Wiederholer gesetzt, _id bleibt', $el[0]['elements'][3]['settings']['items'][1] === [ '_id' => 'x2', 'item_title' => 'Wie funktioniert das Yoga-Guthaben?' ] );
check( 'apply: Stile/Klassen unverändert', $el[0]['elements'][0]['styles'] === layout()[0]['elements'][0]['styles'] && $el[0]['elements'][0]['settings']['tag']['value'] === 'h3' );
check( 'apply: Ergebnis enthält alt und neu', $res[0]['old'] === 'Private Classes &amp; More' && $res[0]['new'] === 'Private Sessions' );

$el  = layout();
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 't1', 'path' => 'settings.editor', 'value' => 'x', 'expect' => 'etwas anderes' ] ] );
check( 'apply: expect-Abweichung wird abgelehnt', $res[0]['status'] === 'error' && $el === layout() );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'zz', 'path' => 'settings.title', 'value' => 'x' ] ] );
check( 'apply: unbekanntes Widget', $res[0]['status'] === 'error' );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'h1', 'path' => 'settings.gibtsnicht', 'value' => 'x' ] ] );
check( 'apply: fehlender Pfad legt nichts an', $res[0]['status'] === 'error' && $el === layout() );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'h1', 'path' => 'settings.link', 'value' => 'x' ] ] );
check( 'apply: Pfad auf Nicht-Text wird abgelehnt', $res[0]['status'] === 'error' && $el === layout() );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'h1', 'path' => 'elements', 'value' => 'x' ] ] );
check( 'apply: Pfad außerhalb von settings wird abgelehnt', $res[0]['status'] === 'error' );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'Probe' ] ], true );
check( 'apply: Probelauf verändert nichts', $res[0]['status'] === 'ok' && $el === layout() );
$res = BW_Bridge_Elementor_Texts::apply( $el, [ [ 'widget_id' => 'h1', 'path' => 'settings.title', 'value' => 'A' ], [ 'widget_id' => 'zz', 'path' => 'settings.title', 'value' => 'B' ] ] );
check( 'apply: gemischte Liste meldet Fehler je Eintrag', $res[0]['status'] === 'ok' && $res[1]['status'] === 'error' );

/* --- diff --- */
$before = layout(); $after = layout();
BW_Bridge_Elementor_Texts::apply( $after, [ [ 'widget_id' => 't1', 'path' => 'settings.editor', 'value' => '<p>Neu</p>' ] ] );
unset( $after[0]['elements'][4] ); $after[0]['elements'] = array_values( $after[0]['elements'] );
$after[0]['elements'][] = [ 'id' => 'n1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'escaped-html', 'value' => 'Neu hinzu' ] ] ];
$d = BW_Bridge_Elementor_Texts::diff( $before, $after );
check( 'diff: geändert', count( $d['changed'] ) === 1 && $d['changed'][0]['old'] === '<p>Alt</p>' && $d['changed'][0]['new'] === '<p>Neu</p>' );
check( 'diff: hinzugefügt', count( $d['added'] ) === 1 && $d['added'][0]['widget_id'] === 'n1' );
check( 'diff: entfernt', count( $d['removed'] ) === 1 && $d['removed'][0]['widget_id'] === 'l1' );
$d = BW_Bridge_Elementor_Texts::diff( layout(), layout() );
check( 'diff: identisch ergibt nichts', ! $d['changed'] && ! $d['added'] && ! $d['removed'] );

/* --- visible_lines --- */
$html = '<html><head><style>p{color:red}</style><script>var x="Nicht sichtbar";</script></head><body><h1>Titel &amp; Co</h1><p>Zeile eins<br>Zeile zwei</p><svg><text>Icon</text></svg><noscript>Kein JS</noscript><ul><li>A</li><li>B</li></ul></body></html>';
$lines = BW_Bridge_Search::visible_lines( $html );
check( 'visible_lines: Text je Zeile, Entities aufgelöst', $lines === [ 'Titel & Co', 'Zeile eins', 'Zeile zwei', 'A', 'B' ] );

echo "\n" . ( $fail ? "$fail FEHLGESCHLAGEN, $pass bestanden" : "$pass/$pass Prüfungen bestanden" ) . "\n";
exit( $fail ? 1 : 0 );
