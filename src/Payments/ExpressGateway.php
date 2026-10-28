<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * A core gateway with wallet buttons (Apple Pay, Google Pay) on the product
 * page, the cart and the top of the checkout (ADR-053). Internal for now,
 * like ConnectedGateway.
 */
interface ExpressGateway {
	public const PLACES = [ 'product', 'cart', 'checkout' ];

	/**
	 * What the buttons need on the page `$place` (one of PLACES), with
	 * `script`, the module that draws them; null when the merchant turned
	 * them off there or the gateway cannot take payments yet.
	 *
	 * @return array<string,mixed>|null
	 */
	public function express_config( string $place ): ?array;
}
