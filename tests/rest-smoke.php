<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Jobs;
require_once __DIR__ . '/runtime-helpers.php';
function request( string $method, string $path, array $body = array(), bool $nonce = true ): \WP_REST_Response {
	$r = new \WP_REST_Request( $method, '/lineweb-change-desk/v1/' . $path );
	$r->set_body_params( $body );
	if ( $nonce ) {
		$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	return rest_do_request( $r );
}
wp_set_current_user( 0 );
equal( 403, request( 'GET', 'sources' )->get_status(), 'Guest denied' );
$admin = admin();
$id    = source();
equal( 403, request( 'POST', 'preview', array( 'source_ids' => array( $id ) ), false )->get_status(), 'Missing nonce denied' );
equal( 400, request( 'POST', 'preview', array( 'source_ids' => array_fill( 0, 11, $id ) ) )->get_status() );
equal(
	400,
	request(
		'POST',
		'jobs',
		array(
			'mode'        => 'ai',
			'instruction' => str_repeat( 'x', 2001 ),
			'source_ids'  => array( $id ),
		)
	)->get_status()
);
$preview = request( 'POST', 'preview', array( 'source_ids' => array( $id ) ) )->get_data();
$hashes  = array();
foreach ( $preview['sources'] as $source ) {
	$hashes[ $source['id'] ] = $source['hash'];
}
$result = request(
	'POST',
	'jobs',
	array(
		'mode'          => 'exact',
		'find'          => '09:00',
		'replacement'   => '10:00',
		'source_ids'    => array( $id ),
		'source_hashes' => $hashes,
	)
);
equal( 200, $result->get_status() );
$job = $result->get_data();
ok( ! isset( $job['snapshots'][0]['before'] ) && ! isset( $job['chunks'] ), 'REST hides full snapshots and provider internals' );
equal(
	400,
	request(
		'POST',
		'jobs/' . $job['id'] . '/apply',
		array(
			'proposal_ids' => array( $job['proposals'][0]['id'] ),
			'operation_id' => wp_generate_uuid4(),
			'replacement'  => 'arbitrary',
		)
	)->get_status(),
	'Browser cannot inject replacement'
);
$author = wp_insert_user(
	array(
		'user_login' => 'lwcd_author_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'author',
	)
);
wp_set_current_user( $author );
equal( 403, request( 'GET', 'sources' )->get_status() );
wp_set_current_user( $admin );
wp_update_post(
	array(
		'ID'            => $id,
		'post_password' => 'protected',
	)
);
equal( 403, request( 'GET', 'jobs/' . $job['id'] )->get_status() );
wp_update_post(
	array(
		'ID'            => $id,
		'post_password' => '',
	)
);
$cancel = Jobs::create(
	\Lineweb\ChangeDesk\Scope::collect( array( $id ) ),
	array(
		'instruction' => 'New hours',
		'provider_id' => 'synthetic-unconfigured',
	),
	'ai'
);
Jobs::cancel( $cancel );
equal( 409, request( 'POST', 'jobs/' . $cancel . '/analyze', array( 'confirmed_provider_transfer' => true ) )->get_status(), 'Cancellation stops next chunk' );
$interrupted             = Jobs::create(
	\Lineweb\ChangeDesk\Scope::collect( array( $id ) ),
	array(
		'instruction' => 'New hours',
		'provider_id' => 'synthetic-unconfigured',
	),
	'ai'
);
$stored                  = Jobs::get( $interrupted );
$stored['status']        = 'analyzing';
$stored['inflight']      = array(
	'index'    => 0,
	'time'     => time() - 601,
	'reserved' => true,
);
$stored['requests_used'] = 1;
Jobs::store( $interrupted, $stored );
$recovered = request( 'GET', 'jobs/' . $interrupted )->get_data();
equal( 'needs_verification', $recovered['status'], 'Interrupted analysis becomes an explicit uncertain outcome' );
equal( 1, $recovered['requests_used'], 'Reserved request remains counted after disconnect' );
equal( 409, request( 'POST', 'jobs/' . $interrupted . '/analyze', array( 'confirmed_provider_transfer' => true ) )->get_status(), 'Never replay uncertain provider call' );
equal( 'needs_verification', Jobs::get( $interrupted )['status'], 'Recovery state persists' );
$large = source( str_repeat( 'x', 11995 ) . '09:00' );
$scope = \Lineweb\ChangeDesk\Scope::collect( array( $id, $large ) );
equal(
	400,
	request(
		'POST',
		'jobs',
		array(
			'mode'          => 'exact',
			'find'          => '09:00',
			'replacement'   => '10:00 AM',
			'source_ids'    => $scope['ids'],
			'source_hashes' => array_map( static fn( $source ) => $source['hash'], $scope['sources'] ),
		)
	)->get_status(),
	'Invalid expanded exact text is an actionable error rather than an empty ready job'
);
wp_delete_post( $large, true );
wp_delete_post( $id, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $author );
echo "PASS REST nonce, source scope, permissions, immutable proposals, cancellation\n";
