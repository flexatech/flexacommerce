<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Pricing\Rules;

use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRules;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Graduated tiers (ADR-042): each unit at the tier its position reaches. */
final class GraduatedTest extends TestCase {
	private const TIERS = [
		[
			'min'    => 3,
			'mode'   => PriceRule::PERCENT,
			'amount' => 1000,
		],
		[
			'min'    => 6,
			'mode'   => PriceRule::PRICE,
			'amount' => 500,
		],
	];

	public function test_units_below_the_first_tier_pay_the_price(): void {
		self::assertSame( 2000, PriceRules::graduated( self::TIERS, 1000, 1, 2 ) );
		self::assertSame( 1000 + 1000 + 900 + 900, PriceRules::graduated( self::TIERS, 1000, 1, 4 ) );
		self::assertSame( 2000 + 2700 + 1000, PriceRules::graduated( self::TIERS, 1000, 1, 7 ) );
	}

	public function test_counting_goes_on_from_the_lines_above(): void {
		// Units 3 to 6 of the product: three at 900, one at 500.
		self::assertSame( 2700 + 500, PriceRules::graduated( self::TIERS, 1000, 3, 4 ) );
		self::assertSame( 1500, PriceRules::graduated( self::TIERS, 1000, 10, 3 ) );
	}

	public function test_a_tier_from_one_prices_every_unit(): void {
		$tiers = [
			[
				'min'    => 1,
				'mode'   => PriceRule::FIXED,
				'amount' => 100,
			],
		];
		self::assertSame( 2700, PriceRules::graduated( $tiers, 1000, 1, 3 ) );
	}
}
