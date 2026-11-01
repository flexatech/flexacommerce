import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, store } from './store';

/** The own wishlist; `?fxc_wishlist=1` works with and without pretty permalinks. */
const wishlist = (): string => `${ store.account }${ store.account.includes( '?' ) ? '&' : '?' }fxc_wishlist=1`;

function heart( page: Page ) {
	return page.locator( 'form.fxc-wishlist-button--single' ).getByRole( 'button', { name: 'Save to wishlist' } );
}

test.describe( 'scripts off', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'a guest saves a product and finds it in the wishlist', async ( { page } ) => {
		await page.goto( store.simple.url );
		await heart( page ).click();
		const notice = page.locator( 'form.fxc-wishlist-button--single' ).getByText( 'Saved to your wishlist.' );
		await expect( notice ).toBeVisible();
		await page.getByRole( 'link', { name: 'View wishlist' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Wishlist' } ) ).toBeVisible();
		await expect( page.locator( '.fxc-wishlist__table' ).getByRole( 'link', { name: store.simple.name, exact: true } ) ).toBeVisible();

		await page.getByRole( 'button', { name: `Remove ${ store.simple.name } from your wishlist` } ).click();
		await expect( page.getByText( 'Removed from your wishlist.' ) ).toBeVisible();
		await expect( page.getByText( 'No products saved yet.' ) ).toBeVisible();
	} );
} );

test( 'the heart saves without a reload, remembers it, and the list can be shared', async ( { page, browser }, info ) => {
	await page.goto( store.simple.url );
	const button = heart( page );
	await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
	await button.click();
	await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( page.locator( 'form.fxc-wishlist-button--single [role="status"]' ) ).toHaveText( 'Saved to your wishlist.' );
	await expectAccessible( page, info, 'wishlist button' );

	// A new page view reads the cookie, with no request.
	await page.reload();
	await expect( heart( page ) ).toHaveAttribute( 'aria-pressed', 'true' );

	await page.goto( wishlist() );
	await expect( page.locator( '.fxc-wishlist__table' ).getByRole( 'link', { name: store.simple.name, exact: true } ) ).toBeVisible();
	await expectAccessible( page, info, 'wishlist page' );
	const link = await page.getByLabel( 'Share link' ).inputValue();
	expect( link ).toMatch( /[a-f0-9]{32}/ );

	// Someone else opens the link in their own browser.
	const other = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const friend = await other.newPage();
	await friend.goto( link );
	await expect( friend.getByRole( 'heading', { name: 'Shared wishlist' } ) ).toBeVisible();
	await expect( friend.getByRole( 'link', { name: store.simple.name, exact: true } ) ).toBeVisible();
	await expect( friend.getByRole( 'button', { name: /Remove/ } ) ).toHaveCount( 0 );
	await other.close();

	// The heart takes it off again.
	await page.goto( store.simple.url );
	await heart( page ).click();
	await expect( heart( page ) ).toHaveAttribute( 'aria-pressed', 'false' );
	await page.goto( wishlist() );
	await expect( page.getByText( 'No products saved yet.' ) ).toBeVisible();
} );

test( 'a guest list moves into the account on sign-in', async ( { page } ) => {
	await page.goto( store.variable.url );
	await heart( page ).click();
	await expect( heart( page ) ).toHaveAttribute( 'aria-pressed', 'true' );

	await page.goto( store.account );
	const login = page.locator( '#fxc-login' );
	await login.getByLabel( /username or email/i ).fill( store.customer.login );
	await login.getByLabel( /password/i ).fill( store.customer.password );
	await login.getByRole( 'button', { name: /log in/i } ).click();
	await page.locator( '.fxc-account__nav' ).getByRole( 'link', { name: 'Wishlist' } ).click();
	await expect( page.locator( '.fxc-wishlist__table' ).getByRole( 'link', { name: store.variable.name, exact: true } ) ).toBeVisible();

	// The heart follows the account, read from the store once the cookie names the user.
	await page.goto( store.variable.url );
	await expect( heart( page ) ).toHaveAttribute( 'aria-pressed', 'true' );
} );
