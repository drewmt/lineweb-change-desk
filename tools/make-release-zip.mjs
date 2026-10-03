import { copyFile, lstat, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectedRuntimeEntries } from './runtime-allowlist.mjs';

const slug = 'lineweb-change-desk';
const plugin = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const archive = path.join( plugin, `${ slug }.zip` );
const staging = await mkdtemp( path.join( os.tmpdir(), 'lwcd-release-' ) );
try {
	for ( const entry of expectedRuntimeEntries ) {
		const source = path.join( plugin, entry.slice( slug.length + 1 ) );
		if ( ! ( await lstat( source ) ).isFile() ) {
			throw new Error( `Not a regular runtime file: ${ entry }` );
		}
		const destination = path.join( staging, entry );
		await mkdir( path.dirname( destination ), { recursive: true } );
		await copyFile( source, destination );
	}
	await rm( archive, { force: true } );
	const result = spawnSync( 'zip', [ '-q', '-r', archive, slug ], {
		cwd: staging,
		encoding: 'utf8',
	} );
	if ( result.status !== 0 ) {
		throw new Error( result.stderr || 'Cannot create release ZIP.' );
	}
} finally {
	await rm( staging, { recursive: true, force: true } );
}
