import { expect, test, type Page } from '@playwright/test';
import { expectAccessible } from './store';

async function logInAsAdmin( page: Page ): Promise< void > {
	// The login screen moves the focus to the user name once loaded, which can catch a typed password.
	await expect( async () => {
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'bench' );
		await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin', { timeout: 1000 } );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 5000 } );
	} ).toPass( { timeout: 30_000 } );
}

// The designer is admin markup: the theme does not change it, so one project runs it.
test( 'the email designer adds a button, picks a block on the preview, saves and resets', async ( { page }, info ) => {
	test.skip( info.project.name !== 'tt5', 'admin screen, theme independent' );

	await logInAsAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=flexacommerce-settings&tab=emails' );
	// Headless Chromium stalls animation frames after a link click, so go to the link's address.
	const href = await page.getByRole( 'link', { name: 'Open the email designer' } ).getAttribute( 'href' );
	await page.goto( href ?? '' );
	await expect( page.getByRole( 'heading', { name: 'Email design' } ) ).toBeVisible();
	await expect( page.getByText( 'Default design', { exact: true } ) ).toBeVisible();

	const frame = page.frameLocator( 'iframe[title="Email preview"]' );
	await expect( frame.locator( '[data-fxc-block]' ).first() ).toBeVisible();
	// The preview frame has no scripts, so axe cannot run inside it; it shows the email, not the admin screen.
	await expectAccessible( page, info, 'email designer', [ 'iframe[title="Email preview"]' ] );

	// A button goes in above the footer, and the preview follows the text.
	await page.getByRole( 'button', { name: 'Add block' } ).click();
	await page.getByRole( 'button', { name: 'Button', exact: true } ).click();
	await page.getByLabel( 'Button text' ).fill( 'Shop again' );
	await expect( frame.getByText( 'Shop again' ) ).toBeVisible();

	// Clicking a block in the preview opens its settings.
	await frame.locator( '[data-fxc-block]' ).filter( { hasText: 'Shop again' } ).click();
	await expect( page.getByLabel( 'Button text' ) ).toHaveValue( 'Shop again' );
	await page.getByRole( 'button', { name: 'All blocks' } ).click();

	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.getByText( 'Email design saved.' ) ).toBeVisible();
	await expect( page.getByText( 'Customized', { exact: true } ) ).toBeVisible();

	await page.reload();
	await expect( frame.getByText( 'Shop again' ) ).toBeVisible();

	// Reset leaves the other specs a store with the default emails.
	await page.getByRole( 'button', { name: 'Reset to default' } ).click();
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Reset', exact: true } ).click();
	await expect( page.getByText( 'This email uses the default design again.' ) ).toBeVisible();
	await expect( page.getByText( 'Default design', { exact: true } ) ).toBeVisible();
	await expect( frame.getByText( 'Shop again' ) ).toHaveCount( 0 );
} );
