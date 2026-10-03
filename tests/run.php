<?php
namespace Lineweb\ChangeDesk\Tests;

require_once __DIR__ . '/helpers.php';
define( 'ABSPATH', __DIR__ );
class_exists( 'WP_Error' ) || require __DIR__ . '/wp-error.php';
function_exists( 'is_wp_error' ) || require __DIR__ . '/wp-functions.php';
$file = dirname( __DIR__ ) . '/includes/class-proposals.php';
ok( is_file( $file ), 'Proposal validation exists' );
require_once $file;
use Lineweb\ChangeDesk\Proposals;
$fields = array(
	array(
		'id'       => 'f1',
		'original' => '09:00',
		'post_id'  => 1,
	),
);
equal( 1, count( Proposals::exact( $fields, '09:00', '10:00' ) ) );
equal( array(), Proposals::exact( $fields, '', '10:00' ) );
equal( array(), Proposals::exact( $fields, '09:00', '09:00' ) );
$mixed = array_merge(
	$fields,
	array(
		array(
			'id'       => 'long',
			'original' => str_repeat( 'x', 11995 ) . '09:00',
			'post_id'  => 2,
		),
	)
);
ok( is_wp_error( Proposals::exact( $mixed, '09:00', '10:00 AM' ) ), 'An oversized exact replacement is an explicit error, never false no-match success' );
$good = array(
	array(
		'field_id'    => 'f1',
		'original'    => '09:00',
		'replacement' => '10:00',
		'reason'      => 'New hours',
	),
);
ok( ! is_wp_error( Proposals::validate( $fields, $good ) ), 'Valid proposals pass' );
foreach ( array( '<img src=x onerror=alert(1)>', str_repeat( 'a', 12001 ), "bad\xff", "a\0b" ) as $bad ) {
	$input                   = $good;
	$input[0]['replacement'] = $bad;
	ok( is_wp_error( Proposals::validate( $fields, $input ) ), 'Unsafe/oversized/invalid text rejected' );
}
foreach ( array(
	false,
	array( 'wrong' => 1 ),
	array(
		array(
			'field_id'    => 'missing',
			'original'    => '09:00',
			'replacement' => '10:00',
			'reason'      => 'Hours',
		),
	),
	array( $good[0], $good[0] ),
) as $bad ) {
	ok( is_wp_error( Proposals::validate( $fields, $bad ) ), 'Wrong structure and targets rejected' );
}
$input                = $good;
$input[0]['original'] = '08:00';
ok( is_wp_error( Proposals::validate( $fields, $input ) ), 'Original mismatch rejected' );
$input                   = $good;
$input[0]['replacement'] = '10 < 20 & 30 > 25';
ok( ! is_wp_error( Proposals::validate( $fields, $input ) ), 'Comparison symbols allowed' );
echo "PASS proposal validation and exact-match behavior\n";
require __DIR__ . '/provider-tests.php';
