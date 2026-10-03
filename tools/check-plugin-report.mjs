import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export function assertPluginReport( output ) {
	const start = output.search( /^\[/m );
	assert.ok( start >= 0, 'Missing strict-json Plugin Check report.' );
	const results = JSON.parse(
		output.slice( start, output.lastIndexOf( ']' ) + 1 )
	);
	assert.ok( Array.isArray( results ), 'Invalid Plugin Check report.' );
	for ( const result of results ) {
		assert.ok(
			[ 'ERROR', 'WARNING' ].includes( result.type ),
			'Unknown finding type.'
		);
		assert.notEqual(
			result.type,
			'ERROR',
			`Plugin Check error: ${ result.code }`
		);
	}
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	assertPluginReport( readFileSync( process.argv[ 2 ], 'utf8' ) );
	process.stdout.write( 'PASS Plugin Check report contains zero errors.\n' );
}
