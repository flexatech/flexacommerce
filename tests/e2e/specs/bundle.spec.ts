import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, money, store } from './store';

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

test( 'bundle: the page lists the items and the cart shows them under the bundle', async ( { page }, info ) => {
	await page.goto( store.bundle.url );
	const includes = page.locator( '.fxc-bundle' );
	await expect( includes ).toContainText( 'This bundle includes:' );
	await expect( includes.getByRole( 'link', { name: store.bundle.mug, exact: true } ) ).toBeVisible();
	await expect( includes.getByRole( 'link', { name: store.simple.name, exact: true } ) ).toBeVisible();
	await expect( page.getByText( money( store.bundle.price ) ).first() ).toBeVisible();
	await expectAccessible( page, info, 'bundle product' );

	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).toHaveText( /1/ );

	await page.goto( store.cart );
	const row = page.locator( 'tr', { hasText: store.bundle.name } ).first();
	await expect( row.locator( '.fxc-cart__includes' ) ).toHaveText( 'Includes:' );
	await expect( row.getByText( `2 × ${ store.bundle.mug }` ) ).toBeVisible();
	await expect( row.getByText( `1 × ${ store.simple.name }` ) ).toBeVisible();
	await expect( page.getByText( money( store.bundle.price ) ).first() ).toBeVisible();
	await expectAccessible( page, info, 'cart with a bundle' );
} );

// One project checks the editor; it changes nothing, so the storefront specs keep their data.
test( 'bundle: the product editor lists the items and finds more', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project checks the editor' );
	test.setTimeout( 90_000 );

	await logInAsAdmin( page );
	await page.goto( `/wp-admin/post.php?post=${ store.bundle.id }&action=edit` );
	// The block editor opens on its welcome guide and folds its meta box pane; the product editor lives there.
	const guide = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
	await guide.or( page.getByRole( 'button', { name: 'Meta Boxes' } ) ).first().waitFor( { timeout: 30_000 } );
	if ( await guide.isVisible() ) {
		await guide.getByRole( 'button', { name: 'Close' } ).click();
	}
	const pane = page.getByRole( 'button', { name: 'Meta Boxes' } );
	await expect( pane ).toBeVisible( { timeout: 30_000 } );
	if ( ( await pane.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await pane.press( 'Enter' ); // its resize handle sits over the middle of the button
	}
	const items = page.getByRole( 'list', { name: 'Items in this bundle' } );
	await items.scrollIntoViewIfNeeded( { timeout: 30_000 } );
	await expect( items.getByRole( 'listitem' ) ).toHaveCount( 2 );
	await expect( page.getByLabel( `Units of ${ store.bundle.mug } in one bundle` ) ).toHaveValue( '2' );
	await expect( page.getByLabel( 'Discount (%)' ) ).toHaveValue( '10' );

	const search = page.getByLabel( 'Add a product to the bundle' );
	await search.fill( 'Custom sign' );
	await page.getByRole( 'option', { name: /Custom sign/ } ).click();
	await expect( items.getByRole( 'listitem' ) ).toHaveCount( 3 );
	await search.press( 'Escape' );
	await page.getByRole( 'button', { name: 'Remove Custom sign' } ).click();
	await expect( items.getByRole( 'listitem' ) ).toHaveCount( 2 );
} );
