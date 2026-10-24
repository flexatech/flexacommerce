<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and formats Money in the store currency unless another is given.
 *
 * @api
 */
interface MoneyFactory {
	public function currency(): string;

	public function exponent( ?string $currency = null ): int;

	public function from_minor( int $amount, ?string $currency = null ): Money;

	public function from_decimal_string( string $value, ?string $currency = null ): Money;

	/** Plain text for display, for example "$1,234.50". Escape it at output. */
	public function format( Money $money ): string;
}
