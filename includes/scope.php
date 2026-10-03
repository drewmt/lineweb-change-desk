<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Scope {
	public static function can_edit_source( \WP_Post $post ): bool {
		return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $post->ID )
			&& in_array( $post->post_type, array( 'post', 'page' ), true ) && 'publish' === $post->post_status
			&& '' === $post->post_password;
	}
	public static function collect( array $ids ): array|\WP_Error {
		if ( ! $ids || count( $ids ) > 10 || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
			return new \WP_Error( 'lwcd_scope', __( 'Select between one and ten unique posts or pages.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
		}
		$out  = array(
			'ids'         => array(),
			'sources'     => array(),
			'fields'      => array(),
			'unsupported' => array(),
		);
		$size = 0;
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return new \WP_Error( 'lwcd_scope', __( 'Invalid source ID.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
			}
			$post = get_post( $id );
			if ( ! $post || ! self::can_edit_source( $post ) ) {
				return new \WP_Error( 'lwcd_forbidden', __( 'You cannot edit one of the selected sources.', 'lineweb-change-desk' ), array( 'status' => 403 ) );
			}
			if ( strlen( $post->post_content ) > 1000000 ) {
				return new \WP_Error( 'lwcd_scope', __( 'This source is too large for safe analysis.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
			}
			$extracted = TextFields::extract( $post->post_content, $id );
			foreach ( $extracted['fields'] as $field ) {
				$size += Proposals::length( $field['original'] );
			}
			if ( $size > 60000 || count( $out['fields'] ) + count( $extracted['fields'] ) > 1000 ) {
				return new \WP_Error( 'lwcd_scope', __( 'Selected text exceeds the analysis limit. Select fewer sources.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
			}
			$out['ids'][]          = $id;
			$out['sources'][ $id ] = array(
				'id'          => $id,
				'title'       => $post->post_title,
				'hash'        => $extracted['source_hash'],
				'fields'      => $extracted['fields'],
				'unsupported' => $extracted['unsupported'],
			);
			$out['fields']         = array_merge( $out['fields'], $extracted['fields'] );
			foreach ( $extracted['unsupported'] as $item ) {
				$out['unsupported'][] = array( 'post_id' => $id ) + $item;
			}
		}
		return $out;
	}
}
