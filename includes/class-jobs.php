<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Jobs {
	public static function register(): void {
		// Jobs are accessed only through our owner/source-authorized REST contract.
		// Core editor, XML-RPC and generic post APIs must not inherit post capabilities.
		$capabilities = array_fill_keys(
			array(
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'publish_posts',
				'read_private_posts',
				'delete_posts',
				'delete_private_posts',
				'delete_published_posts',
				'delete_others_posts',
				'edit_private_posts',
				'edit_published_posts',
				'create_posts',
				'read',
			),
			'do_not_allow'
		);
		register_post_type(
			'lwcd_job',
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'can_export'          => false,
				'rewrite'             => false,
				'supports'            => array(),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
			)
		);
	}
	public static function create( array $scope, array $analysis, string $mode ): int|\WP_Error {
		if ( ! in_array( $mode, array( 'exact', 'ai' ), true ) || ! Db::supported() ) {
			return Db::error();
		}
		$fresh = Scope::collect( $scope['ids'] ?? array() );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		foreach ( $fresh['sources'] as $id => $source ) {
			if ( ( $scope['sources'][ $id ]['hash'] ?? '' ) !== $source['hash'] ) {
				return new \WP_Error( 'lwcd_conflict', __( 'A source changed. Preview again.', 'lineweb-change-desk' ), array( 'status' => 409 ) );
			}
		}
		$valid = Proposals::validate( $fresh['fields'], $analysis['proposals'] ?? array() );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'lwcd_job',
				'post_status'  => 'private',
				'post_author'  => get_current_user_id(),
				'post_title'   => 'Change Desk ' . gmdate( 'Y-m-d H:i' ),
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$plan = Provider::chunks( $fresh['fields'] );
		$data = array(
			'id'            => $id,
			'owner'         => get_current_user_id(),
			'created'       => time(),
			'expires'       => time() + 30 * DAY_IN_SECONDS,
			'mode'          => $mode,
			'sources'       => $fresh['sources'],
			'fields'        => $fresh['fields'],
			'proposals'     => $valid,
			'unsupported'   => $fresh['unsupported'],
			'snapshots'     => array(),
			'operations'    => array(),
			'status'        => 'exact' === $mode ? 'ready' : 'pending',
			'instruction'   => $analysis['instruction'] ?? '',
			'provider_id'   => $analysis['provider_id'] ?? '',
			'chunks'        => $plan['chunks'],
			'cursor'        => 0,
			'inflight'      => null,
			'requests_used' => 0,
			'analyzed_ids'  => 'exact' === $mode ? array_column( $fresh['fields'], 'id' ) : array(),
			'failed_ids'    => array(),
			'unscanned_ids' => 'exact' === $mode ? array() : $plan['unscanned_ids'],
		);
		if ( ! self::store( $id, $data ) ) {
			wp_delete_post( $id, true );
			return Db::error();
		}
		foreach ( $fresh['ids'] as $source ) {
			add_post_meta( $id, '_lwcd_source', $source );
		}
		return $id;
	}
	/** Internal serialization only. Callers guard the job row and authorize before writing. */
	public static function store( int $id, array $data ): bool {
		global $wpdb;
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$result = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $json ),
			array(
				'ID'        => $id,
				'post_type' => 'lwcd_job',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		clean_post_cache( $id );
		return false !== $result;
	}
	public static function get( int $id ): array|\WP_Error {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d AND post_type='lwcd_job'", $id ) );
		if ( ! $post || ! current_user_can( 'edit_others_posts' ) || ( get_current_user_id() !== (int) $post->post_author && ! current_user_can( 'manage_options' ) ) ) {
			return self::forbidden();
		}
		$data = json_decode( $post->post_content, true );
		if ( ! is_array( $data ) || ( $data['expires'] ?? 0 ) < time() ) {
			return new \WP_Error( 'lwcd_expired', __( 'This job has expired.', 'lineweb-change-desk' ), array( 'status' => 410 ) );
		}
		if ( ! isset( $data['sources'], $data['fields'], $data['proposals'], $data['owner'], $data['status'], $data['created'] ) || ! is_array( $data['sources'] ) ) {
			return Db::error();
		}
		foreach ( $data['sources'] as $source ) {
			$p = get_post( $source['id'] );
			if ( ! $p || ! Scope::can_edit_source( $p ) ) {
				return self::forbidden();
			}
		}
		return $data;
	}
	public static function cancel( int $id ): bool|\WP_Error {
		return Db::guard(
			array( $id ),
			static function () use ( $id ) {
				$job = self::get( $id );
				if ( is_wp_error( $job ) ) {
					return $job;
				}if ( get_current_user_id() !== $job['owner'] ) {
					return self::forbidden();
				}
				$remaining = $job['cursor'] + ( $job['inflight'] ? 1 : 0 );
				foreach ( array_slice( $job['chunks'], $remaining ) as $chunk ) {
					$job['unscanned_ids'] = array_merge( $job['unscanned_ids'], array_column( $chunk, 'id' ) );
				}
				$job['unscanned_ids'] = array_values( array_unique( $job['unscanned_ids'] ) );
				$job['status']        = 'cancelled';
				return self::store( $id, $job ) ? true : Db::error();
			}
		);
	}
	public static function delete( int $id ): bool|\WP_Error {
		$post = get_post( $id );
		if ( ! $post || 'lwcd_job' !== $post->post_type || ! current_user_can( 'edit_others_posts' ) || ( get_current_user_id() !== (int) $post->post_author && ! current_user_can( 'manage_options' ) ) ) {
			return self::forbidden();
		}
		return Db::guard( array( $id ), static fn()=>wp_delete_post( $id, true ) ? true : Db::error() );
	}
	public static function history( int $page = 1 ): array {
		global $wpdb;
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type='lwcd_job' AND post_status='private' AND post_author=%d
			AND (CASE WHEN JSON_VALID(post_content) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(post_content, '$.expires')) AS UNSIGNED) ELSE 0 END) >= %d
			ORDER BY ID DESC LIMIT 21 OFFSET %d",
				get_current_user_id(),
				time(),
				( max( 1, $page ) - 1 ) * 20
			)
		);
		$more = count( $ids ) > 20;
		$ids  = array_slice( $ids, 0, 20 );
		$out  = array();
		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$job = self::get( $id );
			if ( ! is_wp_error( $job ) ) {
				$out[] = array(
					'id'      => $id,
					'created' => $job['created'],
					'expires' => $job['expires'],
					'status'  => $job['status'],
					'mode'    => $job['mode'],
					'sources' => count( $job['sources'] ),
				);
			}
		}
		return array(
			'items'    => $out,
			'page'     => $page,
			'has_more' => $more,
		);
	}
	public static function forbidden(): \WP_Error {
		return new \WP_Error( 'lwcd_forbidden', __( 'You cannot access this job or its source content.', 'lineweb-change-desk' ), array( 'status' => 403 ) );
	}
}
