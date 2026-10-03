import { expect, test } from '@wordpress/e2e-test-utils-playwright';
test( 'reviews one exact change, applies selectively and restores with a valid Gutenberg block', async ( {
	page,
	requestUtils,
	editor,
} ) => {
	const original =
		'<!-- wp:paragraph --><p>Hours: <strong>09:00</strong>. Call <a href="/contact/">our team</a>.</p><!-- /wp:paragraph -->';
	const title = `Synthetic opening hours ${ Date.now() }`;
	const post = await requestUtils.createRecord( 'pages', {
		title,
		status: 'publish',
		content: original,
	} );
	const errors: string[] = [];
	const httpErrors: string[] = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'response', ( r ) => {
		if ( r.status() >= 400 ) {
			httpErrors.push( `${ r.status() } ${ r.url() }` );
		}
	} );
	await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
	await expect(
		page.getByRole( 'heading', {
			name: 'Keep business information consistent.',
		} )
	).toBeVisible();
	await page.getByLabel( 'Search content' ).fill( title );
	await page.getByRole( 'button', { name: 'Search', exact: true } ).click();
	await page.getByLabel( `Select ${ title }`, { exact: true } ).check();
	await page.getByLabel( 'Old text' ).fill( '09:00' );
	await page.getByLabel( 'New text' ).fill( '10:00' );
	await page
		.getByRole( 'button', { name: 'Preview selected content' } )
		.click();
	await page
		.getByRole( 'button', { name: 'Prepare exact proposals' } )
		.click();
	const approve = page.getByLabel( 'Approve change 1' );
	await expect( approve ).not.toBeChecked();
	await approve.check();
	await page
		.getByRole( 'button', { name: 'Review selected changes' } )
		.click();
	await expect(
		page.getByRole( 'heading', { name: 'Final review', exact: true } )
	).toBeFocused();
	await page
		.getByRole( 'button', { name: 'Apply approved changes' } )
		.click();
	await expect(
		page.getByText( 'Applied', { exact: true } ).first()
	).toBeVisible();
	const after = await requestUtils.rest( {
		path: `/wp/v2/pages/${ post.id }?context=edit`,
	} );
	expect( after.content.raw ).toBe(
		'<!-- wp:paragraph --><p>Hours: <strong>10:00</strong>. Call <a href="/contact/">our team</a>.</p><!-- /wp:paragraph -->'
	);
	await page.reload();
	await page
		.getByRole( 'button', { name: 'Open job', exact: true } )
		.first()
		.click();
	await expect(
		page.getByText( 'Applied', { exact: true } ).first()
	).toBeVisible();
	await expect( page.locator( '.lwcd-operation' ) ).toContainText( 'Apply' );
	await page
		.getByRole( 'button', { name: 'Restore original content' } )
		.click();
	await expect(
		page.getByText( 'Restored', { exact: true } ).first()
	).toBeVisible();
	const restored = await requestUtils.rest( {
		path: `/wp/v2/pages/${ post.id }?context=edit`,
	} );
	expect( restored.content.raw ).toBe( original );
	await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
	await expect(
		editor.canvas.getByText( 'Hours:', { exact: false } )
	).toBeVisible();
	await expect( editor.canvas.locator( '.is-invalid' ) ).toHaveCount( 0 );
	await expect(
		page.getByText( 'This block contains unexpected or invalid content.' )
	).toHaveCount( 0 );
	expect( errors ).toEqual( [] );
	expect( httpErrors ).toEqual( [] );
	await page.goto( `/?page_id=${ post.id }` );
	expect(
		await page
			.locator(
				'script[src*="lineweb-change-desk"],link[href*="lineweb-change-desk"]'
			)
			.count()
	).toBe( 0 );
	await requestUtils.rest( {
		method: 'DELETE',
		path: `/wp/v2/pages/${ post.id }`,
		params: { force: true },
	} );
} );
test( 'blocks a second confirmation after a lost write response and failed reconciliation', async ( {
	page,
	requestUtils,
} ) => {
	const title = `Synthetic interrupted change ${ Date.now() }`;
	const original = '<!-- wp:paragraph --><p>09:00</p><!-- /wp:paragraph -->';
	const post = await requestUtils.createRecord( 'pages', {
		title,
		status: 'publish',
		content: original,
	} );
	try {
		await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
		await page.getByLabel( 'Search content' ).fill( title );
		await page
			.getByRole( 'button', { name: 'Search', exact: true } )
			.click();
		await page.getByLabel( `Select ${ title }`, { exact: true } ).check();
		await page.getByLabel( 'Old text' ).fill( '09:00' );
		await page.getByLabel( 'New text' ).fill( '10:00' );
		await page
			.getByRole( 'button', { name: 'Preview selected content' } )
			.click();
		await page
			.getByRole( 'button', { name: 'Prepare exact proposals' } )
			.click();
		await page.getByLabel( 'Approve change 1' ).check();
		await page
			.getByRole( 'button', { name: 'Review selected changes' } )
			.click();
		let attempts = 0;
		await page.route(
			/\/lineweb-change-desk\/v1\/jobs\/\d+(?:\/apply)?(?:\?|$)/,
			async ( route ) => {
				if ( route.request().method() === 'POST' ) {
					attempts++;
				}
				await route.abort();
			}
		);
		const apply = page.getByRole( 'button', {
			name: 'Apply approved changes',
		} );
		await apply.click();
		await expect( page.getByRole( 'alert' ) ).toBeVisible();
		await expect( apply ).toBeDisabled();
		await apply.evaluate( ( button: HTMLButtonElement ) => button.click() );
		expect( attempts ).toBe( 1 );
		expect(
			(
				await requestUtils.rest( {
					path: `/wp/v2/pages/${ post.id }?context=edit`,
				} )
			).content.raw
		).toBe( original );
	} finally {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ post.id }`,
			params: { force: true },
		} );
	}
} );
test( 'paginates retained jobs so the oldest of 21 remains accessible', async ( {
	page,
	requestUtils,
} ) => {
	const post = await requestUtils.createRecord( 'pages', {
		title: `Synthetic history ${ Date.now() }`,
		status: 'publish',
		content: '<!-- wp:paragraph --><p>09:00</p><!-- /wp:paragraph -->',
	} );
	try {
		const preview = await requestUtils.rest( {
			method: 'POST',
			path: '/lineweb-change-desk/v1/preview',
			data: { source_ids: [ post.id ] },
		} );
		let oldest = 0;
		for ( let i = 0; i < 21; i++ ) {
			const job = await requestUtils.rest( {
				method: 'POST',
				path: '/lineweb-change-desk/v1/jobs',
				data: {
					mode: 'exact',
					find: '09:00',
					replacement: '10:00',
					source_ids: [ post.id ],
					source_hashes: {
						[ post.id ]: preview.sources[ post.id ].hash,
					},
				},
			} );
			if ( i === 0 ) {
				oldest = job.id;
			}
		}
		await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
		await expect( page.locator( '.lwcd-history-row' ) ).toHaveCount( 20 );
		await page.getByRole( 'button', { name: 'Next history' } ).click();
		await expect(
			page.getByRole( 'button', { name: 'Previous history' } )
		).toBeEnabled();
		const response = page.waitForResponse(
			( r ) =>
				r.url().includes( `/jobs/${ oldest }` ) &&
				r.request().method() === 'GET'
		);
		await page
			.getByRole( 'button', { name: 'Open job', exact: true } )
			.first()
			.click();
		expect( ( await ( await response ).json() ).id ).toBe( oldest );
		await expect( page.getByLabel( 'Approve change 1' ) ).toBeVisible();
	} finally {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ post.id }`,
			params: { force: true },
		} );
	}
} );
test( 'loads native Greek and real RTL without overflow', async ( {
	page,
	requestUtils,
} ) => {
	const current = await requestUtils.rest( {
		path: '/wp/v2/users/me?context=edit',
	} );
	try {
		for ( const locale of [ 'el', 'ar' ] ) {
			await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/users/me',
				data: { locale },
			} );
			await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
			await expect( page.locator( 'html' ) ).toHaveAttribute(
				'lang',
				locale
			);
			if ( locale === 'el' ) {
				await expect(
					page.getByRole( 'heading', {
						name: 'Οι πληροφορίες σας, παντού σωστές.',
					} )
				).toBeVisible();
				await expect(
					page.getByLabel( 'Παλιό κείμενο' )
				).toBeVisible();
			} else {
				await expect( page.locator( 'html' ) ).toHaveAttribute(
					'dir',
					'rtl'
				);
			}
			await page.setViewportSize( { width: 375, height: 1000 } );
			expect(
				await page.evaluate(
					() => document.documentElement.scrollWidth <= innerWidth
				)
			).toBe( true );
		}
	} finally {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/users/me',
			data: { locale: current.locale },
		} );
	}
} );
test( 'fits desktop and mobile with centered branding and honest provider status', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/admin.php?page=lineweb-change-desk' );
	await expect(
		page.getByText( 'Exact text changes work without AI.', {
			exact: false,
		} )
	).toBeVisible();
	for ( const width of [ 1440, 375, 320 ] ) {
		await page.setViewportSize( { width, height: 1000 } );
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe( true );
		const brand = await page
			.locator( '.lineweb-suite-admin__brand-card' )
			.boundingBox();
		const logo = await page
			.locator( '.lineweb-suite-admin__brand-mark' )
			.boundingBox();
		expect(
			Math.abs( brand!.x + brand!.width / 2 - logo!.x - logo!.width / 2 )
		).toBeLessThan( 2 );
		await page.screenshot( {
			path: `artifacts-change-desk/admin-${ width }.png`,
			fullPage: true,
		} );
	}
	await page.setViewportSize( { width: 1440, height: 1000 } );
	await page.evaluate( () => ( document.documentElement.style.zoom = '2' ) );
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe( true );
} );
