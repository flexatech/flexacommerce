import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, store } from './store';

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

type Restock = { sent: { email: string; key: string }[]; subjects: string[] };

/** Restocks the product and runs the cron (tools/bench/mu-plugins/e2e.php), in the page. */
async function restock( page: Page, id: number ): Promise< Restock > {
	return page.evaluate( async ( product ) => {
		const response = await fetch( '/', { headers: { 'X-FXC-E2E-Restock': String( product ) } } );
		return ( await response.json() ) as Restock;
	}, id );
}

test.describe( 'scripts off', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'a guest asks for an email on a sold-out product', async ( { page }, info ) => {
		await page.goto( store.soldout.url );
		await expect( page.getByText( 'Out of stock', { exact: true } ) ).toBeVisible();
		const form = page.locator( 'form.fxc-stock-alert' );
		await expect( form.getByText( 'Email me when it is back in stock' ) ).toBeVisible();
		await form.getByLabel( 'Email address' ).fill( `${ info.project.name }-vase@example.test` );
		await form.getByRole( 'button', { name: 'Notify me' } ).click();
		await expect( page.getByText( 'Thanks. We will email you when it is back in stock.' ) ).toBeVisible();
	} );
} );

test( 'a guest asks for an email on a variation that ran out', async ( { page }, info ) => {
	await page.goto( store.swatches.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	const alert = form.locator( '.fxc-stock-alert--inline' );
	await expect( alert ).toBeHidden();
	await form.getByRole( 'radio', { name: /Satin/ } ).check( { force: true } );
	await expect( alert ).toBeVisible();
	await expectAccessible( page, info, 'stock alert on a variation' );

	await alert.getByLabel( 'Email address' ).fill( `${ info.project.name }-satin@example.test` );
	await alert.getByRole( 'button', { name: 'Notify me' } ).click();
	await expect( alert.getByRole( 'status' ) ).toHaveText( 'Thanks. We will email you when it is back in stock.' );

	// A variation in stock hides the block again.
	await form.getByRole( 'radio', { name: 'Gloss' } ).check( { force: true } );
	await expect( alert ).toBeHidden();
} );

test( 'the restock sends one email, and its link stops the emails', async ( { page }, info ) => {
	const email = `${ info.project.name }-vase@example.test`;
	await page.goto( store.soldout.url );
	const result = await restock( page, store.soldout.id );
	const mine = result.sent.find( ( row ) => row.email === email );
	expect( mine?.key ).toMatch( /^\d+\.[a-f0-9]{32}$/ );
	expect( result.subjects ).toContain( `${ store.soldout.name } is back in stock` );

	// Sent rows are not sent again.
	const again = await restock( page, store.soldout.id );
	expect( again.subjects ).not.toContain( `${ store.soldout.name } is back in stock` );

	await page.goto( `/?fxc_stock_stop=${ mine?.key }` );
	await expect( page.getByText( 'Stop the emails about products you asked to hear about when they are back in stock?' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Stop back-in-stock emails' } ).click();
	await expect( page.getByText( 'You will get no more back-in-stock emails for the products you asked about.' ) ).toBeVisible();
} );

// Admin markup, theme independent: one project runs it.
test( 'the admin sees who waits for what', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );
	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-stock-alerts' );
	await expect( page.getByRole( 'heading', { name: 'Stock alerts' } ) ).toBeVisible();
	await expect( page.getByText( 'Most wanted' ) ).toBeVisible();
	const satin = page.getByRole( 'row' ).filter( { hasText: 'tt5-satin@example.test' } );
	await expect( satin ).toBeVisible();
	await expect( satin.getByText( 'Waiting' ) ).toBeVisible();
	await expect( page.getByRole( 'row' ).filter( { hasText: 'tt5-vase@example.test' } ).getByText( 'Emailed' ) ).toBeVisible();
	await expectAccessible( page, info, 'stock alerts' );

	await page.getByRole( 'tab', { name: 'Settings' } ).click();
	await expect( page.getByLabel( 'Show the signup form' ) ).toBeChecked();
	await expectAccessible( page, info, 'stock alert settings' );
} );
