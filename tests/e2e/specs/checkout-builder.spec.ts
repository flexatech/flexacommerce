import { expect, test, type Page } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling } from './store';

const BUILDER = '/wp-admin/admin.php?page=flexacommerce-checkout';

async function logInAsAdmin( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'bench' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

/** True when the first element comes before the second in the page. */
const before = ( page: Page, first: string, second: string ) =>
	page.evaluate(
		( [ a, b ] ) => {
			const x = document.querySelector( a );
			const y = document.querySelector( b );
			return x !== null && y !== null && ( x.compareDocumentPosition( y ) & Node.DOCUMENT_POSITION_FOLLOWING ) !== 0;
		},
		[ first, second ] as const
	);

// The builder is admin markup: the theme does not change it, so one project runs it.
test( 'the checkout builder moves a field, adds a question and the checkout asks it', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( BUILDER );
	const save = page.getByRole( 'button', { name: 'Save changes' } );
	await expect( save ).toBeDisabled();

	// Keyboard drag: Phone goes up one row in the billing section.
	const grip = page.getByRole( 'button', { name: 'Move Phone' } ).first();
	await grip.focus();
	const live = page.locator( '[id^="DndLiveRegion"]' ).first();
	await page.keyboard.press( 'Space' );
	await expect( live ).toHaveText( 'Phone is over position 11 of 11 in Billing details.' );
	await page.keyboard.press( 'ArrowUp' );
	await expect( live ).toHaveText( 'Phone is over position 10 of 11 in Billing details.' );
	await page.keyboard.press( 'Space' );
	await expect( live ).toHaveText( 'Phone, moved to position 10 of 11 in Billing details.' );
	await expect( save ).toBeEnabled();

	// A question of our own, from the palette, lands in Additional information.
	await page.getByRole( 'button', { name: 'Short text' } ).click();
	const settings = page.getByLabel( 'Field settings' );
	await expect( settings.getByLabel( 'Label', { exact: true } ) ).toBeFocused();
	await settings.getByLabel( 'Label', { exact: true } ).fill( 'Gift message' );
	await settings.getByRole( 'radio', { name: 'Required', exact: true } ).check();
	await save.click();
	await expect( page.getByText( 'Checkout fields saved.' ) ).toBeVisible();
	await expect( save ).toBeDisabled();
	await expectAccessible( page, info, 'checkout builder' );

	// A guest sees the new order and the question.
	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper );
	await shopper.goto( '/checkout/' );
	expect( await before( shopper, '#fxc-billing-phone', '#fxc-billing-postcode' ) ).toBe( true );
	const gift = shopper.locator( '#fxc-additional-gift_message' );
	await expect( gift ).toBeVisible();

	await fillBilling( shopper );
	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	// Required like the address fields: the browser holds the order back (the server check is in CheckoutPageTest).
	expect( await gift.evaluate( ( el: HTMLInputElement ) => el.validity.valueMissing ) ).toBe( true );
	await expect( shopper.locator( '.fxc-order' ) ).toHaveCount( 0 );
	await expect( shopper.getByText( 'Gift message' ) ).toBeVisible();

	await gift.fill( 'Happy birthday' );
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( shopper.locator( '.fxc-order' ) ).toContainText( 'Gift message' );
	await expect( shopper.locator( '.fxc-order' ) ).toContainText( 'Happy birthday' );
	await guest.close();

	// Back to the default, so the other journeys see the usual form.
	await page.goto( BUILDER );
	await page.getByRole( 'button', { name: 'Reset to default' } ).click();
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Reset', exact: true } ).click();
	await expect( page.getByText( 'Checkout fields reset.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Reset to default' } ) ).toBeDisabled();
} );
