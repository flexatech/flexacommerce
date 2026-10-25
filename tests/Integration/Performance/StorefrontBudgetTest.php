<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Performance;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Storefront\Templates;
use Flexa\Commerce\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 T1 budgets (doc 10 §4.1): catalog pages and the store API, each
 * request in a fresh process. Catalog pages are compared with the same URL
 * where `fxc_product` is a plain post type, so the difference is what
 * FlexaCommerce adds to a core archive or single post of the same posts.
 * The store routes are compared with the same URL without the plugin
 * (a 404 from the REST server), which leaves the cost of the route itself.
 */
final class StorefrontBudgetTest extends TestCase {
	/** @var list<int> */
	private static array $posts = [];

	/** @var list<int> */
	private static array $listed = [];

	private static int $simple = 0;

	/** @var array<int,int> variant count => product id */
	private static array $variable = [];

	/** @var array<int,string> line count => session token */
	private static array $carts = [];

	/** @var array<string,mixed> */
	private static array $budgets = [];

	public static function setUpBeforeClass(): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		self::$budgets = require __DIR__ . '/budgets.php';
		wp_set_current_user( 0 );

		for ( $i = 1; $i <= 48; $i++ ) {
			self::$listed[] = self::product( 'Budget product ' . $i, [ 'base' => [ 'regular_price' => 1000 + $i ] ] );
		}
		self::$simple        = self::product(
			'Budget simple',
			[
				'base' => [
					'regular_price'  => 2500,
					'manage_stock'   => true,
					'stock_quantity' => 1000,
				],
			]
		);
		self::$variable[6]   = self::variable( [ 'Size' => [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ] ] );
		self::$variable[250] = self::variable(
			[
				'Color' => array_map( static fn ( int $n ): string => 'Color ' . $n, range( 1, 25 ) ),
				'Size'  => array_map( static fn ( int $n ): string => 'Size ' . $n, range( 1, 10 ) ),
			]
		);

