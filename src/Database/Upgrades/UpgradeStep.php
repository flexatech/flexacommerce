<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database\Upgrades;

defined( 'ABSPATH' ) || exit;

/**
 * One numbered schema or data change (doc 03 §6). Steps must be safe to run
 * twice: check INFORMATION_SCHEMA (Schema::column_exists, ::index_exists)
 * before every ALTER. Long backfills schedule themselves in batches instead
 * of running inline.
 */
interface UpgradeStep {
	public function run(): void;
}
