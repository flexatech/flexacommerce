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

/** The date, color, swatch and button option fields (ADR-028). */
final class OptionFieldTypesTest extends CartTestCase {
	private string $format = '';

	public function set_up(): void {
		parent::set_up();
		$this->format = (string) get_option( 'date_format' );
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a cart write commits, which can keep the lists.
		update_option( ProductOptions::OPTION, [], true );
		update_option( PricingService::RULES_OPTION, [], true );
		update_option( 'date_format', $this->format );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_a_date_takes_a_day_in_its_range(): void {
		update_option( 'date_format', 'd.m.Y' );
		$set   = $this->sets()->save(
			$this->one(
				[
					'type'     => OptionField::DATE,
					'label'    => 'Delivery',
					'required' => true,
					'min'      => 1,
					'max'      => 30,
				]
			)
		);
		$today = current_datetime()->setTime( 0, 0 );
		$day   = static fn ( int $days ): string => $today->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		self::assertSame( [ $day( 1 ), $day( 30 ) ], $set->fields[0]->date_range() );

		$id      = $this->simple( 2000 );
		$browser = $this->shopper();
		$state   = $browser->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $day( 1 ) ] );
		self::assertSame( [ '1-1' => $day( 1 ) ], $state->items[0]->line->options );
		self::assertSame(
			[
				[
					'name'  => 'Delivery',
					'value' => $today->modify( '+1 day' )->format( 'd.m.Y' ),
				],
			],
			$state->items[0]->line->labels,
			'the cart shows the site date format'
		);

