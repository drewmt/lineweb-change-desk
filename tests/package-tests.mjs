import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { assertPluginReport } from '../tools/check-plugin-report.mjs';
const packageJson = JSON.parse(
	readFileSync( new URL( '../package.json', import.meta.url ), 'utf8' )
);
assert.ok(
	packageJson.scripts[ 'plugin-zip' ].includes( 'make-release-zip.mjs' ),
	'Package only exact runtime files, not npm mandatory source documents.'
);
assert.equal(
	packageJson.devDependencies.eslint,
	'9.39.5',
	'Declare the compatible jsdoc ESLint peer rather than relying on an ancestor workspace.'
);
import {
	expectedRuntimeEntries,
	validateRuntimeEntries,
} from '../tools/runtime-allowlist.mjs';
validateRuntimeEntries( expectedRuntimeEntries );
const directoryEntries = [
	...expectedRuntimeEntries.filter(
		( entry ) =>
			! entry.includes( '/languages/' ) && ! entry.endsWith( '/LICENSE' )
	),
	'lineweb-change-desk/LICENSE',
	'lineweb-change-desk/languages/README.txt',
];
validateRuntimeEntries( directoryEntries, 'wordpress' );
for ( const catalog of [
	'lineweb-change-desk-el.po',
	'lineweb-change-desk-el.mo',
	'lineweb-change-desk-el.l10n.php',
	'lineweb-change-desk-el-lwcd-admin.json',
	'lineweb-change-desk.pot',
] ) {
	assert.throws( () =>
		validateRuntimeEntries(
			[
				...directoryEntries,
				`lineweb-change-desk/languages/${ catalog }`,
			],
			'wordpress'
		)
	);
}
assert.throws( () =>
	validateRuntimeEntries(
		directoryEntries.filter( ( entry ) => ! entry.endsWith( '/LICENSE' ) ),
		'wordpress'
	)
);
assert.throws( () => validateRuntimeEntries( directoryEntries, 'unknown' ) );
process.stdout.write(
	'PASS directory package requires license and excludes translation catalogs.\n'
);
for ( const forbidden of [
	'docs/private-state.md',
	'docs/spec.md',
	'notes.md',
	'unexpected.php',
	'includes/backdoor.php',
	'assets/extra.php',
	'tests/fixture.php',
	'../secret',
	'/secret',
	'build/../../notes.md',
] ) {
	assert.throws( () =>
		validateRuntimeEntries( [
			...expectedRuntimeEntries,
			`lineweb-change-desk/${ forbidden }`,
		] )
	);
}
assert.throws( () =>
	validateRuntimeEntries(
		expectedRuntimeEntries.filter(
			( entry ) => ! entry.endsWith( '/uninstall.php' )
		)
	)
);
assert.throws( () =>
	validateRuntimeEntries( [
		...expectedRuntimeEntries,
		expectedRuntimeEntries[ 0 ],
	] )
);
process.stdout.write(
	'PASS exact runtime boundary rejects private notes, fixtures, unknown PHP, paths and duplicates.\n'
);
assertPluginReport( '[]' );
assertPluginReport( 'wp-env log\n[{"type":"WARNING","code":"example"}]\nDone' );
assert.throws( () =>
	assertPluginReport( '[{"type":"ERROR","code":"unsafe"}]' )
);
assert.throws( () => assertPluginReport( 'Broken report' ) );
assert.throws( () => assertPluginReport( '[{"unknown":"field"}]' ) );
process.stdout.write(
	'PASS Plugin Check gate rejects errors and malformed reports.\n'
);
