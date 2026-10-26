<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Problem;

defined( 'ABSPATH' ) || exit;

final class WriteResult {
	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const SKIPPED = 'skipped';

	/** @param list<Problem> $problems */
	public function __construct(
		public readonly string $action,
		public readonly int $target_id,
		public readonly array $problems = [],
	) {}

	/** @param list<Problem> $problems */
	public static function skipped( array $problems ): self {
		return new self( self::SKIPPED, 0, $problems );
	}
}
