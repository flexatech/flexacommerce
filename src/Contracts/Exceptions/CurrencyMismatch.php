<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Exceptions;

use Flexa\Commerce\Contracts\CommerceException;

defined( 'ABSPATH' ) || exit;

/** @api */
final class CurrencyMismatch extends CommerceException {
	public const CODE = 'currency_mismatch';

	public static function between( string $a, string $b ): self {
		return new self( sprintf( 'Cannot combine amounts in %s and %s.', $a, $b ) );
	}
}
