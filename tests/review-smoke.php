<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\{Jobs, Proposals, Scope, Writer};
require_once __DIR__ . '/runtime-helpers.php';
admin();
$first     = source();
$second    = source();
$scope     = Scope::collect( array( $first, $second ) );
$job       = Jobs::create( $scope, array( 'proposals' => Proposals::exact( $scope['fields'], '09:00', '10:00' ) ), 'exact' );
$ids       = array_column( Jobs::get( $job )['proposals'], 'id' );
$operation = wp_generate_uuid4();
global $wpdb;
$original_db = $wpdb;
$fault_db    = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends \wpdb {
	private int $commits = 0;
	public function query( $query ) {
		$result = parent::query( $query );
		if ( 'COMMIT' === $query && 2 === ++$this->commits ) {
			return false;
		}
		return $result;
	}
};
$fault_db->set_prefix( $wpdb->prefix );
$wpdb = $fault_db;
try {
	$result = Writer::apply( $job, $ids, $operation );
	equal( 'applied', $result['sources'][ $first ]['state'] );
	equal( 'needs_verification', $result['sources'][ $second ]['state'], 'Lost commit acknowledgement is never definite failure' );
	equal( $operation, $result['operation_id'] );
	equal( '<!-- wp:paragraph --><p>10:00</p><!-- /wp:paragraph -->', get_post( $second )->post_content );
	equal( 'applied', Jobs::get( $job )['operations'][ $operation ]['sources'][ $second ]['state'], 'Refresh can reconcile persisted state' );
	$audit = Jobs::get( $job )['operations'][ $operation ];
	equal( 'apply', $audit['type'] ?? null, 'Persisted history identifies the action' );
	equal( get_current_user_id(), $audit['actor_id'] ?? null );
	equal( $ids, $audit['selection'] ?? null );
	ok( ( $audit['created'] ?? 0 ) >= time() - 60, 'Execution time is retained' );
} finally {
	$wpdb = $original_db;
	wp_delete_post( $first, true );
	wp_delete_post( $second, true );
}
echo "PASS later-source uncertain commit preserves operation identity and recoverable outcome\n";
$sources = array( source(), source(), source() );
$scope   = Scope::collect( $sources );
$job     = Jobs::create( $scope, array( 'proposals' => Proposals::exact( $scope['fields'], '09:00', '10:00' ) ), 'exact' );
wp_update_post(
	wp_slash(
		array(
			'ID'           => $sources[1],
			'post_content' => 'Newer human edit',
		)
	)
);
$hook = static function ( $data, $postarr ) use ( $sources ) {
	if ( (int) ( $postarr['ID'] ?? 0 ) === $sources[2] ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $hook, 10, 2 );
$operation = wp_generate_uuid4();
try {
	Writer::apply( $job, array_column( Jobs::get( $job )['proposals'], 'id' ), $operation );
	$read     = new \WP_REST_Request( 'GET', '/lineweb-change-desk/v1/jobs/' . $job );
	$reopened = rest_do_request( $read )->get_data();
	$stored   = $reopened['operations'][ $operation ];
	equal( array( 'applied', 'conflict', 'needs_verification' ), array_column( $stored['sources'], 'state' ), 'Reopening retains all mixed outcomes' );
	equal( 'apply', $stored['type'] );
	equal( get_current_user_id(), $stored['actor_id'] );
} finally {
	remove_filter( 'wp_insert_post_data', $hook, 10 );
	foreach ( $sources as $source ) {
		wp_delete_post( $source, true );
	}
}
echo "PASS reopened mixed apply/conflict/uncertain history retains action and actor\n";
