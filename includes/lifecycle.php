<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Lifecycle {
	public static function activate(): void {
		if ( ! wp_next_scheduled( 'lwcd_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lwcd_cleanup' );
		} }
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'lwcd_cleanup' );
	}
	public static function cleanup(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='lwcd_job' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(post_content),post_content,'{}'),'$.expires')) AS UNSIGNED)<%d ORDER BY ID ASC LIMIT 50", time() ) );
		foreach ( $ids as $id ) {
			$payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $id ) ), true );
			if ( ( $payload['expires'] ?? 0 ) < time() ) {
				wp_delete_post( (int) $id, true );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s LIMIT 100", $wpdb->esc_like( 'lwcd_quota_' ) . '%', 'lwcd_quota_' . gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) );
	}
	public static function purge_source( int $id ): void {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		$jobs = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_lwcd_source' AND meta_value=%s", (string) $id ) );
		foreach ( array_unique( $jobs ) as $job ) {
			wp_delete_post( (int) $job, true );
		}
	}
}
