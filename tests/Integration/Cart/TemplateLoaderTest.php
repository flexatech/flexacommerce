<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Storefront\TemplateLoader;

/**
 * Classic theme templates: the plugin's own, a theme copy that wins, the
 * outdated-copy check, and the rendered pages.
 */
final class TemplateLoaderTest extends CartTestCase {
	private string $theme = '';

	public function set_up(): void {
		parent::set_up();
		$this->theme = sys_get_temp_dir() . '/fxc-theme-' . $this->run;
		wp_mkdir_p( $this->theme . '/flexacommerce/emails' );
		file_put_contents( $this->theme . '/header.php', "<header>head</header>\n" );
		file_put_contents( $this->theme . '/footer.php', "<footer>foot</footer>\n" );
		file_put_contents( $this->theme . '/sidebar.php', '' );
		add_filter( 'stylesheet_directory', [ $this, 'theme_dir' ] );
		add_filter( 'template_directory', [ $this, 'theme_dir' ] );
		// locate_template() reads the paths from globals set at load.
		wp_set_template_globals();
	}

	public function tear_down(): void {
		remove_filter( 'stylesheet_directory', [ $this, 'theme_dir' ] );
		remove_filter( 'template_directory', [ $this, 'theme_dir' ] );
		wp_set_template_globals();
		foreach ( [ 'flexacommerce/emails/layout.php', 'flexacommerce/emails/unknown.php', 'flexacommerce/single-product.php', 'flexacommerce/archive-product.php', 'header.php', 'footer.php', 'sidebar.php' ] as $file ) {
			if ( is_file( $this->theme . '/' . $file ) ) {
				unlink( $this->theme . '/' . $file );
			}
		}
		foreach ( [ '/flexacommerce/emails', '/flexacommerce', '' ] as $dir ) {
			if ( is_dir( $this->theme . $dir ) ) {
				rmdir( $this->theme . $dir );
			}
		}
		parent::tear_down();
	}

	public function theme_dir(): string {
		return $this->theme;
	}

