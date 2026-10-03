<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Proposals {
	public static function length( string $text ): int {
		$count = preg_match_all( '/./us', $text, $matches );
		return false === $count ? 0 : $count;
	}
	public static function plain( $text, int $limit = 12000 ): bool {
		return is_string( $text ) && 1 === preg_match( '//u', $text ) && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text )
			&& self::length( $text ) <= $limit && ! preg_match( '/<\s*(?:\/?\s*[a-zA-Z]|!|\?)/', $text );
	}
	public static function exact( array $fields, string $find, string $replacement ): array|\WP_Error {
		if ( '' === $find || $find === $replacement || ! self::plain( $find ) || ! self::plain( $replacement ) ) {
			return array();
		}
		$items = array();
		foreach ( $fields as $field ) {
			if ( str_contains( $field['original'], $find ) ) {
				$items[] = array(
					'field_id'    => $field['id'],
					'original'    => $field['original'],
					'replacement' => str_replace( $find, $replacement, $field['original'] ),
					'reason'      => __( 'Exact text match. No AI request.', 'lineweb-change-desk' ),
				);
			}
		}
		$validated = self::validate( $fields, $items );
		return $validated;
	}
	public static function validate( array $fields, $items ): array|\WP_Error {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > count( $fields ) ) {
			return self::invalid();
		}
		$map  = array_column( $fields, null, 'id' );
		$seen = array();
		$out  = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'id', 'field_id', 'original', 'replacement', 'reason' ) )
				|| ! isset( $item['field_id'], $item['original'], $item['replacement'] ) || ! is_string( $item['field_id'] )
				|| ! isset( $map[ $item['field_id'] ] ) || isset( $seen[ $item['field_id'] ] ) || $map[ $item['field_id'] ]['original'] !== $item['original']
				|| ! self::plain( $item['replacement'] ) || ! self::plain( $item['reason'] ?? '', 1000 ) ) {
				return self::invalid();
			}
			$seen[ $item['field_id'] ] = true;
			if ( $item['replacement'] === $item['original'] ) {
				continue;
			}
			$out[] = array(
				'id'          => hash( 'sha256', $item['field_id'] . "\0" . $item['original'] . "\0" . $item['replacement'] ),
				'field_id'    => $item['field_id'],
				'original'    => $item['original'],
				'replacement' => $item['replacement'],
				'reason'      => $item['reason'] ?? '',
			);
		}
		return $out;
	}
	private static function invalid(): \WP_Error {
		return new \WP_Error( 'lwcd_invalid_proposal', __( 'The proposal does not match a safe source field.', 'lineweb-change-desk' ), array( 'status' => 400 ) );
	}
}
