<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class TextFields {
	private const LEAVES     = array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/button' );
	private const CONTAINERS = array( 'core/group', 'core/columns', 'core/column', 'core/list', 'core/buttons' );
	private const TAGS       = array( 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'DIV', 'A', 'SPAN', 'STRONG', 'EM', 'B', 'I', 'S', 'U', 'MARK', 'CODE', 'SUP', 'SUB', 'BR' );
	public static function extract( string $content, int $post_id ): array {
		$result = array(
			'fields'      => array(),
			'unsupported' => array(),
			'source_hash' => hash( 'sha256', $content ),
		);
		$blocks = parse_blocks( $content );
		if ( serialize_blocks( $blocks ) !== $content ) {
			$result['unsupported'][] = array(
				'block_path' => '',
				'block_name' => 'malformed',
				'reason'     => __( 'Block markup cannot be safely round-tripped.', 'lineweb-change-desk' ),
			);
			return $result;
		}
		self::walk( $blocks, array(), $post_id, $result );
		return $result;
	}
	private static function walk( array $blocks, array $path, int $id, array &$out ): void {
		foreach ( $blocks as $index => $block ) {
			$here = array( ...$path, $index );
			$name = $block['blockName'];
			if ( in_array( $name, self::CONTAINERS, true ) && $block['innerBlocks'] ) {
				self::walk( $block['innerBlocks'], $here, $id, $out );
				continue;
			}
			if ( in_array( $name, self::LEAVES, true ) && ! $block['innerBlocks'] && self::safe_html( $block['innerHTML'] ) ) {
				$processor = new \WP_HTML_Tag_Processor( $block['innerHTML'] );
				$token     = 0;
				while ( $processor->next_token() ) {
					if ( '#text' === $processor->get_token_type() ) {
						$text = $processor->get_modifiable_text();
						if ( '' !== trim( $text ) ) {
							$out['fields'][] = array(
								'id'          => hash( 'sha256', $id . ':' . implode( '.', $here ) . ':' . $token . ':' . $out['source_hash'] ),
								'post_id'     => $id,
								'block_path'  => $here,
								'token_index' => $token,
								'original'    => $text,
							);
						}
					}
					++$token;
				}
			} elseif ( '' !== trim( $block['innerHTML'] ) || $name ) {
				$out['unsupported'][] = array(
					'block_path' => implode( '.', $here ),
					'block_name' => $name ?? 'freeform',
					'reason'     => __( 'Manual review: unsupported block or markup.', 'lineweb-change-desk' ),
				);
			}
		}
	}
	private static function safe_html( string $html ): bool {
		$p        = new \WP_HTML_Tag_Processor( $html );
		$stack    = array();
		$has_root = false;
		while ( $p->next_token() ) {
			if ( '#tag' !== $p->get_token_type() ) {
				if ( '#text' !== $p->get_token_type() ) {
					return false;
				} continue;
			}
			$tag = $p->get_tag();
			if ( ! in_array( $tag, self::TAGS, true ) ) {
				return false;
			}
			$has_root = true;
			if ( 'BR' === $tag ) {
				continue;
			}
			if ( $p->is_tag_closer() ) {
				if ( array_pop( $stack ) !== $tag ) {
					return false;
				}
			} else {
				$stack[] = $tag;
			}
		}
		return $has_root && ! $stack && ! $p->paused_at_incomplete_token();
	}
	public static function patch( string $content, int $post_id, array $proposals ): string|\WP_Error {
		$extracted = self::extract( $content, $post_id );
		$validated = Proposals::validate( $extracted['fields'], $proposals );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$fields  = array_column( $extracted['fields'], null, 'id' );
		$changes = array();
		foreach ( $validated as $proposal ) {
			$field = $fields[ $proposal['field_id'] ];
			$changes[ implode( '.', $field['block_path'] ) ][ $field['token_index'] ] = $proposal['replacement'];
		}
		$blocks = parse_blocks( $content );
		self::mutate( $blocks, array(), $changes );
		return serialize_blocks( $blocks );
	}
	private static function mutate( array &$blocks, array $path, array $changes ): void {
		foreach ( $blocks as $i => &$block ) {
			$here = array( ...$path, $i );
			$key  = implode( '.', $here );
			if ( $block['innerBlocks'] ) {
				self::mutate( $block['innerBlocks'], $here, $changes );
			}
			if ( ! isset( $changes[ $key ] ) ) {
				continue;
			}
			$processor = new \WP_HTML_Tag_Processor( $block['innerHTML'] );
			$token     = 0;
			while ( $processor->next_token() ) {
				if ( isset( $changes[ $key ][ $token ] ) ) {
					$processor->set_modifiable_text( $changes[ $key ][ $token ] );
				}
				++$token;
			}
			$block['innerHTML']    = $processor->get_updated_html();
			$block['innerContent'] = array( $block['innerHTML'] );
		}
	}
}
