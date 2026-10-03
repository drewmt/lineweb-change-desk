import path from 'node:path';

const slug = 'lineweb-change-desk';
const files = new Set( [
	`${ slug }.php`,
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'languages/README.txt',
	'assets/admin.css',
	'assets/lineweb-logo.png',
	'build/admin/index.js',
	'build/admin/index.asset.php',
	'build/admin/style-index.css',
	'build/admin/style-index-rtl.css',
	...[
		'admin',
		'db',
		'jobs',
		'lifecycle',
		'proposals',
		'provider',
		'quota',
		'rest',
		'scope',
		'textfields',
		'writer',
	].map( ( name ) => `includes/class-${ name }.php` ),
	`languages/${ slug }.pot`,
	...[ 'el', 'el_GR' ].flatMap( ( locale ) => [
		`languages/${ slug }-${ locale }.po`,
		`languages/${ slug }-${ locale }.mo`,
		`languages/${ slug }-${ locale }-lwcd-admin.json`,
	] ),
] );
const directories = new Set( [
	'',
	'assets/',
	'build/',
	'build/admin/',
	'includes/',
	'languages/',
] );

const directoryFiles = new Set(
	[ ...files ].filter(
		( file ) =>
			! file.startsWith( 'languages/' ) || file === 'languages/README.txt'
	)
);

export function validateRuntimeEntries( entries, profile = 'direct' ) {
	if ( ! [ 'direct', 'wordpress' ].includes( profile ) ) {
		throw new Error( `Unknown release profile: ${ profile }` );
	}
	const requiredFiles = profile === 'wordpress' ? directoryFiles : files;
	const found = new Set();
	for ( const entry of entries ) {
		if (
			path.isAbsolute( entry ) ||
			entry.includes( '\\' ) ||
			entry.includes( '..' ) ||
			! entry.startsWith( `${ slug }/` )
		) {
			throw new Error( `Unsafe release path: ${ entry }` );
		}
		const relative = entry.slice( slug.length + 1 );
		if (
			found.has( relative ) ||
			( ! requiredFiles.has( relative ) && ! directories.has( relative ) )
		) {
			throw new Error( `Unexpected release entry: ${ entry }` );
		}
		found.add( relative );
	}
	for ( const file of requiredFiles ) {
		if ( ! found.has( file ) ) {
			throw new Error( `Missing release file: ${ file }` );
		}
	}
	return entries;
}
export const expectedRuntimeEntries = [ ...files ].map(
	( file ) => `${ slug }/${ file }`
);
export const expectedWordPressEntries = [ ...directoryFiles ].map(
	( file ) => `${ slug }/${ file }`
);
