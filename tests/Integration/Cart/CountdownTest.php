<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\Rules\PriceRule;
use Flexa\Commerce\Pricing\Rules\PriceRuleRepository;
use Flexa\Commerce\Support\Settings;

/** The countdown before a price goes up (ADR-030). */
final class CountdownTest extends CartTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( PriceRuleRepository::OPTION, [], true );
		Settings::update( [ 'countdown_days' => 7 ] );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		Plugin::instance()->cart()->pricing()->reset();
	}

	public function test_a_rule_that_ends_soon_shows_a_countdown(): void {
		$id   = $this->simple( 1000 );
		$ends = self::in( 2 * DAY_IN_SECONDS );
		$this->rule( 'Weekend sale', 1000, $ends );

		$html = $this->form( $id );
		self::assertStringContainsString( '<div class="fxc-countdown fxc-countdown--offer" data-ends="' . gmdate( 'c', (int) strtotime( $ends . ' UTC' ) ) . '"', $html );
		self::assertStringContainsString( 'data-wp-init="callbacks.countdown"><p class="fxc-countdown__text">This price ends on <time datetime=', $html );
		self::assertStringContainsString( '<p class="fxc-countdown__clock" aria-hidden="true" hidden><span class="fxc-countdown__title">Offer ends in</span>', $html );
		self::assertStringContainsString( '<span class="fxc-countdown__name">Seconds</span>', $html );

		Settings::update( [ 'countdown_days' => 0 ] );
		self::assertStringNotContainsString( 'fxc-countdown', $this->form( $id ), 'the merchant can turn it off' );
	}

	public function test_only_an_end_within_the_set_days(): void {
		$id = $this->simple( 1000 );
		$this->rule( 'Autumn sale', 1000, self::in( 30 * DAY_IN_SECONDS ) );
		self::assertStringNotContainsString( 'fxc-countdown', $this->form( $id ), 'a month away is not urgent' );

		Settings::update( [ 'countdown_days' => 31 ] );
		self::assertStringContainsString( 'fxc-countdown--offer', $this->form( $id ) );
	}

	public function test_no_countdown_when_the_price_stays(): void {
		$id = $this->simple( 1000 );
		$this->rule( 'Short', 1000, self::in( DAY_IN_SECONDS ) );
		$this->rule( 'Always', 2000, null );
		self::assertStringNotContainsString( 'fxc-countdown', $this->form( $id ), 'a bigger rule with no end keeps the price' );
	}

	public function test_a_scheduled_sale_counts_down(): void {
		$id = $this->simple( 1000 );
		$this->save(
			$id,
			[
				'base' => [
					'regular_price' => 1000,
					'sale_price'    => 800,
					'sale_to'       => self::in( 3 * DAY_IN_SECONDS ),
				],
			]
		);
		self::assertStringContainsString( 'fxc-countdown--offer', $this->form( $id ) );
	}

	public function test_bulk_prices_count_down_under_the_table(): void {
		$id   = $this->simple( 1000 );
		$ends = self::in( 2 * DAY_IN_SECONDS );
		$this->rules()->save(
			[
				'name'    => 'Bulk',
				'type'    => PriceRule::BULK,
				'ends_at' => $ends,
				'tiers'   => [
					[
						'min'    => 5,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
				],
			]
		);
		$html = $this->form( $id );
		self::assertStringNotContainsString( 'fxc-countdown--offer', $html, 'one unit costs the same after the end' );
		self::assertMatchesRegularExpression( '#</table><div class="fxc-countdown fxc-countdown--tiers"[^>]*>.*Quantity prices end in#', $html );
	}

	public function test_a_bulk_rule_from_one_counts_down_once(): void {
		$id = $this->simple( 1000 );
		$this->rules()->save(
			[
				'name'    => 'Bulk',
				'type'    => PriceRule::BULK,
				'ends_at' => self::in( 2 * DAY_IN_SECONDS ),
				'tiers'   => [
					[
						'min'    => 1,
						'mode'   => PriceRule::PERCENT,
						'amount' => 1000,
					],
				],
			]
		);
		$html = $this->form( $id );
		self::assertSame( 1, substr_count( $html, 'class="fxc-countdown ' ) );
		self::assertStringContainsString( 'fxc-countdown--offer', $html );
	}

	private function rule( string $name, int $percent, ?string $ends ): void {
		$this->rules()->save(
			[
				'name'    => $name,
				'mode'    => PriceRule::PERCENT,
				'amount'  => $percent,
				'ends_at' => $ends,
			]
		);
	}

	private function rules(): PriceRuleRepository {
		return Plugin::instance()->price_rule_repository();
	}

	private static function in( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	private function form( int $id ): string {
		Plugin::instance()->cart()->pricing()->reset();
		return do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
	}
}
