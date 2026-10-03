import { spawnSync } from 'node:child_process';
import { validateRuntimeEntries } from './runtime-allowlist.mjs';
const archive = process.argv[ 2 ] || 'lineweb-change-desk.zip';
const listed = spawnSync( 'unzip', [ '-Z1', archive ], { encoding: 'utf8' } );
if ( listed.status !== 0 ) {
	throw new Error( listed.stderr || 'Cannot inspect ZIP' );
}
const entries = validateRuntimeEntries(
	listed.stdout.split( /\r?\n/ ).filter( Boolean )
);
process.stdout.write(
	`${ entries.length } strictly allowlisted runtime entries.\n`
);
