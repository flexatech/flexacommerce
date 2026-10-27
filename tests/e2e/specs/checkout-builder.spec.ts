import { expect, test, type Page } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, money, store } from './store';

const BUILDER = '/wp-admin/admin.php?page=flexacommerce-checkout';

async function logInAsAdmin( page: Page ): Promise< void > {
	// The login screen moves the focus to the user name once loaded, which can catch a typed password.
	await expect( async () => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'bench' );
		await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin', { timeout: 1000 } );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 5000 } );
	} ).toPass( { timeout: 30_000 } );
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
test( 'the checkout builder moves a field and a section, adds a question and a heading, and the checkout follows', async ( { page, browser }, info ) => {
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
	const settings = page.getByLabel( 'Settings', { exact: true } );
	await expect( settings.getByLabel( 'Label', { exact: true } ) ).toBeFocused();
	await settings.getByLabel( 'Label', { exact: true } ).fill( 'Gift message' );
	await settings.getByRole( 'radio', { name: 'Required', exact: true } ).check();

	// A heading goes after the section of the selected field.
	await page.getByRole( 'button', { name: 'Heading', exact: true } ).click();
	await expect( settings.getByLabel( 'Text', { exact: true } ) ).toBeFocused();
	await settings.getByLabel( 'Text', { exact: true } ).fill( 'Almost done' );

	// The order summary moves to the main column, at its end.
	await page.getByRole( 'button', { name: /^Your order/ } ).click();
	await settings.getByLabel( 'Column' ).selectOption( 'main' );

	// One column, seen on the site before saving; then back to two columns.
	await page.getByText( 'One column', { exact: true } ).click();
	const [ tab ] = await Promise.all( [ page.waitForEvent( 'popup' ), page.getByRole( 'button', { name: 'Preview on site' } ).click() ] );
	await expect( tab.locator( '.fxc-checkout__preview' ) ).toBeVisible();
	await expect( tab.locator( '.fxc-checkout__columns--one' ) ).toBeVisible();
	await expect( tab.getByRole( 'heading', { name: 'Almost done' } ) ).toBeVisible();
	await expect( tab.getByRole( 'button', { name: 'Place order' } ) ).toBeDisabled();
	await tab.close();
	await page.getByText( 'Two columns', { exact: true } ).click();

	await save.click();
	await expect( page.getByText( 'Checkout saved.' ) ).toBeVisible();
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
	await expect( shopper.locator( '.fxc-checkout__columns--one' ) ).toHaveCount( 0 );
	await expect( shopper.locator( '.fxc-checkout__details .fxc-checkout__summary' ) ).toBeVisible();
	expect( await before( shopper, '#fxc-additional-gift_message', '.fxc-checkout__heading' ) ).toBe( true );
	expect( await before( shopper, '.fxc-checkout__heading', '.fxc-checkout__summary' ) ).toBe( true );

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
	await expect( page.getByText( 'Checkout reset.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Reset to default' } ) ).toBeDisabled();
} );

test( 'a field shown for one country follows the country the shopper picks', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( BUILDER );
	await page.getByRole( 'button', { name: 'Short text' } ).click();
	const settings = page.getByLabel( 'Settings', { exact: true } );
	await settings.getByLabel( 'Label', { exact: true } ).fill( 'VAT number' );
	await settings.getByRole( 'radio', { name: 'Required', exact: true } ).check();
	await settings.getByLabel( 'Only for these countries' ).selectOption( 'DE' );
	await expect( settings.getByRole( 'button', { name: 'Remove Germany' } ) ).toBeVisible();
	await expect( page.getByText( 'Conditional', { exact: true } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Checkout saved.' ) ).toBeVisible();
	await expectAccessible( page, info, 'checkout builder conditions' );

	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper );
	await shopper.goto( '/checkout/' );
	const vat = shopper.locator( '#fxc-additional-vat_number' );
	const country = shopper.locator( '#fxc-billing-country' );
	await country.selectOption( 'US' );
	await expect( vat ).toBeHidden();
	await country.selectOption( 'DE' );
	await expect( vat ).toBeVisible();
	await expect( vat ).toBeEnabled();

	// Back in the US, the hidden question does not hold the order back.
	await fillBilling( shopper );
	await expect( vat ).toBeHidden();
	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await expect( shopper.locator( '.fxc-order' ) ).not.toContainText( 'VAT number' );
	await guest.close();

	await page.goto( BUILDER );
	await page.getByRole( 'button', { name: 'Reset to default' } ).click();
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Reset', exact: true } ).click();
	await expect( page.getByText( 'Checkout reset.' ) ).toBeVisible();
} );