		foreach ( [ $day( 0 ), $day( 31 ) ] as $outside ) {
			try {
				$browser->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $outside ] );
				self::fail( $outside . ' is outside the range' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'Pick a date for Delivery within the dates offered.', $e->getMessage() );
			}
		}
		foreach ( [ '2026-02-30', '27/10/2026', '2026-1-5' ] as $bad ) {
			try {
				$browser->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $bad ] );
				self::fail( $bad . ' is not a date' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'invalid_option', $e->reason );
			}
		}

		$html = $this->form( $id );
		self::assertStringContainsString( '<input type="date" id="', $html );
		self::assertStringContainsString( ' required min="' . $day( 1 ) . '" max="' . $day( 30 ) . '">', $html );
	}

	public function test_the_editor_keeps_days_within_bounds(): void {
		$date = [
			'type'  => OptionField::DATE,
			'label' => 'D',
		];
		foreach (
			[
				'negative'     => [ 'min' => -1 ],
				'a fraction'   => [ 'min' => 1.5 ],
				'ten years on' => [ 'max' => OptionSetRepository::MAX_DAYS + 1 ],
				'latest first' => [
					'min' => 5,
					'max' => 2,
				],
			] as $case => $input
		) {
			try {
				$this->sets()->save( $this->one( $input + $date ) );
				self::fail( $case . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( [ 'fields', 0 ], [ $e->field, $e->index ], $case );
			}
		}
		$field = $this->sets()->save( $this->one( $date + [ 'min' => 0 ] ) )->fields[0];
		self::assertSame( [ 0.0, null ], [ $field->min, $field->max ] );
	}

	public function test_a_color_always_posts_and_charges(): void {
		$set   = $this->sets()->save(
			$this->one(
				[
					'type'        => OptionField::COLOR,
					'label'       => 'Thread',
					'required'    => true,
					'placeholder' => '#FF0000',
					'price_mode'  => OptionField::FIXED,
					'price'       => 200,
				]
			)
		);
		$field = $set->fields[0];
		self::assertFalse( $field->required, 'a color picker always has a value' );
		self::assertSame( '#ff0000', $field->placeholder );

		$id    = $this->simple( 2000 );
		$state = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => '#AABBCC' ] );
		self::assertSame( [ '1-1' => '#aabbcc' ], $state->items[0]->line->options );
		self::assertSame( '#AABBCC', $state->items[0]->line->labels[0]['value'] );
		self::assertSame( 2200, $state->items[0]->unit?->active->amount );

		foreach ( [ 'red', '#abc', '#aabbcz' ] as $bad ) {
			try {
				$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => $bad ] );
				self::fail( $bad . ' is not a color' );
			} catch ( InvalidCartRequest $e ) {
				self::assertSame( 'invalid_option', $e->reason );
			}
		}

		$html = $this->form( $id );
		self::assertMatchesRegularExpression( '/<input type="color" id="[^"]+" name="options\[1-1\]" value="#ff0000">/', $html );

		$this->expectException( InvalidOptionSet::class );
		$this->sets()->save( [ 'fields' => [ [ 'placeholder' => 'red' ] + $field->to_array() ] ], $set->id );
	}

	public function test_swatches_and_buttons_take_one_pick(): void {
		$this->sets()->save(
			[
				'name'   => 'Look',
				'fields' => [
					[
						'type'     => OptionField::SWATCH,
						'label'    => 'Color',
						'required' => true,
						'choices'  => [
							[
								'label'   => 'Black',
								'color'   => '#000000',
								'default' => true,
							],
							[
								'label'      => 'Red',
								'color'      => '#C62828',
								'price_mode' => OptionField::FIXED,
								'price'      => 200,
							],
						],
					],
					[
						'type'    => OptionField::BUTTON,
						'label'   => 'Size',
						'choices' => [
							[
								'label' => 'S',
								'color' => '#ffffff',
							],
							[ 'label' => 'M' ],
						],
					],
				],
			]
		);
		$set = $this->sets()->all()[0];
		self::assertSame( [ '#000000', '#c62828' ], array_column( $set->fields[0]->choices, 'color' ) );
		self::assertSame( [ '', '' ], array_column( $set->fields[1]->choices, 'color' ), 'only swatches keep a color' );

		$id    = $this->simple( 2000 );
		$state = $this->shopper()->cart()->add(
			0,
			$id,
			0,
			1,
			[],
			[
				'1-1' => '2',
				'1-2' => '2',
			]
		);
		self::assertSame(
			[
				[
					'name'  => 'Color',
					'value' => 'Red',
				],
				[
					'name'  => 'Size',
					'value' => 'M',
				],
			],
			$state->items[0]->line->labels
		);
		self::assertSame( 2200, $state->items[0]->unit?->active->amount );

		try {
			$this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => [ '1', '2' ] ] );
			self::fail( 'a swatch takes one pick' );
		} catch ( InvalidCartRequest $e ) {
			self::assertSame( 'invalid_option', $e->reason );
		}

		$html = (string) preg_replace( '/>\s*</', '><', $this->form( $id ) );
		self::assertStringContainsString( '<fieldset class="fxc-option fxc-option--swatch" data-option="1-1"><legend>Color', $html );
		self::assertStringContainsString(
			'<label class="fxc-swatch fxc-swatch--color" title="Red (+$2.00)"><input class="fxc-swatch__input" type="radio" name="options[1-1]" value="2" required><span class="fxc-swatch__face" style="--fxc-swatch-color:#c62828"></span><span class="screen-reader-text">Red <span class="fxc-option__price">(+$2.00)</span></span></label>',
			$html
		);
		self::assertStringContainsString( 'value="1" checked required>', $html );
		self::assertStringContainsString( '<label class="fxc-swatch fxc-swatch--button"><input class="fxc-swatch__input" type="radio" name="options[1-2]" value="2"><span class="fxc-swatch__face">M</span></label>', $html );
	}

	public function test_the_editor_refuses_a_swatch_without_a_color(): void {
		foreach (
			[
				'no color'       => [ [ 'label' => 'A' ] ],
				'a color name'   => [
					[
						'label' => 'A',
						'color' => 'blue',
					],
				],
				'two at a start' => [
					[
						'label'   => 'A',
						'color'   => '#000000',
						'default' => true,
					],
					[
						'label'   => 'B',
						'color'   => '#ffffff',
						'default' => true,
					],
				],
			] as $case => $choices
		) {
			try {
				$this->sets()->save(
					$this->one(
						[
							'type'    => OptionField::SWATCH,
							'label'   => 'S',
							'choices' => $choices,
						]
					)
				);
				self::fail( $case . ' is refused' );
			} catch ( InvalidOptionSet $e ) {
				self::assertSame( [ 'fields', 0 ], [ $e->field, $e->index ], $case );
			}
		}
		self::assertSame( [], $this->sets()->all() );
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
