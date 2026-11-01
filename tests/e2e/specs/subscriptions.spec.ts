import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, fillBilling, money, store } from './store';

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

const SCREEN = '/wp-admin/admin.php?page=flexacommerce-subscriptions';

test( 'the product page shows the plan', async ( { page }, info ) => {
	await page.goto( store.club.url );
	const price = page.locator( '.fxc-price' ).first();
	await expect( price ).toContainText( money( store.club.price ) );
	await expect( price.locator( '.fxc-price__period' ) ).toHaveText( '/ month' );
	await expect( page.locator( '.fxc-price__terms' ).first() ).toHaveText( `${ money( store.club.fee ) } sign-up fee` );
	await expectAccessible( page, info, 'subscription product' );
} );

// One project places the order; the checkout markup is checked by the other specs on every theme.
test( 'a guest subscribes and the admin manages the subscription', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project places the order' );
	test.setTimeout( 90_000 );

	const email = `club-${ Date.now() }@example.test`;
	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await shopper.goto( store.club.url );
	const form = shopper.locator( 'form.fxc-add-to-cart--single' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();

	await shopper.goto( store.checkout );
	await fillBilling( shopper, email );
	// The first payment carries the sign-up fee; the flat rate ships it.
	await expect( shopper.locator( '[data-fxc-total="total"]' ) ).toHaveText( money( store.club.price + store.club.fee + 500 ) );
	await expectAccessible( shopper, info, 'checkout with a subscription' );
	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await guest.close();

	await logInAsAdmin( page );
	await page.goto( SCREEN );
	await page.getByRole( 'searchbox', { name: 'Search subscriptions' } ).fill( email );
	const row = page.getByRole( 'row' ).filter( { hasText: email } );
	await expect( row ).toContainText( 'Active' );
	// Each renewal is the product and its shipping, without the sign-up fee.
	await expect( row ).toContainText( `${ money( store.club.price + 500 ) } / month` );
	await expectAccessible( page, info, 'subscriptions list' );
	await row.getByRole( 'button' ).first().click();

	const view = page.getByRole( 'dialog' );
	await expect( view.getByRole( 'row' ).filter( { hasText: 'Coffee club × 1' } ) ).toContainText( money( store.club.price ) );
	await expect( view.getByRole( 'row' ).filter( { hasText: 'Shipping' } ) ).toContainText( money( 500 ) );
	await expect( view.getByText( 'First order', { exact: true } ).first() ).toBeVisible();
	await expectAccessible( page, info, 'subscription dialog' );

	await view.getByRole( 'button', { name: 'Cancel at the end of the period' } ).click();
	await expect( page.getByText( 'The subscription ends after the period that is paid for.' ) ).toBeVisible();
	await expect( view.getByText( 'Ends soon' ).first() ).toBeVisible();

	await view.getByRole( 'button', { name: 'Reactivate' } ).click();
	await expect( page.getByText( 'Subscription reactivated.' ) ).toBeVisible();
	await expect( view.getByText( 'Active' ).first() ).toBeVisible();
	await view.getByRole( 'button', { name: 'Close' } ).first().click();
} );
