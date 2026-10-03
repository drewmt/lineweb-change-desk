<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Quota;
require_once __DIR__ . '/runtime-helpers.php';
ok( class_exists( Quota::class ), 'Atomic quota implemented' );
$user = admin();
$day  = 'test-' . wp_generate_password( 8, false );
for ( $i = 0;$i < 20;$i++ ) {
	equal( true, Quota::reserve( $user, $day ) );
}
ok( is_wp_error( Quota::reserve( $user, $day ) ), '21st user call rejected' );
for ( $i = 0;$i < 20;$i++ ) {
	equal( true, Quota::reserve( $user + 10000, $day ) );
}
for ( $i = 0;$i < 10;$i++ ) {
	equal( true, Quota::reserve( $user + 20000, $day ) );
}
ok( is_wp_error( Quota::reserve( $user + 30000, $day ) ), '51st site call rejected' );
$parallel = $day . '-p';
$workers  = array();
for ( $i = 0;$i < 4;$i++ ) {
	$pipes     = array();
	$process   = proc_open(
		array( 'php', __DIR__ . '/quota-worker.php', (string) $user, $parallel, ABSPATH ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	$workers[] = array( $process, $pipes );
}
$wins   = 0;
$errors = '';
foreach ( $workers as [$process, $pipes] ) {
	fclose( $pipes[0] );
	$wins   += (int) stream_get_contents( $pipes[1] );
	$errors .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	equal( 0, proc_close( $process ) );
}
equal( '', $errors );
equal( 20, $wins, 'Parallel callers cannot overshoot quota' );
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lwcd_quota_' . $day . '_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lwcd_quota_' . $parallel . '_' ) . '%' ) );
echo "PASS atomic quota user/site ceilings and parallel contention\n";
