<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\MoneyFactory;

defined( 'ABSPATH' ) || exit;

final class StoreMoneyFactory implements MoneyFactory {
	public function currency(): string {
		return Settings::string( 'currency' );
	}

	public function exponent( ?string $currency = null ): int {
		return Currency::exponent( $currency ?? $this->currency() );
	}

	public function from_minor( int $amount, ?string $currency = null ): Money {
		return new Money( $amount, $currency ?? $this->currency() );
	}

	public function from_decimal_string( string $value, ?string $currency = null ): Money {
		$currency ??= $this->currency();
		return Money::from_decimal_string( $value, $currency, Currency::exponent( $currency ) );
	}

	public function format( Money $money ): string {
		$decimal = ltrim( $money->to_decimal_string( $this->exponent( $money->currency ) ), '-' );

		[ $whole, $fraction ] = array_pad( explode( '.', $decimal, 2 ), 2, '' );
		$grouped              = strrev( implode( Settings::string( 'thousand_separator' ), str_split( strrev( $whole ), 3 ) ) );
		$number               = $fraction === '' ? $grouped : $grouped . Settings::string( 'decimal_separator' ) . $fraction;

		$symbol = Currency::symbol( $money->currency );
		$text   = match ( Settings::string( 'currency_position' ) ) {
			'right'       => $number . $symbol,
			'left_space'  => $symbol . ' ' . $number,
			'right_space' => $number . ' ' . $symbol,
			default       => $symbol . $number,
		};

		return $money->is_negative() ? '-' . $text : $text;
	}
}
