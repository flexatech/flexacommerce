import { expect, test, type Page } from '@playwright/test';
import { addSimple, expectAccessible, fillBilling, store } from './store';

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

// The order screen is theme independent; the customer page is checked in the same run.
test( 'the admin adds tracking and the customer sees it on the order page', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );
	test.setTimeout( 60_000 );

	await addSimple( page );
	await page.goto( store.checkout );
	await fillBilling( page, 'tracking@example.test' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	const received = page.url();
	const orderId = new URL( received ).searchParams.get( 'order-received' );
	expect( orderId ).toMatch( /^\d+$/ );
	await expect( page.locator( '.fxc-order__shipments' ) ).toHaveCount( 0 );

	await logInAsAdmin( page );
	await page.goto( `/wp-admin/admin.php?page=flexacommerce-orders&order=${ orderId }` );
	await expect( page.getByText( 'Nothing shipped yet.', { exact: false } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Add tracking' } ).click();

	const dialog = page.getByRole( 'dialog' );
	await expect( dialog.getByRole( 'heading', { name: 'Add tracking' } ) ).toBeVisible();
	await dialog.getByLabel( 'Carrier', { exact: true } ).selectOption( { label: 'UPS' } );
	await dialog.getByLabel( 'Tracking number' ).fill( '1Z999AA10123456784' );
	await dialog.getByLabel( 'Mark the order as completed' ).check();
	await dialog.getByLabel( 'Email the customer the tracking' ).uncheck();
	await expectAccessible( page, info, 'add tracking dialog' );
	await dialog.getByRole( 'button', { name: 'Add tracking' } ).click();

	await expect( page.getByText( 'Tracking added.' ) ).toBeVisible();
	await expect( dialog ).toHaveCount( 0 );
	await expect( page.getByRole( 'link', { name: /1Z999AA10123456784/ } ) ).toBeVisible();
	await expectAccessible( page, info, 'order with a shipment' );

	await page.goto( received );
	const section = page.locator( '.fxc-order__shipments' );
	await expect( section.getByRole( 'heading', { name: 'Tracking' } ) ).toBeVisible();
	const link = section.getByRole( 'link', { name: /1Z999AA10123456784/ } );
	await expect( link ).toHaveAttribute( 'href', 'https://www.ups.com/track?tracknum=1Z999AA10123456784' );
	await expect( link ).toHaveAttribute( 'target', '_blank' );
	await expect( section.getByText( 'UPS' ) ).toBeVisible();
	await expectAccessible( page, info, 'order page with tracking' );
} );
