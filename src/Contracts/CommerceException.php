<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Base of every exception an extension may catch. `code()` is a stable string
 * that callers can branch on; messages are for humans and may change.
 *
 * @api
 */
abstract class CommerceException extends \RuntimeException {
	public const CODE = 'commerce_error';

	public function code(): string {
		return static::CODE;
	}
}
