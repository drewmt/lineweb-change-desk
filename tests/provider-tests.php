<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Provider;
$file = dirname( __DIR__ ) . '/includes/provider.php';
ok( is_file( $file ), 'Native provider pipeline exists' );
require_once $file;
$fields = array();
for ( $i = 0;$i < 6;$i++ ) {
	$fields[] = array(
		'id'       => 'f' . $i,
		'original' => str_repeat( 'x', 12000 ),
		'post_id'  => 1,
	);
}
$fields[] = array(
	'id'       => 'large',
	'original' => str_repeat( 'x', 12001 ),
	'post_id'  => 1,
);
$plan     = Provider::chunks( $fields );
equal( 5, count( $plan['chunks'] ) );
equal( array( 'f5', 'large' ), $plan['unscanned_ids'] );
$f    = array(
	array(
		'id'       => 'f1',
		'original' => '09:00',
		'post_id'  => 1,
	),
);
$json = '{"reviewed_ids":["f1"],"proposals":[{"field_id":"f1","original":"09:00","replacement":"10:00","reason":"New hours"}]}';
equal( 1, count( Provider::validate_response( $f, $json, true ) ) );
foreach ( array( $json . 'truncated', '{}', '{"reviewed_ids":[],"proposals":[]}', str_replace( '"09:00"', '"wrong"', $json ), str_replace( '"f1"', '"foreign"', $json ), str_replace( '"10:00"', '"<img src=x>"', $json ) ) as $bad ) {
	ok( is_wp_error( Provider::validate_response( $f, $bad, true ) ), 'Incomplete/malformed/foreign/HTML result rejected' );
}
ok( is_wp_error( Provider::validate_response( $f, $json, false ) ), 'Non-stop result rejected even with valid JSON' );
echo "PASS provider chunk limits, coverage, terminal state and untrusted output\n";
