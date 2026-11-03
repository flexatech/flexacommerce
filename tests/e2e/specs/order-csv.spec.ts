import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';
import { expectAccessible } from './store';

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

// Admin markup, theme independent: one project runs it.
test( 'the order list exports to CSV in both layouts and keeps its date filter', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-orders' );
	await page.getByRole( 'button', { name: 'Export CSV' } ).click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog.getByText( 'The file has every order.', { exact: false } ) ).toBeVisible();
	await expectAccessible( page, info, 'order export dialog' );

	let saved = page.waitForEvent( 'download' );
	await dialog.getByRole( 'button', { name: 'Download CSV' } ).click();
	let download = await saved;
	expect( download.suggestedFilename() ).toMatch( /^orders-\d{4}-\d{2}-\d{2}\.csv$/ );
	let text = await readFile( ( await download.path() ) as string, 'utf8' );
	expect( text.startsWith( '﻿Order number,Date,Status,Email,Currency,Payment status' ) ).toBe( true );
	await expect( dialog ).toBeHidden();

	// A day no order has: the file is only its header (the first toast may say the same on an empty bench).
	await page.getByRole( 'button', { name: /^Orders from/ } ).click();
	const calendar = page.getByRole( 'dialog', { name: 'Choose date' } );
	await calendar.getByRole( 'button', { name: 'Next month' } ).click();
	await calendar.getByRole( 'gridcell' ).first().click();
	await expect( calendar ).toBeHidden();
	await page.getByRole( 'button', { name: 'Export CSV' } ).click();
	await expect( dialog.getByText( 'match the tab, search and dates', { exact: false } ) ).toBeVisible();
	await dialog.getByLabel( 'One row per product' ).check();
	saved = page.waitForEvent( 'download' );
	await dialog.getByRole( 'button', { name: 'Download CSV' } ).click();
	download = await saved;
	await expect( page.getByText( 'No orders match, so the file has only its header.' ).last() ).toBeVisible();
	expect( download.suggestedFilename() ).toMatch( /^order-items-\d{4}-\d{2}-\d{2}\.csv$/ );
	text = await readFile( ( await download.path() ) as string, 'utf8' );
	expect( text ).toBe( '﻿Order number,Date,Status,Email,Currency,Product,SKU,Details,Quantity,Unit price,Line subtotal,Line discount,Line tax,Line total,Refunded quantity,Refunded amount\n' );
} );
