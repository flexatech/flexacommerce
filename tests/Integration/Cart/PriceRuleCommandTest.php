<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cli\PriceRuleCommand;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;

/** What `wp flexacommerce pricing-rule list` shows for each rule. */
final class PriceRuleCommandTest extends \WP_UnitTestCase {
	private const NOW = '2026-06-01 12:00:00';

	public function tear_down(): void {
		update_option( PriceRuleRepository::OPTION, [], true );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	public function test_each_rule_reads_in_one_row(): void {
		update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
		$repository = Plugin::instance()->price_rule_repository();
		$repository->save(
			[
				'name'      => 'Summer',
				'amount'    => 1250,
				'starts_at' => '2026-05-01 00:00:00',
				'ends_at'   => '2026-07-01 00:00:00',
			]
		);
		$repository->save(
			[
				'name'                => 'Wholesale',
				'type'                => PriceRule::BULK,
				'tiers'               => [
					[
						'min'    => 5,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
					[
						'min'    => 10,
						'mode'   => PriceRule::FIXED,
						'amount' => 150,
					],
				],
				'customers'           => PriceRule::ROLES,
				'roles'               => [ 'editor' ],
				'applies_to'          => PriceRule::CATEGORIES,
				'category_ids'        => [ 7 ],
				'exclude_product_ids' => [ 3, 4 ],
			]
		);
		$repository->save(
			[
				'name'      => 'Card fee',
				'type'      => PriceRule::FEE,
				'amount'    => 300,
				'cap'       => 500,
				'starts_at' => '2026-07-01 00:00:00',
			]
		);
		$repository->save(
			[
				'name'        => 'Old price',
				'enabled'     => false,
				'mode'        => PriceRule::PRICE,
				'amount'      => 899,
				'customers'   => PriceRule::LOGGED_IN,
				'applies_to'  => PriceRule::PRODUCTS,
				'product_ids' => [ 12 ],
			]
		);
		$repository->save(
			[
				'name'    => 'Spring',
				'mode'    => PriceRule::FIXED,
				'amount'  => 200,
				'ends_at' => '2026-05-01 00:00:00',
			]
		);

		$rows = PriceRuleCommand::rows( $repository->all(), self::NOW, Plugin::instance()->cart()->pricing() );
		self::assertSame(
			[
				'id'        => 1,
				'name'      => 'Summer',
				'status'    => 'live',
				'type'      => 'adjust',
				'change'    => '-12.5%',
				'products'  => 'all products',
				'customers' => 'everyone',
				'starts'    => '2026-05-01 07:00',
				'ends'      => '2026-07-01 07:00',
			],
			$rows[0],
			'dates in the site time zone'
		);
		self::assertSame( [ '5+ -10%, 10+ -$1.50', '1 category, 2 left out', 'editor' ], [ $rows[1]['change'], $rows[1]['products'], $rows[1]['customers'] ] );
		self::assertSame( [ 'scheduled', '+3% fee (at most $5.00)' ], [ $rows[2]['status'], $rows[2]['change'] ] );
		self::assertSame( [ 'off', 'price $8.99', '1 product', 'logged in' ], [ $rows[3]['status'], $rows[3]['change'], $rows[3]['products'], $rows[3]['customers'] ] );
		self::assertSame( [ 'ended', '-$2.00', '' ], [ $rows[4]['status'], $rows[4]['change'], $rows[4]['starts'] ] );
		self::assertSame( PriceRuleCommand::FIELDS, array_keys( $rows[0] ), 'every row has every column, in order' );
	}
}
