import { expect, test, type Page } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, money, store } from './store';

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

const SCREEN = '/wp-admin/admin.php?page=flexacommerce-gift-cards';

// One project makes the card; the checkout markup is checked by the other specs on every theme.
test( 'the admin makes a card and a shopper pays part of an order with it', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project makes the card' );
	test.setTimeout( 90_000 );

	await logInAsAdmin( page );
	await page.goto( SCREEN );
	await page.getByRole( 'button', { name: 'New gift card' } ).click();
	const made = page.getByRole( 'dialog' );
	await made.getByLabel( /^Value/ ).fill( '5' );
	await made.getByLabel( 'Private note' ).fill( 'E2E card' );
	await made.getByLabel( 'Email the code to the recipient' ).uncheck();
	await expectAccessible( page, info, 'new gift card dialog' );
	await made.getByRole( 'button', { name: 'Make gift card' } ).click();
	await expect( page.getByText( 'Gift card made.' ) ).toBeVisible();

	// The new card opens: its code is the dialog title.
	const card = page.getByRole( 'dialog' );
	await expect( card.getByText( `${ money( 500 ) } left of ${ money( 500 ) }.` ) ).toBeVisible();
	const code = ( await card.locator( '.fxc\\:font-mono' ).first().innerText() ).trim();
	expect( code ).toMatch( /^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/ );
	await expectAccessible( page, info, 'gift card dialog' );
	await card.getByRole( 'button', { name: 'Close' } ).first().click();

	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper );
	await shopper.goto( store.checkout );
	await fillBilling( shopper, 'giftcard@example.test' );
	await expect( shopper.locator( '[data-fxc-total="total"]' ) ).toHaveText( money( store.simple.price + 500 ) );

	await shopper.getByText( 'Have a coupon or gift card?' ).click();
	const box = shopper.getByLabel( 'Coupon or gift card code', { exact: true } );
	await box.fill( code.toLowerCase().replaceAll( '-', ' ' ) );
	await box.press( 'Enter' );
	await expect( shopper.locator( '[data-fxc-coupon-message]' ) ).toHaveText( 'Gift card applied.' );
	const row = shopper.locator( '.fxc-checkout__gift-card' );
	await expect( row ).toContainText( code.slice( -4 ) );
	await expect( row ).toContainText( money( 500 ) );
	await expect( shopper.locator( '[data-fxc-total="due"]' ) ).toHaveText( money( store.simple.price ) );
	await expectAccessible( shopper, info, 'checkout with a gift card' );

	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await guest.close();

	await page.goto( SCREEN );
	await page.getByRole( 'searchbox', { name: 'Search gift cards' } ).fill( code.slice( -4 ) );
	await page.getByRole( 'button', { name: code } ).click();
	const spent = page.getByRole( 'dialog' );
	await expect( spent.getByText( `${ money( 0 ) } left of ${ money( 500 ) }.` ) ).toBeVisible();
	await expect( spent.getByRole( 'cell', { name: 'Paid an order' } ) ).toBeVisible();

	// Turned off, the card is listed as disabled.
	await spent.getByLabel( 'Card can pay' ).click();
	await expect( page.getByText( 'Gift card turned off.', { exact: false } ) ).toBeVisible();
	await spent.getByRole( 'button', { name: 'Close' } ).first().click();
} );
