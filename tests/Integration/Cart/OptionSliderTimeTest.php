<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRules;

/** The slider, time and date and time option fields (ADR-044). */
final class OptionSliderTimeTest extends CartTestCase {
	/** @var array<string,string> */
	private array $formats = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'date_format', 'time_format' ] as $key ) {
			$this->formats[ $key ] = (string) get_option( $key );
		}
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		update_option( ProductOptions::OPTION, [], true );
		update_option( PricingService::RULES_OPTION, [], true );
		foreach ( $this->formats as $key => $value ) {
			update_option( $key, $value );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_a_slider_posts_a_number_on_its_steps(): void {
		$set   = $this->sets()->save(
			$this->one(
				[
					'type'        => OptionField::RANGE,
					'label'       => 'Length (m)',
					'required'    => true,
					'min'         => 1,
					'max'         => 10,
					'step'        => 0.5,
					'placeholder' => '2.50',
					'price_mode'  => OptionField::FORMULA,
					'formula'     => '{1} * 3',
				]
			)
		);
		$field = $set->fields[0];
		self::assertFalse( $field->required, 'a slider always has a value' );
		self::assertSame( [ 1.0, 10.0, 0.5, '2.5' ], [ $field->min, $field->max, $field->step, $field->placeholder ] );

		$id    = $this->simple( 2000 );
		$state = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => '4.0' ] );
		self::assertSame( [ '1-1' => '4' ], $state->items[0]->line->options );
		self::assertSame( 2000 + 1200, $state->items[0]->unit?->active->amount, 'the formula reads the number: 4 x 3.00' );

		foreach ( [ '0.5', '10.5', '4.2', 'long' ] as $bad ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $bad ] );
				self::fail( $bad . ' is refused' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'invalid_option', $e->reason );
			}
		}

		$html = $this->form( $id );
		self::assertMatchesRegularExpression( '/<input type="range" id="([^"]+)" name="options\[1-1\]" value="2.5" min="1" max="10" step="0.5"><output for="\1">2.5<\/output>/', $html );

		$plain = $this->sets()->save(
			$this->one(
				[
					'type'  => OptionField::RANGE,
					'label' => 'Plain',
				]
			)
		)->fields[0];
		self::assertSame( [ 0.0, 100.0, 1.0, '0' ], [ $plain->min, $plain->max, $plain->step, $plain->start() ], 'an empty slider runs from 0 to 100' );
	}

	public function test_the_editor_checks_a_slider(): void {
		$range = [
			'type'  => OptionField::RANGE,
			'label' => 'R',
		];
		foreach (
			[
				'no room'       => [
					'min' => 5,
					'max' => 5,
				],
				'no step'       => [ 'step' => 0 ],
				'start outside' => [ 'placeholder' => '101' ],
				'start between' => [
					'step'        => 10,
					'placeholder' => '15',
				],
				'start as text' => [ 'placeholder' => 'half' ],
			] as $case => $input
		) {
			try {
				$this->sets()->save( $this->one( $input + $range ) );
				self::fail( $case . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( [ 'fields', 0 ], [ $e->field, $e->index ], $case );
			}
		}
	}

	public function test_a_time_takes_a_slot_within_its_hours(): void {
		update_option( 'time_format', 'H:i' );
		$set   = $this->sets()->save(
			$this->one(
				[
					'type'        => OptionField::TIME,
					'label'       => 'Pickup',
					'required'    => true,
					'placeholder' => 'ignored',
					'min'         => '09:00',
					'max'         => 1020,
					'step'        => 30,
				]
			)
		);
		$field = $set->fields[0];
		self::assertSame( [ 540.0, 1020.0, 30.0, '' ], [ $field->min, $field->max, $field->step, $field->placeholder ] );

		$id    = $this->simple( 2000 );
		$state = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => '09:30:00' ] );
		self::assertSame( [ '1-1' => '09:30' ], $state->items[0]->line->options );
		self::assertSame( '09:30', $state->items[0]->line->labels[0]['value'] );

		foreach ( [ '08:30', '17:30', '09:45' ] as $outside ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $outside ] );
				self::fail( $outside . ' is not offered' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'Pick a time for Pickup within the times offered.', $e->getMessage() );
			}
		}
		foreach ( [ '9:30', '24:00', 'noon' ] as $bad ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $bad ] );
				self::fail( $bad . ' is not a time' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'Check the value of Pickup.', $e->getMessage() );
			}
		}

		$html = $this->form( $id );
		self::assertMatchesRegularExpression( '/<input type="time" id="[^"]+" name="options\[1-1\]" required min="09:00" max="17:00" step="1800">/', $html );

		foreach (
			[
				'not a time'   => [ 'min' => '9am' ],
				'past the day' => [ 'max' => OptionSetRepository::DAY_MINUTES ],
				'latest first' => [
					'min' => '12:00',
					'max' => '11:00',
				],
				'long step'    => [ 'step' => OptionSetRepository::MAX_STEP + 1 ],
			] as $case => $input
		) {
			try {
				$this->sets()->save(
					$this->one(
						$input + [
							'type'  => OptionField::TIME,
							'label' => 'T',
						]
					)
				);
				self::fail( $case . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( 'fields', $e->field, $case );
			}
		}
	}

	public function test_a_date_and_time_takes_a_moment_in_its_days(): void {
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		$this->sets()->save(
			$this->one(
				[
					'type'       => OptionField::DATETIME,
					'label'      => 'Delivery',
					'min'        => 1,
					'max'        => 7,
					'price_mode' => OptionField::FIXED,
					'price'      => 500,
				]
			)
		);
		$today = current_datetime()->setTime( 0, 0 );
		$day   = static fn ( int $days ): string => $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' );

		$id    = $this->simple( 2000 );
		$state = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $day( 2 ) . 'T14:05:00' ] );
		self::assertSame( [ '1-1' => $day( 2 ) . 'T14:05' ], $state->items[0]->line->options );
		self::assertSame( $today->modify( '+2 days' )->format( 'd.m.Y' ) . ' 14:05', $state->items[0]->line->labels[0]['value'] );
		self::assertSame( 2500, $state->items[0]->unit?->active->amount );

		$empty = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => '' ] );
		self::assertSame( 2000, $empty->items[0]->unit?->active->amount, 'an optional field left empty costs nothing' );

		foreach ( [ $day( 0 ) . 'T12:00', $day( 8 ) . 'T00:00' ] as $outside ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $outside ] );
				self::fail( $outside . ' is outside the days' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'Pick a date for Delivery within the dates offered.', $e->getMessage() );
			}
		}
		foreach ( [ $day( 2 ), $day( 2 ) . 'T25:00', '2026-02-30T10:00' ] as $bad ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $bad ] );
				self::fail( $bad . ' is not a date and time' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'invalid_option', $e->reason );
			}
		}

		$html = $this->form( $id );
		self::assertStringContainsString( '<input type="datetime-local" id="', $html );
		self::assertStringContainsString( ' min="' . $day( 1 ) . 'T00:00" max="' . $day( 7 ) . 'T23:59">', $html );
	}

	public function test_a_field_can_show_once_the_slider_has_a_value(): void {
		$this->sets()->save(
			[
				'name'   => 'Set',
				'fields' => [
					[
						'id'    => 1,
						'type'  => OptionField::RANGE,
						'label' => 'Size',
					],
					[
						'id'    => 2,
						'type'  => OptionField::TEXT,
						'label' => 'Note',
						'logic' => [
							'action' => OptionField::SHOW,
							'match'  => OptionField::ALL,
							'rules'  => [
								[
									'field' => 1,
									'op'    => OptionField::NOT_EMPTY,
									'value' => '',
								],
							],
						],
					],
				],
			]
		);
		$html = $this->form( $this->simple( 2000 ) );
		self::assertDoesNotMatchRegularExpression( '/data-option="1-2" hidden/', $html, 'the slider starts with a value, so the note shows' );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	private function one( array $field ): array {
		return [
			'name'   => 'Set',
			'fields' => [ $field ],
		];
	}

	private function form( int $id ): string {
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
		return do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
	}

	private function sets(): OptionSetRepository {
		return Plugin::instance()->option_sets();
	}

	private function shopper( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			$tokens ?? new MemoryTokenStore(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}
}
