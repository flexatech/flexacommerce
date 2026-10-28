<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Coupons;

use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;

/**
 * Promotion templates (ADR-047) live in the admin app; these are their values as the editors send them, for the
 * templates that need no products picked. Each must save as it is, so a merchant who changes nothing gets no error.
 */
final class TemplatePayloadTest extends CouponTestCase {
	public function tear_down(): void {
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
	}

	public function test_the_coupon_templates_save_as_they_are(): void {
		$templates = [
			'code'          => [
				'code'   => 'SAVE10',
				'type'   => Coupon::PERCENT,
				'amount' => 1000,
			],
			'spend_save'    => [
				'automatic'    => true,
				'code'         => 'Spend and save',
				'type'         => Coupon::PERCENT,
				'amount'       => 1000,
				'min_subtotal' => 10000,
			],
			'amount_off'    => [
				'automatic'    => true,
				'code'         => 'Big order discount',
				'type'         => Coupon::FIXED_CART,
				'amount'       => 1500,
				'min_subtotal' => 15000,
			],
			'free_shipping' => [
				'automatic'     => true,
				'code'          => 'Free shipping',
				'type'          => Coupon::PERCENT,
				'amount'        => 0,
				'min_subtotal'  => 7500,
				'free_shipping' => true,
			],
			'bogo'          => [
				'automatic' => true,
				'code'      => 'Buy one, get one free',
				'type'      => Coupon::BUY_X_GET_Y,
				'amount'    => 10000,
				'buy_qty'   => 1,
				'get_qty'   => 1,
			],
			'three_for_two' => [
				'automatic' => true,
				'code'      => 'Buy 2, get 1 half price',
				'type'      => Coupon::BUY_X_GET_Y,
				'amount'    => 5000,
				'buy_qty'   => 2,
				'get_qty'   => 1,
			],
			'welcome'       => [
				'code'               => 'WELCOME10',
				'type'               => Coupon::PERCENT,
				'amount'             => 1000,
				'per_customer_limit' => 1,
				'conditions'         => self::block( 'order_count', 'lte', 0 ),
			],
			'loyalty'       => [
				'automatic'  => true,
				'code'       => 'Thank you discount',
				'type'       => Coupon::PERCENT,
				'amount'     => 500,
				'conditions' => self::block( 'lifetime_spend', 'gte', 50000 ),
			],
		];
		foreach ( $templates as $key => $input ) {
			$coupon = $this->coupons()->editor()->save( $input );
			self::assertGreaterThan( 0, $coupon->id, $key );
			self::assertSame( $input['amount'], $coupon->amount, $key );
			self::assertSame( ! empty( $input['automatic'] ), $coupon->automatic, $key );
		}
	}

	public function test_the_rule_templates_save_as_they_are(): void {
		$rules = Plugin::instance()->price_rule_repository();
		$store = $rules->save(
			[
				'name'   => 'Store-wide sale',
				'type'   => PriceRule::ADJUST,
				'mode'   => PriceRule::PERCENT,
				'amount' => 1000,
			]
		);
		$fee   = $rules->save(
			[
				'name'   => 'Handling fee',
				'type'   => PriceRule::FEE,
				'mode'   => PriceRule::FIXED,
				'amount' => 200,
			]
		);
		self::assertSame( [ 1000, 200 ], [ $store->amount, $fee->amount ] );
	}

	/** @return array<string,mixed> */
	private static function block( string $type, string $op, int $value ): array {
		return [
			'match' => 'all',
			'items' => [
				[
					'type'  => $type,
					'op'    => $op,
					'value' => $value,
				],
			],
		];
	}
}
