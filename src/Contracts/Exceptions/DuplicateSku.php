<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/**
 * A SKU is already used by another variant row. `product_id` is the product
 * that holds it (0 when the conflict is inside the same save).
 *
 * @api
 */
final class DuplicateSku extends CommerceException {
	public const CODE = 'duplicate_sku';

	public function __construct( public readonly string $sku, public readonly int $product_id = 0 ) {
		parent::__construct(
			sprintf(
				/* translators: %s: the SKU. */
				__( 'The SKU "%s" is already in use.', 'flexacommerce' ),
				$sku
			)
		);
	}
}
