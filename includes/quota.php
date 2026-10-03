<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Quota {
	public static function reserve( int $user_id, string $utc_day ): bool|\WP_Error {
		global $wpdb;
		if ( ! Db::supported() || $user_id < 1 || ! preg_match( '/^[a-zA-Z0-9-]{1,30}$/', $utc_day ) ) {
			return Db::error();
		}
		$keys = array(
			'lwcd_quota_' . $utc_day . '_site'             => 50,
			'lwcd_quota_' . $utc_day . '_user_' . $user_id => 20,
		);
		ksort( $keys );
		// Initialize outside the transaction: INSERT IGNORE on an existing row
		// otherwise takes shared locks which competing SELECT FOR UPDATE upgrades can deadlock.
		foreach ( $keys as $key => $limit ) {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'0','no')", $key ) ) ) {
				return Db::error();
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return Db::error();
		}
		foreach ( $keys as $key => $limit ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $key ) );
			if ( null === $value || (int) $value >= $limit ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'lwcd_quota', __( 'Daily AI request limit reached.', 'lineweb-change-desk' ), array( 'status' => 429 ) );
			}
		}
		foreach ( $keys as $key => $limit ) {
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s", $key ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return Db::error();
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return Db::error();
		}
		foreach ( $keys as $key => $limit ) {
			wp_cache_delete( $key, 'options' );
		}
		return true;
	}
}
