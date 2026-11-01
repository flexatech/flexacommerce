<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Catalog;

use Flexa\Commerce\Catalog\SubscriptionPlan;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Tests\Unit\TestCase;

final class SubscriptionPlanTest extends TestCase {
	public function test_days_and_weeks_add_whole_days(): void {
		$day  = new SubscriptionPlan( SubscriptionPlan::DAY, 10 );
		$week = new SubscriptionPlan( SubscriptionPlan::WEEK, 2 );
		self::assertSame( '2026-03-11 08:30:00', $day->after( '2026-03-01 08:30:00', 1 ) );
		self::assertSame( '2026-03-29 08:30:00', $week->after( '2026-03-01 08:30:00', 2 ) );
	}

	public function test_months_keep_the_anchor_day(): void {
		$plan = new SubscriptionPlan( SubscriptionPlan::MONTH );
		self::assertSame( '2026-02-28 09:00:00', $plan->after( '2026-01-31 09:00:00', 1 ) );
		self::assertSame( '2026-04-30 09:00:00', $plan->after( '2026-03-31 09:00:00', 1 ) );
		// From the short month, the anchor brings the 31st back.
		self::assertSame( '2026-03-31 09:00:00', $plan->after( '2026-02-28 09:00:00', 1, 31 ) );
		self::assertSame( '2026-05-31 09:00:00', $plan->after( '2026-01-31 09:00:00', 4, 31 ) );
	}

	public function test_years_land_on_february_28_after_a_leap_day(): void {
		$plan = new SubscriptionPlan( SubscriptionPlan::YEAR );
		self::assertSame( '2029-02-28 00:00:00', $plan->after( '2028-02-29 00:00:00', 1 ) );
		self::assertSame( '2032-02-29 00:00:00', $plan->after( '2028-02-29 00:00:00', 4, 29 ) );
	}

	public function test_parse_checks_each_field(): void {
		self::assertNull( SubscriptionPlan::parse( null, 'base.subscription' ) );
		self::assertNull( SubscriptionPlan::parse( false, 'base.subscription' ) );
		$plan = SubscriptionPlan::parse(
			[
				'period'     => 'month',
				'interval'   => '3',
				'length'     => '',
				'trial_days' => 14,
				'signup_fee' => 500,
			],
			'base.subscription'
		);
		self::assertSame(
			[
				'period'     => 'month',
				'interval'   => 3,
				'length'     => 0,
				'trial_days' => 14,
				'signup_fee' => 500,
			],
			$plan?->to_array()
		);

		foreach ( [
			[ [ 'period' => 'hour' ], 'base.subscription.period' ],
			[
				[
					'period'   => 'month',
					'interval' => 0,
				],
				'base.subscription.interval',
			],
			[
				[
					'period'   => 'month',
					'interval' => 1.5,
				],
				'base.subscription.interval',
			],
			[
				[
					'period' => 'month',
					'length' => 1000,
				],
				'base.subscription.length',
			],
			[
				[
					'period'     => 'month',
					'trial_days' => 731,
				],
				'base.subscription.trial_days',
			],
			[
				[
					'period'     => 'month',
					'signup_fee' => -1,
				],
				'base.subscription.signup_fee',
			],
		] as [ $input, $path ] ) {
			try {
				SubscriptionPlan::parse( $input, 'base.subscription' );
				self::fail( 'Accepted ' . wp_json_encode( $input ) );
			} catch ( InvalidProduct $e ) {
				self::assertSame( $path, $e->field );
			}
		}
	}

	public function test_from_array_clamps_and_rejects_unknown_periods(): void {
		self::assertNull( SubscriptionPlan::from_array( [ 'period' => 'hour' ] ) );
		self::assertNull( SubscriptionPlan::from_array( 'month' ) );
		$plan = SubscriptionPlan::from_array(
			[
				'period'   => 'week',
				'interval' => 9999,
				'length'   => -3,
			]
		);
		self::assertSame( 365, $plan?->interval );
		self::assertSame( 0, $plan?->length );
	}

	public function test_key_ignores_the_signup_fee(): void {
		$a = new SubscriptionPlan( SubscriptionPlan::MONTH, 1, 12, 0, 0 );
		$b = new SubscriptionPlan( SubscriptionPlan::MONTH, 1, 12, 0, 900 );
		$c = new SubscriptionPlan( SubscriptionPlan::MONTH, 1, 12, 7, 0 );
		self::assertSame( $a->key(), $b->key() );
		self::assertNotSame( $a->key(), $c->key() );
	}

	public function test_describe(): void {
		self::assertSame( '$10.00 / month', ( new SubscriptionPlan( SubscriptionPlan::MONTH ) )->describe( '$10.00' ) );
		self::assertSame(
			'$10.00 every 3 months for 12 months with a 14-day free trial and a $5.00 sign-up fee',
			( new SubscriptionPlan( SubscriptionPlan::MONTH, 3, 4, 14, 500 ) )->describe( '$10.00', '$5.00' )
		);
		self::assertSame( '4 payments, 14-day free trial, $5.00 sign-up fee', ( new SubscriptionPlan( SubscriptionPlan::MONTH, 3, 4, 14, 500 ) )->terms( '$5.00' ) );
	}

	public function test_variant_keeps_the_plan_in_meta(): void {
		$plan = new SubscriptionPlan( SubscriptionPlan::WEEK, 2 );
		$row  = Variant::base_for( 1 )->with( [ 'meta' => [ 'other' => 1 ] ] )->with( [ SubscriptionPlan::META => $plan ] );
		self::assertSame( $plan->to_array(), $row->plan()?->to_array() );
		self::assertSame( 1, $row->meta['other'] );
		self::assertSame(
			$plan->to_array(),
			Variant::from_row(
				[
					'id'         => 1,
					'product_id' => 1,
					'is_base'    => 1,
				] + $row->to_row()
			)->plan()?->to_array()
		);
		$off = $row->with( [ SubscriptionPlan::META => null ] );
		self::assertNull( $off->plan() );
		self::assertSame( [ 'other' => 1 ], $off->meta );
	}
}
