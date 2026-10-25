<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Shipping;

use Flexa\Commerce\Contracts\Money;

defined( 'ABSPATH' ) || exit;

/** @api */
final class PackageLine {
	/** @param array{0:int,1:int,2:int}|null $dimensions_mm length, width, height */
	public function __construct(
		public readonly int $variant_id,
		public readonly int $qty,
		public readonly ?int $weight_g,
		public readonly ?array $dimensions_mm,
		public readonly Money $line_total,
	) {}
}
