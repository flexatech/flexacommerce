<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponEditor;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;

/** The coupon stage (doc 05 §5.8), both directions. */
final class CouponMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;
	private CouponRepository $coupons;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		$this->coupons = new CouponRepository( $wpdb );
	}

	public function test_coupons_are_imported_with_their_rules(): void {
		$summer = self::wc_coupon(
			'Summer',
			'fixed_cart',
			'5.50',
			function ( \WC_Coupon $c ): void {
				$c->set_description( 'Summer sale' );
				$c->set_date_expires( gmmktime( 0, 0, 0, 1, 1, 2030 ) );
				$c->set_minimum_amount( '20' );
				$c->set_maximum_amount( '200' );
				$c->set_usage_limit( 50 );
				$c->set_usage_count( 3 );
				$c->set_usage_limit_per_user( 2 );
				$c->set_individual_use( true );
				$c->set_exclude_sale_items( true );
				$c->set_product_ids( [ $this->store->products['tee'], $this->store->variations['red_large'] ] );
				$c->set_excluded_product_ids( [ $this->store->products['unicode'] ] );
				$c->set_product_categories( [ $this->store->terms['shirts'] ] );
				$c->set_email_restrictions( [ '*@example.com' ] );
			}
		);
		self::wc_coupon( 'old', 'percent', '12.5', static fn ( \WC_Coupon $c ) => $c->set_status( 'draft' ) );

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 3, $job->counter( 'coupons', 'created' ) );

		$coupon = $this->fxc_coupon( 'summer' );
		$this->assertSame( $this->local_id( 'coupon', $summer ), $coupon->id );
		$this->assertSame( 'summer', $coupon->code, 'WooCommerce keeps codes in lower case.' );
		$this->assertSame( Coupon::FIXED_CART, $coupon->type );
		$this->assertSame( 550, $coupon->amount );
		$this->assertSame( Coupon::ACTIVE, $coupon->status );
		$this->assertSame( 'Summer sale', $coupon->description );
		$this->assertSame( '2030-01-01 00:00:00', $coupon->expires_at );
		$this->assertSame( 2000, $coupon->min_subtotal );
		$this->assertSame( 20000, $coupon->max_subtotal );
		$this->assertSame( [ 50, 3, 2 ], [ $coupon->usage_limit, $coupon->usage_count, $coupon->per_customer_limit ] );
		$this->assertTrue( $coupon->individual_use );
		$this->assertTrue( $coupon->exclude_sale_items );
		$this->assertSame( [ $this->local_id( 'product', $this->store->products['tee'] ) ], $coupon->product_ids );
		$this->assertSame( [ $this->local_id( 'variant', $this->store->variations['red_large'] ) ], $coupon->variant_ids, 'A variation is a variant rule, apart from the product ids.' );
		$this->assertSame( [ $this->local_id( 'product', $this->store->products['unicode'] ) ], $coupon->exclude_product_ids );
		$this->assertSame( [ $this->local_id( 'term', $this->store->terms['shirts'] ) ], $coupon->category_ids );
		$this->assertSame( [ '*@example.com' ], $coupon->emails );

		$old = $this->fxc_coupon( 'old' );
		$this->assertSame( 1250, $old->amount, 'A percentage is kept in hundredths.' );
		$this->assertSame( Coupon::DISABLED, $old->status, 'A draft is imported disabled.' );

		$save10 = $this->fxc_coupon( 'save10' );
		$this->assertSame( 1000, $save10->amount );
		$this->assertSame( 1, $this->coupons->uses_by( $save10->id, $this->store->customer, 'ana@example.com' ), 'The imported order counts toward the per-customer limit.' );
	}

	public function test_rules_fxc_cannot_apply(): void {
		self::wc_coupon( 'shipfree', 'fixed_cart', '0', static fn ( \WC_Coupon $c ) => $c->set_free_shipping( true ) );
		self::wc_coupon( 'shipplus', 'fixed_cart', '4', static fn ( \WC_Coupon $c ) => $c->set_free_shipping( true ) );
		self::wc_coupon( 'twoitems', 'percent', '5', static fn ( \WC_Coupon $c ) => $c->set_limit_usage_to_x_items( 2 ) );
		self::wc_coupon( 'grouped', 'percent', '5', fn ( \WC_Coupon $c ) => $c->set_product_ids( [ $this->store->products['grouped'] ] ) );
		self::wc_coupon( 'cart', 'fixed_cart', '33.333' );
		self::wc_coupon( 'taken', 'percent', '5' );
		$this->fxc_editor()->save(
			[
				'code'   => 'TAKEN',
				'type'   => Coupon::PERCENT,
				'amount' => 700,
			]
		);

		$job = $this->run_job( Direction::WC_TO_FXC );
		$this->assertSame( JobStatus::COMPLETED_WITH_WARNINGS, $job->status );
		$this->assertSame( 1, $job->counter( 'coupons', 'rejected' ) );
		$this->assertSame( [ 0, true ], [ $this->fxc_coupon( 'shipfree' )->amount, $this->fxc_coupon( 'shipfree' )->free_shipping ], 'Free shipping alone is imported.' );
		$this->assertSame( 700, $this->fxc_coupon( 'taken' )->amount, 'The coupon made in FlexaCommerce is kept.' );

		$this->assertSame( Coupon::ACTIVE, $this->fxc_coupon( 'shipplus' )->status );
		$this->assertSame( 400, $this->fxc_coupon( 'shipplus' )->amount );
		$this->assertTrue( $this->fxc_coupon( 'shipplus' )->free_shipping );
		$this->assertSame( [ Coupon::ACTIVE, 2 ], [ $this->fxc_coupon( 'twoitems' )->status, $this->fxc_coupon( 'twoitems' )->item_limit ], 'An item limit is kept.' );
		$this->assertSame( Coupon::DISABLED, $this->fxc_coupon( 'grouped' )->status );
		$this->assertSame( [], $this->fxc_coupon( 'grouped' )->product_ids );
		$this->assertSame( 3333, $this->fxc_coupon( 'cart' )->amount );

		$codes = array_count_values( array_column( $this->service->log( $job->id )['rows'], 'code' ) );
		foreach ( [ 'coupon_rules_lost', 'coupon_product_missing', 'coupon_rounded', 'duplicate_code' ] as $code ) {
			$this->assertSame( 1, $codes[ $code ] ?? 0, $code );
		}
		$this->assertArrayNotHasKey( 'unsupported_coupon', $codes );
		$this->assertArrayNotHasKey( 'coupon_item_limit', $codes );
	}

	public function test_a_second_run_updates_the_same_coupon(): void {
		$id  = self::wc_coupon( 'later', 'percent', '5' );
		$job = $this->run_job( Direction::WC_TO_FXC );
		$fxc = $this->fxc_coupon( 'later' );

		$coupon = new \WC_Coupon( $id );
		$coupon->set_amount( '7' );
		$coupon->save();
		$this->service->start( $job->id );
		$again = $this->service->run( $job->id );
		$this->assertSame( 1, $again->counter( 'coupons', 'updated' ) );
		$this->assertSame( $fxc->id, $this->fxc_coupon( 'later' )->id );
		$this->assertSame( 700, $this->fxc_coupon( 'later' )->amount );
	}

	public function test_coupons_go_back_to_woocommerce(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$tee    = $this->local_id( 'product', $this->store->products['tee'] );
		$spring = $this->fxc_editor()->save(
			[
				'code'        => 'spring',
				'type'        => Coupon::FIXED_PRODUCT,
				'amount'      => 250,
				'starts_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'expires_at'  => '2031-06-01 00:00:00',
				'product_ids' => [ $tee ],
				'variant_ids' => [ $this->local_id( 'variant', $this->store->variations['red_large'] ) ],
				'item_limit'  => 3,
				'emails'      => [ 'ana@example.com' ],
			]
		);
		$ship   = $this->fxc_editor()->save(
			[
				'code'          => 'shiponly',
				'type'          => Coupon::FIXED_CART,
				'amount'        => 0,
				'free_shipping' => true,
			]
		);
		$save10 = $this->fxc_coupon( 'save10' );
		$this->fxc_editor()->save( [ 'amount' => 1500 ], $save10->id );
		$this->edited_later( $save10->id );

		$job = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 2, $job->counter( 'coupons', 'created' ) );
		$this->assertSame( 1, $job->counter( 'coupons', 'updated' ) );

		// A draft is not found by its code, so it is looked up through the id map.
		$spring = new \WC_Coupon( $this->remote_id( 'coupon', $spring->id ) );
		$this->assertSame( 'draft', $spring->get_status(), 'WooCommerce has no start date, so a later coupon waits as a draft.' );
		$this->assertSame( 'fixed_product', $spring->get_discount_type() );
		$this->assertSame( '2.5', (string) $spring->get_amount() );
		$this->assertSame( [ $this->store->products['tee'], $this->store->variations['red_large'] ], $spring->get_product_ids(), 'WooCommerce keeps variations with the products.' );
		$this->assertSame( [ 'ana@example.com' ], $spring->get_email_restrictions() );
		$this->assertSame( 3, $spring->get_limit_usage_to_x_items() );
		$this->assertSame( gmmktime( 0, 0, 0, 6, 1, 2031 ), $spring->get_date_expires()?->getTimestamp() );

		$ship = new \WC_Coupon( $this->remote_id( 'coupon', $ship->id ) );
		$this->assertTrue( $ship->get_free_shipping() );
		$this->assertSame( '0', (string) $ship->get_amount() );

		$wc_save10 = new \WC_Coupon( 'save10' );
		$this->assertSame( $this->local_id( 'coupon', $wc_save10->get_id() ), $save10->id, 'The imported coupon is updated in place.' );
		$this->assertSame( '15', (string) $wc_save10->get_amount() );
		$this->assertSame( 'publish', $wc_save10->get_status() );
		$this->assertSame( [ (string) $this->store->customer ], array_map( 'strval', $wc_save10->get_used_by() ) );
		$codes = array_column( $this->service->log( $job->id )['rows'], 'code' );
		$this->assertContains( 'coupon_starts_later', $codes );
	}

	public function test_a_buy_x_get_y_coupon_stays_in_flexacommerce(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$this->fxc_editor()->save(
			[
				'code'    => 'b2g1',
				'type'    => Coupon::BUY_X_GET_Y,
				'amount'  => Coupon::FULL_PERCENT,
				'buy_qty' => 2,
				'get_qty' => 1,
			]
		);

		$job = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $job->status, JobStatus::FINISHED );
		$this->assertSame( 1, $job->counter( 'coupons', 'rejected' ) );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'b2g1' ), 'WooCommerce has no buy X get Y coupon.' );
		$this->assertContains( 'unsupported_coupon', array_column( $this->service->log( $job->id )['rows'], 'code' ) );
	}

	public function test_an_automatic_discount_stays_in_flexacommerce(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$this->fxc_editor()->save(
			[
				'code'      => 'Spring sale',
				'type'      => Coupon::PERCENT,
				'amount'    => 1000,
				'automatic' => true,
			]
		);

		$job = $this->run_job( Direction::FXC_TO_WC );
		$this->assertSame( 1, $job->counter( 'coupons', 'rejected' ) );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'spring sale' ), 'A typed code would let anyone use it by name.' );
		$this->assertContains( 'unsupported_coupon', array_column( $this->service->log( $job->id )['rows'], 'code' ) );
	}

	/** @param (callable(\WC_Coupon):mixed)|null $edit */
	private static function wc_coupon( string $code, string $type, string $amount, ?callable $edit = null ): int {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $amount );
		if ( $edit !== null ) {
			$edit( $coupon );
		}
		return $coupon->save();
	}

	private function fxc_coupon( string $code ): ?Coupon {
		global $wpdb;
		return ( new CouponRepository( $wpdb ) )->by_codes( [ $code ] )[ $code ];
	}

	private function fxc_editor(): CouponEditor {
		return new CouponEditor( $this->coupons, Plugin::instance()->services()->clock() );
	}

	private function local_id( string $entity, int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $entity, (string) $wc_id ) );
	}

	private function remote_id( string $entity, int $local_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT remote_id FROM %i WHERE entity = %s AND local_id = %d', Schema::table( 'id_map' ), $entity, $local_id ) );
	}

	/** The edit lands in the same second as the import; move it later so it counts as newer. */
	private function edited_later( int $coupon_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = DATE_ADD(updated_at, INTERVAL 1 HOUR) WHERE id = %d', Schema::table( 'coupons' ), $coupon_id ) );
	}

	private function run_job( string $direction ): Job {
		$job = $this->service->create(
			$direction,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'settings', 'terms', 'attributes', 'products', 'customers', 'orders', 'coupons' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}
}
