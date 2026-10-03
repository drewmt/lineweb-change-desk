<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Provider;
require_once __DIR__ . '/helpers.php';
add_filter( 'wp_supports_ai', '__return_false' );
ok( class_exists( Provider::class ), 'Native provider exists' );
equal( false, Provider::status()['available'] );
$reserved = 0;
$result   = Provider::analyze_chunk(
	array(
		array(
			'id'       => 'f1',
			'original' => '09:00',
			'post_id'  => 1,
		),
	),
	'New hours',
	function () use ( &$reserved ) {
		++$reserved;
		return true;
	}
);
ok( is_wp_error( $result ), 'Unavailable provider cannot analyze' );
equal( 0, $reserved );
remove_filter( 'wp_supports_ai', '__return_false' );
echo "PASS provider unavailable state without quota consumption\n";
