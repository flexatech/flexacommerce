<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One taxable line of a request. `amount` is the line total after
 * discounts; it includes tax when the request says prices include tax,
 * except for shipping lines, which are always exclusive of tax.
 *
 * @api
 */
final class TaxLine {
	public const PRODUCT  = 'product';
	public const SHIPPING = 'shipping';
	public const FEE      = 'fee';

	public const TAXABLE       = 'taxable';
	public const SHIPPING_ONLY = 'shipping';
	public const NONE          = 'none';

	public const STATUSES = [ self::TAXABLE, self::SHIPPING_ONLY, self::NONE ];

	public function __construct(
		public readonly string $key,
		public readonly string $type,
		public readonly Money $amount,
		public readonly int $quantity = 1,
		public readonly string $tax_class = '',
		public readonly string $tax_status = self::TAXABLE,
		public readonly int $variant_id = 0,
	) {
		if ( ! in_array( $type, [ self::PRODUCT, self::SHIPPING, self::FEE ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown tax line type "%s".', $type ) );
		}
		if ( $amount->is_negative() ) {
			throw new \InvalidArgumentException( 'A tax line amount cannot be negative.' );
		}
	}

	public function is_inclusive( bool $prices_include_tax ): bool {
		return $prices_include_tax && $this->type !== self::SHIPPING;
	}
}
