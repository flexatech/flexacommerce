import { expect, test } from '@playwright/test';
import { expectAccessible, money, store } from './store';

test( 'simple product: add to cart updates the mini cart and the cookies', async ( { page, context }, info ) => {
	await page.goto( store.simple.url );
	await expect( page.getByRole( 'heading', { level: 1, name: store.simple.name } ) ).toBeVisible();
	await expectAccessible( page, info, 'simple product' );

	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await form.locator( '.fxc-add-to-cart__quantity' ).fill( '2' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	// The script adds through the store API; the page stays where it is.
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await expect( page ).toHaveURL( store.simple.url );
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).toHaveText( /2/ );

	const cookies = Object.fromEntries( ( await context.cookies() ).map( ( c ) => [ c.name, c ] ) );
	expect( cookies.fxc_session?.httpOnly ).toBe( true );
	expect( cookies.fxc_cart_count?.value ).toBe( '2' );
	await expectAccessible( page, info, 'simple product after add' );
} );

test( 'variable product: choosing options shows the variant price', async ( { page }, info ) => {
	await page.goto( store.variable.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	const button = form.getByRole( 'button', { name: 'Add to cart' } );
	await expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
	await expect( form.locator( '.fxc-add-to-cart__variant' ) ).toBeHidden();

	for ( const [ attribute, value ] of Object.entries( store.variable.choices ) ) {
		await form.getByLabel( attribute, { exact: false } ).selectOption( { label: value } );
	}
	await expect( form.locator( '.fxc-add-to-cart__variant .fxc-price__amount' ) ).toHaveText( money( store.variable.price ) );
	await expect( button ).not.toHaveAttribute( 'aria-disabled', 'true' );
	await expectAccessible( page, info, 'variable product' );

	await button.click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).toHaveText( /1/ );
} );

test( 'variable product: adding without a choice asks for the option', async ( { page } ) => {
	await page.goto( store.variable.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	// aria-disabled, not disabled: the button stays focusable, and the
	// required selects stop the submit with the browser's own message.
	await form.getByRole( 'button', { name: 'Add to cart' } ).click( { force: true } );
	const first = form.locator( 'select[data-attribute]' ).first();
	expect( await first.evaluate( ( select: HTMLSelectElement ) => select.validationMessage ) ).not.toBe( '' );
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).not.toHaveText( /[1-9]/ );
	expect( ( await page.context().cookies() ).find( ( c ) => c.name === 'fxc_cart_count' )?.value ?? '0' ).toBe( '0' );
} );

test( 'option formula: the price follows what the customer types', async ( { page }, info ) => {
	await page.goto( store.formula.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	const total = form.locator( '.fxc-options__total' );
	await expect( total ).toBeHidden();

	// 120 x 50 cm at 25.00 a square metre, then 0.50 a letter (spaces are free).
	await form.getByLabel( 'Width (cm)' ).fill( '120' );
	await form.getByLabel( 'Height (cm)' ).fill( '50' );
	await expect( total ).toHaveText( 'Options: +' + money( 1500 ) );
	await form.getByLabel( 'Lettering' ).fill( 'OPEN LATE' );
	await expect( total ).toHaveText( 'Options: +' + money( 1900 ) );
	await expectAccessible( page, info, 'option formula' );

	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await page.goto( store.cart );
	await expect( page.locator( '.fxc-cart' ).getByText( money( store.formula.price + 1900 ) ).first() ).toBeVisible();
} );
