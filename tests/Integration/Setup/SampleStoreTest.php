<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Setup;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Payments\GatewaySettings;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Setup\DemoSettings;
use Flexa\Commerce\Setup\SampleStore;
use Flexa\Commerce\Shipping\Zone;
use Flexa\Commerce\Shipping\ZoneRepository;
use Flexa\Commerce\Support\Settings;

/**
 * The sample store: the shipped CSV file through the importer, then the
 * pictures, gift card, plan, downloads, reviews and coupons on top, and a
 * remove() that takes back exactly what install() added, with the store
 * settings DemoSettings adds for a physical or digital store. ProductService
 * commits the test transaction, so the test removes the store itself and
 * commits the removal (a rollback would bring the variant rows back).
 */
final class SampleStoreTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		if ( get_option( SampleStore::OPTION ) !== false ) {
			SampleStore::make()->remove();
		}
		$GLOBALS['wpdb']->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		parent::tear_down();
		delete_option( ZoneRepository::OPTION );
		delete_option( GatewaySettings::PREFIX . 'bacs' );
		delete_option( GatewaySettings::PREFIX . 'cod' );
		Plugin::instance()->payments()->gateways()->settings()->forget( 'bacs' );
		Plugin::instance()->payments()->gateways()->settings()->forget( 'cod' );
		Plugin::instance()->shipping()->service()->zones()->flush();
	}

	public function test_install_adds_every_product_type_and_remove_takes_it_back(): void {
		$store  = SampleStore::make();
		$counts = $store->install();

		self::assertSame( [], $counts['errors'], (string) wp_json_encode( $counts ) );
		self::assertSame( 25, $counts['products'] );
		self::assertSame( 3, $counts['coupons'] );
		self::assertSame( 6, $counts['reviews'] );
		self::assertGreaterThan( 20, $counts['images'] );
		self::assertSame( 25, $store->status()['products'] );

		$products = Plugin::instance()->catalog()->product_service();
		$by_sku   = static function ( string $sku ) use ( $products ): \Flexa\Commerce\Catalog\Product {
			$post = get_posts(
				[
					'post_type'   => PostTypes::PRODUCT,
					'post_status' => 'any',
					'numberposts' => -1,
				]
			);
			foreach ( $post as $candidate ) {
				$product = $products->get( $candidate->ID );
				if ( $product->base->sku === $sku ) {
					return $product;
				}
			}
			self::fail( 'No product ' . $sku );
		};

		$tee = $by_sku( 'sample-vneck-tee' );
		self::assertSame( ProductTypes::VARIABLE, $tee->type );
		self::assertCount( 3, $tee->variations );
		self::assertGreaterThan( 0, (int) get_post_thumbnail_id( $tee->id ) );
		foreach ( $tee->variations as $variation ) {
			self::assertGreaterThan( 0, (int) $variation->image_id, 'Each colour has its picture.' );
		}
		self::assertCount( 4, $by_sku( 'sample-hoodie' )->variations );
		self::assertSame( ProductTypes::GROUPED, $by_sku( 'sample-logo-collection' )->type );
		self::assertSame( ProductTypes::EXTERNAL, $by_sku( 'sample-pennant' )->type );
		$set = $by_sku( 'sample-winter-set' );
		self::assertSame( ProductTypes::BUNDLE, $set->type );
		self::assertCount( 2, $set->bundle?->items ?? [] );

		self::assertTrue( $by_sku( 'sample-gift-card' )->is_gift_card() );
		$plan = $by_sku( 'sample-coffee-club' )->base->plan();
		self::assertSame( [ 'month', 1 ], [ $plan?->period, $plan?->interval ] );
		$album = $by_sku( 'sample-album' );
		self::assertTrue( $album->base->downloadable );
		self::assertCount( 2, $album->base->downloads->files );
		self::assertSame( 1, count( $by_sku( 'sample-sunglasses' )->gallery_ids ) );
		self::assertSame( 2, Reviews::summary( $tee->id )['count'] );

		$coupons = Plugin::instance()->coupons()->repository();
		self::assertTrue( $coupons->code_taken( 'SAMPLE10' ) );

		try {
			$store->install();
			self::fail( 'A second install must be refused.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'already', $e->getMessage() );
		}

		$attachments = get_option( SampleStore::OPTION )['attachments'];
		$removed     = $store->remove();
		self::assertSame( 25, $removed['products'] );
		self::assertSame( 3, $removed['coupons'] );
		self::assertSame( count( $attachments ), $removed['images'] );
		self::assertFalse( get_option( SampleStore::OPTION ) );
		self::assertFalse( $coupons->code_taken( 'SAMPLE10' ) );
		self::assertNull( get_post( $tee->id ) );
		self::assertFalse( $store->status()['installed'] );
		$uploads = wp_upload_dir( null, false );
		self::assertDirectoryDoesNotExist( $uploads['basedir'] . '/flexacommerce-sample' );
		global $wpdb;
		self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE sku LIKE %s', Schema::table( 'variants' ), 'sample-%' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_product_the_shop_already_has_is_left_alone(): void {
		$id       = (int) wp_insert_post(
			[
				'post_type'   => PostTypes::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => 'My own tee',
			]
		);
		$products = Plugin::instance()->catalog()->product_service();
		$products->save(
			$id,
			\Flexa\Commerce\Catalog\ProductInput::from_array(
				[
					'base' => [
						'sku'           => 'sample-tshirt',
						'regular_price' => 999,
					],
				]
			)
		);

		$counts = SampleStore::make()->install();
		self::assertSame( 1, $counts['skipped'] );
		self::assertSame( 24, $counts['products'] );

		SampleStore::make()->remove();
		self::assertSame( 'My own tee', get_the_title( $id ) );
		self::assertSame( 999, $products->get( $id )->base->regular_price );
		wp_delete_post( $id, true );
	}
	public function test_the_admin_routes_add_and_remove_the_store(): void {
		$route = '/flexacommerce/v1/admin/products/sample';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, rest_do_request( new \WP_REST_Request( 'POST', $route ) )->get_status() );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		self::assertFalse( rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_data()['installed'] );
		$wrong = new \WP_REST_Request( 'POST', $route );
		$wrong->set_body_params( [ 'type' => 'services' ] );
		self::assertSame( 400, rest_do_request( $wrong )->get_status() );

		$added = rest_do_request( new \WP_REST_Request( 'POST', $route ) );
		self::assertSame( 201, $added->get_status() );
		self::assertSame( [ true, 25, 3 ], [ $added->get_data()['status']['installed'], $added->get_data()['products'], $added->get_data()['coupons'] ] );
		self::assertSame( 409, rest_do_request( new \WP_REST_Request( 'POST', $route ) )->get_status() );

		$removed = rest_do_request( new \WP_REST_Request( 'DELETE', $route ) );
		self::assertSame( 200, $removed->get_status() );
		self::assertSame( [ false, 25 ], [ $removed->get_data()['status']['installed'], $removed->get_data()['products'] ] );
	}
	public function test_a_digital_store_sells_downloads_and_needs_no_shipping(): void {
		$counts = SampleStore::make()->install( 'digital', DemoSettings::PARTS );

		self::assertSame( [], $counts['errors'], (string) wp_json_encode( $counts ) );
		self::assertSame( 6, $counts['products'], 'Album, single, ebook, wallpapers, presets and the gift card.' );
		self::assertSame( 2, $counts['coupons'], 'No free-shipping coupon without shipping.' );
		self::assertFalse( Plugin::instance()->coupons()->repository()->code_taken( 'FREESHIP' ) );
		self::assertFalse( $counts['store']['zone'] );
		self::assertSame( [ 'bacs', 'cod' ], $counts['store']['gateways'] );
		$gateways = Plugin::instance()->payments()->gateways();
		self::assertFalse( $gateways->settings()->enabled( $gateways->get( 'cod' ) ), 'Cash on delivery needs something to deliver.' );
		self::assertSame( 'digital', SampleStore::make()->status()['type'] );
		self::assertSame( [], Settings::get( 'shipping_classes' ), 'The bulky items stay out.' );

		foreach ( get_option( SampleStore::OPTION )['products'] as $id ) {
			self::assertTrue( Plugin::instance()->catalog()->product_service()->get( $id )->base->virtual, get_the_title( $id ) . ' ships nothing.' );
		}
	}

	public function test_a_physical_store_gets_shipping_payments_tax_pages_and_a_menu_and_remove_puts_them_back(): void {
		Settings::update( [ 'store_country' => 'US' ] );
		$terms_before = Settings::int( 'terms_page_id' );
		$counts       = SampleStore::make()->install( 'physical', DemoSettings::PARTS );

		self::assertSame( [], $counts['errors'], (string) wp_json_encode( $counts ) );
		self::assertSame( 19, $counts['products'] );
		self::assertSame( 3, $counts['coupons'] );
		self::assertTrue( $counts['store']['zone'] );
		self::assertSame( [ 'bacs' ], $counts['store']['gateways'], 'Cash on delivery is on already.' );
		self::assertTrue( $counts['store']['tax'] );
		self::assertTrue( $counts['store']['menu'] );

		$zones = Plugin::instance()->shipping()->service()->zones();
		$zones->flush();
		$all    = $zones->all();
		$sample = $all[ count( $all ) - 2 ];
		self::assertSame( [ 'country:US' ], $sample->regions );
		self::assertSame( [ 'flat_rate', 'free_shipping', 'local_pickup' ], array_map( static fn ( $m ) => $m->type, $sample->methods ) );
		self::assertSame( [ 'flat_rate' ], array_map( static fn ( $m ) => $m->type, end( $all )->methods ), 'Everywhere else had no method.' );
		self::assertContains( SampleStore::SHIPPING_CLASS, array_column( Settings::get( 'shipping_classes' ), 'slug' ) );

		$gateways = Plugin::instance()->payments()->gateways();
		self::assertTrue( $gateways->settings()->enabled( $gateways->get( 'bacs' ) ) );
		self::assertTrue( $gateways->settings()->enabled( $gateways->get( 'cod' ) ) );
		self::assertTrue( Settings::bool( 'tax_enabled' ) );
		self::assertCount( 1, Plugin::instance()->tax()->repository()->all( null, DemoSettings::RATE_SOURCE ) );

		$terms = Settings::int( 'terms_page_id' );
		self::assertNotSame( $terms_before, $terms );
		self::assertSame( 'publish', get_post_status( $terms ) );
		$pages = get_option( SampleStore::OPTION )['store']['pages'];
		$store = get_option( SampleStore::OPTION )['store'];
		$menu  = $store['menu'] > 0 ? $store['menu'] : $store['navigation'];
		self::assertGreaterThan( 0, $menu );

		$removed = SampleStore::make()->remove();
		self::assertSame( 19, $removed['products'] );
		self::assertSame( [ 1, 1, 1 ], [ $removed['store']['zones'], $removed['store']['gateways'], $removed['store']['rates'] ] );
		self::assertSame( count( $pages ), $removed['store']['pages'] );
		self::assertSame( 1, $removed['store']['menus'] );

		$zones->flush();
		self::assertCount( 1, $zones->all() );
		self::assertSame( Zone::REST_OF_WORLD, $zones->all()[0]->id );
		self::assertSame( [], $zones->all()[0]->methods );
		self::assertFalse( get_option( GatewaySettings::PREFIX . 'bacs' ) );
		self::assertFalse( Settings::bool( 'tax_enabled' ) );
		self::assertSame( [], Plugin::instance()->tax()->repository()->all( null, DemoSettings::RATE_SOURCE ) );
		self::assertSame( $terms_before, Settings::int( 'terms_page_id' ) );
		self::assertNull( get_post( $terms ) );
		self::assertTrue( $store['menu'] > 0 ? ! is_nav_menu( $menu ) : get_post( $menu ) === null );
		self::assertNotContains( SampleStore::SHIPPING_CLASS, array_column( Settings::get( 'shipping_classes' ), 'slug' ) );
	}

	public function test_a_setting_the_shop_changed_after_the_install_is_kept(): void {
		SampleStore::make()->install( 'digital', [ 'payments' ] );

		$gateways = Plugin::instance()->payments()->gateways();
		$gateways->settings()->save( $gateways->get( 'bacs' ), [ 'title' => 'Pay by wire' ] );
		$own = get_option( GatewaySettings::PREFIX . 'bacs' );

		$removed = SampleStore::make()->remove();
		self::assertSame( 1, $removed['store']['gateways'], 'Only cash on delivery is still as installed.' );
		self::assertSame( $own, get_option( GatewaySettings::PREFIX . 'bacs' ) );
		self::assertFalse( get_option( GatewaySettings::PREFIX . 'cod' ) );
		self::assertTrue( $gateways->settings()->enabled( $gateways->get( 'cod' ) ), 'Back on, as it was.' );
	}
}
