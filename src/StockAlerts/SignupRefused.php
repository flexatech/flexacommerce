<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

defined( 'ABSPATH' ) || exit;

/**
 * A back-in-stock signup that was not taken (ADR-065). `reason` is one of
 * off, login, email, product or in_stock; the form and the store route turn
 * it into a message.
 */
final class SignupRefused extends \RuntimeException {
	public readonly string $reason;

	public function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}
}
