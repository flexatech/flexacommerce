import { expect, test } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, store } from './store';

test( 'customer signs in, orders, and finds the order in the account', async ( { page }, info ) => {
	await page.goto( store.account );
	await expectAccessible( page, info, 'account sign-in' );
	const login = page.locator( '#fxc-login' );
	await login.getByLabel( /username or email/i ).fill( store.customer.login );
	await login.getByLabel( /password/i ).fill( store.customer.password );
	await login.getByRole( 'button', { name: /log in/i } ).click();
	await expect( page.locator( '.fxc-account__nav' ) ).toBeVisible();
	await expectAccessible( page, info, 'account dashboard' );

	await addSimple( page );
	await page.goto( store.checkout );
	await fillBilling( page, 'shopper@example.test' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	const number = ( await page.locator( '.fxc-order__overview' ).innerText() ).match( /#?(\d+)/ )?.[ 1 ] ?? '';

	await page.goto( store.account );
	await page.locator( '.fxc-account__nav' ).getByRole( 'link', { name: 'Orders' } ).click();
	await expect( page.getByText( new RegExp( `#?${ number }\\b` ) ).first() ).toBeVisible();
	await expectAccessible( page, info, 'account orders' );

	await page.locator( '.fxc-account__nav' ).getByRole( 'link', { name: 'Addresses' } ).click();
	await expect( page.locator( 'form' ).filter( { has: page.locator( 'input, select' ) } ).first() ).toBeVisible();
	await expectAccessible( page, info, 'account addresses' );
} );
