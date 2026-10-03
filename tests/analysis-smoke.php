<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\{Jobs, Provider, Scope};
require_once __DIR__ . '/runtime-helpers.php';
require_once __DIR__ . '/native-provider-fixture.php';
admin();
$id = source();
\WordPress\AiClient\AiClient::defaultRegistry()->registerProvider( FixtureProvider::class );
equal( true, Provider::status()['available'], 'Native structured-text capability is detected' );
equal( 'lwcd-fixture', Provider::status()['provider_id'] );
$scope        = Scope::collect( array( $id ) );
$reservations = 0;
foreach ( array( 'success', 'malformed', 'timeout' ) as $mode ) {
	FixtureProvider::$mode = $mode;
	$before                = FixtureProvider::$calls;
	$out                   = Provider::analyze_chunk(
		$scope['fields'],
		'Update hours',
		static function () use ( &$reservations ) {
			++$reservations;
			return true;
		},
		'lwcd-fixture'
	);
	ok( ! is_wp_error( $out ), 'Native provider call returns bounded result' );
	equal( $before + 1, FixtureProvider::$calls, 'Exactly one native call, never retry' );
	equal( 1, $out['requests_used'] );
	equal( 'success' === $mode ? 1 : 0, count( $out['analyzed_ids'] ) );
	equal( 'success' === $mode ? 0 : 1, count( $out['failed_ids'] ) );
}
equal( 3, $reservations );
FixtureProvider::$mode = 'success';
function analyze_request( int $job ): \WP_REST_Response {
	$r = new \WP_REST_Request( 'POST', '/lineweb-change-desk/v1/jobs/' . $job . '/analyze' );
	$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$r->set_body_params( array( 'confirmed_provider_transfer' => true ) );
	return rest_do_request( $r );
}
$job                     = Jobs::create(
	$scope,
	array(
		'instruction' => 'Update hours',
		'provider_id' => 'lwcd-fixture',
	),
	'ai'
);
$before                  = FixtureProvider::$calls;
FixtureProvider::$before = static function () use ( $job ) {
	equal( 1, Jobs::get( $job )['requests_used'], 'Reservation is persisted before dispatch' );
	equal( 409, analyze_request( $job )->get_status(), 'Competing request cannot dispatch the claimed chunk' );
};
$response                = analyze_request( $job );
equal( 200, $response->get_status() );
equal( 'ready', $response->get_data()['status'] );
equal( $before + 1, FixtureProvider::$calls );
equal( 1, Jobs::get( $job )['requests_used'] );
// Two chunks: cancel while the first native call is outstanding, then block the second.
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => implode( '', array_map( static fn( $i ) => '<!-- wp:paragraph --><p>09:00 ' . $i . '</p><!-- /wp:paragraph -->', range( 1, 16 ) ) ),
		)
	)
);
$scope           = Scope::collect( array( $id ) );
$job             = Jobs::create(
	$scope,
	array(
		'instruction' => 'Update hours',
		'provider_id' => 'lwcd-fixture',
	),
	'ai'
);
$before_dispatch = Jobs::create(
	$scope,
	array(
		'instruction' => 'Update hours',
		'provider_id' => 'lwcd-fixture',
	),
	'ai'
);
Jobs::cancel( $before_dispatch );
equal( 16, count( Jobs::get( $before_dispatch )['unscanned_ids'] ), 'Cancellation before dispatch classifies every untouched field' );
$data                  = Jobs::get( $job );
$data['status']        = 'pending';
$data['cursor']        = 0;
$data['requests_used'] = 0;
$data['analyzed_ids']  = array();
$data['chunks']        = Provider::chunks( $scope['fields'] )['chunks'];
Jobs::store( $job, $data );
FixtureProvider::$before = static function () use ( $job ) {
	Jobs::cancel( $job );
};
$cancelled               = analyze_request( $job )->get_data();
equal( 'cancelled', $cancelled['status'] );
equal( 8, $cancelled['coverage']['analyzed'] );
equal( 8, $cancelled['coverage']['unscanned'], 'Untouched distinct chunk remains explicitly unscanned' );
$before = FixtureProvider::$calls;
equal( 409, analyze_request( $job )->get_status() );
equal( $before, FixtureProvider::$calls, 'Cancellation stops request two' );
FixtureProvider::$before = null;
$between                 = Jobs::create(
	$scope,
	array(
		'instruction' => 'Update hours',
		'provider_id' => 'lwcd-fixture',
	),
	'ai'
);
equal( 'pending', analyze_request( $between )->get_data()['status'] );
Jobs::cancel( $between );
equal( 8, count( Jobs::get( $between )['unscanned_ids'] ), 'Cancellation between chunks accounts for remaining fields' );
Jobs::cancel( $between );
equal( 8, count( Jobs::get( $between )['unscanned_ids'] ), 'Repeated cancellation cannot duplicate coverage' );
wp_delete_post( $id, true );
echo "PASS native SDK fixture, malformed/timeout, single dispatch, reserved count, competing claim and cancellation\n";
