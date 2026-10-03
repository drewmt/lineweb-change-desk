import { expect, test } from '@wordpress/e2e-test-utils-playwright';
test( 'captures synthetic documentation views', async ( {
	page,
	requestUtils,
} ) => {
	const seed = await requestUtils.rest( {
		path: '/wp/v2/pages?search=Lineweb%20demo&per_page=20',
	} );
	if (
		! seed.some( ( post ) => post.slug === 'lineweb-change-desk-hours' )
	) {
		test.skip(
			true,
			'Run the local demo seed before capturing documentation.'
		);
	}
	await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
	await page.getByLabel( 'Search content' ).fill( '"Lineweb demo ·"' );
	await page.getByRole( 'button', { name: 'Search', exact: true } ).click();
	await page
		.getByLabel( 'Select Lineweb demo · Studio opening hours', {
			exact: true,
		} )
		.check();
	await page
		.getByLabel( 'Select Lineweb demo · Contact information', {
			exact: true,
		} )
		.check();
	await page.getByLabel( 'Old text' ).fill( '09:00–17:00' );
	await page.getByLabel( 'New text' ).fill( '10:00–18:00' );
	await page.addStyleTag( {
		content: '#wpadminbar { display: none !important; }',
	} );
	await expect( page.locator( '.lwcd-source' ) ).toHaveCount( 2 );
	await page.getByLabel( 'New text' ).blur();
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	const workspace = page.locator( '.lwcd' );
	await page.screenshot( {
		path: 'artifacts-change-desk/screenshot-1.png',
		fullPage: false,
	} );
	await page
		.getByRole( 'button', { name: 'Preview selected content' } )
		.click();
	await page
		.getByRole( 'button', { name: 'Prepare exact proposals' } )
		.click();
	await expect( page.getByLabel( 'Approve change 2' ) ).toBeVisible();
	await page.getByLabel( 'Approve change 1' ).check();
	await page.getByLabel( 'Approve change 2' ).check();
	await page
		.getByRole( 'button', { name: 'Review selected changes' } )
		.click();
	const review = page
		.getByRole( 'heading', { name: 'Review each proposed change' } )
		.locator( '..' )
		.locator( '..' )
		.locator( '..' );
	await review.screenshot( {
		path: 'artifacts-change-desk/screenshot-2.png',
	} );
	await page.setViewportSize( { width: 375, height: 1000 } );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe( true );
	await review.screenshot( {
		path: 'artifacts-change-desk/screenshot-3.png',
	} );
	const job = await requestUtils.rest( {
		path: '/lineweb-change-desk/v1/jobs',
	} );
	const current = await requestUtils.rest( {
		path: `/lineweb-change-desk/v1/jobs/${ job.items[ 0 ].id }`,
	} );
	expect( Object.keys( current.snapshots ) ).toHaveLength( 0 );
	await requestUtils.rest( {
		method: 'DELETE',
		path: `/lineweb-change-desk/v1/jobs/${ current.id }`,
	} );
	await expect( workspace ).toBeVisible();
} );
