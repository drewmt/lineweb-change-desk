<?php
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Jobs;
use Lineweb\ChangeDesk\Scope;
use Lineweb\ChangeDesk\Lifecycle;
require_once __DIR__ . '/runtime-helpers.php';
ok( class_exists( Jobs::class ), 'Private jobs implemented' );
$admin = admin();
$id    = source();
$job   = job( $id );
$type  = get_post_type_object( 'lwcd_job' );
ok( ! current_user_can( $type->cap->read_private_posts ), 'Native WordPress APIs cannot bypass private job authorization' );
ok( ! current_user_can( 'edit_post', $job ), 'Generic core editing cannot access private job payloads' );
Lifecycle::activate();
ok( false !== wp_next_scheduled( 'lwcd_cleanup' ), 'Owned cleanup scheduled' );
$unchanged = get_post( $id )->post_content;
Lifecycle::deactivate();
equal( false, wp_next_scheduled( 'lwcd_cleanup' ) );
equal( $unchanged, get_post( $id )->post_content );
Lifecycle::activate();
ok( is_int( $job ) && $job > 0, 'Job created' );
$data = Jobs::get( $job );
equal( $admin, $data['owner'] );
ok( ! get_post_type_object( 'lwcd_job' )->show_in_rest, 'No core REST exposure' );
$editor = wp_insert_user(
	array(
		'user_login' => 'lwcd_test_editor_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
wp_set_current_user( $editor );
ok( is_wp_error( Jobs::get( $job ) ), 'Another editor cannot read snapshots' );
wp_set_current_user( $admin );
wp_update_post(
	array(
		'ID'          => $id,
		'post_status' => 'private',
	)
);
ok( is_wp_error( Jobs::get( $job ) ), 'Revoked source status denies retained snapshots' );
wp_update_post(
	array(
		'ID'          => $id,
		'post_status' => 'publish',
	)
);
$data            = Jobs::get( $job );
$data['expires'] = time() - 1;
Jobs::store( $job, $data );
ok( is_wp_error( Jobs::get( $job ) ), 'Expired snapshots not readable' );
Lifecycle::cleanup();
equal( null, get_post( $job ) );
$job = job( $id );
wp_delete_post( $id, true );
equal( null, get_post( $job ) );
$id              = source();
$job             = job( $id );
$data            = Jobs::get( $job );
$data['expires'] = time() + DAY_IN_SECONDS;
Jobs::store( $job, $data );
$temporary = array();
for ( $i = 0;$i < 50;$i++ ) {
	$temporary[] = wp_insert_post(
		array(
			'post_type'    => 'lwcd_job',
			'post_status'  => 'private',
			'post_content' => wp_json_encode( array( 'expires' => time() + DAY_IN_SECONDS ) ),
		)
	);
}
$expired = wp_insert_post(
	array(
		'post_type'    => 'lwcd_job',
		'post_status'  => 'private',
		'post_content' => wp_json_encode( array( 'expires' => time() - 1 ) ),
	)
);
Lifecycle::cleanup();
equal( null, get_post( $expired ), 'Cleanup must find expired jobs beyond first active batch' );
foreach ( $temporary as $temp ) {
	wp_delete_post( (int) $temp, true );
}
wp_delete_post( $id, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
$history_source = source();
$oldest         = job( $history_source );
for ( $i = 0; $i < 20; ++$i ) {
	job( $history_source );
}
$second_page = Jobs::history( 2 );
ok( in_array( $oldest, array_column( $second_page['items'] ?? $second_page, 'id' ), true ), 'The earliest of 21 retained jobs remains reachable on page two' );
wp_delete_post( $history_source, true );
wp_delete_user( $editor );
echo "PASS private jobs, ownership, revoked access, expiry, deleted-source purge\n";
