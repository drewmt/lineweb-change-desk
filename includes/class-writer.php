<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Writer {
	public static function apply( int $job_id, array $proposal_ids, string $operation_id ): array|\WP_Error {
		return self::run( $job_id, $proposal_ids, $operation_id, false );
	}
	public static function restore( int $job_id, array $post_ids, string $operation_id ): array|\WP_Error {
		return self::run( $job_id, $post_ids, $operation_id, true );
	}
	private static function run( int $job_id, array $ids, string $operation, bool $restore ): array|\WP_Error {
		if ( ! $ids || count( $ids ) > 1000 || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $operation ) ) {
			return self::bad();
		}
		$job = Jobs::get( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( get_current_user_id() !== $job['owner'] ) {
			return Jobs::forbidden();
		}
		$targets   = array();
		$fields    = array_column( $job['fields'], null, 'id' );
		$proposals = array_column( $job['proposals'], null, 'id' );
		foreach ( $ids as $id ) {
			if ( $restore ) {
				if ( ! is_int( $id ) || ! isset( $job['snapshots'][ $id ] ) ) {
					return self::bad();
				} $targets[ $id ] = array();
			} else {
				if ( ! is_string( $id ) || ! isset( $proposals[ $id ] ) ) {
					return self::bad();
				} $p                  = $proposals[ $id ];
				$source               = $fields[ $p['field_id'] ]['post_id'];
				$targets[ $source ][] = $p;
			}
		}
		ksort( $targets );
		$fingerprint = hash( 'sha256', wp_json_encode( array( $restore, $ids ) ) );
		$results     = array();
		foreach ( $targets as $id => $selected ) {
			$out = Db::guard(
				array( $job_id, $id ),
				static function () use ( $job_id, $id, $selected, $operation, $restore, $fingerprint, $ids ) {
					global $wpdb;
					$job = Jobs::get( $job_id );
					if ( is_wp_error( $job ) ) {
						return $job;
					}
					if ( get_current_user_id() !== $job['owner'] ) {
						return Jobs::forbidden();
					}
					if ( isset( $job['operations'][ $operation ] ) ) {
						if ( $job['operations'][ $operation ]['fingerprint'] !== $fingerprint ) {
							return self::bad();
						}
						if ( isset( $job['operations'][ $operation ]['sources'][ $id ] ) ) {
							return $job['operations'][ $operation ]['sources'][ $id ];
						}
					}
					if ( count( $job['operations'] ) >= 30 && ! isset( $job['operations'][ $operation ] ) ) {
						return new \WP_Error( 'lwcd_operations', __( 'Job operation limit reached. Start a new preview.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
					}
					if ( ! isset( $job['operations'][ $operation ] ) ) {
						$job['operations'][ $operation ] = array(
							'fingerprint' => $fingerprint,
							'type'        => $restore ? 'restore' : 'apply',
							'actor_id'    => get_current_user_id(),
							'created'     => time(),
							'selection'   => $ids,
							'sources'     => array(),
						);
					}
					$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ) );
					$state  = 'conflict';
					$lock   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_edit_lock' LIMIT 1", $id ) );
					$parts  = explode( ':', $lock );
					$locked = isset( $parts[1] ) && (int) $parts[0] > time() - (int) apply_filters( 'wp_check_post_lock_window', 150 ) && get_current_user_id() !== (int) $parts[1];
					if ( $row && Scope::can_edit_source( new \WP_Post( $row ) ) && ! $locked && ! $job['inflight'] && ! in_array( $job['status'], array( 'pending', 'analyzing' ), true ) ) {
						$snapshot = $job['snapshots'][ $id ] ?? null;
						$expected = $restore ? ( $snapshot['after_hash'] ?? '' ) : $job['sources'][ $id ]['hash'];
						if ( hash( 'sha256', $row->post_content ) === $expected && ( ! $restore || empty( $snapshot['restored'] ) ) ) {
							$next = $restore ? $snapshot['before'] : TextFields::patch( $row->post_content, $id, $selected );
							if ( ! is_wp_error( $next ) ) {
								$wpdb->query( 'SAVEPOINT lwcd_source' );
								// Durable recovery data if a third-party hook unexpectedly
								// commits our transaction. No automatic write replay is allowed.
								$previous = $job['snapshots'][ $id ] ?? null;
								if ( ! $restore ) {
									$job['snapshots'][ $id ] = array(
										'before'     => $row->post_content,
										'after'      => $next,
										'after_hash' => hash( 'sha256', $next ),
										'restored'   => false,
									);
								}
								$job['operations'][ $operation ]['fingerprint']    = $fingerprint;
								$job['operations'][ $operation ]['sources'][ $id ] = array( 'state' => 'needs_verification' );
								if ( ! Jobs::store( $job_id, $job ) ) {
									return Db::error();
								}
								$written = wp_update_post(
									wp_slash(
										array(
											'ID'           => $id,
											'post_content' => $next,
										)
									),
									true
								);
								$actual  = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $id ) );
								if ( ! is_wp_error( $written ) && $actual === $next ) {
									$quiet = $wpdb->suppress_errors( true );
									$alive = false !== $wpdb->query( 'RELEASE SAVEPOINT lwcd_source' );
									$wpdb->suppress_errors( $quiet );
									$state = $alive ? ( $restore ? 'restored' : 'applied' ) : 'needs_verification';
									if ( $restore && $alive ) {
										$job['snapshots'][ $id ] = array_merge( $snapshot, array( 'restored' => true ) );
									}
								} else {
									$quiet = $wpdb->suppress_errors( true );
									$alive = false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT lwcd_source' );
									$wpdb->suppress_errors( $quiet );
									$state = $alive ? 'failed' : 'needs_verification';
									if ( $alive ) {
										if ( $previous ) {
											$job['snapshots'][ $id ] = $previous;
										} else {
											unset( $job['snapshots'][ $id ] );
										}
									}
								}
								clean_post_cache( $id );
							} else {
								$state = 'failed';
							}
						}
					}
					$result = array( 'state' => $state );
					$job['operations'][ $operation ]['fingerprint']    = $fingerprint;
					$job['operations'][ $operation ]['sources'][ $id ] = $result;
					if ( ! Jobs::store( $job_id, $job ) ) {
						return Db::error();
					}
					return $result;
				}
			);
			if ( is_wp_error( $out ) ) {
				if ( ! $results ) {
					return $out;
				}
				$results[ $id ] = array( 'state' => 'lwcd_storage' === $out->get_error_code() ? 'needs_verification' : 'failed' );
			} else {
				$results[ $id ] = $out;
			}
		}
		return array(
			'operation_id' => $operation,
			'sources'      => $results,
		);
	}
	private static function bad(): \WP_Error {
		return new \WP_Error( 'lwcd_operation', __( 'Invalid selection or operation ID.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
	}
}