test( 'a steps checkout asks for one step at a time and the Back button goes back a step', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( BUILDER );
	await page.getByText( 'Steps', { exact: true } ).click();
	await expect( page.getByText( 'Step 1 · Information' ).first() ).toBeVisible();
	await expect( page.getByText( 'Every step', { exact: true } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Checkout saved.' ) ).toBeVisible();

	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper );
	await shopper.goto( '/checkout/' );
	const steps = shopper.getByRole( 'navigation', { name: 'Checkout steps' } );
	await expect( steps.getByRole( 'button', { name: 'Information' } ) ).toHaveAttribute( 'aria-current', 'step' );
	await expect( shopper.locator( '#fxc-payment-method' ) ).toBeHidden();
	await expect( shopper.locator( '.fxc-checkout__summary' ) ).toBeVisible();

	// Continue holds back an empty address.
	const email = shopper.locator( '#fxc-billing-email' );
	await shopper.getByRole( 'button', { name: 'Continue to Shipping' } ).click();
	await expect( email ).toBeFocused();
	expect( await email.evaluate( ( el: HTMLInputElement ) => el.validity.valueMissing ) ).toBe( true );
	await expect( shopper.locator( '[data-fxc-rates]' ) ).toBeHidden();

	await fillBilling( shopper );
	await shopper.getByRole( 'button', { name: 'Continue to Shipping' } ).click();
	await expect( steps.getByRole( 'button', { name: 'Shipping' } ) ).toHaveAttribute( 'aria-current', 'step' );
	await expect( shopper.locator( '.fxc-checkout__details [data-fxc-rates]' ) ).toBeVisible();
	await expect( email ).toBeHidden();
	const recap = shopper.locator( '.fxc-checkout__recap' );
	await expect( recap ).toContainText( 'guest@example.test' );
	await expect( recap ).toContainText( '100 Main Street, Portland' );
	await expectAccessible( shopper, info, 'checkout steps' );

	// The browser's Back button, then forward again with Enter.
	await shopper.goBack();
	await expect( steps.getByRole( 'button', { name: 'Information' } ) ).toHaveAttribute( 'aria-current', 'step' );
	await expect( email ).toBeVisible();
	await shopper.locator( '#fxc-billing-city' ).press( 'Enter' );
	await expect( steps.getByRole( 'button', { name: 'Shipping' } ) ).toHaveAttribute( 'aria-current', 'step' );
	await shopper.getByRole( 'button', { name: 'Continue to Payment' } ).click();
	await expect( recap ).toContainText( 'Shipping method' );

	// Change goes back to the field.
	await recap.getByRole( 'button', { name: 'Change Contact' } ).click();
	await expect( email ).toBeFocused();
	await steps.getByRole( 'button', { name: 'Payment' } ).click();
	await expect( steps.getByRole( 'button', { name: 'Payment' } ) ).toHaveAttribute( 'aria-current', 'step' );

	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await guest.close();

	await page.goto( BUILDER );
	await page.getByRole( 'button', { name: 'Reset to default' } ).click();
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Reset', exact: true } ).click();
	await expect( page.getByText( 'Checkout reset.' ) ).toBeVisible();
} );

test( 'the coupon box under the summary applies and removes a code without leaving the checkout', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project creates the coupon' );

	await logInAsAdmin( page );
	await page.goto( BUILDER );
	await page.getByRole( 'button', { name: /^Your order/ } ).click();
	await expect( page.getByRole( 'switch', { name: /Coupon code box/ } ) ).toBeChecked();
	const coupon = await page.evaluate( async () => {
		const admin = ( window as unknown as { flexaCommerce: { restUrl: string; restNonce: string } } ).flexaCommerce;
		const response = await fetch( admin.restUrl + 'admin/coupons', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': admin.restNonce },
			body: JSON.stringify( { code: 'CHECKOUT10', type: 'percent', amount: 1000 } ),
		} );
		return { status: response.status, id: ( await response.json() ).id as number };
	} );
	expect( coupon.status ).toBe( 201 );

	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper );
	await shopper.goto( '/checkout/' );
	await fillBilling( shopper );
	const total = shopper.locator( '[data-fxc-total="total"]' );
	const discounted = store.simple.price - Math.round( store.simple.price / 10 ) + 500;
	await expect( total ).toHaveText( money( store.simple.price + 500 ) );

	await shopper.getByText( 'Have a coupon code?' ).click();
	const code = shopper.getByLabel( 'Coupon code', { exact: true } );
	const said = shopper.locator( '[data-fxc-coupon-message]' );
	await code.fill( 'nope' );
	await code.press( 'Enter' );
	await expect( said.getByRole( 'alert' ) ).not.toBeEmpty();
	await expect( code ).toBeFocused();

	await code.fill( 'checkout10' );
	await shopper.getByRole( 'button', { name: 'Apply', exact: true } ).click();
	await expect( said ).toHaveText( 'Coupon applied.' );
	await expect( shopper.locator( '.fxc-checkout__coupon' ) ).toContainText( 'Coupon: CHECKOUT10' );
	await expect( total ).toHaveText( money( discounted ) );
	await expectAccessible( shopper, info, 'checkout coupon box' );

	await shopper.getByRole( 'button', { name: 'Remove coupon checkout10' } ).click();
	await expect( said ).toHaveText( 'Coupon removed.' );
	await expect( shopper.locator( '.fxc-checkout__coupon' ) ).toHaveCount( 0 );
	await expect( code ).toBeFocused();
	await expect( total ).toHaveText( money( store.simple.price + 500 ) );

	// Applied again, the order is placed with the discount: the expected total followed.
	await code.fill( 'CHECKOUT10' );
	await code.press( 'Enter' );
	await expect( total ).toHaveText( money( discounted ) );
	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order__overview' ) ).toContainText( money( discounted ), { timeout: 15_000 } );
	await guest.close();

	await page.evaluate( async ( id ) => {
		const admin = ( window as unknown as { flexaCommerce: { restUrl: string; restNonce: string } } ).flexaCommerce;
		await fetch( admin.restUrl + 'admin/coupons/' + id, { method: 'DELETE', headers: { 'X-WP-Nonce': admin.restNonce } } );
	}, coupon.id );
} );
