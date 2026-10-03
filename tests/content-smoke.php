<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\TextFields;
use Lineweb\ChangeDesk\Proposals;
require_once __DIR__ . '/helpers.php';
ok( class_exists( TextFields::class ), 'Safe text extraction is implemented' );
$source = '<!-- wp:paragraph --><p>Hours: <strong>09:00</strong>. <a href="/contact/">Contact</a></p><!-- /wp:paragraph -->';
$fields = TextFields::extract( $source, 123 )['fields'];
equal( 4, count( $fields ) );
$target = array_values( array_filter( $fields, fn( $f ) => '09:00' === $f['original'] ) )[0];
$patch  = array(
	array(
		'field_id'    => $target['id'],
		'original'    => '09:00',
		'replacement' => '10:00',
	),
);
equal( str_replace( '<strong>09:00</strong>', '<strong>10:00</strong>', $source ), TextFields::patch( $source, 123, $patch ) );
ok( is_wp_error( TextFields::patch( $source, 124, $patch ) ), 'Wrong post ID rejected' );
$repeat = '<!-- wp:paragraph --><p>09:00 <strong>09:00</strong></p><!-- /wp:paragraph -->';
$f      = TextFields::extract( $repeat, 123 )['fields'];
ok( $f[0]['id'] !== $f[1]['id'], 'Repeated words have distinct IDs' );
equal(
	str_replace( '<strong>09:00</strong>', '<strong>10:00</strong>', $repeat ),
	TextFields::patch(
		$repeat,
		123,
		array(
			array(
				'field_id'    => $f[1]['id'],
				'original'    => '09:00',
				'replacement' => '10:00',
			),
		)
	)
);
$entities = '<!-- wp:paragraph --><p>Ελλάδα &amp; ΕΕ &euro; 10</p><!-- /wp:paragraph -->';
$f        = TextFields::extract( $entities, 4 )['fields'];
equal( 'Ελλάδα & ΕΕ € 10', $f[0]['original'] );
equal(
	'<!-- wp:paragraph --><p>Ελλάδα &amp; ΕΕ € 20 &lt; 30</p><!-- /wp:paragraph -->',
	TextFields::patch(
		$entities,
		4,
		array(
			array(
				'field_id'    => $f[0]['id'],
				'original'    => $f[0]['original'],
				'replacement' => 'Ελλάδα & ΕΕ € 20 < 30',
			),
		)
	)
);
$nested = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
equal( 1, count( TextFields::extract( $nested, 3 )['fields'] ) );
equal( str_replace( '>Nested<', '>Changed<', $nested ), TextFields::patch( $nested, 3, Proposals::exact( TextFields::extract( $nested, 3 )['fields'], 'Nested', 'Changed' ) ) );
foreach ( array( '<p>Freeform</p>', '<!-- wp:html --><p>Custom</p><!-- /wp:html -->', '<!-- wp:block {"ref":12} /-->', '<!-- wp:paragraph --><p>Unclosed' ) as $unsupported ) {
	$extracted = TextFields::extract( $unsupported, 2 );
	equal( array(), $extracted['fields'] );
	ok( count( $extracted['unsupported'] ) > 0, 'Unsupported coverage visible' );
}
$split = '<!-- wp:paragraph --><p>Monday <strong>09:00</strong></p><!-- /wp:paragraph -->';
equal( array(), Proposals::exact( TextFields::extract( $split, 1 )['fields'], 'Monday 09:00', 'Tuesday 10:00' ) );
ok(
	is_wp_error(
		TextFields::patch(
			$source,
			123,
			array(
				array(
					'field_id'    => $target['id'],
					'original'    => '09:00',
					'replacement' => '<script>alert(1)</script>',
				),
			)
		)
	),
	'HTML rejected'
);
echo "PASS safe content: node identity, markup, entities, Greek, nested, unsupported, split nodes, HTML\n";
