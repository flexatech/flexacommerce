import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, type TestInfo } from '@playwright/test';

/** What tools/bench/e2e-prep.php wrote for this store. */
type Store = {
	shop: string;
	cart: string;
	checkout: string;
	account: string;
	filters: string;
	customer: { login: string; password: string };
	simple: { id: number; name: string; url: string; sku: string; price: number };
	variable: { id: number; name: string; url: string; choices: Record< string, string >; price: number };
	swatches: { name: string; url: string; category: string; price: number };
	category: { slug: string; url: string };
};

export const store: Store = JSON.parse(
	readFileSync( fileURLToPath( new URL( '../../../tools/bench/out/e2e.json', import.meta.url ) ), 'utf8' )
);

/** The store's prices in cents as the storefront prints them (USD, en_US). */
export const money = ( cents: number ): string =>
	'$' + ( cents / 100 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );

const OURS = '[class*="fxc-"], [class*="wp-block-flexacommerce"]';

/**
 * Runs axe (WCAG 2.1 A and AA) on the page. A violation inside FlexaCommerce
 * markup fails the test; one in the theme is attached to the report, since
 * the theme is not ours to fix.
 */
export async function expectAccessible( page: Page, info: TestInfo, label: string ): Promise< void > {
	const results = await new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] ).analyze();
	const ours: string[] = [];
	const theirs: string[] = [];
	for ( const violation of results.violations ) {
		for ( const node of violation.nodes ) {
			const target = node.target.map( String ).join( ' ' );
			const inside = await page.evaluate(
				( [ selector, ours ] ) => {
					const element = document.querySelector( selector );
					return element !== null && element.closest( ours ) !== null;
				},
				[ target, OURS ] as const
			);
			( inside ? ours : theirs ).push( `${ violation.id } (${ violation.impact }): ${ target }\n  ${ node.failureSummary ?? '' }` );
		}
	}
	if ( theirs.length > 0 ) {
		await info.attach( `axe-theme-${ label }`, { body: theirs.join( '\n' ), contentType: 'text/plain' } );
	}
	expect( ours, `axe violations in FlexaCommerce markup on ${ label }` ).toEqual( [] );
}

/** Adds the simple product through its product page. */
export async function addSimple( page: Page, quantity = 1 ): Promise< void > {
	await page.goto( store.simple.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await form.locator( '.fxc-add-to-cart__quantity' ).fill( String( quantity ) );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
}

/** Fills the billing address with a US address the flat rate ships to. */
export async function fillBilling( page: Page, email = 'guest@example.test' ): Promise< void > {
	const field = ( id: string ) => page.locator( `#fxc-billing-${ id }` );
	await field( 'email' ).fill( email );
	await field( 'first_name' ).fill( 'Alex' );
	await field( 'last_name' ).fill( 'Rivera' );
	await field( 'country' ).selectOption( 'US' );
	await field( 'address_1' ).fill( '100 Main Street' );
	await field( 'city' ).fill( 'Portland' );
	await field( 'state' ).selectOption( 'OR' );
	await field( 'postcode' ).fill( '97201' );
}
