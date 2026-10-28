<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Support\Settings;

/** The pricing blocks and shortcodes (ADR-050). */
final class PricingBlocksTest extends CouponTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
		Settings::update(
			[
				'tier_table'     => true,
				'countdown_days' => 7,
			]
		);
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_quantity_prices_show_where_placed(): void {
		$id = $this->simple( 1000 );
		self::assertSame( '', $this->block( 'quantity-prices', [ 'productId' => $id ] ), 'no bulk rule, no table' );

		$this->bulk();
		Settings::update( [ 'tier_table' => false ] );
		$html = $this->block( 'quantity-prices', [ 'productId' => $id ] );
		self::assertStringStartsWith( '<div class="fxc-quantity-prices wp-block-flexacommerce-quantity-prices" data-product="' . $id . '" data-wp-interactive="flexacommerce/cart">', $html, 'the block shows even with the table under the form turned off' );
		self::assertStringContainsString( '<tr data-min="5" data-max="0"><th scope="row">5 or more</th><td>$9.00 each</td><td>Save 10%</td></tr>', $html );
		self::assertStringNotContainsString( 'fxc-tiers', do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' ) );

		self::assertStringContainsString( '<table class="fxc-tiers">', do_shortcode( '[flexacommerce_quantity_prices id="' . $id . '"]' ) );
	}

	public function test_the_discount_badge_says_what_is_off(): void {
		$id = $this->simple( 1000 );
		self::assertSame( '', $this->block( 'discount-badge', [ 'productId' => $id ] ) );

		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => 1000,
					'sale_price'    => 750,
				],
			]
		);
		Plugin::instance()->cart()->pricing()->reset();
		self::assertSame( '<p class="fxc-badge fxc-discount-badge">Save 25%</p>', do_shortcode( '[flexacommerce_discount_badge id="' . $id . '"]' ) );

		// A pricing rule counts too.
		$plain = $this->simple( 1000 );
		$this->rules()->save(
			[
				'name'        => 'Tenth off',
				'amount'      => 1000,
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ $plain ],
			]
		);
		self::assertStringContainsString( '>Save 10%</p>', $this->block( 'discount-badge', [ 'productId' => $plain ] ) );

		[ $shirt, $sizes ] = $this->sized();
		$this->save(
			$shirt,
			[
				'variations' => array_map(
					static fn ( int $variant ): array => [
						'id'            => $variant,
						'regular_price' => 2000,
						'sale_price'    => $variant === $sizes['s'] ? 1000 : null,
					],
					array_values( $sizes )
				),
			]
		);
		self::assertStringContainsString( '>Up to 50% off</p>', $this->block( 'discount-badge', [ 'productId' => $shirt ] ), 'one size on sale' );
	}

	public function test_a_countdown_to_a_rule_or_the_product_price(): void {
		$ends = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$rule = $this->rules()->save(
			[
				'name'    => 'Autumn',
				'amount'  => 1000,
				'ends_at' => $ends,
			]
		);
		$html = $this->block( 'offer-countdown', [ 'ruleId' => $rule->id ] );
		self::assertStringContainsString( '<div class="fxc-offer-countdown wp-block-flexacommerce-offer-countdown" data-wp-interactive="flexacommerce/cart"><div class="fxc-countdown fxc-countdown--offer" data-ends="' . gmdate( 'c', (int) strtotime( $ends . ' UTC' ) ) . '"', $html, 'a month away still shows: the merchant placed it' );
		self::assertStringContainsString( 'fxc-countdown--offer', do_shortcode( '[flexacommerce_countdown rule="' . $rule->id . '"]' ) );

		$id = $this->simple( 1000 );
		self::assertStringContainsString( 'fxc-countdown--offer', $this->block( 'offer-countdown', [ 'productId' => $id ] ), 'the product price goes up when the rule ends' );
		self::assertStringNotContainsString( 'fxc-countdown', do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' ), 'the form keeps the countdown days' );

		$members = $this->rules()->save(
			[
				'name'      => 'Members',
				'amount'    => 1000,
				'ends_at'   => $ends,
				'customers' => PriceRule::LOGGED_IN,
			]
		);
		$fee     = $this->rules()->save(
			[
				'name'    => 'Rush fee',
				'type'    => PriceRule::FEE,
				'amount'  => 500,
				'mode'    => PriceRule::FIXED,
				'ends_at' => $ends,
			]
		);
		$open    = $this->rules()->save(
			[
				'name'   => 'Always',
				'amount' => 500,
			]
		);
		foreach ( [ $members->id, $fee->id, $open->id, 999999 ] as $other ) {
			self::assertSame( '', $this->block( 'offer-countdown', [ 'ruleId' => $other ] ), (string) $other );
		}
		wp_set_current_user( self::factory()->user->create() );
		self::assertNotSame( '', $this->block( 'offer-countdown', [ 'ruleId' => $members->id ] ), 'for logged-in customers' );
	}

	public function test_the_nudge_stays_cacheable_for_guests(): void {
		$this->coupon(
			'Big basket',
			Coupon::FIXED_CART,
			500,
			[
				'automatic'    => true,
				'min_subtotal' => 5000,
			]
		);
		$html = $this->block( 'discount-nudge' );
		self::assertStringContainsString( 'data-wp-init="callbacks.nudge" data-wp-bind--hidden="state.noNudge" hidden>', $html );
		self::assertStringContainsString( '<p aria-live="polite" data-wp-text="state.nudgeLabel"></p>', $html );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$id = $this->simple( 1000 );
		Plugin::instance()->cart()->cart()->add( $user, $id, $this->service()->get( $id )->base->id, 2 );
		$html = do_shortcode( '[flexacommerce_discount_nudge]' );
		self::assertStringContainsString( '<p aria-live="polite" data-wp-text="state.nudgeLabel">Spend $30.00 more for this discount: Big basket.</p>', $html );
		self::assertStringContainsString( 'style="width:40%"', $html );
		self::assertStringNotContainsString( ' hidden>', $html );
		self::assertSame( 3000, wp_interactivity_state( 'flexacommerce/cart' )['nudge']['remaining']['amount'] ?? null );
	}

	public function test_the_blocks_are_registered_with_their_shortcodes(): void {
		foreach ( [ 'quantity-prices', 'discount-badge', 'offer-countdown', 'discount-nudge' ] as $name ) {
			self::assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'flexacommerce/' . $name ), $name );
		}
		foreach ( [ 'flexacommerce_quantity_prices', 'flexacommerce_discount_badge', 'flexacommerce_countdown', 'flexacommerce_discount_nudge' ] as $tag ) {
			self::assertTrue( shortcode_exists( $tag ), $tag );
		}
		self::assertArrayHasKey( 'ruleId', \WP_Block_Type_Registry::get_instance()->get_registered( 'flexacommerce/offer-countdown' )->attributes ?? [] );
	}

	private function bulk(): void {
		$this->rules()->save(
			[
				'name'  => 'Bulk',
				'type'  => PriceRule::BULK,
				'tiers' => [
					[
						'min'    => 5,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
				],
			]
		);
	}

	/** @param array<string,int> $attributes */
	private function block( string $name, array $attributes = [] ): string {
		Plugin::instance()->cart()->pricing()->reset();
		return do_blocks( '<!-- wp:flexacommerce/' . $name . ' ' . (string) wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	private function rules(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
	}
}
