<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/** The entity cannot be imported. Rejects one entity, never the batch (doc 05 §6). */
final class Rejected extends \RuntimeException {
	/** @param array<string,mixed> $context */
	public function __construct(
		public readonly string $reason,
		string $message,
		public readonly array $context = [],
	) {
		parent::__construct( $message );
	}

	public function problem(): Problem {
		return Problem::error( $this->reason, $this->getMessage(), $this->context );
	}
}
