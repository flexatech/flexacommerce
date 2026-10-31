import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, money, store } from './store';

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

async function addProduct( page: Page, search: string, name: string ): Promise< void > {
	const box = page.getByRole( 'combobox', { name: 'Add a product' } );
	await box.fill( search );
	await page.getByRole( 'option', { name: new RegExp( name ) } ).first().click();
}

// Admin markup, theme independent: one project runs it.
test( 'the admin creates an order for a guest, edits it, then changes its addresses', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );
	test.setTimeout( 90_000 );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-orders' );
	await page.getByRole( 'link', { name: 'Create order' } ).click();
	await expect( page.getByRole( 'heading', { name: 'New order' } ) ).toBeVisible();

	await addProduct( page, store.simple.sku, store.simple.name );
	await expect( page.getByLabel( 'Quantity of line 1' ) ).toHaveValue( '1' );

	await page.locator( '#fxc-billing-first_name' ).fill( 'Grace' );
	await page.locator( '#fxc-billing-last_name' ).fill( 'Hopper' );
	await page.locator( '#fxc-billing-address_1' ).fill( '1 Navy Way' );
	await page.locator( '#fxc-billing-city' ).fill( 'Arlington' );
	await page.locator( '#fxc-billing-postcode' ).fill( '22201' );
	await page.locator( '#fxc-billing-state' ).fill( 'VA' );
	await page.locator( '#fxc-billing-country' ).selectOption( 'US' );
	await page.locator( '#fxc-billing-email' ).fill( 'grace@example.test' );

	const summary = page.getByTestId( 'order-editor-summary' );
	await expect( summary.getByText( money( store.simple.price ) ).first() ).toBeVisible( { timeout: 10_000 } );
	await expectAccessible( page, info, 'new order' );

	const create = page.getByRole( 'button', { name: 'Create order' } );
	await expect( create ).toBeEnabled( { timeout: 10_000 } );
	await create.click();
	await expect( page.getByRole( 'heading', { name: /^Order #/ } ) ).toBeVisible( { timeout: 15_000 } );
	await expect( page.getByText( 'Order created by the admin.' ) ).toBeVisible();
	await expect( page.getByText( 'Order details sent to the customer.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Copy payment link' } ) ).toBeVisible();
	const orderId = new URL( page.url() ).searchParams.get( 'order' );
	expect( orderId ).toMatch( /^\d+$/ );

	// Two of the same now: the lines and the totals are worked out again.
	await page.getByRole( 'button', { name: 'Edit order' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Edit order' } ) ).toBeVisible();
	await expect( page.getByLabel( 'Quantity of line 1' ) ).toHaveValue( '1' );
	await expect( page.locator( '#fxc-billing-email' ) ).toHaveValue( 'grace@example.test' );
	await page.getByLabel( 'Quantity of line 1' ).fill( '2' );
	await expect( summary.getByText( money( store.simple.price * 2 ) ).first() ).toBeVisible( { timeout: 10_000 } );
	const save = page.getByRole( 'button', { name: 'Save changes' } );
	await expect( save ).toBeEnabled( { timeout: 10_000 } );
	await save.click();
	await expect( page.getByText( 'Order edited by the admin.' ) ).toBeVisible( { timeout: 15_000 } );
	expect( new URL( page.url() ).searchParams.get( 'edit' ) ).toBeNull();

	// Addresses change without touching the totals.
	await page.getByRole( 'button', { name: 'Edit addresses' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Edit addresses' } );
	await expect( dialog.locator( '#fxc-billing-city' ) ).toHaveValue( 'Arlington' );
	await dialog.locator( '#fxc-billing-city' ).fill( 'Alexandria' );
	await expectAccessible( page, info, 'edit addresses' );
	await dialog.getByRole( 'button', { name: 'Save addresses' } ).click();
	await expect( dialog ).toBeHidden();
	await expect( page.getByText( /22201 Alexandria/ ) ).toBeVisible();
	await expect( page.getByText( 'Addresses changed by the admin.' ) ).toBeVisible();

	// A deep link to the editor opens it again.
	await page.goto( `/wp-admin/admin.php?page=flexacommerce-orders&order=${ orderId }&edit=1` );
	await expect( page.getByLabel( 'Quantity of line 1' ) ).toHaveValue( '2' );
} );
