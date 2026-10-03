import { writeFile } from 'node:fs/promises';
import path from 'node:path';
const version = process.env.WP_VERSION || 'latest';
const php = process.env.PHP_VERSION || '8.3';
const mode = process.env.WP_ENV_MODE || 'source';
if ( version !== 'latest' && ! /^7\.\d+(?:\.\d+)?$/.test( version ) ) {
	throw new Error( 'Unsupported WordPress version' );
}
if ( ! [ '8.3', '8.4', '8.5' ].includes( php ) ) {
	throw new Error( 'Unsupported PHP version' );
}
if ( ! [ 'source', 'archive' ].includes( mode ) ) {
	throw new Error( 'Unsupported environment mode' );
}
const root = path.resolve( process.env.PLUGIN_ROOT || '.' );
const mappings =
	mode === 'source'
		? { 'wp-content/plugins/lineweb-change-desk': root }
		: {
				'wp-content/lineweb-ci': path.resolve(
					process.env.ARCHIVE_ROOT || '.'
				),
				'wp-content/lineweb-tests': path.join( root, 'tests' ),
		  };
await writeFile(
	'.wp-env.json',
	JSON.stringify(
		{
			core:
				version === 'latest'
					? null
					: `https://wordpress.org/wordpress-${ version }.zip`,
			phpVersion: php,
			port: Number( process.env.WP_PORT || 8888 ),
			plugins: [],
			mappings,
			testsEnvironment: false,
			config: {
				WP_DEBUG: true,
				SCRIPT_DEBUG: true,
				WP_ENVIRONMENT_TYPE: 'local',
			},
		},
		null,
		2
	) + '\n'
);
