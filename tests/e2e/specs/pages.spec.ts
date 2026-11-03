import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, store } from './store';

async function logInAsAdmin( page: Page ): Promise< void > {
	await expect( async () => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'bench' );
		await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin', { timeout: 1000 } );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 5000 } );
	} ).toPass( { timeout: 30_000 } );
}

// One project checks the card. It may add an order tracking page, which no other spec looks for.
test( 'pages: the Storefront card shows each store page and creates the optional ones', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project checks the admin card' );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=storefront' );
	const card = page.locator( '.fxc\\:space-y-6 > div' ).filter( { has: page.getByRole( 'heading', { name: 'Pages', exact: true } ) } );
	await expect( card.getByRole( 'combobox', { name: 'Cart', exact: true } ) ).toBeVisible();
	await expect( card.getByRole( 'group', { name: 'Cart' } ).getByText( 'Working' ) ).toBeVisible();
	await expect( card.getByRole( 'button', { name: 'Create missing pages' } ) ).toHaveCount( 0 );
	await expectAccessible( page, info, 'store pages card' );

	const tracking = card.getByRole( 'group', { name: 'Order tracking' } );
	const create = tracking.getByRole( 'button', { name: 'Create page' } );
	if ( await create.isVisible() ) {
		await create.click();
	}
	await expect( tracking.getByText( 'Working' ) ).toBeVisible();
	const href = await tracking.getByRole( 'link', { name: 'View Order tracking page' } ).getAttribute( 'href' );
	await page.goto( href ?? '/' );
	await expect( page.getByLabel( /order number/i ) ).toBeVisible();
} );

// Every theme: the Shop row turns the sidebar on, the shop shows it, phones fold it (ADR-084).
test( 'pages: the Shop row adds filters beside the shop list', async ( { page }, info ) => {
	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=storefront' );
	const row = page.getByRole( 'group', { name: 'Shop', exact: true } );
	const picker = row.getByRole( 'combobox', { name: 'Shop', exact: true } );
	await expect( picker ).toBeVisible();
	if ( ( await picker.inputValue() ) !== 'off' ) {
		await picker.selectOption( 'off' );
		await expect( row.getByText( 'No filters', { exact: true } ).first() ).toBeVisible();
	}
	await row.getByRole( 'button', { name: 'Add filters' } ).click();
	await expect( row.getByText( 'Filters on', { exact: true } ) ).toBeVisible();
	await expect( picker ).toHaveValue( 'left' );
	if ( info.project.name === 'tt5' ) {
		await expectAccessible( page, info, 'shop row' );
	}

	try {
		await page.goto( store.shop );
		const form = page.getByRole( 'form', { name: 'Filter products' } );
		await expect( form ).toBeVisible();
		const side = await form.boundingBox();
		const grid = await page.locator( '.type-fxc_product' ).first().boundingBox();
		expect( side && grid && side.x < grid.x ).toBe( true );
		await expect( page.locator( '.fxc-filters-toggle > summary' ) ).toBeHidden();
		await expectAccessible( page, info, 'shop with filters' );

		// A ticked box still applies at once, and the toggle then counts it.
		await form.locator( `input[name="filter_cat[]"][value="${ store.category.slug }"]` ).click();
		await expect( page ).toHaveURL( new RegExp( `filter_cat.*${ store.category.slug }` ) );

		await page.setViewportSize( { width: 390, height: 844 } );
		const toggle = page.locator( '.fxc-filters-toggle > summary' );
		await expect( toggle ).toHaveText( 'Filters (1)' );
		await expect( page.getByRole( 'form', { name: 'Filter products' } ) ).toBeHidden();
		await toggle.click();
		await expect( page.getByRole( 'form', { name: 'Filter products' } ) ).toBeVisible();
		await expectAccessible( page, info, 'shop filters on a phone' );
	} finally {
		await page.setViewportSize( { width: 1280, height: 720 } );
		await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=storefront' );
		await page.getByRole( 'group', { name: 'Shop', exact: true } ).getByRole( 'combobox', { name: 'Shop', exact: true } ).selectOption( 'off' );
		await expect( page.getByRole( 'group', { name: 'Shop', exact: true } ).getByRole( 'button', { name: 'Add filters' } ) ).toBeVisible();
	}
} );
