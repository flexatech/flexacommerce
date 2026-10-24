<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** The product or variation cannot be bought right now. @api */
final class NotPurchasable extends CommerceException {
	public const CODE = 'not_purchasable';

	public function __construct( public readonly int $variant_id, string $message = '' ) {
		parent::__construct( $message !== '' ? $message : __( 'This product cannot be purchased.', 'flexacommerce' ) );
	}
}
