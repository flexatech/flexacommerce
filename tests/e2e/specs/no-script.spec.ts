import { expect, test } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, money, store } from './store';

// The storefront forms post without scripts (doc 02 §6.3).
test.describe( 'scripts off', () => {
	test.use( { javaScriptEnabled: false } );

	// axe needs scripts, so this journey checks behavior only; the next
	// test checks the server-rendered markup.
	test( 'add to cart and check out', async ( { page } ) => {
		await page.goto( store.simple.url );
		await page.locator( 'form.fxc-add-to-cart--single' ).getByRole( 'button', { name: 'Add to cart' } ).click();
		await page.goto( store.cart );
		await expect( page.locator( '.fxc-cart__line' ) ).toHaveCount( 1 );

		await page.goto( store.checkout );
		await fillBilling( page, 'nojs@example.test' );
		// Without scripts the rates come from a full refresh of the form.
		await page.getByRole( 'button', { name: 'Update shipping and totals' } ).click();
		await expect( page.locator( '#fxc-shipping-rate' ) ).toContainText( money( 500 ) );
		await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
		await page.locator( '#fxc-terms' ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect( page.locator( '.fxc-order' ) ).toBeVisible();
		await expect( page.locator( '.fxc-order__overview' ) ).toContainText( money( store.simple.price + 500 ) );
	} );
} );

test( 'scripts that fail to load: the server renders the error summary', async ( { page }, info ) => {
	await addSimple( page );
	// From here on no script file loads, as on a blocked or failed CDN.
	await page.route( /\.m?js(\?|$)/, ( route ) => route.abort() );
	await page.goto( store.checkout );
	await fillBilling( page );
	await page.locator( '#fxc-billing-postcode' ).fill( 'ABCDE' );
	await page.getByRole( 'button', { name: 'Update shipping and totals' } ).click();
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();

	const summary = page.locator( '.fxc-checkout__errors[data-fxc-errors]' );
	await expect( summary ).toBeVisible();
	await expect( summary ).toHaveAttribute( 'role', 'alert' );
	await expect( summary.getByRole( 'link', { name: /zip|postcode|postal/i } ) ).toHaveAttribute( 'href', '#fxc-billing-postcode' );
	await expect( page.locator( '#fxc-billing-postcode' ) ).toHaveAttribute( 'aria-invalid', 'true' );
	await expectAccessible( page, info, 'server error summary' );
} );