		foreach ( [ 1, 10 ] as $lines ) {
			$tokens   = new MemoryTokenStore();
			$services = new CartServices( Plugin::instance()->services(), Plugin::instance()->catalog(), $tokens );
			foreach ( array_slice( self::$listed, 0, $lines ) as $product_id ) {
				$services->cart()->add( 0, $product_id, 0, 1, [] );
			}
			self::$carts[ $lines ] = (string) $tokens->get();
		}
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		foreach ( self::$posts as $id ) {
			wp_delete_post( $id, true );
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'sessions' ) ); // phpcs:ignore WordPress.DB
	}

	protected function setUp(): void {
		if ( is_multisite() ) {
			self::markTestSkipped( 'The probe boots a single site.' );
		}
	}

	public function test_p2_archive_cost_does_not_grow_with_the_page_size(): void {
		$extra = [];
		foreach ( [ 12, 48 ] as $per_page ) {
			$run = Probe::compare(
				'/?post_type=' . PostTypes::PRODUCT,
				[
					'per_page'   => $per_page,
					'bare_types' => true,
				]
			);
			self::assertSame( 200, $run['on']['status'] );
			$extra[ $per_page ] = $this->extra_queries( $run, (int) self::$budgets['P2']['queries'], "P2 at {$per_page}" );
			$this->assert_catalog_page( $run['on'], "P2 at {$per_page}" );
			$this->assert_memory( $run, (int) self::$budgets['P2']['memory'], "P2 at {$per_page}" );
		}
		self::assertSame( $extra[12], $extra[48], 'Archive queries grow with the number of products.' );
	}

	public function test_p3a_simple_product_page(): void {
		$run = Probe::compare( '/?post_type=' . PostTypes::PRODUCT . '&p=' . self::$simple, [ 'bare_types' => true ] );
		self::assertSame( 200, $run['on']['status'] );
		$this->extra_queries( $run, (int) self::$budgets['P3a']['queries'], 'P3a' );
		$this->assert_catalog_page( $run['on'], 'P3a' );
		$this->assert_memory( $run, (int) self::$budgets['P3']['memory'], 'P3a' );
	}

	public function test_p3b_variable_product_page_inlines_up_to_250_variants(): void {
		$extra = [];
		foreach ( self::$variable as $count => $id ) {
			$run = Probe::compare( '/?post_type=' . PostTypes::PRODUCT . '&p=' . $id, [ 'bare_types' => true ] );
			self::assertSame( 200, $run['on']['status'] );
			self::assertGreaterThan( 0, $run['on']['state'], "No variant state for {$count} variants." );
			$extra[ $count ] = $this->extra_queries( $run, (int) self::$budgets['P3b']['queries'], "P3b at {$count}" );
			$this->assert_catalog_page( $run['on'], "P3b at {$count}" );
			$this->assert_memory( $run, (int) self::$budgets['P3']['memory'], "P3b at {$count}" );
			$this->report( "P3b state at {$count}", $run['on']['state'] );
			if ( $count === 250 ) {
				self::assertLessThanOrEqual( self::$budgets['P3b']['state'], $run['on']['state'], 'Inlined variant state over budget.' );
			}
		}
		self::assertSame( $extra[6], $extra[250], 'Product page queries grow with the number of variants.' );
	}

	public function test_p1h_product_list_cost_does_not_grow_with_its_size(): void {
		$extra = [];
		foreach ( [ 12, 48 ] as $size ) {
			$page = (int) wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Budget home ' . $size,
					'post_content' => Templates::product_query( '{"queryId":2,"query":{"perPage":' . $size . ',"pages":0,"offset":0,"postType":"' . PostTypes::PRODUCT . '","order":"desc","orderBy":"date","inherit":false},"namespace":"flexacommerce/product-query"}' ),
				]
			);
			self::$posts[] = $page;
			$run           = Probe::compare(
				'/?page_id=' . $page,
				[
					'bare_types' => true,
					// Each item of the list; get_post_class() adds it.
					'count'      => ' type-' . PostTypes::PRODUCT . ' ',
				]
			);
			self::assertSame( 200, $run['on']['status'] );
			self::assertSame( $size, $run['on']['counted'], "P1h at {$size}: the list did not render {$size} products." );
			$extra[ $size ] = $this->extra_queries( $run, (int) self::$budgets['P1h']['queries'], "P1h at {$size}" );
			$this->assert_catalog_page( $run['on'], "P1h at {$size}" );
		}
		self::assertSame( $extra[12], $extra[48], 'Product list queries grow with the number of products.' );
	}

	/** P8: what a product page loads of ours, simple and with options. */
	public function test_p8_product_page_assets(): void {
		foreach ( [ 'simple' => self::$simple, 'variable' => self::$variable[6] ] as $label => $id ) {
			$bytes = Probe::run( '/?post_type=' . PostTypes::PRODUCT . '&p=' . $id, true )['bytes'];
			$js    = 0;
			$css   = 0;
			foreach ( $bytes as $file => $size ) {
				if ( str_ends_with( $file, '.js' ) ) {
					$js += $size;
				} else {
					$css += $size;
				}
			}
			$this->report( "P8 {$label} JS", $js );
			$this->report( "P8 {$label} CSS", $css );
			self::assertGreaterThan( 0, $js, "P8 {$label}: no script found, the probe missed the page's assets." );
			self::assertGreaterThan( 0, $css, "P8 {$label}: no style found, the probe missed the page's assets." );
			self::assertLessThanOrEqual( (int) self::$budgets['P8']['js'], $js, "P8 {$label} JS: " . (string) wp_json_encode( $bytes ) );
			self::assertLessThanOrEqual( (int) self::$budgets['P8']['css'], $css, "P8 {$label} CSS: " . (string) wp_json_encode( $bytes ) );
		}
	}

	/**
	 * P11 on the phase 5 catalog URLs: sorting, filters, a category, search,
	 * and the classic theme templates. One run each, with the plugin only.
	 */
	public function test_p11_sorted_filtered_and_classic_catalog_pages_stay_cacheable(): void {
		$term = wp_insert_term( 'Budget category', PostTypes::CATEGORY );
		self::assertIsArray( $term );
		$slug = (string) get_term( (int) $term['term_id'] )->slug;
		wp_set_object_terms( self::$simple, [ (int) $term['term_id'] ], PostTypes::CATEGORY );
		$saved = Settings::int( 'cart_page_id' );
		try {
			$pages = [
				'sorted and filtered' => [ '/?post_type=' . PostTypes::PRODUCT . '&orderby=price-desc&filter_cat=' . $slug, [] ],
				'category'            => [ '/?' . PostTypes::CATEGORY . '=' . $slug, [] ],
				'search'              => [ '/?s=Budget', [] ],
				'classic product'     => [ '/?post_type=' . PostTypes::PRODUCT . '&p=' . self::$variable[6], [ 'theme' => 'classic' ] ],
				'classic archive'     => [ '/?post_type=' . PostTypes::PRODUCT . '&orderby=price', [ 'theme' => 'classic' ] ],
			];
			$runs = [];
			foreach ( $pages as $label => [ $uri, $options ] ) {
				$runs[ $label ] = Probe::run( $uri, true, $options );
				self::assertSame( 200, $runs[ $label ]['status'], $label );
				$this->assert_catalog_page( $runs[ $label ], "P11 {$label}" );
			}
			self::assertSame( 'single-product.php', $runs['classic product']['template'] );
			self::assertGreaterThan( 0, $runs['classic product']['state'], 'The classic product page has no add-to-cart state.' );
			self::assertSame( 'archive-product.php', $runs['classic archive']['template'] );

			// The other side: the cart page differs per visitor and turns caching off.
			$cart = (int) wp_insert_post(
				[
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Budget cart',
				]
			);
			self::$posts[] = $cart;
			Settings::update( [ 'cart_page_id' => $cart ] );
			self::assertTrue( Probe::run( '/?page_id=' . $cart, true )['nocache'], 'The cart page can be cached.' );
		} finally {
			Settings::update( [ 'cart_page_id' => $saved ] );
			wp_delete_term( (int) $term['term_id'], PostTypes::CATEGORY );
		}
	}

	public function test_p4q_get_cart_cost_does_not_grow_with_lines(): void {
		$extra = [];
		foreach ( self::$carts as $lines => $token ) {
			$run = Probe::compare( '/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/cart', [ 'cookies' => [ 'fxc_session' => $token ] ] );
			self::assertSame( 200, $run['on']['status'] );
			self::assertSame( $lines, $run['on']['rest']['item_count'] ?? null, 'The probe did not read the cart.' );
			$extra[ $lines ] = $this->extra_queries( $run, (int) self::$budgets['P4q']['queries'], "P4q at {$lines}" );
		}
		self::assertSame( $extra[1], $extra[10], 'GET cart queries grow with the number of lines.' );
	}

	public function test_p5q_add_to_cart(): void {
		$run = Probe::compare(
			'/?rest_route=/' . FLEXACOMMERCE_REST_NAMESPACE . '/store/cart/items',
			[
				'method'  => 'POST',
				'headers' => [
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
					'X-FXC-Nonce'  => Plugin::instance()->cart()->nonce()->create( 0, '', null ),
				],
				'body'    => (string) wp_json_encode(
					[
						'product_id' => self::$simple,
						'quantity'   => 1,
					]
				),
			]
		);
		self::assertSame( 201, $run['on']['status'], (string) wp_json_encode( $run['on']['rest'] ) );
		$this->extra_queries( $run, (int) self::$budgets['P5q']['queries'], 'P5q' );
	}

	/**
	 * @param array{on:array<string,mixed>,off:array<string,mixed>} $run
	 */
	private function extra_queries( array $run, int $budget, string $label ): int {
		$extra = count( $run['on']['queries'] ) - count( $run['off']['queries'] );
		$this->report( "{$label} queries", $extra );

		// The theme's templates for a plain post type can run more queries
		// than ours, which hides our cost in the difference. Queries made
		// from FlexaCommerce code are counted on their own as well.
		$own = array_values( array_filter( $run['on']['queries'], static fn ( array $q ): bool => str_contains( $q['caller'], 'Flexa\\Commerce\\' ) ) );
		$this->report( "{$label} own queries", count( $own ) );
		self::assertLessThanOrEqual(
			$budget,
			count( $own ),
			"{$label}: " . count( $own ) . " queries from FlexaCommerce (budget {$budget}):\n  " . implode( "\n  ", array_column( $own, 'sql' ) )
		);
		if ( $extra > $budget ) {
			$core = array_count_values( array_column( $run['off']['queries'], 'sql' ) );
			$list = [];
			foreach ( $run['on']['queries'] as $query ) {
				if ( ( $core[ $query['sql'] ] ?? 0 ) > 0 ) {
					--$core[ $query['sql'] ];
					continue;
				}
				$list[] = $query['sql'] . "\n    from " . $query['caller'];
			}
			self::fail( "{$label}: {$extra} extra queries (budget {$budget}):\n  " . implode( "\n  ", $list ) );
		}
		return $extra;
	}

	/** P11: a catalog page neither touches the session table nor turns caching off. */
	private function assert_catalog_page( array $on, string $label ): void {
		$table = Schema::table( 'sessions' );
		foreach ( $on['queries'] as $query ) {
			self::assertStringNotContainsString( $table, $query['sql'], "{$label}: a catalog page queried the session table." );
		}
		self::assertFalse( $on['nocache'], "{$label}: a catalog page sent no-cache headers." );
		$cache = strtolower( (string) ( $on['headers']['Cache-Control'] ?? '' ) );
		self::assertStringNotContainsString( 'no-', $cache, "{$label}: Cache-Control {$cache}." );
		self::assertArrayNotHasKey( 'Set-Cookie', $on['headers'] );
		// O7: gateways, shipping, checkout, orders and emails stay unloaded on catalog pages.
		$loaded = array_values(
			array_filter(
				(array) $on['own'],
				static fn ( string $class ): bool => preg_match( '/^Flexa\\\\Commerce\\\\(Payments|Shipping|Checkout|Orders|Notifications)\\\\/', $class ) === 1
			)
		);
		self::assertSame( [], $loaded, "{$label}: a catalog page loaded checkout classes." );
	}

	/** @param array{on:array<string,mixed>,off:array<string,mixed>} $run */
	private function assert_memory( array $run, int $budget, string $label ): void {
		$delta = (int) $run['on']['peak'] - (int) $run['off']['peak'];
		$this->report( "{$label} memory", $delta );
		if ( Probe::has_opcache() ) {
			self::assertLessThanOrEqual( $budget, $delta, "{$label}: extra peak memory {$delta} B is over the budget." );
		}
	}

	private function report( string $label, int $value ): void {
		if ( getenv( 'FXC_PERF_REPORT' ) === '1' ) {
			fwrite( STDERR, "\n{$label}: {$value}" );
		}
	}

	/** @param array<string,mixed> $data */
	private static function product( string $title, array $data ): int {
		$id            = (int) wp_insert_post(
			[
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		self::$posts[] = $id;
		Plugin::instance()->catalog()->product_service()->save( $id, ProductInput::from_array( $data ) );
		return $id;
	}

	/** @param array<string,list<string>> $axes */
	private static function variable( array $axes ): int {
		$attributes = [];
		foreach ( $axes as $name => $values ) {
			$attributes[] = [
				'type'      => 'local',
				'name'      => $name,
				'values'    => $values,
				'variation' => true,
			];
		}
		$id      = self::product(
			'Budget variable ' . count( $axes ),
			[
				'type'       => 'variable',
				'attributes' => $attributes,
			]
		);
		$service = Plugin::instance()->catalog()->product_service();
		$rows    = [];
		foreach ( $service->generate_variants( $id )->product->variations as $variation ) {
			$rows[] = [
				'id'            => $variation->id,
				'regular_price' => 3000,
			];
		}
		$service->save( $id, ProductInput::from_array( [ 'variations' => $rows ] ) );
		return $id;
	}
}
