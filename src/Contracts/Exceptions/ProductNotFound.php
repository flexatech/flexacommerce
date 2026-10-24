<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** @api */
final class ProductNotFound extends CommerceException {
	public const CODE = 'product_not_found';

	public function __construct( public readonly int $product_id ) {
		parent::__construct( __( 'Product not found.', 'flexacommerce' ) );
	}
}
