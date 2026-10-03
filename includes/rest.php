<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Rest {
	public static function register(): void {
		$routes = array(
			array( 'sources', 'GET', 'sources' ),
			array( 'preview', 'POST', 'preview' ),
			array( 'jobs', 'POST', 'create' ),
			array( 'jobs', 'GET', 'history' ),
			array( 'jobs/(?P<id>\d+)', 'GET', 'read' ),
			array( 'jobs/(?P<id>\d+)', 'DELETE', 'delete' ),
			array( 'jobs/(?P<id>\d+)/analyze', 'POST', 'analyze' ),
			array( 'jobs/(?P<id>\d+)/cancel', 'POST', 'cancel' ),
			array( 'jobs/(?P<id>\d+)/apply', 'POST', 'apply' ),
			array( 'jobs/(?P<id>\d+)/restore', 'POST', 'restore' ),
		);
		foreach ( $routes as [$path, $method, $callback] ) {
			register_rest_route(
				'lineweb-change-desk/v1',
				'/' . $path,
				array(
					'methods'             => $method,
					'permission_callback' => array( self::class, 'permission' ),
					'callback'            => array( self::class, $callback ),
				)
			);
		}
	}
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return Jobs::forbidden();
		}
		if ( 'GET' !== $request->get_method() && ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_Error( 'lwcd_nonce', __( 'Your session expired. Refresh the page before continuing.', 'lineweb-change-desk' ), array( 'status' => 403 ) );
		}
		return true;
	}
	public static function sources( \WP_REST_Request $r ): array|\WP_Error {
		$search = $r->get_param( 'search' ) ?? '';
		$page   = $r->get_param( 'page' ) ?? 1;
		if ( ! is_string( $search ) || Proposals::length( $search ) > 200 || ! is_numeric( $page ) || (int) $page < 1 || (int) $page > 10000 ) {
			return self::bad();
		}
		$q     = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 25,
				'paged'          => (int) $page,
				's'              => sanitize_text_field( $search ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $q->posts as $p ) {
			if ( Scope::can_edit_source( $p ) ) {
				$items[] = array(
					'id'       => $p->ID,
					'title'    => $p->post_title,
					'type'     => $p->post_type,
					'modified' => $p->post_modified_gmt,
				);
			}
		}
		return array(
			'items' => $items,
			'page'  => (int) $page,
			'pages' => (int) $q->max_num_pages,
		);
	}
	public static function preview( \WP_REST_Request $r ): array|\WP_Error {
		$ids = $r->get_param( 'source_ids' );
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			return self::bad();
		}
		return Scope::collect( $ids );
	}
	public static function create( \WP_REST_Request $r ): array|\WP_Error {
		$mode        = $r->get_param( 'mode' );
		$instruction = $r->get_param( 'instruction' ) ?? '';
		if ( ! in_array( $mode, array( 'exact', 'ai' ), true ) || ! Proposals::plain( $instruction, 2000 ) || ( 'ai' === $mode && '' === trim( $instruction ) ) ) {
			return self::bad();
		}
		$scope = self::preview( $r );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$hashes = $r->get_param( 'source_hashes' );
		if ( ! is_array( $hashes ) ) {
			return self::bad();
		}
		foreach ( $scope['sources'] as $id => $source ) {
			if ( ( $hashes[ $id ] ?? null ) !== $source['hash'] ) {
				return new \WP_Error( 'lwcd_conflict', __( 'A source changed. Preview again.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
			}
		}
		$proposals = array();
		$provider  = '';
		if ( 'exact' === $mode ) {
			$find    = $r->get_param( 'find' );
			$replace = $r->get_param( 'replacement' );
			if ( ! Proposals::plain( $find, 2000 ) || '' === $find || ! Proposals::plain( $replace, 2000 ) || $find === $replace ) {
				return self::bad();
			}
			$proposals = Proposals::exact( $scope['fields'], $find, $replace );
			if ( is_wp_error( $proposals ) ) {
				return $proposals;
			}
		} else {
			$status = Provider::status();
			if ( ! $status['available'] ) {
				return new \WP_Error( 'lwcd_provider_unavailable', $status['message'], array( 'status' => 503 ) );
			}$provider = $status['provider_id'];
		}
		$id = Jobs::create(
			$scope,
			array(
				'proposals'   => $proposals,
				'instruction' => $instruction,
				'provider_id' => $provider,
			),
			$mode
		);
		return is_wp_error( $id ) ? $id : self::view( Jobs::get( $id ) );
	}
	public static function read( \WP_REST_Request $r ): array|\WP_Error {
		$id = (int) $r['id'];
		return self::view(
			Db::guard(
				array( $id ),
				static function () use ( $id ) {
					$job = Jobs::get( $id );
					return is_wp_error( $job ) ? $job : self::recover( $job );
				}
			)
		);
	}
	/** A lost request has an unknown billing outcome. Do not reclaim or retry its chunk. */
	private static function recover( array $job ): array|\WP_Error {
		if ( ! $job['inflight'] || $job['inflight']['time'] > time() - 600 ) {
			return $job;
		}
		$current           = $job['inflight']['index'];
		$job['failed_ids'] = array_values( array_unique( array_merge( $job['failed_ids'], array_column( $job['chunks'][ $current ] ?? array(), 'id' ) ) ) );
		foreach ( array_slice( $job['chunks'], $current + 1 ) as $chunk ) {
			$job['unscanned_ids'] = array_merge( $job['unscanned_ids'], array_column( $chunk, 'id' ) );
		}
		$job['unscanned_ids'] = array_values( array_unique( $job['unscanned_ids'] ) );
		$job['cursor']        = count( $job['chunks'] );
		$job['inflight']      = null;
		$job['status']        = 'needs_verification';
		$job['last_error']    = __( 'Analysis was interrupted. The provider request may have been charged. This job will not repeat it. Review existing proposals or start a new preview manually.', 'lineweb-change-desk' );
		return Jobs::store( $job['id'], $job ) ? $job : Db::error();
	}
	public static function history( \WP_REST_Request $r ): array|\WP_Error {
		$page = $r->get_param( 'page' ) ?? 1;
		if ( ! is_numeric( $page ) || (int) $page < 1 || (int) $page > 10000 ) {
			return self::bad();
		}
		return Jobs::history( (int) $page );
	}
	public static function delete( \WP_REST_Request $r ): array|\WP_Error {
		$out = Jobs::delete( (int) $r['id'] );
		return is_wp_error( $out ) ? $out : array( 'deleted' => true );
	}
	public static function cancel( \WP_REST_Request $r ): array|\WP_Error {
		$out = Jobs::cancel( (int) $r['id'] );
		return is_wp_error( $out ) ? $out : self::read( $r );
	}
	private static function write( \WP_REST_Request $r, bool $restore ): array|\WP_Error {
		$key  = $restore ? 'post_ids' : 'proposal_ids';
		$body = $r->get_body_params();
		$json = $r->get_json_params();
		if ( is_array( $json ) ) {
			$body = $json;
		}
		if ( array_diff( array_keys( $body ), array( $key, 'operation_id' ) ) ) {
			return self::bad();
		}
		$ids       = $r->get_param( $key );
		$operation = $r->get_param( 'operation_id' );
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || ! is_string( $operation ) ) {
			return self::bad();
		}
		return $restore ? Writer::restore( (int) $r['id'], $ids, $operation ) : Writer::apply( (int) $r['id'], $ids, $operation );
	}
	public static function apply( \WP_REST_Request $r ): array|\WP_Error {
		return self::write( $r, false );
	}
	public static function restore( \WP_REST_Request $r ): array|\WP_Error {
		return self::write( $r, true );
	}
	/** Browser receives only what the review workflow requires, not full recovery snapshots. */
	private static function view( array|\WP_Error $job ): array|\WP_Error {
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$map = array_column( $job['fields'], null, 'id' );
		foreach ( $job['proposals'] as &$p ) {
			$p['post_id'] = $map[ $p['field_id'] ]['post_id'];
		}
		unset( $p );
		$job['snapshots']        = array_map( static fn( $s )=>array( 'restored' => $s['restored'] ), $job['snapshots'] );
		$job['remaining_chunks'] = max( 0, count( $job['chunks'] ) - $job['cursor'] );
		$job['coverage']         = array(
			'supported' => count( $job['fields'] ),
			'analyzed'  => count( $job['analyzed_ids'] ),
			'failed'    => count( $job['failed_ids'] ),
			'unscanned' => count( $job['unscanned_ids'] ),
			'manual'    => count( $job['unsupported'] ),
		);
		unset( $job['chunks'], $job['fields'] );
		foreach ( $job['sources'] as &$s ) {
			$s['supported'] = count( $s['fields'] );
			unset( $s['fields'] );
		}
		unset( $s );
		return $job;
	}
	public static function analyze( \WP_REST_Request $r ): array|\WP_Error {
		if ( true !== $r->get_param( 'confirmed_provider_transfer' ) ) {
			return self::bad();
		}
		$id  = (int) $r['id'];
		$job = Jobs::get( $id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$claim = Db::guard(
			array_merge( array( $id ), array_keys( $job['sources'] ) ),
			static function () use ( $id ) {
				$job = Jobs::get( $id );
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				if ( $job['owner'] !== get_current_user_id() ) {
					return Jobs::forbidden();
				}
				$job = self::recover( $job );
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				if ( 'needs_verification' === $job['status'] ) {
					return $job;
				}
				if ( 'ai' !== $job['mode'] || in_array( $job['status'], array( 'cancelled', 'needs_verification', 'ready' ), true ) || $job['inflight'] || $job['cursor'] >= count( $job['chunks'] ) ) {
					return new \WP_Error( 'lwcd_analysis_state', __( 'Analysis is complete, cancelled or already in progress. Refresh job status.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
				}
				foreach ( $job['sources'] as $source ) {
					if ( hash( 'sha256', get_post( $source['id'] )->post_content ) !== $source['hash'] ) {
						return new \WP_Error( 'lwcd_conflict', __( 'A source changed. Preview again.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
					}
				}
				$status = Provider::status();
				if ( ! $status['available'] || $status['provider_id'] !== $job['provider_id'] ) {
					return new \WP_Error( 'lwcd_provider_changed', __( 'The configured provider changed or is unavailable. Start a new preview.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
				}
				$job['status']   = 'analyzing';
				$job['inflight'] = array(
					'index' => $job['cursor'],
					'time'  => time(),
				);
				return Jobs::store( $id, $job ) ? $job : Db::error();
			}
		);
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( 'analyzing' !== $claim['status'] ) {
			return new \WP_Error( 'lwcd_analysis_state', __( 'Analysis needs verification and will not be repeated.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
		}
		$out  = Provider::analyze_chunk(
			$claim['chunks'][ $claim['cursor'] ],
			$claim['instruction'],
			static function () use ( $id, $claim ) {
				$reservation = Quota::reserve( get_current_user_id(), gmdate( 'Y-m-d' ) );
				if ( true !== $reservation ) {
					return $reservation;
				}
				return Db::guard(
					array( $id ),
					static function () use ( $id, $claim ) {
						$job = Jobs::get( $id );
						if ( is_wp_error( $job ) ) {
							return $job;
						}
						if ( 'cancelled' === $job['status'] || ( $job['inflight']['index'] ?? -1 ) !== $claim['cursor'] ) {
							return self::bad();
						}
						++$job['requests_used'];
						$job['inflight']['reserved'] = true;
						return Jobs::store( $id, $job ) ? true : Db::error();
					}
				);
			},
			$claim['provider_id']
		);
		$done = Db::guard(
			array( $id ),
			static function () use ( $id, $claim, $out ) {
				$job = Jobs::get( $id );
				if ( is_wp_error( $job ) ) {
					return $job;
				}
				if ( ( $job['inflight']['index'] ?? -1 ) !== $claim['cursor'] ) {
					return $job;
				}
				$field_ids = array_column( $claim['chunks'][ $claim['cursor'] ], 'id' );
				if ( is_wp_error( $out ) ) {
					$job['failed_ids'] = array_merge( $job['failed_ids'], $field_ids );
					$job['last_error'] = $out->get_error_message();
				} else {
					foreach ( array( 'proposals', 'analyzed_ids', 'failed_ids' ) as $key ) {
						$job[ $key ] = array_merge( $job[ $key ], $out[ $key ] );
					}
				}
				++$job['cursor'];
				$job['inflight'] = null;
				if ( 'cancelled' !== $job['status'] ) {
					$job['status'] = $job['cursor'] >= count( $job['chunks'] ) ? 'ready' : ( is_wp_error( $out ) ? 'partial' : 'pending' );
				}
				return Jobs::store( $id, $job ) ? $job : Db::error();
			}
		);
		return self::view( $done );
	}
	private static function bad(): \WP_Error {
		return new \WP_Error( 'lwcd_input', __( 'Check the selected sources and input limits.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
	}
}
