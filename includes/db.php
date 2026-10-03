<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
/** Transactions guard only local database changes; third-party external side effects cannot be undone. */
final class Db {
	public static function supported(): bool {
		global $wpdb;
		static $supported;
		if ( isset( $supported ) ) {
			return $supported;
		}
		$supported = true;
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
			if ( ! $row || 'InnoDB' !== $row->Engine ) {
				$supported = false;
				break;
			}
		}
		return $supported;
	}
	public static function guard( array $ids, callable $callback ) {
		global $wpdb;
		if ( ! self::supported() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error();
		}
		$ids = array_unique( array_map( 'intval', $ids ) );
		sort( $ids, SORT_NUMERIC );
		try {
			foreach ( $ids as $id ) {
				$wpdb->get_row( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $id ) );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'Source lock failed' );
				}
				$wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE", $id ) );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( 'Metadata lock failed' );
				}
				clean_post_cache( $id );
			}
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
			} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
				$result = self::error();
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$result = self::error();
		}
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
		return $result;
	}
	public static function error(): \WP_Error {
		return new \WP_Error( 'lwcd_storage', __( 'Transactional storage could not be verified. Refresh job status and inspect the source before repeating any write.', 'lineweb-change-desk' ), array( 'status' => 503 ) );
	}
}
