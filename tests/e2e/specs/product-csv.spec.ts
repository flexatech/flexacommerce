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
test( 'products export to CSV and a CSV row imports as a new product', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/edit.php?post_type=fxc_product' );
	// Headless Chromium stops animation frames after a link click away from
	// the product list (core links too), so the spec follows the href.
	const exportLink = page.locator( '.wrap' ).getByRole( 'link', { name: 'Export', exact: true } );
	await expect( page.locator( '.wrap' ).getByRole( 'link', { name: 'Import', exact: true } ) ).toHaveAttribute( 'href', /page=flexacommerce-product-csv&tab=import$/ );
	await page.goto( ( await exportLink.getAttribute( 'href' ) ) as string );
	await expect( page.getByRole( 'heading', { name: 'Import and export products' } ) ).toBeVisible();
	await expectAccessible( page, info, 'product CSV export' );

	const saved = page.waitForEvent( 'download' );
	await page.getByRole( 'button', { name: 'Download CSV' } ).click();
	const download = await saved;
	expect( download.suggestedFilename() ).toMatch( /^products-\d{4}-\d{2}-\d{2}\.csv$/ );
	const text = await readFile( ( await download.path() ) as string, 'utf8' );
	expect( text.startsWith( '﻿ID,Type,SKU,Name,Published' ) ).toBe( true );

	// A draft, so the shop specs never see it.
	const sku = `E2E-CSV-${ Date.now() }`;
	await page.getByRole( 'tab', { name: 'Import' } ).click();
	await page.getByLabel( 'CSV file' ).setInputFiles( {
		name: 'one.csv',
		mimeType: 'text/csv',
		buffer: Buffer.from( `Type;SKU;Name;Published;Regular price;Upsells\nsimple;${ sku };CSV lamp;-1;12,50;\n` ),
	} );
	await expect( page.getByText( 'one.csv: 1 rows' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Continue' } ).click();

	await expect( page.getByLabel( 'Import SKU as' ) ).toHaveValue( 'sku' );
	await expect( page.getByLabel( 'Import Upsells as' ) ).toHaveValue( '' );
	await expectAccessible( page, info, 'product CSV mapping' );
	await page.getByRole( 'button', { name: 'Check the file' } ).click();

	await expect( page.getByText( 'New: 1. Updated: 0. Skipped: 0. With errors: 0.' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Import products' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Import finished' } ) ).toBeVisible();
	await expect( page.getByText( 'New: 1. Updated: 0. Skipped: 0. With errors: 0.' ) ).toBeVisible();

	// The same file again, with update off: the row is skipped.
	await page.getByRole( 'button', { name: 'Import another file' } ).click();
	await page.getByLabel( 'CSV file' ).setInputFiles( {
		name: 'one.csv',
		mimeType: 'text/csv',
		buffer: Buffer.from( `SKU,Name\n${ sku },Renamed\n` ),
	} );
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await page.getByRole( 'button', { name: 'Check the file' } ).click();
	await expect( page.getByText( 'New: 0. Updated: 0. Skipped: 1. With errors: 0.' ) ).toBeVisible();
	await expect( page.getByRole( 'cell', { name: 'A product with this SKU already exists.' } ) ).toBeVisible();
} );
