<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Options;

use Flexa\Commerce\Options\Formula;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Formula prices (ADR-039): the parser, its inputs and what it refuses. */
final class FormulaTest extends TestCase {
	private const INPUTS = [
		'base'   => 20.0,
		'qty'    => 3.0,
		'fields' => [
			2 => 120.0,
			3 => 50.0,
		],
	];

	/** @return iterable<string,array{string,float}> */
	public static function results(): iterable {
		yield 'precedence' => [ '1 + 2 * 3', 7.0 ];
		yield 'brackets' => [ '(1 + 2) * 3', 9.0 ];
		yield 'left to right' => [ '10 - 4 - 3', 3.0 ];
		yield 'unary' => [ '-2 * -3 + +1', 7.0 ];
		yield 'decimals' => [ '.5 + 0.25', 0.75 ];
		yield 'fields' => [ '{2} * {3} * 0.01', 60.0 ];
		yield 'spaces in a ref' => [ '{ 2 } / 10', 12.0 ];
		yield 'a missing field reads 0' => [ '{9} + 1', 1.0 ];
		yield 'base and qty' => [ 'BASE * 0.1 + qty', 5.0 ];
		yield 'round' => [ 'round(2.345, 2)', 2.35 ];
		yield 'round half away from zero' => [ 'round(-2.5)', -3.0 ];
		yield 'ceil and floor' => [ 'ceil(({2} - 100) / 5) + floor(1.9)', 5.0 ];
		yield 'min and max' => [ 'max(0, min(5, 9, {3}) - 10)', 0.0 ];
		yield 'division by zero' => [ '5 / (qty - 3)', 0.0 ];
	}

	/** @dataProvider results */
	public function test_it_works_out( string $formula, float $expected ): void {
		self::assertEqualsWithDelta( $expected, Formula::evaluate( $formula, self::INPUTS ), 1e-9, $formula );
	}

	/** @return iterable<array{string}> */
	public static function broken(): iterable {
		foreach ( [ '', '  ', '1 +', '(1', '1)', '1 2', '1.2.3', 'width * 2', 'round()', 'round(1, 2, 3)', 'ceil(1, 2)', 'pow(2, 3)', 'min', '{x}', '{1', '2 ^ 3', '1; 2', str_repeat( '1+', 150 ) . '1' ] as $formula ) {
			yield $formula => [ $formula ];
		}
	}

	/** @dataProvider broken */
	public function test_it_refuses( string $formula ): void {
		self::assertNull( Formula::evaluate( $formula, self::INPUTS ) );
		self::assertNull( Formula::refs( $formula ) );
	}

	public function test_it_lists_its_fields(): void {
		self::assertSame( [ 2, 3 ], Formula::refs( '{2} * {3} + {2}' ) );
		self::assertSame( [], Formula::refs( 'base * 2' ) );
		self::assertTrue( Formula::uses_base( 'max(Base, 1)' ) );
		self::assertFalse( Formula::uses_base( '{2} * 2' ) );
	}

	public function test_a_field_prices_in_minor_units(): void {
		$field  = new OptionField( 1, OptionField::NUMBER, 'Width', price_mode: OptionField::FORMULA, formula: '{1} * 0.015' );
		$inputs = OptionField::inputs( 2000, 1, 2, [ 1 => $field->measure( '33' ) ] );
		self::assertSame( 50, $field->charge( '33', 2000, $inputs ), '0.495 rounds to 50 cents.' );
		self::assertSame( 0, $field->charge( '', 2000, $inputs ), 'Nothing entered, nothing charged.' );

		$less = new OptionField( 1, OptionField::NUMBER, 'Width', price_mode: OptionField::FORMULA, formula: '{1} - 100' );
		self::assertSame( 0, $less->charge( '33', 2000, $inputs ), 'Below 0 counts as 0.' );

		$share = new OptionField( 1, OptionField::TEXT, 'Name', price_mode: OptionField::FORMULA, formula: 'base * 0.1' );
		self::assertSame( 2000, $share->charge( 'x', 20000, OptionField::inputs( 20000, 1, 0, [] ), 0 ), 'A currency without decimals.' );
	}

	public function test_values_read_as_numbers(): void {
		$text = new OptionField( 1, OptionField::TEXT, 'Engraving' );
		self::assertSame( 5.0, $text->measure( "Ha  ll\nö" ), 'Characters, spaces left out.' );
		self::assertSame( 12.5, ( new OptionField( 1, OptionField::NUMBER, 'Width' ) )->measure( '12.5' ) );
		self::assertSame( 2.0, ( new OptionField( 1, OptionField::CHECKBOXES, 'Extras' ) )->measure( [ '1', '3' ] ) );
		self::assertSame( 1.0, ( new OptionField( 1, OptionField::SELECT, 'Size' ) )->measure( '2' ) );
		self::assertSame( 0.0, ( new OptionField( 1, OptionField::DATE, 'Day' ) )->measure( '2026-01-01' ) );
	}
}
