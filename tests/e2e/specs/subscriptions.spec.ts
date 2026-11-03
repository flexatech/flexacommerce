import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, fillBilling, money, store } from './store';

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

const SCREEN = '/wp-admin/admin.php?page=flexacommerce-subscriptions';

test( 'the product page shows the plan', async ( { page }, info ) => {
	await page.goto( store.club.url );
	const price = page.locator( '.fxc-price' ).first();
	await expect( price ).toContainText( money( store.club.price ) );
	await expect( price.locator( '.fxc-price__period' ) ).toHaveText( '/ month' );
	await expect( page.locator( '.fxc-price__terms' ).first() ).toHaveText( `${ money( store.club.fee ) } sign-up fee` );
	await expectAccessible( page, info, 'subscription product' );
} );

// One project places the order; the checkout markup is checked by the other specs on every theme.
test( 'a guest subscribes and the admin manages the subscription', async ( { page, browser }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project places the order' );
	test.setTimeout( 90_000 );

	const email = `club-${ Date.now() }@example.test`;
	const guest = await browser.newContext( { extraHTTPHeaders: info.project.use.extraHTTPHeaders } );
	const shopper = await guest.newPage();
	await shopper.goto( store.club.url );
	const form = shopper.locator( 'form.fxc-add-to-cart--single' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();

	await shopper.goto( store.checkout );
	await fillBilling( shopper, email );
	// The first payment carries the sign-up fee; the flat rate ships it.
	await expect( shopper.locator( '[data-fxc-total="total"]' ) ).toHaveText( money( store.club.price + store.club.fee + 500 ) );
	await expectAccessible( shopper, info, 'checkout with a subscription' );
	await shopper.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await shopper.locator( '#fxc-terms' ).check();
	await shopper.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( shopper.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );
	await guest.close();

	await logInAsAdmin( page );
	await page.goto( SCREEN );
	await page.getByRole( 'searchbox', { name: 'Search subscriptions' } ).fill( email );
	const row = page.getByRole( 'row' ).filter( { hasText: email } );
	await expect( row ).toContainText( 'Active' );
	// Each renewal is the product and its shipping, without the sign-up fee.
	await expect( row ).toContainText( `${ money( store.club.price + 500 ) } / month` );
	await expectAccessible( page, info, 'subscriptions list' );
	await row.getByRole( 'button' ).first().click();

	const view = page.getByRole( 'dialog' );
	await expect( view.getByRole( 'row' ).filter( { hasText: 'Coffee club × 1' } ) ).toContainText( money( store.club.price ) );
	await expect( view.getByRole( 'row' ).filter( { hasText: 'Shipping' } ) ).toContainText( money( 500 ) );
	await expect( view.getByText( 'First order', { exact: true } ).first() ).toBeVisible();
	await expectAccessible( page, info, 'subscription dialog' );

	await view.getByRole( 'button', { name: 'Cancel at the end of the period' } ).click();
	await expect( page.getByText( 'The subscription ends after the period that is paid for.' ) ).toBeVisible();
	await expect( view.getByText( 'Ends soon' ).first() ).toBeVisible();

	await view.getByRole( 'button', { name: 'Reactivate' } ).click();
	await expect( page.getByText( 'Subscription reactivated.' ) ).toBeVisible();
	await expect( view.getByText( 'Active' ).first() ).toBeVisible();
	await view.getByRole( 'button', { name: 'Close' } ).first().click();
} );

test( 'the admin sets how many days ahead the renewal reminder goes out', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project changes the setting' );
	await logInAsAdmin( page );
	const open = async (): Promise< void > => {
		await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=emails' );
		await page.getByRole( 'button', { name: /Subscription renewal reminder/ } ).click();
	};
	const save = async ( days: string ): Promise< void > => {
		await page.getByLabel( 'Days before the renewal' ).fill( days );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect( page.getByText( 'Email settings saved.' ) ).toBeVisible();
	};
	await open();
	await expect( page.getByLabel( 'Days before the renewal' ) ).toHaveValue( '3' );
	await expectAccessible( page, info, 'renewal reminder settings' );
	await save( '5' );
	await open();
	await expect( page.getByLabel( 'Days before the renewal' ) ).toHaveValue( '5' );
	await save( '3' );
} );