	public function test_product_pages_use_our_template_until_the_theme_has_a_copy(): void {
		$id = $this->simple();
		$this->go_to( (string) get_permalink( $id ) );
		self::assertSame( FLEXACOMMERCE_PATH . 'templates/single-product.php', TemplateLoader::include( 'theme-single.php' ) );

		file_put_contents( $this->theme . '/flexacommerce/single-product.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		self::assertSame( $this->theme . '/flexacommerce/single-product.php', TemplateLoader::include( 'theme-single.php' ) );

		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		self::assertSame( FLEXACOMMERCE_PATH . 'templates/archive-product.php', TemplateLoader::include( 'theme-archive.php' ) );

		$this->go_to( home_url( '/' ) );
		self::assertSame( 'theme-index.php', TemplateLoader::include( 'theme-index.php' ) );
	}

	public function test_outdated_copies_are_reported(): void {
		file_put_contents( $this->theme . '/flexacommerce/single-product.php', "<?php\n/**\n * @version 0.9.0\n */\n" );
		file_put_contents( $this->theme . '/flexacommerce/archive-product.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		file_put_contents( $this->theme . '/flexacommerce/emails/layout.php', "<?php\n// no version\n" );
		file_put_contents( $this->theme . '/flexacommerce/emails/unknown.php', "<?php\n" );

		self::assertSame(
			[
				[
					'file'   => 'flexacommerce/emails/layout.php',
					'theme'  => '',
					'plugin' => '1.0.0',
				],
				[
					'file'   => 'flexacommerce/single-product.php',
					'theme'  => '0.9.0',
					'plugin' => '1.0.0',
				],
			],
			TemplateLoader::outdated()
		);
	}

	public function test_every_template_has_a_version(): void {
		foreach ( [ TemplateLoader::SINGLE, TemplateLoader::ARCHIVE ] as $name ) {
			self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', TemplateLoader::version( FLEXACOMMERCE_PATH . 'templates/' . $name ), $name );
		}
		foreach ( array_merge( (array) glob( FLEXACOMMERCE_PATH . 'templates/emails/*.php' ), (array) glob( FLEXACOMMERCE_PATH . 'templates/emails/parts/*.php' ) ) as $file ) {
			self::assertNotSame( '', TemplateLoader::version( (string) $file ), (string) $file );
		}
	}

	public function test_the_product_template_renders(): void {
		$id  = $this->simple( 1999, 4 );
		$sku = $this->sku( 'CLS' );
		$this->save(
			$id,
			[
				'base' => [
					'sku'    => $sku,
					'weight' => '500',
				],
			]
		);
		$this->go_to( (string) get_permalink( $id ) );
		$html = $this->render( TemplateLoader::SINGLE );
		self::assertStringContainsString( '<header>head</header>', $html );
		self::assertStringContainsString( '<div id="primary" class="content-area fxc-classic"><main id="main" class="site-main">', $html );
		self::assertStringContainsString( 'class="fxc-breadcrumbs"', $html );
		self::assertStringContainsString( '<h1 class="fxc-product__title entry-title">Test product</h1>', $html );
		self::assertStringContainsString( '19.99', $html );
		self::assertStringContainsString( 'name="product_id" value="' . $id . '"', $html );
		self::assertStringContainsString( esc_html( $sku ), $html );
		self::assertStringContainsString( '<td>0.5 kg</td>', $html );
		self::assertStringContainsString( '</main></div>', $html );
		self::assertTrue( wp_style_is( 'flexacommerce-storefront', 'enqueued' ) || wp_style_is( 'flexacommerce-storefront', 'done' ) );
	}

	public function test_the_archive_template_lists_products_with_cards(): void {
		$id = $this->simple( 750 );
		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		$wrapper = static fn (): array => [ '<section class="custom">', '</section>' ];
		add_filter( 'flexacommerce.template_wrapper', $wrapper );
		$html    = $this->render( TemplateLoader::ARCHIVE );
		remove_filter( 'flexacommerce.template_wrapper', $wrapper );
		self::assertStringContainsString( '<section class="custom">', $html );
		self::assertStringNotContainsString( 'id="primary"', $html );
		self::assertStringContainsString( '<form class="fxc-sorting', $html );
		self::assertStringContainsString( '<ul class="fxc-products">', $html );
		self::assertStringContainsString( 'post-' . $id, $html );
		self::assertStringContainsString( '7.50', $html );
	}

	public function test_a_theme_without_a_sidebar_gets_none(): void {
		$this->simple();
		file_put_contents( $this->theme . '/sidebar.php', "<aside>side</aside>\n" );
		$this->go_to( add_query_arg( 'post_type', PostTypes::PRODUCT, home_url( '/' ) ) );
		self::assertStringContainsString( '<aside>side</aside>', $this->render( TemplateLoader::ARCHIVE ) );

		// Core's theme-compat sidebar.php is deprecated (an unexpected
		// deprecation fails the test) and its list markup fails axe.
		unlink( $this->theme . '/sidebar.php' );
		$html = $this->render( TemplateLoader::ARCHIVE );
		self::assertStringNotContainsString( 'id="sidebar"', $html );
		self::assertStringNotContainsString( '<aside>side</aside>', $html );
	}

	public function test_parts(): void {
		$id = $this->simple( 1234 );
		self::assertStringContainsString( '12.34', Storefront::instance()->part( 'card', $id ) );
		self::assertStringContainsString( 'name="product_id" value="' . $id . '"', Storefront::instance()->part( 'card', $id ) );
		self::assertSame( '', Storefront::instance()->part( 'no-such-block', $id ) );
		self::assertSame( '', Storefront::instance()->part( 'card', 999999 ) );
	}

	private function render( string $name ): string {
		$file = TemplateLoader::include( 'theme.php' );
		self::assertSame( FLEXACOMMERCE_PATH . 'templates/' . $name, $file );
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
