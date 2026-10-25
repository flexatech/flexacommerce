import { expect, test } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, money, store } from './store';

test( 'guest checkout with cash on delivery reaches the confirmation', async ( { page }, info ) => {
	await addSimple( page );
	await page.goto( store.checkout );
	await expectAccessible( page, info, 'checkout' );

	await fillBilling( page );
	await expect( page.locator( '#fxc-shipping-rate' ) ).toContainText( money( 500 ) );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();

	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.locator( '.fxc-order__overview' ) ).toContainText( money( store.simple.price + 500 ) );
	await expect( page.locator( '.fxc-order' ) ).toContainText( store.simple.name );
	await expectAccessible( page, info, 'order confirmation' );
	// The cart is empty after the order.
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).not.toHaveText( /[1-9]/ );
} );

test( 'checkout errors: the summary takes focus and links to each field', async ( { page }, info ) => {
	await addSimple( page );
	await page.goto( store.checkout );
	// Valid for the browser, rejected by the store: a US ZIP has digits.
	await fillBilling( page );
	await page.locator( '#fxc-billing-postcode' ).fill( 'ABCDE' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();

	const summary = page.locator( '.fxc-checkout__errors[data-fxc-errors]' );
	await expect( summary ).toBeVisible();
	await expect( summary ).toBeFocused();
	await expect( summary ).toHaveAttribute( 'role', 'alert' );
	const links = summary.getByRole( 'link' );
	expect( await links.count() ).toBeGreaterThan( 0 );
	await expectAccessible( page, info, 'checkout with errors' );

	await summary.getByRole( 'link', { name: /zip|postcode|postal/i } ).click();
	await expect( page.locator( '#fxc-billing-postcode' ) ).toBeFocused();
	await expect( page.locator( '#fxc-billing-postcode' ) ).toHaveAttribute( 'aria-invalid', 'true' );
} );
