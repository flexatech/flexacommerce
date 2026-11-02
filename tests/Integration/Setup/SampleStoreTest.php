<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Setup;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductTypes;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Setup\SampleStore;

/**
 * The sample store: the shipped CSV file through the importer, then the
 * pictures, gift card, plan, downloads, reviews and coupons on top, and a
 * remove() that takes back exactly what install() added. ProductService
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
	}

	public function test_install_adds_every_product_type_and_remove_takes_it_back(): void {
		$store  = SampleStore::make();
		$counts = $store->install();

		self::assertSame( [], $counts['errors'], (string) wp_json_encode( $counts ) );
		self::assertSame( 22, $counts['products'] );
		self::assertSame( 3, $counts['coupons'] );
		self::assertSame( 6, $counts['reviews'] );
		self::assertGreaterThan( 20, $counts['images'] );
		self::assertSame( 22, $store->status()['products'] );

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
		self::assertSame( 22, $removed['products'] );
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
		self::assertSame( 21, $counts['products'] );

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

		$added = rest_do_request( new \WP_REST_Request( 'POST', $route ) );
		self::assertSame( 201, $added->get_status() );
		self::assertSame( [ true, 22, 3 ], [ $added->get_data()['status']['installed'], $added->get_data()['products'], $added->get_data()['coupons'] ] );
		self::assertSame( 409, rest_do_request( new \WP_REST_Request( 'POST', $route ) )->get_status() );

		$removed = rest_do_request( new \WP_REST_Request( 'DELETE', $route ) );
		self::assertSame( 200, $removed->get_status() );
		self::assertSame( [ false, 22 ], [ $removed->get_data()['status']['installed'], $removed->get_data()['products'] ] );
	}
}
