import { copyFile, lstat, mkdir, mkdtemp, rm } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	expectedRuntimeEntries,
	expectedWordPressEntries,
	validateRuntimeEntries,
} from './runtime-allowlist.mjs';

const slug = 'lineweb-change-desk';
const plugin = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const args = process.argv.slice( 2 );
if ( args.length > 1 || ( args.length && args[ 0 ] !== '--wordpress' ) ) {
	throw new Error( 'Usage: node tools/make-release-zip.mjs [--wordpress]' );
}
const directory = args[ 0 ] === '--wordpress';
const entries = directory ? expectedWordPressEntries : expectedRuntimeEntries;
const archive = path.join(
	plugin,
	`${ slug }${ directory ? '-wordpress-org' : '' }.zip`
);
const staging = await mkdtemp( path.join( os.tmpdir(), 'lwcd-release-' ) );
try {
	for ( const entry of entries ) {
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
	const inventory = spawnSync( 'unzip', [ '-Z1', archive ], {
		encoding: 'utf8',
	} );
	if ( inventory.status !== 0 ) {
		throw new Error( 'Cannot inspect release ZIP.' );
	}
	validateRuntimeEntries(
		inventory.stdout.split( /\r?\n/ ).filter( Boolean ),
		directory ? 'wordpress' : 'direct'
	);
	process.stdout.write(
		`${ path.basename( archive ) }: verified ${
			directory ? 'WordPress.org' : 'direct-install'
		} boundary.\n`
	);
} finally {
	await rm( staging, { recursive: true, force: true } );
}