test( 'the customer changes the quantity, pauses and resumes from the account', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project places the order' );
	test.setTimeout( 90_000 );
	await logInAsAdmin( page );
	await page.goto( store.club.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await page.goto( store.checkout );
	await fillBilling( page, 'admin@example.test' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );

	await page.goto( `${ store.account }?fxcm_subscriptions=1` );
	await page.locator( '.fxc-account__subscriptions' ).getByRole( 'link', { name: /Coffee club/ } ).first().click();
	await page.getByLabel( 'Coffee club', { exact: true } ).fill( '2' );
	await page.getByRole( 'button', { name: 'Update quantities' } ).click();
	// A new price is shown before it is saved.
	const review = page.getByRole( 'region', { name: 'Review your change' } );
	await expect( review ).toContainText( 'New renewal price' );
	await expectAccessible( page, info, 'subscription change review' );
	await review.getByRole( 'button', { name: 'Confirm the change' } ).click();
	await expect( page.getByText( 'Subscription updated. The next renewal bills the change.' ) ).toBeVisible();
	await expect( page.getByLabel( 'Coffee club', { exact: true } ) ).toHaveValue( '2' );
	await expectAccessible( page, info, 'subscription with change forms' );

	await page.getByRole( 'button', { name: 'Pause subscription' } ).click();
	await expect( page.getByText( 'Your subscription is paused.' ).first() ).toBeVisible();
	await page.getByRole( 'button', { name: 'Resume subscription' } ).click();
	await expect( page.getByText( 'Your subscription is active again.' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Pause subscription' } ) ).toBeVisible();
} );

test( 'the customer switches to another size and pays the difference', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'one project places the order' );
	test.setTimeout( 90_000 );
	await logInAsAdmin( page );
	await page.goto( store.box.url );
	const form = page.locator( 'form.fxc-add-to-cart--single' );
	await form.getByLabel( 'Size', { exact: false } ).selectOption( { label: 'Small' } );
	await form.getByRole( 'button', { name: 'Add to cart' } ).click();
	await expect( form.locator( '.fxc-add-to-cart__message' ) ).not.toBeEmpty();
	await page.goto( store.checkout );
	await fillBilling( page, 'admin@example.test' );
	await page.locator( '#fxc-payment-method' ).getByLabel( /cash on delivery/i ).check();
	await page.locator( '#fxc-terms' ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect( page.locator( '.fxc-order' ) ).toBeVisible( { timeout: 15_000 } );

	await page.goto( `${ store.account }?fxcm_subscriptions=1` );
	await page.locator( '.fxc-account__subscriptions' ).getByRole( 'link', { name: /Coffee box/ } ).first().click();
	const plan = page.getByRole( 'group', { name: 'Switch plan' } );
	await plan.getByRole( 'combobox' ).selectOption( { label: `Large: ${ money( store.box.large ) } / month` } );
	await expectAccessible( page, info, 'subscription switch form' );
	await page.getByRole( 'button', { name: 'Review the switch' } ).click();
	const review = page.getByRole( 'region', { name: 'Review your switch' } );
	await expect( review ).toContainText( 'New renewal price' );
	await expect( review ).toContainText( 'To pay now' );
	await expectAccessible( page, info, 'subscription switch review' );
	await review.getByRole( 'button', { name: 'Confirm the switch' } ).click();
	// Cash on delivery saves no card, so the difference is paid on the order's pay page.
	await expect( page.locator( '.fxc-order__pay-form' ) ).toBeVisible( { timeout: 15_000 } );

	await page.goto( `${ store.account }?fxcm_subscriptions=1` );
	await page.locator( '.fxc-account__subscriptions' ).getByRole( 'link', { name: /Coffee box/ } ).first().click();
	await expect( page.getByRole( 'link', { name: 'Pay for the switch' } ) ).toBeVisible();
	await expect( page.getByRole( 'table', { name: 'Related orders' } ) ).toContainText( 'Plan switch' );
} );
