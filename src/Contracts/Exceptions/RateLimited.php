<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** Too many writes in a short time. `retry_after` is in seconds. @api */
final class RateLimited extends CommerceException {
	public const CODE = 'rate_limited';

	public function __construct( public readonly int $retry_after = 60 ) {
		parent::__construct( __( 'Too many requests. Please wait a moment and try again.', 'flexacommerce' ) );
	}
}
