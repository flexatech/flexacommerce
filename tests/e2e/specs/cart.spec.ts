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
