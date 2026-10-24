<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Input of the `flexacommerce.price.unit` filter besides the variant.
 * `quantity` is the line quantity (1 on product pages), `at` the moment the
 * price applies (UTC).
 *
 * @api
 */
final class PriceContext {
	public function __construct(
		public readonly int $user_id,
		public readonly int $quantity,
		public readonly \DateTimeImmutable $at,
	) {}
}
