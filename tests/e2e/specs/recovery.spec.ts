import { expect, test, type Page } from '@playwright/test';
import { addSimple, expectAccessible, store } from './store';

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

/** Runs the reminder cron for the address (tools/bench/mu-plugins/e2e.php), in the page. */
async function runReminders( page: Page, email: string ): Promise< string > {
	const key = await page.evaluate( async ( address ) => {
		const response = await fetch( '/', { headers: { 'X-FXC-E2E-Recovery': address } } );
		return ( ( await response.json() ) as { key: string | null } ).key;
	}, email );
	expect( key ).toMatch( /^\d+\.[a-f0-9]{32}$/ );
	return key as string;
}

// Admin markup, theme independent: one project runs it.
test( 'a guest cart left at checkout gets a reminder whose link brings it back', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );
	test.setTimeout( 90_000 );
	const email = 'left-cart@example.test';

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-recovery' );
	await expect( page.getByText( 'Cart reminders are off.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Open settings' } ).click();
	await page.getByLabel( 'Send cart reminders' ).check( { force: true } );
	await page.getByLabel( 'Cart counts as left after' ).fill( '15' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Cart reminder settings saved.' ) ).toBeVisible();
	await expectAccessible( page, info, 'cart reminder settings' );

	// A guest types an email at checkout and leaves.
	const guest = await browser.newContext( { baseURL: info.project.use.baseURL, extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await addSimple( shopper, 2 );
	await shopper.goto( store.checkout );
	await expect( shopper.getByText( 'If you leave without ordering, we may email you a reminder about your cart.' ) ).toBeVisible();
	const saved = shopper.waitForResponse( ( response ) => response.url().includes( 'checkout/email' ) && response.request().method() === 'POST' );
	await shopper.locator( '#fxc-billing-email' ).fill( email );
	await shopper.locator( '#fxc-billing-first_name' ).click();
	expect( await ( await saved ).json() ).toMatchObject( { saved: true } );
	const key = await runReminders( shopper, email );
	await guest.close();

	// The link opened in a fresh browser fills its cart and goes to the checkout.
	const other = await browser.newContext( { baseURL: info.project.use.baseURL, extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const back = await other.newPage();
	await back.goto( `/?fxc_recover=${ key }` );
	await expect( back ).toHaveURL( new RegExp( new URL( store.checkout ).pathname ) );
	await expect( back.getByText( store.simple.name ).first() ).toBeVisible();
	await expect( back.locator( '#fxc-billing-email' ) ).toHaveValue( email );
	await other.close();

	await page.getByRole( 'tab', { name: 'Carts' } ).click();
	const row = page.getByRole( 'row' ).filter( { hasText: email } );
	await expect( row ).toBeVisible();
	await expect( row.getByText( 'Open', { exact: true } ) ).toBeVisible();
	await expect( row.getByText( '1 reminder sent' ) ).toBeVisible();
	await expectAccessible( page, info, 'abandoned carts' );
} );
