<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/** A job action that is not allowed now. `reason` is stable for REST and CLI. */
final class MigrationError extends \RuntimeException {
	public function __construct( public readonly string $reason, string $message ) {
		parent::__construct( $message );
	}
}
