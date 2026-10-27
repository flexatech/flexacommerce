import { expect, test } from '@playwright/test';
import { addSimple, expectAccessible, money, store } from './store';

test( 'cart: change the quantity, then remove the line', async ( { page }, info ) => {
	await addSimple( page );
	await page.goto( store.cart );
	const line = page.locator( '.fxc-cart__line' );
	await expect( line ).toHaveCount( 1 );
	await expect( line ).toContainText( store.simple.name );
	await expectAccessible( page, info, 'cart' );

	await line.getByLabel( `Quantity of ${ store.simple.name }` ).fill( '3' );
	await page.getByRole( 'button', { name: 'Update cart' } ).click();
	await expect( line.getByLabel( `Quantity of ${ store.simple.name }` ) ).toHaveValue( '3' );
	await expect( page.locator( '.fxc-cart__total' ).first() ).toContainText( money( store.simple.price * 3 ) );
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).toHaveText( /3/ );

	await line.getByRole( 'button', { name: `Remove ${ store.simple.name } from the cart` } ).click();
	await expect( page.getByText( 'Your cart is empty.' ) ).toBeVisible();
	await expectAccessible( page, info, 'empty cart' );
} );

test( 'cart: Enter in the quantity field updates the line', async ( { page } ) => {
	await addSimple( page );
	await page.goto( store.cart );
	const quantity = page.locator( '.fxc-cart__line' ).getByLabel( `Quantity of ${ store.simple.name }` );
	await quantity.fill( '2' );
	await quantity.press( 'Enter' );
	await expect( page.locator( '.fxc-cart__total' ).first() ).toContainText( money( store.simple.price * 2 ) );
	await page.reload();
	await expect( page.locator( '.fxc-cart__line' ) ).toHaveCount( 1 );
	await expect( quantity ).toHaveValue( '2' );
} );

test( 'cart: edit a line on its product page', async ( { page }, info ) => {
	await page.goto( store.variable.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	for ( const [ attribute, value ] of Object.entries( store.variable.choices ) ) {
		await form.getByLabel( attribute, { exact: false } ).selectOption( { label: value } );
	}
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();

	await page.goto( store.cart );
	await page.getByRole( 'link', { name: `Change the options of ${ store.variable.name }` } ).click();
	const edit = page.locator( 'form.fxc-add-to-cart--edit' );
	for ( const [ attribute, value ] of Object.entries( store.variable.choices ) ) {
		await expect( edit.getByLabel( attribute, { exact: false } ).locator( 'option:checked' ) ).toHaveText( value );
	}
	await expect( edit.locator( '.fxc-add-to-cart__variant .fxc-price__amount' ) ).toHaveText( money( store.variable.price ) );
	await expectAccessible( page, info, 'edit a cart line' );

	await edit.locator( '.fxc-add-to-cart__quantity' ).fill( '2' );
	await edit.getByRole( 'button', { name: 'Update cart' } ).click();
	await expect( page.getByText( 'Cart updated.' ) ).toBeVisible();
	const line = page.locator( '.fxc-cart__line' );
	await expect( line ).toHaveCount( 1 );
	await expect( line.getByLabel( `Quantity of ${ store.variable.name }` ) ).toHaveValue( '2' );
} );
