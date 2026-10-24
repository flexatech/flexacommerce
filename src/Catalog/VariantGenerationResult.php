<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

final class VariantGenerationResult {
	public function __construct(
		public readonly int $created,
		public readonly int $skipped,
		public readonly bool $limit_reached,
		public readonly Product $product,
	) {}
}
