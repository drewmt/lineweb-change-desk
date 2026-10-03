<?php
/** Remove only Change Desk jobs and quotas. Never mutate source content or revisions. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
global $wpdb;
$lwcd_job_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='lwcd_job'" );
foreach ( $lwcd_job_ids as $lwcd_job_id ) {
	wp_delete_post( (int) $lwcd_job_id, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lwcd_quota_' ) . '%' ) );
wp_clear_scheduled_hook( 'lwcd_cleanup' );
