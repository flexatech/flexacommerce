import { expect, test } from '@playwright/test';
import { expectAccessible, money, store } from './store';

test( 'grouped product: two products go to the cart in one click', async ( { page }, info ) => {
	await page.goto( store.grouped.url );
	const form = page.locator( 'form.fxc-add-to-cart--group' );
	await expect( form.locator( 'table.fxc-group tbody tr' ) ).toHaveCount( 2 );
	await expectAccessible( page, info, 'grouped product' );

	// Nothing picked: the form says so and sends nothing.
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).not.toHaveText( /[1-9]/ );

	await form.getByLabel( `Quantity of ${ store.simple.name }` ).fill( '2' );
	await form.getByLabel( `Quantity of ${ store.grouped.mug }` ).fill( '1' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( page.locator( '.fxc-mini-cart__count' ).first() ).toHaveText( /3/ );

	await page.goto( store.cart );
	await expect( page.getByText( money( store.simple.price * 2 + 400 ) ).first() ).toBeVisible();
} );

test( 'external product: the button opens the partner page', async ( { page }, info ) => {
	await page.goto( store.external.url );
	const link = page.getByRole( 'link', { name: store.external.text } );
	await expect( link ).toHaveAttribute( 'href', store.external.link );
	await expect( link ).toHaveAttribute( 'rel', /sponsored/ );
	await expect( page.locator( 'form.fxc-add-to-cart' ) ).toHaveCount( 0 );
	await expectAccessible( page, info, 'external product' );
} );
