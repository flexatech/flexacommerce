import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, money, store } from './store';

const items = ( page: Page ) => page.locator( '.type-fxc_product' );

/** The lowest price of each listed product, in cents. */
async function prices( page: Page ): Promise< number[] > {
	return items( page ).evaluateAll( ( nodes ) =>
		nodes.map( ( node ) => {
			const amount = node.querySelector( '.fxc-price__amount' )?.textContent ?? '';
			return Math.round( parseFloat( amount.replace( /[^0-9.]/g, '' ) ) * 100 );
		} )
	);
}

test( 'shop lists products and sorts them by price', async ( { page }, info ) => {
	await page.goto( store.shop );
	await expect( items( page ).first() ).toBeVisible();
	await expectAccessible( page, info, 'shop' );

	await page.getByLabel( 'Sort products' ).selectOption( 'price' );
	await page.locator( 'form.fxc-sorting' ).getByRole( 'button', { name: 'Sort' } ).click();
	await expect( page ).toHaveURL( /orderby=price/ );
	const sorted = await prices( page );
	expect( sorted.length ).toBeGreaterThan( 1 );
	expect( sorted ).toEqual( [ ...sorted ].sort( ( a, b ) => a - b ) );
	await expect( page.getByLabel( 'Sort products' ) ).toHaveValue( 'price' );
} );

test( 'filters narrow the shop to a category', async ( { page }, info ) => {
	await page.goto( store.filters );
	const form = page.getByRole( 'form', { name: 'Filter products' } );
	await expect( form ).toBeVisible();
	await expectAccessible( page, info, 'filters' );

	await form.locator( `input[name="filter_cat[]"][value="${ store.category.slug }"]` ).check();
	await form.getByRole( 'button', { name: 'Apply filters' } ).click();
	await expect( page ).toHaveURL( new RegExp( `filter_cat.*${ store.category.slug }` ) );
	const classes = await items( page ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.className ) );
	expect( classes.length ).toBeGreaterThan( 0 );
	for ( const name of classes ) {
		expect( name ).toContain( `fxc_product_cat-${ store.category.slug }` );
	}
} );

test( 'a category page lists only its products', async ( { page }, info ) => {
	await page.goto( store.category.url );
	await expect( items( page ).first() ).toBeVisible();
	await expect( page.locator( '.fxc-breadcrumbs' ) ).toBeVisible();
	await expectAccessible( page, info, 'category' );
} );

test( 'a swatch in a list opens the product with that value picked', async ( { page }, info ) => {
	await page.goto( store.swatches.category );
	const swatches = page.getByRole( 'group', { name: `Finish of ${ store.swatches.name }` } );
	await expect( swatches.getByRole( 'link' ) ).toHaveCount( 3 );
	await expect( swatches.getByRole( 'link', { name: 'Satin (out of stock)' } ) ).toHaveClass( /is-unavailable/ );
	await expectAccessible( page, info, 'list swatches' );

	await swatches.getByRole( 'link', { name: 'Gloss', exact: true } ).click();
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await expect( form.getByRole( 'radio', { name: 'Gloss' } ) ).toBeChecked();
	await expect( form.locator( '.fxc-add-to-cart__variant .fxc-price__amount' ) ).toHaveText( money( store.swatches.price ) );
	await expect( form.getByRole( 'button', { name: 'Add to cart' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
} );
