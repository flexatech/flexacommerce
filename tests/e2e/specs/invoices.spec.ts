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

/** Fetched in the page: e2e.bench resolves only inside the browser. */
async function fetchPdf( page: Page, url: string ): Promise< { status: number; type: string; disposition: string; head: string } > {
	return page.evaluate( async ( href ) => {
		const response = await fetch( href, { credentials: 'same-origin' } );
		const head = new Uint8Array( await response.arrayBuffer() ).subarray( 0, 5 );
		return {
			status: response.status,
			type: response.headers.get( 'content-type' ) ?? '',
			disposition: response.headers.get( 'content-disposition' ) ?? '',
			head: String.fromCharCode( ...head ),
		};
	}, url );
}

// Admin markup, theme independent: one project runs it.
test( 'invoice settings save and an order gets its invoice PDF and packing slip', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );
	test.setTimeout( 60_000 );

	// A guest order to invoice.
	await addSimple( page );
	await page.goto( store.checkout );
	await fillBilling( page, 'invoice@example.test' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	const orderId = new URL( page.url() ).searchParams.get( 'order-received' );
	expect( orderId ).toMatch( /^\d+$/ );

	// Cash on delivery orders are processing, so the guest gets the link.
	const link = page.locator( '.fxc-order__invoice' ).getByRole( 'link', { name: 'Download invoice (PDF)' } );
	await expect( link ).toHaveAttribute( 'href', new RegExp( `fxc_invoice=${ orderId }&key=` ) );
	expect( await fetchPdf( page, ( await link.getAttribute( 'href' ) ) as string ) ).toMatchObject( { status: 200, head: '%PDF-' } );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=invoices' );
	await expect( page.getByRole( 'heading', { name: 'Numbering' } ) ).toBeVisible();
	await expectAccessible( page, info, 'invoice settings' );

	const footer = page.getByLabel( 'Footer' );
	const before = await footer.inputValue();
	await footer.fill( 'Thank you for your order.' );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Invoice settings saved.' ) ).toBeVisible();
	await page.reload();
	await expect( page.getByLabel( 'Footer' ) ).toHaveValue( 'Thank you for your order.' );
	// Put it back for the next run.
	await page.getByLabel( 'Footer' ).fill( before );
	await page.getByRole( 'button', { name: 'Save changes' } ).click();
	await expect( page.getByText( 'Invoice settings saved.' ) ).toBeVisible();

	await page.goto( `/wp-admin/admin.php?page=flexacommerce-orders&order=${ orderId }` );
	const card = page.locator( 'section, div' ).filter( { has: page.getByRole( 'link', { name: 'Packing slip' } ) } ).last();
	await expect( card ).toBeVisible();
	// The guest's download gave the order its number.
	await expect( card.getByRole( 'button', { name: 'Assign number' } ) ).toHaveCount( 0 );
	await expect( page.getByTestId( 'invoice-number' ) ).not.toBeEmpty();
	const number = ( await page.getByTestId( 'invoice-number' ).textContent() ) ?? '';
	await expectAccessible( page, info, 'order with invoice' );

	for ( const name of [ 'Invoice PDF', 'Packing slip' ] ) {
		const href = ( await card.getByRole( 'link', { name } ).getAttribute( 'href' ) ) as string;
		const pdf = await fetchPdf( page, href );
		expect( pdf.status ).toBe( 200 );
		expect( pdf.type ).toContain( 'application/pdf' );
		expect( pdf.head ).toBe( '%PDF-' );
		if ( name === 'Invoice PDF' ) {
			expect( pdf.disposition ).toContain( `invoice-${ number }.pdf` );
		}
	}
} );
