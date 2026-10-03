import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
const root = await mkdtemp( path.join( os.tmpdir(), 'lwcd-upgrade-' ) );
try {
	const unzip = spawnSync( 'unzip', [
		'-q',
		'lineweb-change-desk.zip',
		'-d',
		root,
	] );
	if ( unzip.status !== 0 ) {
		throw new Error( 'Cannot extract release ZIP' );
	}
	for ( const [ file, pattern ] of [
		[ 'lineweb-change-desk.php', /^( \* Version:\s+).+$/m ],
		[ 'readme.txt', /^(Stable tag:\s+).+$/m ],
	] ) {
		const target = path.join( root, 'lineweb-change-desk', file );
		await writeFile(
			target,
			( await readFile( target, 'utf8' ) ).replace(
				pattern,
				( match, prefix ) => `${ prefix }0.0.0`
			)
		);
	}
	const target = path.join( root, 'fixture.zip' );
	if (
		spawnSync( 'zip', [ '-q', '-r', target, 'lineweb-change-desk' ], {
			cwd: root,
		} ).status !== 0
	) {
		throw new Error( 'Cannot archive fixture' );
	}
	await cp( target, 'lineweb-change-desk-0.0.0.zip' );
} finally {
	await rm( root, { recursive: true, force: true } );
}
