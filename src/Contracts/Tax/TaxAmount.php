<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** @api */
final class TaxAmount {
	public function __construct(
		public readonly TaxRate $rate,
		public readonly Money $amount,
	) {}
}
