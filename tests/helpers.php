<?php
/** Synthetic test assertions, not shipped in the runtime ZIP. */
namespace Lineweb\ChangeDesk\Tests;

function equal( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function ok( bool $value, string $message ): void {
	equal( true, $value, $message );
}
