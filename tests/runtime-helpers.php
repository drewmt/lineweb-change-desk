<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Scope;
use Lineweb\ChangeDesk\Proposals;
use Lineweb\ChangeDesk\Jobs;
require_once __DIR__ . '/helpers.php';
function admin(): int {
	$id = (int) get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	)[0];
	wp_set_current_user( $id );
	return $id;
}
function source( string $text = '09:00' ): int {
	return wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'LWCD synthetic test',
				'post_content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->',
			)
		)
	);
}
function job( int $id ): int {
	$scope = Scope::collect( array( $id ) );
	return Jobs::create( $scope, array( 'proposals' => Proposals::exact( $scope['fields'], '09:00', '10:00' ) ), 'exact' );
}
