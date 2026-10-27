<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Contracts\Exceptions\InvalidOptionSet;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Pricing\Rules\PriceRules;

/** Formula prices for options (ADR-039): what the editor keeps, what the cart charges, what the form carries. */
final class FormulaPriceTest extends CartTestCase {
	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a cart write commits, which can keep the lists.
		update_option( ProductOptions::OPTION, [], true );
		update_option( PricingService::RULES_OPTION, [], true );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		( new \ReflectionProperty( Plugin::class, 'cart' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'checkout' ) )->setValue( Plugin::instance(), null );
		( new \ReflectionProperty( Plugin::class, 'product_options' ) )->setValue( Plugin::instance(), null );
	}

	public function test_the_editor_keeps_a_formula(): void {
		$set = $this->sets()->save( $this->sign( ' {1} * {2}  / 10000 * 25 ' ) );
		self::assertSame( OptionField::FORMULA, $set->fields[2]->price_mode );
		self::assertSame( '{1} * {2} / 10000 * 25', $set->fields[2]->formula, 'Trimmed, spaces collapsed.' );
		self::assertSame( 0, $set->fields[2]->price );
		self::assertSame( 'max(5, {3} * 2)', $set->fields[3]->choices[1]['formula'], 'A choice keeps its own.' );
		self::assertSame( '', $set->fields[3]->choices[0]['formula'] );

		$set = $this->sets()->save( [ 'fields' => $this->sign( '{1} * 2', OptionField::FIXED )['fields'] ], $set->id );
		self::assertSame( '', $set->fields[2]->formula, 'Another price mode drops the formula.' );
	}

	public function test_the_editor_refuses_a_formula_it_cannot_work_out(): void {
		foreach (
			[
				''          => 'Enter a formula for the price.',
				'{1} *'     => 'The formula {1} * cannot be worked out.',
				'width * 2' => 'cannot be worked out',
				'{9} * 2'   => 'The formula {9} * 2 uses {9}, which is not a field of this set.',
				'{4} * 2'   => 'uses {4}',
			] as $formula => $message
		) {
			try {
				$this->sets()->save( $this->sign( $formula ) );
				self::fail( 'Saved ' . $formula );
			} catch ( InvalidOptionSet $e ) {
				self::assertStringContainsString( $message, $e->getMessage(), $formula );
				self::assertSame( 'fields', $e->field );
			}
		}
		self::assertSame( [], $this->sets()->all() );
	}

	public function test_the_cart_works_out_the_price(): void {
		$id = $this->simple( 2000 );
		$this->sets()->save( $this->sign( 'round({1} * {2} / 10000 * 25, 2) + {3} * 0.5 / qty' ) );
		$cart = $this->shopper()->cart();

		// 120 x 50 cm at 25 a square metre is 15.00; "SALE" adds 4 x 0.50 shared by 2 items.
		$state = $cart->add(
			0,
			$id,
			0,
			2,
			[],
			[
				'1-1' => '120',
				'1-2' => '50',
				'1-3' => 'S A L E',
				'1-5' => '1',
			]
		);
		self::assertSame( 2000 + 1600, $state->items[0]->unit?->active->amount );

		$key   = $state->items[0]->line->key;
		$state = $cart->replace(
			0,
			$key,
			$id,
			0,
			4,
			[],
			[
				'1-1' => '120',
				'1-2' => '50',
				'1-5' => '2',
			]
		);
		// No text now, so no charge for it; "Double" costs max(5, 0 * 2).
		self::assertSame( 2000 + 500, $state->items[0]->unit?->active->amount, 'An empty field reads 0.' );
		self::assertSame( 4 * 2500, $state->items[0]->total?->amount );
	}

	public function test_the_unit_price_is_after_rules(): void {
		$id = $this->simple( 2000 );
		$this->sets()->save(
			[
				'name'   => 'Share',
				'fields' => [
					[
						'type'       => OptionField::TEXT,
						'label'      => 'Note',
						'price_mode' => OptionField::FORMULA,
						'formula'    => 'base * 0.1',
					],
				],
			]
		);
		Plugin::instance()->price_rule_repository()->save(
			[
				'name'   => 'Half off',
				'amount' => 5000,
			]
		);
		$state = $this->shopper()->cart()->add( 0, $id, 0, 1, [], [ '1-1' => 'Hi' ] );
		self::assertSame( 1000 + 100, $state->items[0]->unit?->active->amount );

		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( '"price":["formula",0,"base * 0.1"]', $html );
		self::assertStringContainsString( '"base":1000', $html, 'A formula that reads base gets the unit price.' );
	}

	public function test_the_form_carries_the_formulas(): void {
		$id = $this->simple( 2000 );
		$this->sets()->save( $this->sign( '{1} * {2} / 10000 * 25' ) );
		$html = do_shortcode( '[flexacommerce_add_to_cart id="' . $id . '"]' );
		self::assertStringContainsString( '"price":["formula",0,"{1} * {2} \\/ 10000 * 25"]', $html );
		self::assertStringContainsString( '"prices":{"2":["formula",0,"max(5, {3} * 2)"]}', $html );
		self::assertStringContainsString( '"base":null', $html, 'No unit price needed.' );
		self::assertStringNotContainsString( '(+', $html, 'A formula has no price to show by the field.' );
	}

	/** @return array<string,mixed> width {1}, height {2} with the formula, text {3}, a heading {4}, a finish {5} */
	private function sign( string $formula, string $mode = OptionField::FORMULA ): array {
		return [
			'name'   => 'Sign',
			'fields' => [
				[
					'id'    => 1,
					'type'  => OptionField::NUMBER,
					'label' => 'Width',
				],
				[
					'id'    => 2,
					'type'  => OptionField::NUMBER,
					'label' => 'Height',
				],
				[
					'id'         => 3,
					'type'       => OptionField::TEXT,
					'label'      => 'Text',
					'price_mode' => $mode,
					'price'      => 100,
					'formula'    => $formula,
				],
				[
					'id'      => 5,
					'type'    => OptionField::SELECT,
					'label'   => 'Finish',
					'choices' => [
						[
							'id'    => 1,
							'label' => 'Single',
						],
						[
							'id'         => 2,
							'label'      => 'Double',
							'price_mode' => OptionField::FORMULA,
							'formula'    => 'max(5, {3} * 2)',
						],
					],
				],
				[
					'id'    => 4,
					'type'  => OptionField::HEADING,
					'label' => 'Notes',
				],
			],
		];
	}

	private function sets(): OptionSetRepository {
		return Plugin::instance()->option_sets();
	}

	private function shopper(): CartServices {
		$plugin = Plugin::instance();
		return new CartServices(
			$plugin->services(),
			$plugin->catalog(),
			new MemoryTokenStore(),
			rules: fn (): PriceRules => $plugin->price_rules(),
			product_options: fn (): ProductOptions => new ProductOptions( $plugin->option_sets() ),
		);
	}
}
