<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Jobs;
use Lineweb\ChangeDesk\Writer;
require_once __DIR__ . '/runtime-helpers.php';
ok( class_exists( Writer::class ), 'Guarded writer implemented' );
admin();
$id       = source();
$before   = get_post( $id )->post_content;
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
$op       = wp_generate_uuid4();
$result   = Writer::apply( $job, array( $proposal ), $op );
equal( 'applied', $result['sources'][ $id ]['state'] );
equal( '<!-- wp:paragraph --><p>10:00</p><!-- /wp:paragraph -->', get_post( $id )->post_content );
equal( $result, Writer::apply( $job, array( $proposal ), $op ) );
ok( is_wp_error( Writer::apply( $job, array( 'foreign' ), wp_generate_uuid4() ) ), 'Unknown proposal denied' );
$restore = wp_generate_uuid4();
$result  = Writer::restore( $job, array( $id ), $restore );
equal( 'restored', $result['sources'][ $id ]['state'] );
equal( $before, get_post( $id )->post_content );
equal( $result, Writer::restore( $job, array( $id ), $restore ) );
Jobs::delete( $job );
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => 'Human changed this beforehand.',
		)
	)
);
equal( 'conflict', Writer::apply( $job, array( $proposal ), wp_generate_uuid4() )['sources'][ $id ]['state'] );
equal( 'Human changed this beforehand.', get_post( $id )->post_content );
Jobs::delete( $job );
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => $before,
		)
	)
);
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
Writer::apply( $job, array( $proposal ), wp_generate_uuid4() );
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => 'Human changed this afterwards.',
		)
	)
);
equal( 'conflict', Writer::restore( $job, array( $id ), wp_generate_uuid4() )['sources'][ $id ]['state'] );
equal( 'Human changed this afterwards.', get_post( $id )->post_content );
Jobs::delete( $job );
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => $before,
		)
	)
);
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
$hook     = static function ( $data, $postarr ) use ( $id ) {
	if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) {
		$data['post_content'] = 'Third-party transformed text';
	}return $data;
};
add_filter( 'wp_insert_post_data', $hook, 10, 2 );
equal( 'failed', Writer::apply( $job, array( $proposal ), wp_generate_uuid4() )['sources'][ $id ]['state'] );
remove_filter( 'wp_insert_post_data', $hook, 10 );
equal( $before, get_post( $id )->post_content );
Jobs::delete( $job );
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
$other    = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
global $wpdb;
$other->set_prefix( $wpdb->prefix );
$other->update( $other->posts, array( 'post_content' => 'Concurrent connection saved human content.' ), array( 'ID' => $id ) );
equal( 'conflict', Writer::apply( $job, array( $proposal ), wp_generate_uuid4() )['sources'][ $id ]['state'] );
equal( 'Concurrent connection saved human content.', get_post( $id )->post_content );
Jobs::delete( $job );
wp_update_post(
	wp_slash(
		array(
			'ID'           => $id,
			'post_content' => $before,
		)
	)
);
$job      = job( $id );
$proposal = Jobs::get( $job )['proposals'][0]['id'];
update_post_meta( $id, '_edit_lock', time() . ':999999' );
equal( 'conflict', Writer::apply( $job, array( $proposal ), wp_generate_uuid4() )['sources'][ $id ]['state'] );
delete_post_meta( $id, '_edit_lock' );
Jobs::delete( $job );
$job         = job( $id );
$proposal    = Jobs::get( $job )['proposals'][0]['id'];
$commit_hook = static function ( $data, $postarr ) use ( $id ) {
	if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}return $data;
};
add_filter( 'wp_insert_post_data', $commit_hook, 10, 2 );
equal( 'needs_verification', Writer::apply( $job, array( $proposal ), wp_generate_uuid4() )['sources'][ $id ]['state'], 'Third-party transaction break is not reported as safely applied' );
remove_filter( 'wp_insert_post_data', $commit_hook, 10 );
ok( isset( Jobs::get( $job )['snapshots'][ $id ] ), 'Recovery snapshot survives unexpected commit' );
wp_delete_post( $id, true );
echo "PASS selective writes, idempotency, stale apply/restore conflicts, hook read-back guard\n";
