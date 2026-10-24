<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** @api */
final class IncompatibleApi extends CommerceException {
	public const CODE = 'incompatible_api';

	public function __construct( public readonly string $required, public readonly string $available ) {
		parent::__construct( sprintf( 'FlexaCommerce extension API %s is required, %s is available.', $required, $available ) );
	}
}
