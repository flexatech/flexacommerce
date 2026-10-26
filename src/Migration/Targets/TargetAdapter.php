<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Writes canonical records into one store. The target records its own id map
 * rows (and those of children: variants, order items, transactions), so a
 * FlexaCommerce write and its map rows commit together (doc 05 §8.1).
 */
interface TargetAdapter {
	public function system(): string;

	/** @throws \Flexa\Commerce\Migration\Canonical\Rejected When the record cannot be written. */
	public function write( Record $record, Mapping $map, Job $job ): WriteResult;

	/** Last change of a target entity, `Y-m-d H:i:s` UTC, or null when it keeps none. */
	public function modified_at( string $entity, int $target_id ): ?string;

	/** After the last entity of a stage (term counts, variable product sync). */
	public function finish_stage( string $stage, Job $job ): void;

	/** Delete an entity this migration created; false when it is gone already. */
	public function delete( string $entity, int $target_id ): bool;

	/**
	 * Checks against the target store (doc 05 §5.5), given the source's
	 * settings record when the source has one.
	 *
	 * @return list<Problem>
	 */
	public function preflight( Job $job, ?Record $settings ): array;
}
